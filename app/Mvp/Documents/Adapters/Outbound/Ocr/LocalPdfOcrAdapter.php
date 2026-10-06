<?php

namespace App\Mvp\Documents\Adapters\Outbound\Ocr;

use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Documents\Domain\Ports\Outbound\OcrGatewayPort;
use App\Mvp\Observability\MetricsRecorder;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Log;

/**
 * Adapter secondario del profilo di esecuzione locale (ADR 0014): implementa
 * {@see OcrGatewayPort} senza servizi esterni. Una pagina con text layer viene
 * letta direttamente (pdftotext), una pagina scansionata viene rasterizzata
 * (pdftoppm) e riconosciuta da Tesseract. Nessun modello linguistico: lo
 * stesso PDF produce sempre lo stesso testo.
 *
 * Il risultato ha la forma di quello di Textract, comprese le righe con la
 * loro confidenza, da cui ExtractSubDocumentFieldsService ricava la
 * confidenza per campo (ADR 0013). Il testo di un text layer non e' frutto di
 * riconoscimento e porta confidenza 100.
 */
class LocalPdfOcrAdapter implements OcrGatewayPort
{
    /** Sotto questa soglia di caratteri visibili la pagina e' una scansione. */
    private const MIN_TEXT_LAYER_CHARS = 10;

    private const RASTER_DPI = 300;

    public function __construct(
        private readonly DocumentStoragePort $storage,
        private readonly ProcessFactory $process,
        private readonly MetricsRecorder $metrics,
        private readonly WorkflowTaskHeartbeat $heartbeat,
        private readonly string $documentBucket,
        private readonly string $documentKeyPrefix,
        private readonly string $languages,
        private readonly int $timeoutSeconds,
    ) {}

