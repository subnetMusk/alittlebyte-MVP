<?php

namespace App\Mvp\Ai\Cover;

use App\Mvp\Ai\CoverImagePrompts;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Copertina generata da un server ComfyUI locale, provider opzionale del
 * profilo di esecuzione locale. Il grafo da eseguire e il checkpoint sono
 * configurazione di deployment (COMFYUI_WORKFLOW, COMFYUI_CHECKPOINT): il
 * codice sostituisce solo i segnaposto %prompt%, %negative_prompt%, %seed%,
 * %width%, %height% e %checkpoint%.
 *
 * Un errore degrada la copertina con un motivo esplicito, come per Bedrock:
 * nessun ripiego su un altro generatore.
 */
final class ComfyUiCoverGenerator implements CoverImageGenerator
{
    private const WIDTH = 1280;

    private const HEIGHT = 720;

    private const CLIENT_ID = 'alittlebyte-mvp';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly WorkflowTaskHeartbeat $heartbeat,
        private readonly string $baseUrl,
        private readonly string $workflowPath,
        private readonly string $checkpoint,
        private readonly int $timeoutSeconds,
        private readonly int $pollIntervalMilliseconds = 2000,
    ) {}

    public function generate(string $prompt, string $tone, string $style, ?string $modelImagePrompt): array
    {
        $workflow = $this->loadWorkflow();

        if ($workflow === null || ! preg_match('#^https?://[^\s/]+#i', $this->baseUrl) || trim($this->checkpoint) === '') {
            return $this->failure(
                'Copertina non disponibile: ComfyUI non configurato (COMFYUI_BASE_URL, COMFYUI_CHECKPOINT, COMFYUI_WORKFLOW).',
                'model_not_configured',
            );
        }

        $positive = CoverImagePrompts::forCommunication($prompt, $tone, $style, $modelImagePrompt);
        // Seed ricavato dal contenuto: la stessa richiesta riproduce la stessa
        // copertina, a parita' di modello e di grafo.
        $seed = unpack('N', hash('sha256', $positive, true))[1];

        $graph = $this->fill($workflow, [
            '%prompt%' => $positive,
            '%negative_prompt%' => CoverImagePrompts::NEGATIVE,
            '%seed%' => $seed,
            '%width%' => self::WIDTH,
            '%height%' => self::HEIGHT,
            '%checkpoint%' => $this->checkpoint,
        ]);

        $startedAt = microtime(true);

        try {
            $promptId = (string) $this->client()
                ->post('/prompt', ['prompt' => $graph, 'client_id' => self::CLIENT_ID])
                ->throw()
                ->json('prompt_id');

            if ($promptId === '') {
                return $this->failure('Copertina non disponibile: ComfyUI non ha accettato il workflow.', 'invalid_response');
            }

            $image = $this->waitForImage($promptId, $startedAt);

            if ($image === null) {
                return $this->failure("Copertina non disponibile: ComfyUI non ha completato la generazione entro {$this->timeoutSeconds} secondi.", 'model_error');
            }

            $response = $this->client()->get('/view', $image)->throw();
        } catch (ConnectionException $e) {
            Log::warning('ComfyUI unreachable', ['base_url' => $this->baseUrl, 'message' => $e->getMessage()]);

            return $this->failure('Copertina non disponibile: ComfyUI non raggiungibile.', 'model_error');
        } catch (RequestException $e) {
            Log::warning('ComfyUI request failed', ['status' => $e->response->status(), 'message' => mb_substr($e->response->body(), 0, 500)]);

            return $this->failure('Copertina non disponibile: ComfyUI ha rifiutato la richiesta.', 'invalid_response');
        }

        $bytes = $response->body();
        $mime = strtok((string) $response->header('Content-Type'), ';') ?: 'image/png';

        if ($bytes === '' || ! str_starts_with($mime, 'image/')) {
            return $this->failure('Copertina non disponibile: ComfyUI non ha restituito un\'immagine.', 'no_payload');
        }

        return ['bytes' => $bytes, 'mime' => $mime, 'warning' => null, 'reason' => null];
    }

    /**
     * Interroga la cronologia finche' il prompt non produce un'immagine o
     * scade il tempo. Il battito tiene vivo il task del workflow intanto.
     *
     * @return array{filename: string, subfolder: string, type: string}|null
     */
    private function waitForImage(string $promptId, float $startedAt): ?array
    {
        while (microtime(true) - $startedAt < $this->timeoutSeconds) {
            $this->heartbeat->beat();

            $entry = $this->client()->get("/history/{$promptId}")->throw()->json($promptId);

            foreach ((array) ($entry['outputs'] ?? []) as $output) {
                $image = $output['images'][0] ?? null;

                if (is_array($image) && isset($image['filename'])) {
                    return [
                        'filename' => (string) $image['filename'],
                        'subfolder' => (string) ($image['subfolder'] ?? ''),
                        'type' => (string) ($image['type'] ?? 'output'),
                    ];
                }
            }

            usleep($this->pollIntervalMilliseconds * 1000);
        }

        return null;
    }

    private function client(): PendingRequest
    {
        return $this->http->baseUrl($this->baseUrl)->timeout(30)->acceptJson();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadWorkflow(): ?array
    {
        if (! is_file($this->workflowPath)) {
            return null;
        }

        $workflow = json_decode((string) file_get_contents($this->workflowPath), true);

        return is_array($workflow) && $workflow !== [] ? $workflow : null;
    }

    /**
     * Un segnaposto che occupa l'intero valore prende il tipo del dato (il
     * seed resta intero); dentro una stringa piu' lunga viene sostituito come
     * testo.
     *
     * @param  array<int|string, mixed>  $node
     * @param  array<string, string|int>  $values
     * @return array<int|string, mixed>
     */
    private function fill(array $node, array $values): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->fill($value, $values);
            } elseif (is_string($value) && array_key_exists($value, $values)) {
                $node[$key] = $values[$value];
            } elseif (is_string($value)) {
                $node[$key] = strtr($value, array_map('strval', $values));
            }
        }

        return $node;
    }

    /**
     * @return array{bytes: null, mime: string, warning: string, reason: string}
     */
    private function failure(string $warning, string $reason): array
    {
        return ['bytes' => null, 'mime' => 'image/png', 'warning' => $warning, 'reason' => $reason];
    }
}
