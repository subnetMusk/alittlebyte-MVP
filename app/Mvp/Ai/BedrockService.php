<?php

namespace App\Mvp\Ai;

use App\Exceptions\InvalidAiOutputException;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use Aws\Result;
use Illuminate\Support\Facades\Log;

class BedrockService
{
    /** @var array<int, array{width: int, height: int}> */
    private const NOVA_CANVAS_SIZE_CANDIDATES = [
        ['width' => 1280, 'height' => 720],
        ['width' => 1024, 'height' => 1024],
        ['width' => 720, 'height' => 1280],
    ];

    private const NOVA_CANVAS_MAX_PROMPT_LENGTH = 1000;

    private const STABILITY_MAX_PROMPT_LENGTH = 1000;

    public function __construct(
        private readonly BedrockRuntimeClient $client,
        private readonly BedrockRuntimeClient $imageClient,
        private readonly ?string $modelId,
        private readonly ?string $imageModelId,
        private readonly AiOutputValidator $validator,
        private readonly WorkflowTaskHeartbeat $heartbeat,
    ) {}

    /**
     * @return array{title: string, body: string, image_prompt: ?string}
     *
     * @throws \RuntimeException
     */
    public function generateCommunication(string $prompt, string $tone, string $style): array
    {
        $this->ensureConfigured();

        $aiPrompt = TextModelPrompts::communication($prompt, $tone, $style);

        try {
            /** @var Result $response */
            $response = $this->client->converse([
                'modelId' => $this->modelId,
                'messages' => [
                    ['role' => 'user', 'content' => [['text' => $aiPrompt]]],
                ],
                'inferenceConfig' => ['maxTokens' => 2048, 'temperature' => 0.7],
            ]);

            $jsonResponse = $this->extractJsonFromAiResponse($response->toArray(), 'generateCommunication');

            return $this->validator->validateGenerateCommunication($jsonResponse);
        } catch (AwsException $e) {
            Log::error('AI Generation Error', ['error' => $e->getMessage()]);
            throw new \RuntimeException("Errore di connessione con Bedrock: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Generate a visual cover for the drafted communication.
     *
     * Returns the raw image bytes when a model is configured and reachable, or
     * a user-facing warning plus a machine-readable reason when the cover is
     * not available: a missing cover degrades the communication, never fails it.
     *
     * @param  ?string  $modelImagePrompt  Direzione visiva prodotta dal modello testuale.
     * @return array{bytes: ?string, mime: string, warning: ?string, reason: ?string}
     */
    public function generateCommunicationImageWithMeta(string $prompt, string $tone, string $style, ?string $modelImagePrompt = null): array
    {
        if (! $this->imageModelId) {
            return $this->imageFailure(
                'Copertina AI non disponibile: modello immagini Bedrock non configurato.',
                'model_not_configured',
            );
        }

        $imagePrompt = $this->buildCommunicationImagePrompt($prompt, $tone, $style, $modelImagePrompt);
        $safeImagePrompt = $this->buildSafeFallbackImagePrompt($tone, $style);
        $usingSafePrompt = false;
        $warning = null;
        $reason = null;

        foreach (self::NOVA_CANVAS_SIZE_CANDIDATES as $size) {
            try {
                $this->heartbeat->beat();

                /** @var Result $result */
                $result = $this->imageClient->invokeModel([
                    'modelId' => $this->imageModelId,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode(
                        $this->buildImageInvokePayload($imagePrompt, $size),
                        JSON_THROW_ON_ERROR
                    ),
                ]);

                $body = $this->decodeInvokeModelBody($result->get('body'));
                $decoded = json_decode($body, true);

                if (! is_array($decoded)) {
                    throw new \RuntimeException('Risposta immagine non decodificabile.');
                }

                $image = $this->extractImageBytes($decoded);
                if ($image !== null) {
                    return [
                        'bytes' => $image['bytes'],
                        'mime' => $image['mime'],
                        'warning' => null,
                        'reason' => null,
                    ];
                }

                $responseWarning = $this->classifyImageResponseWarning($decoded);

                Log::warning('Bedrock image model returned no image payload', [
                    'model' => $this->imageModelId,
                    'size' => $size,
                    'keys' => array_keys($decoded),
                    'finish_reasons' => $decoded['finish_reasons'] ?? null,
                ]);

                if ($this->hasPromptFilterFinishReason($decoded) && ! $usingSafePrompt) {
                    $usingSafePrompt = true;
                    $imagePrompt = $safeImagePrompt;

                    Log::info('Retrying Bedrock image generation with sanitized prompt after filter reason.', [
                        'model' => $this->imageModelId,
                        'size' => $size,
                    ]);

                    continue;
                }

                if ($this->hasPromptFilterFinishReason($decoded) && $usingSafePrompt) {
                    return $this->imageFailure(
                        'Copertina AI non disponibile: la richiesta immagini è stata bloccata dai controlli di sicurezza del modello.',
                        'content_filter',
                    );
                }

                if ($responseWarning['warning'] !== null) {
                    $warning ??= $responseWarning['warning'];
                    $reason ??= $responseWarning['reason'];

                    if (! $responseWarning['retryable']) {
                        return $this->imageFailure($warning, $reason);
                    }
                }

                $warning ??= 'Copertina AI non disponibile: il modello non ha restituito un payload immagine valido.';
                $reason ??= 'no_payload';
            } catch (AwsException $e) {
                $classified = $this->classifyImageError($e->getMessage());
                Log::warning('Bedrock communication image generation failed', [
                    'message' => $e->getMessage(),
                    'size' => $size,
                ]);

                // Credenziali, accesso al modello e parametri rifiutati non
                // cambiano al variare della dimensione richiesta: insistere
                // sulle altre size aggiunge solo latenza a un esito certo.
                if (! $classified['retryable']) {
                    return $this->imageFailure($classified['warning'], $classified['reason']);
                }

                $warning ??= $classified['warning'];
                $reason ??= $classified['reason'];
            } catch (\Throwable $e) {
                $warning ??= 'Copertina AI non disponibile per un errore temporaneo del servizio immagini.';
                $reason ??= 'invalid_response';
                Log::warning('Communication image parsing failed', [
                    'message' => $e->getMessage(),
                    'size' => $size,
                ]);
            }
        }

        return $this->imageFailure(
            $warning ?? 'Copertina AI non disponibile al momento.',
            $reason ?? 'model_error',
        );
    }

    /**
     * @return array{bytes: ?string, mime: string, warning: string, reason: string}
     */
    private function imageFailure(string $warning, string $reason): array
    {
        return [
            'bytes' => null,
            'mime' => 'image/png',
            'warning' => $warning,
            'reason' => $reason,
        ];
    }

    /**
     * Il testo della risposta Converse, decodificato con le regole comuni ai
     * modelli testuali.
     *
     * @param  array<string, mixed>  $rawResponse
     * @return array<int|string, mixed>
     *
     * @throws InvalidAiOutputException when the response text is not decodable JSON.
     */
    private function extractJsonFromAiResponse(array $rawResponse, string $operation): array
    {
        return ModelJsonResponse::decode((string) ($rawResponse['output']['message']['content'][0]['text'] ?? ''), $operation);
    }

    private function buildCommunicationImagePrompt(string $userPrompt, string $tone, string $style, ?string $modelImagePrompt): string
    {
        return CoverImagePrompts::forCommunication($userPrompt, $tone, $style, $modelImagePrompt);
    }

    /**
     * Riformulazione conservativa usata dopo un rifiuto sul prompt: rinuncia al
     * soggetto dettagliato per tenere solo tono, stile e vincoli di sicurezza.
     */
    private function buildSafeFallbackImagePrompt(string $tone, string $style): string
    {
        return 'Create a safe, professional, horizontal corporate cover image. '
            ."Tone: {$tone}. Editorial style: {$style}. "
            .'No realistic people faces, no identifiable personal data, no documents, no readable text, no logos, no signatures. '
            .'Prefer abstract or iconographic elements with a modern corporate visual language.';
    }

    /**
     * @param  array{width: int, height: int}  $size
     * @return array<string, mixed>
     */
    private function buildImageInvokePayload(string $imagePrompt, array $size): array
    {
        $seed = random_int(0, 2_147_483_647);

        if ($this->isStabilityImageModel()) {
            return $this->buildStabilityImagePayload($imagePrompt, $size, $seed);
        }

        return $this->buildNovaCanvasImagePayload($imagePrompt, $size, $seed);
    }

    /**
     * @param  array{width: int, height: int}  $size
     * @return array<string, mixed>
     */
    private function buildNovaCanvasImagePayload(string $imagePrompt, array $size, int $seed): array
    {
        return [
            'taskType' => 'TEXT_IMAGE',
            'textToImageParams' => [
                'text' => mb_substr($imagePrompt, 0, self::NOVA_CANVAS_MAX_PROMPT_LENGTH),
                'negativeText' => CoverImagePrompts::NEGATIVE,
            ],
            'imageGenerationConfig' => [
                'numberOfImages' => 1,
                'height' => $size['height'],
                'width' => $size['width'],
                'cfgScale' => 7.5,
                'seed' => $seed,
            ],
        ];
    }

    /**
     * @param  array{width: int, height: int}  $size
     * @return array<string, mixed>
     */
    private function buildStabilityImagePayload(string $imagePrompt, array $size, int $seed): array
    {
        $trimmedPrompt = mb_substr($imagePrompt, 0, self::STABILITY_MAX_PROMPT_LENGTH);

        if ($this->isStabilityCoreImageModel()) {
            $payload = [
                'prompt' => $trimmedPrompt,
                'negative_prompt' => CoverImagePrompts::NEGATIVE,
                'aspect_ratio' => $this->stabilityAspectRatioFromSize($size),
                'output_format' => 'png',
                'seed' => $seed,
            ];

            if ($this->isStabilitySd3ImageModel()) {
                $payload['mode'] = 'text-to-image';
            }

            return $payload;
        }

        return [
            'text_prompts' => [
                ['text' => $trimmedPrompt],
                ['text' => CoverImagePrompts::NEGATIVE, 'weight' => -1],
            ],
            'cfg_scale' => 7,
            'samples' => 1,
            'height' => $size['height'],
            'width' => $size['width'],
            'seed' => $seed,
        ];
    }

    /**
     * @param  array{width: int, height: int}  $size
     */
    private function stabilityAspectRatioFromSize(array $size): string
    {
        if ($size['width'] > $size['height']) {
            return '16:9';
        }

        if ($size['width'] < $size['height']) {
            return '9:16';
        }

        return '1:1';
    }

    private function isStabilityImageModel(): bool
    {
        return str_contains(strtolower((string) $this->imageModelId), 'stability');
    }

    private function isStabilityCoreImageModel(): bool
    {
        $modelId = strtolower((string) $this->imageModelId);

        return str_contains($modelId, 'stable-image')
            || str_contains($modelId, '.sd3')
            || str_contains($modelId, 'sd3-');
    }

    private function isStabilitySd3ImageModel(): bool
    {
        $modelId = strtolower((string) $this->imageModelId);

        return str_contains($modelId, '.sd3') || str_contains($modelId, 'sd3-');
    }

    /**
     * Solo il prompt rifiutato giustifica un secondo tentativo con il prompt
     * sanificato: "content_filtered" e "safety" sono decisioni definitive del
     * modello e vengono classificate come non ritentabili.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function hasPromptFilterFinishReason(array $decoded): bool
    {
        $finishReasons = $decoded['finish_reasons'] ?? $decoded['finishReasons'] ?? null;

        if (! is_array($finishReasons)) {
            return false;
        }

        foreach ($finishReasons as $reason) {
            if (! is_string($reason)) {
                continue;
            }

            if (str_contains(strtolower(trim($reason)), 'filter reason: prompt')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{warning: ?string, reason: ?string, retryable: bool}
     */
    private function classifyImageResponseWarning(array $decoded): array
    {
        $finishReasons = $decoded['finish_reasons'] ?? $decoded['finishReasons'] ?? null;

        if (! is_array($finishReasons)) {
            return ['warning' => null, 'reason' => null, 'retryable' => true];
        }

        $normalized = array_values(array_filter(array_map(static function (mixed $reason): ?string {
            if (! is_string($reason)) {
                return null;
            }

            $trimmed = trim($reason);

            return $trimmed === '' ? null : strtolower($trimmed);
        }, $finishReasons)));

        if ($normalized === []) {
            return ['warning' => null, 'reason' => null, 'retryable' => true];
        }

        if (array_intersect($normalized, ['content_filtered', 'safety'])) {
            return [
                'warning' => 'Copertina AI non disponibile: la richiesta immagini è stata bloccata dai controlli di sicurezza del modello.',
                'reason' => 'content_filter',
                'retryable' => false,
            ];
        }

        if (in_array('error', $normalized, true)) {
            return [
                'warning' => 'Copertina AI non disponibile: il modello immagini ha restituito un esito errore.',
                'reason' => 'model_error',
                'retryable' => true,
            ];
        }

        return ['warning' => null, 'reason' => null, 'retryable' => true];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{bytes: string, mime: string}|null
     */
    private function extractImageBytes(array $decoded): ?array
    {
        $image = $decoded['images'][0]
            ?? $decoded['artifacts'][0]['base64']
            ?? $decoded['result']['images'][0]
            ?? $decoded['image']
            ?? $decoded['image_base64']
            ?? $decoded['imageUriBase64']
            ?? null;

        if (! is_string($image) || trim($image) === '') {
            return null;
        }

        $mime = $decoded['artifacts'][0]['mimeType']
            ?? $decoded['mimeType']
            ?? 'image/png';

        if (str_starts_with($image, 'data:image/')) {
            [$header, $payload] = array_pad(explode(',', $image, 2), 2, '');
            $image = $payload;

            if (preg_match('#^data:(image/[a-z0-9.+-]+);#i', $header, $matches) === 1) {
                $mime = $matches[1];
            }
        }

        $normalized = preg_replace('/\s+/', '', $image);

        if (! is_string($normalized) || $normalized === '' || ! preg_match('/^[A-Za-z0-9+\/=]+$/', $normalized)) {
            return null;
        }

        $bytes = base64_decode($normalized, true);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => is_string($mime) ? $mime : 'image/png'];
    }

    private function decodeInvokeModelBody(mixed $rawBody): string
    {
        if (is_string($rawBody)) {
            return $rawBody;
        }

        if (is_object($rawBody) && method_exists($rawBody, '__toString')) {
            return (string) $rawBody;
        }

        return '';
    }

    /**
     * Errori di configurazione e di accesso valgono per il modello, non per la
     * singola richiesta: solo i parametri rifiutati giustificano un nuovo
     * tentativo con una dimensione diversa.
     *
     * @return array{warning: string, reason: string, retryable: bool}
     */
    private function classifyImageError(string $errorMessage): array
    {
        $message = strtolower($errorMessage);

        if (str_contains($message, 'legacy') || str_contains($message, 'active model')) {
            return [
                'warning' => 'Copertina AI non disponibile: il modello immagini configurato su Bedrock risulta legacy/non attivo.',
                'reason' => 'model_not_available',
                'retryable' => false,
            ];
        }

        if (str_contains($message, 'model access is denied') || str_contains($message, 'access denied')) {
            return [
                'warning' => 'Copertina AI non disponibile: l\'account AWS non ha accesso al modello immagini Bedrock configurato.',
                'reason' => 'model_access_denied',
                'retryable' => false,
            ];
        }

        if (str_contains($message, 'unrecognizedclientexception') || str_contains($message, 'security token included in the request is invalid')) {
            return [
                'warning' => 'Copertina AI non disponibile: credenziali AWS Bedrock non valide.',
                'reason' => 'invalid_credentials',
                'retryable' => false,
            ];
        }

        if (str_contains($message, 'safety')) {
            return [
                'warning' => 'Copertina AI non disponibile: la richiesta immagini è stata bloccata dai controlli di sicurezza del modello.',
                'reason' => 'content_filter',
                'retryable' => false,
            ];
        }

        // Una dimensione non supportata dal modello e' esattamente il caso per
        // cui esiste la lista di size candidate: qui il retry ha senso.
        if (str_contains($message, 'validationexception')) {
            return [
                'warning' => 'Copertina AI non disponibile: Bedrock ha rifiutato i parametri richiesti per la generazione immagini.',
                'reason' => 'invalid_parameters',
                'retryable' => true,
            ];
        }

        return [
            'warning' => 'Copertina AI non disponibile per un errore del servizio Bedrock.',
            'reason' => 'model_error',
            'retryable' => true,
        ];
    }

    /**
     * Classify the OCR text of a document and return per-recipient page boundaries.
     * Works on any document type; always yields at least one recipient.
     *
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     *
     * @throws \RuntimeException
     */
    public function splitDocument(string $ocrText, int $pageCount, string $pageBoundaryNonce): array
    {
        $this->ensureConfigured();

        $prompt = TextModelPrompts::splitDocument($ocrText, $pageCount, $pageBoundaryNonce);

        try {
            /** @var Result $result */
            $result = $this->client->converse([
                'modelId' => $this->modelId,
                'messages' => [
                    ['role' => 'user', 'content' => [['text' => $prompt]]],
                ],
                'inferenceConfig' => ['maxTokens' => 1024, 'temperature' => 0.1],
            ]);

            $decoded = $this->extractJsonFromAiResponse($result->toArray(), 'splitDocument');

            return $this->validator->validateSplitDocument($decoded);
        } catch (AwsException $e) {
            Log::error('Bedrock splitDocument error', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Errore nella chiamata a Bedrock (split): '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Extract structured fields for a single recipient from its OCR text.
     * Works on any document type, not just payslips.
     *
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, recipient_email: ?string, fiscal_code: ?string, employee_id: ?string, confidence_score: ?int}
     *
     * @throws \RuntimeException
     */
    public function extractFields(string $ocrText): array
    {
        $this->ensureConfigured();

        $prompt = TextModelPrompts::extractFields($ocrText);

        try {
            $result = $this->client->converse([
                'modelId' => $this->modelId,
                'messages' => [
                    ['role' => 'user', 'content' => [['text' => $prompt]]],
                ],
                'inferenceConfig' => ['maxTokens' => 512, 'temperature' => 0.1],
            ]);

            $decoded = $this->extractJsonFromAiResponse($result->toArray(), 'extractFields');

            return $this->validator->validateExtractFields($decoded);
        } catch (AwsException $e) {
            Log::error('Bedrock extractFields error', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Errore nella chiamata a Bedrock (extract): '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @throws \RuntimeException when Bedrock is missing required runtime configuration.
     */
    private function ensureConfigured(): void
    {
        if (! $this->modelId) {
            throw new \RuntimeException('Bedrock non configurato: BEDROCK_MODEL_ID deve arrivare da Parameter Store.');
        }
    }

    /**
     * Canary page-boundary marker shared by the OCR text builder and the
     * classifier prompt, so both sides agree on the exact delimiter format.
     */
    public static function pageBoundaryMarker(int $page, string $nonce): string
    {
        return TextModelPrompts::pageBoundaryMarker($page, $nonce);
    }

    public static function formatUserError(\Throwable $e, string $defaultMessage): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'unrecognizedclientexception') || str_contains($message, 'security token included in the request is invalid')) {
            return 'Credenziali AWS Bedrock non valide. Aggiorna AWS_REAL_ACCESS_KEY_ID / AWS_REAL_SECRET_ACCESS_KEY / AWS_REAL_SESSION_TOKEN e ricarica il runtime.';
        }

        if (str_contains($message, 'expiredtoken')) {
            return 'Le credenziali runtime AWS sono scadute. Aggiorna il ruolo applicativo o il segreto runtime in Secrets Manager.';
        }

        if (str_contains($message, 'model access is denied')) {
            return 'Il modello Bedrock configurato non è accessibile con queste credenziali. Usa un modello abilitato (es. amazon.nova-lite-v1:0).';
        }

        if (str_contains($message, 'on-demand throughput') || str_contains($message, 'inference profile')) {
            return 'Il modello Bedrock richiede un inference profile. Aggiorna BEDROCK_MODEL_ID in Parameter Store.';
        }

        return $defaultMessage;
    }
}
