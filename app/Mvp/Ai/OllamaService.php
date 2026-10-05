<?php

namespace App\Mvp\Ai;

use App\Exceptions\InvalidAiOutputException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Client dei modelli testuali serviti da Ollama, la controparte di
 * BedrockService nel profilo di esecuzione locale (ADR 0014). Stessi prompt
 * (TextModelPrompts), stessa decodifica (ModelJsonResponse), stesso validatore:
 * cambia solo il trasporto.
 */
class OllamaService
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly AiOutputValidator $validator,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeoutSeconds,
    ) {}

    /**
     * @return array{title: string, body: string, image_prompt: ?string}
     *
     * @throws \RuntimeException
     */
    public function generateCommunication(string $prompt, string $tone, string $style): array
    {
        $decoded = $this->chat(
            TextModelPrompts::communication($prompt, $tone, $style),
            'generateCommunication',
            'generate-communication',
            maxTokens: 2048,
            temperature: 0.7,
        );

        return $this->validator->validateGenerateCommunication($decoded);
    }

    /**
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     *
     * @throws \RuntimeException
     */
    public function splitDocument(string $ocrText, int $pageCount, string $pageBoundaryNonce): array
    {
        $decoded = $this->chat(
            TextModelPrompts::splitDocument($ocrText, $pageCount, $pageBoundaryNonce),
            'splitDocument',
            'split-document',
            maxTokens: 1024,
            temperature: 0.1,
        );

        return $this->validator->validateSplitDocument($decoded);
    }

    /**
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, recipient_email: ?string, fiscal_code: ?string, employee_id: ?string, confidence_score: ?int}
     *
     * @throws \RuntimeException
     */
    public function extractFields(string $ocrText): array
    {
        $decoded = $this->chat(
            TextModelPrompts::extractFields($ocrText),
            'extractFields',
            'extract-fields',
            maxTokens: 512,
            temperature: 0.1,
        );

        return $this->validator->validateExtractFields($decoded);
    }

    public static function formatUserError(\Throwable $e, string $defaultMessage): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'non raggiungibile')) {
            return 'Modello locale non raggiungibile: verifica che Ollama sia avviato e che LOCAL_LLM_BASE_URL punti al suo indirizzo.';
        }

        if (str_contains($message, 'not found')) {
            return 'Il modello locale configurato non è presente in Ollama: scaricalo con "ollama pull" o correggi LOCAL_LLM_MODEL.';
        }

        return $defaultMessage;
    }

    /**
     * La generazione e' vincolata allo stesso JSON Schema che AiOutputValidator
     * verifica dopo: senza, la modalita' JSON generica di Ollama impone un
     * oggetto alla radice e lo split, che e' un array, perde i destinatari
     * successivi al primo.
     *
     * @return array<int|string, mixed>
     *
     * @throws \RuntimeException
     */
    private function chat(string $prompt, string $operation, string $schemaName, int $maxTokens, float $temperature): array
    {
        $this->ensureConfigured();

        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post('/api/chat', [
                    'model' => $this->model,
                    'stream' => false,
                    // Il contratto chiede solo il JSON: il ragionamento esplicito
                    // del modello allungherebbe i tempi senza entrare nell'output.
                    'think' => false,
                    'format' => self::decodingSchema($this->validator->schema($schemaName)),
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'options' => ['temperature' => $temperature, 'num_predict' => $maxTokens],
                ])
                ->throw();
        } catch (ConnectionException $e) {
            Log::error('Local LLM unreachable', ['operation' => $operation, 'base_url' => $this->baseUrl, 'message' => $e->getMessage()]);

            throw new \RuntimeException("Modello locale non raggiungibile su {$this->baseUrl}: {$e->getMessage()}", previous: $e);
        } catch (RequestException $e) {
            $detail = (string) ($e->response->json('error') ?? $e->response->body());
            Log::error('Local LLM request failed', ['operation' => $operation, 'status' => $e->response->status(), 'message' => $detail]);

            throw new \RuntimeException("Errore del modello locale ({$operation}): {$detail}", previous: $e);
        }

        $content = $response->json('message.content');

        if (! is_string($content)) {
            throw new InvalidAiOutputException($operation, ['la risposta del modello locale non contiene testo']);
        }

        return ModelJsonResponse::decode($content, $operation);
    }

    /**
     * Lo schema usato per vincolare la decodifica rende obbligatorie tutte le
     * chiavi dichiarate. Nel contratto molte sono facoltative, e la decodifica
     * vincolata lascia allora al modello la scelta di ometterle: il modello
     * salta proprio email, codice fiscale e matricola anche quando il testo li
     * riporta. Le chiavi restano annullabili, e la validazione successiva usa
     * lo schema originale.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function decodingSchema(array $schema): array
    {
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $schema['required'] = array_keys($schema['properties']);

            foreach ($schema['properties'] as $name => $property) {
                if (is_array($property)) {
                    $schema['properties'][$name] = self::decodingSchema($property);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::decodingSchema($schema['items']);
        }

        return $schema;
    }

    /**
     * @throws \RuntimeException when the local model is missing required configuration.
     */
    private function ensureConfigured(): void
    {
        if (trim($this->model) === '') {
            throw new \RuntimeException('Modello locale non configurato: LOCAL_LLM_MODEL è obbligatorio nel profilo local.');
        }

        if (! preg_match('#^https?://[^\s/]+#i', $this->baseUrl)) {
            throw new \RuntimeException('Modello locale non configurato: LOCAL_LLM_BASE_URL deve essere un URL http(s).');
        }
    }
}
