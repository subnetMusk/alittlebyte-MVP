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
 * profilo di esecuzione locale. Il grafo da eseguire, modelli compresi, e'
 * configurazione di deployment (COMFYUI_WORKFLOW): un workflow in formato API
 * in cui il codice sostituisce solo i segnaposto %prompt%, %negative_prompt%,
 * %seed%, %width%, %height% e %filename_prefix%. Solo %prompt% e' obbligatorio.
 *
 * Un errore degrada la copertina con un motivo esplicito, come per Bedrock:
 * nessun ripiego su un altro generatore.
 */
final class ComfyUiCoverGenerator implements CoverImageGenerator
{
    private const WIDTH = 1280;

    private const HEIGHT = 720;

    /** Sottocartella e prefisso dei file salvati nell'output di ComfyUI. */
    private const FILENAME_PREFIX = 'alittlebyte/cover';

    /** Limite della singola chiamata HTTP, comunque entro la scadenza complessiva. */
    private const REQUEST_TIMEOUT_SECONDS = 30;

    private const CLIENT_ID = 'alittlebyte-mvp';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly WorkflowTaskHeartbeat $heartbeat,
        private readonly string $baseUrl,
        private readonly string $workflowPath,
        private readonly int $timeoutSeconds,
        private readonly int $pollIntervalMilliseconds = 2000,
    ) {}

    public function generate(string $prompt, string $tone, string $style, ?string $modelImagePrompt): array
    {
        $workflow = $this->loadWorkflow();
        $problem = is_string($workflow) ? "workflow non utilizzabile: {$workflow}" : null;

        if ($problem === null && ! preg_match('#^https?://[^\s/]+#i', $this->baseUrl)) {
            $problem = 'COMFYUI_BASE_URL deve essere un URL http(s)';
        }

        if ($problem !== null || is_string($workflow)) {
            Log::warning('ComfyUI not configured', ['workflow' => $this->workflowPath, 'problem' => $problem]);

            return $this->failure(
                'Copertina non disponibile: ComfyUI non configurato correttamente (COMFYUI_BASE_URL, COMFYUI_WORKFLOW).',
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
            '%filename_prefix%' => self::FILENAME_PREFIX,
        ]);

        // Una sola scadenza per invio, attesa e download: il task GenerateCover
        // dell'ASL ha un proprio timeout, che questo deve precedere.
        $deadline = microtime(true) + $this->timeoutSeconds;

        try {
            $promptId = (string) $this->client($deadline)
                ->post('/prompt', ['prompt' => $graph, 'client_id' => self::CLIENT_ID])
                ->throw()
                ->json('prompt_id');

            if ($promptId === '') {
                return $this->failure('Copertina non disponibile: ComfyUI non ha accettato il workflow.', 'invalid_response');
            }

            try {
                $outcome = $this->waitForImage($promptId, $deadline);
            } catch (RequestException $e) {
                $this->cancel($promptId);

                throw $e;
            }

            if (isset($outcome['failure'])) {
                if ($outcome['pending']) {
                    $this->cancel($promptId);
                }

                return $this->failure(...$outcome['failure']);
            }

            $response = $this->client($deadline)->get('/view', $outcome['image'])->throw();
        } catch (ConnectionException $e) {
            Log::warning('ComfyUI unreachable', ['base_url' => $this->baseUrl, 'message' => $e->getMessage()]);

            return $this->failure('Copertina non disponibile: ComfyUI non raggiungibile o senza risposta.', 'model_error');
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
     * Interroga la cronologia finche' il prompt non produce un'immagine, non
     * termina senza, o scade il tempo. Il battito tiene vivo il task del
     * workflow intanto. ComfyUI aggiunge il prompt alla cronologia solo a
     * esecuzione conclusa, con o senza errore.
     *
     * Mentre carica i modelli ComfyUI puo' non rispondere per decine di
     * secondi: una lettura senza risposta non chiude l'attesa, che continua
     * fino alla scadenza.
     *
     * @return array{image: array{filename: string, subfolder: string, type: string}}|array{failure: array{0: string, 1: string}, pending: bool}
     */
    private function waitForImage(string $promptId, float $deadline): array
    {
        $slowResponseLogged = false;

        while (microtime(true) < $deadline) {
            $this->heartbeat->beat();

            try {
                $entry = $this->client($deadline)->get("/history/{$promptId}")->throw()->json($promptId);
            } catch (ConnectionException $e) {
                if (! $slowResponseLogged) {
                    Log::info('ComfyUI slow to answer while generating', ['prompt_id' => $promptId, 'message' => $e->getMessage()]);
                    $slowResponseLogged = true;
                }

                $this->pause($deadline);

                continue;
            }

            if (is_array($entry)) {
                $image = $this->firstImage((array) ($entry['outputs'] ?? []));

                if ($image !== null) {
                    return ['image' => $image];
                }

                $status = (array) ($entry['status'] ?? []);

                if (($status['status_str'] ?? null) === 'error') {
                    Log::warning('ComfyUI execution failed', ['prompt_id' => $promptId, 'detail' => $this->executionError($status)]);

                    return ['failure' => ['Copertina non disponibile: ComfyUI ha interrotto la generazione.', 'model_error'], 'pending' => false];
                }

                if (($status['completed'] ?? false) === true) {
                    Log::warning('ComfyUI completed without an image', ['prompt_id' => $promptId]);

                    return ['failure' => ['Copertina non disponibile: ComfyUI non ha restituito un\'immagine.', 'no_payload'], 'pending' => false];
                }
            }

            $this->pause($deadline);
        }

        Log::warning('ComfyUI generation timed out', ['prompt_id' => $promptId, 'timeout_seconds' => $this->timeoutSeconds]);

        return ['failure' => ["Copertina non disponibile: ComfyUI non ha completato la generazione entro {$this->timeoutSeconds} secondi.", 'model_error'], 'pending' => true];
    }

    private function pause(float $deadline): void
    {
        $remainingMicroseconds = (int) (($deadline - microtime(true)) * 1_000_000);
        usleep(max(0, min($this->pollIntervalMilliseconds * 1000, $remainingMicroseconds)));
    }

    /**
     * Toglie dalla coda, o interrompe se gia' in esecuzione, il prompt rimasto
     * senza esito: altrimenti ComfyUI continuerebbe a generare un'immagine che
     * nessuno usera', occupando GPU e memoria. L'interruzione e' mirata al
     * solo prompt della copertina. Le due chiamate hanno un limite breve, per
     * restare entro il timeout del task; un loro errore non cambia l'esito.
     */
    private function cancel(string $promptId): void
    {
        try {
            $this->http->baseUrl($this->baseUrl)->timeout(5)->acceptJson()->post('/queue', ['delete' => [$promptId]])->throw();
            $this->http->baseUrl($this->baseUrl)->timeout(5)->acceptJson()->post('/interrupt', ['prompt_id' => $promptId])->throw();
            Log::info('ComfyUI prompt cancelled', ['prompt_id' => $promptId]);
        } catch (\Throwable $e) {
            Log::warning('ComfyUI prompt cancellation failed', ['prompt_id' => $promptId, 'message' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<int|string, mixed>  $outputs
     * @return array{filename: string, subfolder: string, type: string}|null
     */
    private function firstImage(array $outputs): ?array
    {
        foreach ($outputs as $output) {
            $image = is_array($output) ? ($output['images'][0] ?? null) : null;

            if (is_array($image) && isset($image['filename'])) {
                return [
                    'filename' => (string) $image['filename'],
                    'subfolder' => (string) ($image['subfolder'] ?? ''),
                    'type' => (string) ($image['type'] ?? 'output'),
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $status
     */
    private function executionError(array $status): string
    {
        foreach ((array) ($status['messages'] ?? []) as $message) {
            if (! is_array($message) || ! is_array($message[1] ?? null)) {
                continue;
            }

            if (($message[0] ?? null) === 'execution_error') {
                return trim(($message[1]['node_type'] ?? '').': '.($message[1]['exception_message'] ?? ''), ': ');
            }

            if (($message[0] ?? null) === 'execution_interrupted') {
                return 'esecuzione interrotta';
            }
        }

        return 'errore non specificato';
    }

    private function client(float $deadline): PendingRequest
    {
        $remaining = (int) ceil($deadline - microtime(true));

        return $this->http->baseUrl($this->baseUrl)
            ->timeout(max(1, min(self::REQUEST_TIMEOUT_SECONDS, $remaining)))
            ->acceptJson();
    }

    /**
     * @return array<string, mixed>|string il grafo, oppure il motivo per cui non e' utilizzabile.
     */
    private function loadWorkflow(): array|string
    {
        if (! is_file($this->workflowPath)) {
            return "file {$this->workflowPath} non trovato";
        }

        $workflow = json_decode((string) file_get_contents($this->workflowPath), true);

        if (! is_array($workflow) || $workflow === []) {
            return 'il file non contiene un oggetto JSON';
        }

        // Il formato API e' una mappa id del nodo -> {class_type, inputs}; il
        // formato UI salvato dall'editor ha invece nodes e links.
        foreach ($workflow as $node) {
            if (! is_array($node) || ! isset($node['class_type']) || ! is_array($node['inputs'] ?? null)) {
                return 'il file non e\' un workflow in formato API';
            }
        }

        if (! str_contains((string) json_encode($workflow), '%prompt%')) {
            return 'manca il segnaposto %prompt%';
        }

        return $workflow;
    }

    /**
     * Un segnaposto che occupa l'intero valore prende il tipo del dato (seed e
     * dimensioni restano interi); dentro una stringa piu' lunga viene
     * sostituito come testo.
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
