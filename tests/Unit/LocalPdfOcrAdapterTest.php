<?php

use App\Mvp\Documents\Adapters\Outbound\Ocr\LocalPdfOcrAdapter;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Observability\MetricsRecorder;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use Aws\Sfn\SfnClient;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

function makeLocalOcrAdapter(DocumentStoragePort $storage, ?MetricsRecorder $metrics = null, string $prefix = ''): LocalPdfOcrAdapter
{
    return new LocalPdfOcrAdapter(
        $storage,
        app(ProcessFactory::class),
        $metrics ?? app(MetricsRecorder::class),
        new WorkflowTaskHeartbeat(Mockery::mock(SfnClient::class), app(MetricsRecorder::class)),
        'mvp-documents-local',
        $prefix,
        'ita+eng',
        30,
    );
}

function storageReturning(string $expectedPath, string $bytes = '%PDF-fake'): DocumentStoragePort
{
    $storage = Mockery::mock(DocumentStoragePort::class);
    $storage->shouldReceive('read')->once()->with($expectedPath)->andReturn($bytes);

    return $storage;
}

function tesseractTsv(array $rows): string
{
    $lines = ["level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext"];

    foreach ($rows as [$block, $line, $conf, $text]) {
        $lines[] = "5\t1\t{$block}\t1\t{$line}\t1\t0\t0\t10\t10\t{$conf}\t{$text}";
    }

    return implode("\n", $lines)."\n";
}

function ocrTempDirectories(): array
{
    return glob(sys_get_temp_dir().'/mvp-ocr-*') ?: [];
}

test('a text layer page is read directly and a scanned page goes through tesseract', function () {
    $before = ocrTempDirectories();

    Process::fake([
        '*pdftotext*' => Process::result("ACME S.p.A.   Cedolino marzo 2026\nDipendente: ROSSI MARIO\n\f   \n\f"),
        '*pdftoppm*' => Process::result(''),
        '*tesseract*' => Process::result(tesseractTsv([
            [1, 1, 96.0, 'Dipendente:'],
            [1, 1, 90.0, 'BIANCHI'],
            [1, 1, 92.0, 'LAURA'],
            [1, 2, -1, ''],
            [2, 1, 80.5, 'Netto'],
        ])),
    ]);

    $result = makeLocalOcrAdapter(storageReturning('uploads/doc.pdf'))->detectText('mvp-documents-local', 'uploads/doc.pdf', 'idem');

    expect($result['enabled'])->toBeTrue()
        ->and($result['jobId'])->toBeNull()
        ->and($result['pages'])->toHaveCount(2)
        ->and($result['pages'][0]['blocks'])->toBe([
            ['text' => 'ACME S.p.A.   Cedolino marzo 2026', 'confidence' => 100.0],
            ['text' => 'Dipendente: ROSSI MARIO', 'confidence' => 100.0],
        ])
        ->and($result['pages'][1]['blocks'])->toBe([
            ['text' => 'Dipendente: BIANCHI LAURA', 'confidence' => 92.67],
            ['text' => 'Netto', 'confidence' => 80.5],
        ])
        ->and($result['pages'][1]['page'])->toBe(2)
        ->and($result['text'])->toBe("ACME S.p.A.   Cedolino marzo 2026\nDipendente: ROSSI MARIO\nDipendente: BIANCHI LAURA\nNetto")
        ->and($result['confidenceAvg'])->toEqualWithDelta((100 + 100 + 92.67 + 80.5) / 4, 0.001);

    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'pdftoppm' && in_array('-singlefile', $process->command, true) && $process->timeout === 30);
    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'tesseract' && in_array('ita+eng', $process->command, true));
    Process::assertRanTimes(fn (PendingProcess $process) => $process->command[0] === 'tesseract', 1);

    // Nessuna cartella temporanea lasciata indietro.
    expect(array_diff(ocrTempDirectories(), $before))->toBe([]);
});

test('the S3 key is mapped back to the document disk path', function () {
    Process::fake(['*pdftotext*' => Process::result("Testo con almeno dieci caratteri\f")]);

    $result = makeLocalOcrAdapter(storageReturning('2026/10/doc.pdf'), prefix: 'documents')
        ->detectText('mvp-documents-local', 'documents/2026/10/doc.pdf', 'idem');

    expect($result['pages'])->toHaveCount(1);
});

test('a document outside the configured bucket is refused', function () {
    $storage = Mockery::mock(DocumentStoragePort::class);
    $storage->shouldNotReceive('read');
    Process::fake();

    expect(fn () => makeLocalOcrAdapter($storage)->detectText('another-bucket', 'doc.pdf', 'idem'))
        ->toThrow(RuntimeException::class, 'disco documenti configurato');
});

test('a failing tool fails the OCR, records the metric and cleans up', function () {
    $before = ocrTempDirectories();
    Process::fake(['*pdftotext*' => Process::result('', 'Syntax Error: Could not read xref table', 1)]);
    $metrics = Mockery::mock(MetricsRecorder::class);
    $metrics->shouldReceive('recordDomainCounter')->once()->with('local_ocr_jobs_failed_total');

    expect(fn () => makeLocalOcrAdapter(storageReturning('doc.pdf'), $metrics)->detectText('mvp-documents-local', 'doc.pdf', 'idem'))
        ->toThrow(RuntimeException::class, 'pdftotext non riuscito')
        ->and(array_diff(ocrTempDirectories(), $before))->toBe([]);
});

test('the real tools read the demo dataset: text layer directly, scans through tesseract', function () {
    foreach (['pdftotext', 'pdftoppm', 'tesseract'] as $tool) {
        if (Process::run(['sh', '-c', "command -v {$tool}"])->failed()) {
            $this->markTestSkipped("{$tool} non presente: l'immagine non include gli strumenti dell'OCR locale.");
        }
    }

    $read = fn (string $file) => makeLocalOcrAdapter(storageReturning($file, (string) file_get_contents(base_path("demo/pdf/dataset/{$file}"))))
        ->detectText('mvp-documents-local', $file, 'idem');

    $textLayer = $read('16-cedolino-1dest-auto-completo.pdf');
    $scan = $read('18-cu-1dest-revisione-scansione-media.pdf');

    $textLayerConfidences = array_unique(array_merge(...array_map(fn ($page) => array_column($page['blocks'], 'confidence'), $textLayer['pages'])));

    expect($textLayer['text'])->toContain('Cedolino paga')
        ->and($textLayerConfidences)->toBe([100.0])
        ->and($scan['text'])->toMatch('/certificazione\s+unica/i')
        ->and($scan['confidenceAvg'])->toBeGreaterThan(50.0)->toBeLessThan(100.0);
});