    public function detectText(string $bucket, string $key, string $idempotencyKey): array
    {
        $startedAt = microtime(true);
        $workDir = sys_get_temp_dir().'/mvp-ocr-'.bin2hex(random_bytes(8));

        try {
            if (! mkdir($workDir, 0700) && ! is_dir($workDir)) {
                throw new \RuntimeException('OCR locale: impossibile creare la cartella di lavoro temporanea.');
            }

            $pdfPath = $workDir.'/document.pdf';
            file_put_contents($pdfPath, $this->storage->read($this->storagePath($bucket, $key)));

            $pages = [];

            foreach ($this->textLayerPages($pdfPath) as $index => $pageText) {
                $this->heartbeat->beat();
                $pageNumber = $index + 1;

                if (mb_strlen((string) preg_replace('/\s+/u', '', $pageText)) >= self::MIN_TEXT_LAYER_CHARS) {
                    $blocks = $this->textLayerBlocks($pageText);
                    $method = 'text_layer';
                } else {
                    $blocks = $this->recognizePage($pdfPath, $pageNumber, $workDir);
                    $method = 'tesseract';
                }

                $this->metrics->recordDomainCounter('local_ocr_pages_total', ['method' => $method]);
                $pages[] = $this->page($pageNumber, $blocks);
            }

            $this->metrics->recordDomainCounter('local_ocr_duration_seconds_sum', [], microtime(true) - $startedAt);
            $this->metrics->recordDomainCounter('local_ocr_duration_seconds_count');

            return $this->result($pages);
        } catch (\Throwable $e) {
            $this->metrics->recordDomainCounter('local_ocr_jobs_failed_total');
            Log::error('Local OCR failed', ['bucket' => $bucket, 'key' => $key, 'message' => $e->getMessage()]);

            throw $e instanceof \RuntimeException ? $e : new \RuntimeException('OCR locale non riuscito: '.$e->getMessage(), previous: $e);
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * Le coordinate S3 sono le stesse passate a Textract: chiave = radice del
     * disco documenti + percorso. Qui si torna al percorso relativo al disco.
     */
    private function storagePath(string $bucket, string $key): string
    {
        if ($key === '') {
            throw new \RuntimeException('OCR locale: chiave del documento mancante.');
        }

        if ($bucket !== '' && $this->documentBucket !== '' && $bucket !== $this->documentBucket) {
            throw new \RuntimeException("OCR locale: il documento non sta sul disco documenti configurato (bucket {$bucket}).");
        }

        $prefix = $this->documentKeyPrefix === '' ? '' : $this->documentKeyPrefix.'/';

        return $prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
    }

    /**
     * pdftotext separa le pagine con un form feed, anche dopo l'ultima.
     *
     * @return array<int, string>
     */
    private function textLayerPages(string $pdfPath): array
    {
        $output = $this->run(['pdftotext', '-layout', '-enc', 'UTF-8', $pdfPath, '-']);
        $pages = explode("\f", $output);

        if (count($pages) > 1 && trim((string) end($pages)) === '') {
            array_pop($pages);
        }

        return $pages;
    }

    /**
     * @return array<int, array{text: string, confidence: float|null}>
     */
    private function textLayerBlocks(string $pageText): array
    {
        $blocks = [];

        foreach (preg_split('/\R/u', $pageText) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                $blocks[] = ['text' => $line, 'confidence' => 100.0];
            }
        }

        return $blocks;
    }

    /**
     * Rasterizza una pagina e la riconosce con Tesseract. Dall'output TSV si
     * ricostruiscono le righe: la confidenza di una riga e' la media delle
     * parole riconosciute.
     *
     * @return array<int, array{text: string, confidence: float|null}>
     */
    private function recognizePage(string $pdfPath, int $page, string $workDir): array
    {
        $imageBase = $workDir.'/page-'.$page;
        $this->run(['pdftoppm', '-f', (string) $page, '-l', (string) $page, '-r', (string) self::RASTER_DPI, '-gray', '-png', '-singlefile', $pdfPath, $imageBase]);

        $tsv = $this->run(['tesseract', $imageBase.'.png', 'stdout', '-l', $this->languages, '--psm', '3', 'tsv']);

        /** @var array<string, array{words: array<int, string>, confidences: array<int, float>}> $lines */
        $lines = [];

        foreach (array_slice(preg_split('/\R/u', trim($tsv)) ?: [], 1) as $row) {
            $columns = explode("\t", $row);

            if (count($columns) < 12 || $columns[0] !== '5') {
                continue;
            }

            $word = trim($columns[11]);

            if ($word === '') {
                continue;
            }

            $lineKey = $columns[2].'-'.$columns[3].'-'.$columns[4];
            $lines[$lineKey]['words'][] = $word;

            if ((float) $columns[10] >= 0) {
                $lines[$lineKey]['confidences'][] = (float) $columns[10];
            }
        }

        $blocks = [];

        foreach ($lines as $line) {
            $confidences = $line['confidences'] ?? [];
            $blocks[] = [
                'text' => implode(' ', $line['words']),
                'confidence' => $confidences === [] ? null : round(array_sum($confidences) / count($confidences), 2),
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<int, array{text: string, confidence: float|null}>  $blocks
     * @return array{page: int, text: string, confidenceAvg: float|null, blocks: array<int, array{text: string, confidence: float|null}>}
     */
    private function page(int $pageNumber, array $blocks): array
    {
        $confidences = array_values(array_filter(array_column($blocks, 'confidence'), static fn ($value) => $value !== null));

        return [
            'page' => $pageNumber,
            'text' => implode("\n", array_column($blocks, 'text')),
            'confidenceAvg' => $confidences === [] ? null : array_sum($confidences) / count($confidences),
            'blocks' => $blocks,
        ];
    }

    /**
     * @param  array<int, array{page: int, text: string, confidenceAvg: float|null, blocks: array<int, array{text: string, confidence: float|null}>}>  $pages
     * @return array{enabled: bool, jobId: ?string, text: ?string, pages: array<int, array{page: int, text: string, confidenceAvg: float|null, blocks: array<int, array{text: string, confidence: float|null}>}>, confidenceAvg: ?float}
     */
    private function result(array $pages): array
    {
        $confidences = [];

        foreach ($pages as $page) {
            foreach ($page['blocks'] as $block) {
                if ($block['confidence'] !== null) {
                    $confidences[] = $block['confidence'];
                }
            }
        }

        return [
            'enabled' => true,
            // Nessun job esterno da tracciare: l'identificativo Textract resta vuoto.
            'jobId' => null,
            'text' => trim(implode("\n", array_filter(array_column($pages, 'text')))),
            'pages' => $pages,
            'confidenceAvg' => $confidences === [] ? null : array_sum($confidences) / count($confidences),
        ];
    }

    /**
     * @param  array<int, string>  $command
     */
    private function run(array $command): string
    {
        $result = $this->process->newPendingProcess()->timeout($this->timeoutSeconds)->run($command);

        if ($result->failed()) {
            throw new \RuntimeException(sprintf('OCR locale: %s non riuscito (exit %d): %s', $command[0], (int) $result->exitCode(), trim($result->errorOutput())));
        }

        return $result->output();
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
}
