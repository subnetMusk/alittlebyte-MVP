<?php

use App\Http\Requests\GenerateCommunicationRequest;
use App\Mvp\Ai\Cover\ComfyUiCoverGenerator;
use App\Mvp\Ai\Cover\CoverImageGenerator;
use App\Mvp\Ai\Cover\DeterministicCoverGenerator;
use App\Mvp\Ai\CoverImagePrompts;
use App\Mvp\Ai\OllamaService;
use App\Mvp\Communications\Adapters\Outbound\Ai\LocalCommunicationAiAdapter;
use App\Mvp\Observability\MetricsRecorder;
use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;
use Aws\Sfn\SfnClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function versionedComfyUiWorkflow(): string
{
    return resource_path('ai/comfyui/z-image-turbo.json');
}

function makeComfyUiGenerator(?string $workflowPath = null, int $timeoutSeconds = 5, string $baseUrl = 'http://comfyui.test:8188'): ComfyUiCoverGenerator
{
    return new ComfyUiCoverGenerator(
        app(HttpFactory::class),
        new WorkflowTaskHeartbeat(Mockery::mock(SfnClient::class), app(MetricsRecorder::class)),
        $baseUrl,
        $workflowPath ?? versionedComfyUiWorkflow(),
        $timeoutSeconds,
        pollIntervalMilliseconds: 1,
    );
}

function comfyUiImageEntry(): array
{
    return ['p-1' => [
        'outputs' => ['9' => ['images' => [['filename' => 'cover_00001_.png', 'subfolder' => 'alittlebyte', 'type' => 'output']]]],
        'status' => ['status_str' => 'success', 'completed' => true, 'messages' => []],
    ]];
}

function fakeComfyUi(string $imageBytes = "\x89PNG-fake", string $contentType = 'image/png'): void
{
    Http::fake([
        'comfyui.test:8188/prompt' => Http::response(['prompt_id' => 'p-1', 'number' => 0, 'node_errors' => []]),
        // La cronologia resta vuota finche' l'esecuzione non e' conclusa.
        'comfyui.test:8188/history/p-1' => Http::sequence()->push([])->push(comfyUiImageEntry()),
        'comfyui.test:8188/view*' => Http::response($imageBytes, 200, ['Content-Type' => $contentType]),
    ]);
}

function temporaryWorkflow(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'comfyui-workflow-');
    file_put_contents($path, $contents);

    return $path;
}

test('the deterministic cover is a reproducible 1280x720 png', function () {
    $generator = new DeterministicCoverGenerator;

    $first = $generator->generate('Ferie 2027', 'Empatico', 'Avviso operativo', 'calendar');
    $second = $generator->generate('Ferie 2027', 'Empatico', 'Avviso operativo', 'calendar');
    $other = $generator->generate('Ferie 2028', 'Empatico', 'Avviso operativo', 'calendar');

    expect($first['bytes'])->toBe($second['bytes'])
        ->and($other['bytes'])->not->toBe($first['bytes'])
        ->and($first['mime'])->toBe('image/png')
        ->and($first['warning'])->toBeNull()
        ->and($first['reason'])->toBeNull()
        ->and(getimagesizefromstring((string) $first['bytes'])[0] ?? null)->toBe(1280)
        ->and(getimagesizefromstring((string) $first['bytes'])[1] ?? null)->toBe(720);
});

test('the deterministic cover has a palette for every tone and a pattern for every style of the domain', function () {
    $palettes = (new ReflectionClassConstant(DeterministicCoverGenerator::class, 'PALETTES'))->getValue();
    $patterns = (new ReflectionClassConstant(DeterministicCoverGenerator::class, 'PATTERNS'))->getValue();

    expect(array_keys($palettes))->toEqualCanonicalizing(GenerateCommunicationRequest::TONES)
        ->and(array_keys($patterns))->toEqualCanonicalizing(GenerateCommunicationRequest::STYLES);
});

test('the versioned workflow is a portable API graph with one placeholder per dynamic input', function () {
    $contents = (string) file_get_contents(versionedComfyUiWorkflow());
    $workflow = json_decode($contents, true);

    expect($workflow)->toBeArray()->not->toBeEmpty();

    foreach ($workflow as $node) {
        expect($node)->toHaveKeys(['class_type', 'inputs']);
    }

    foreach (['%prompt%', '%seed%', '%width%', '%height%', '%filename_prefix%'] as $placeholder) {
        expect(substr_count($contents, "\"{$placeholder}\""))->toBe(1, $placeholder);
    }

    // Solo nomi di file dei modelli, nessun percorso della macchina che ha
    // esportato il workflow.
    expect($contents)->not->toMatch('#[A-Za-z]:\\\\\\\\|/Users/|/home/#');
});

test('ComfyUI receives the versioned workflow with only the dynamic inputs filled', function () {
    fakeComfyUi();

    $image = makeComfyUiGenerator()->generate('Ferie 2027', 'Empatico', 'Testo informativo', 'calendar on a desk');

    expect($image)->toBe(['bytes' => "\x89PNG-fake", 'mime' => 'image/png', 'warning' => null, 'reason' => null]);

    $positive = CoverImagePrompts::forCommunication('Ferie 2027', 'Empatico', 'Testo informativo', 'calendar on a desk');
    $expected = [
        '%prompt%' => $positive,
        '%seed%' => unpack('N', hash('sha256', $positive, true))[1],
        '%width%' => 1280,
        '%height%' => 720,
        '%filename_prefix%' => 'alittlebyte/cover',
    ];
    $template = json_decode((string) file_get_contents(versionedComfyUiWorkflow()), true);

    Http::assertSent(function (Request $request) use ($template, $expected) {
        if (! str_ends_with($request->url(), '/prompt')) {
            return false;
        }

        $graph = $request['prompt'];

        expect(array_keys($graph))->toBe(array_keys($template));

        foreach ($template as $id => $node) {
            foreach ($node['inputs'] as $name => $value) {
                $sent = $graph[$id]['inputs'][$name];
                // Modelli, sampler, passi e collegamenti restano quelli del file.
                expect($sent)->toBe(is_string($value) && isset($expected[$value]) ? $expected[$value] : $value, "{$id}.{$name}");
            }
        }

        return $request['client_id'] === 'alittlebyte-mvp';
    });

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/view?')
        && $request['filename'] === 'cover_00001_.png'
        && $request['subfolder'] === 'alittlebyte'
        && $request['type'] === 'output');
});

test('ComfyUI uses the same seed for the same request', function () {
    $seeds = [];
    Http::fake(function (Request $request) use (&$seeds) {
        if (str_ends_with($request->url(), '/prompt')) {
            $seeds[] = $request['prompt']['57:3']['inputs']['seed'];
        }

        return Http::response(['prompt_id' => '']);
    });

    makeComfyUiGenerator()->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);
    makeComfyUiGenerator()->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);

    expect($seeds)->toHaveCount(2)
        ->and($seeds[0])->toBeInt()
        ->and($seeds[0])->toBe($seeds[1]);
});

test('ComfyUI failures degrade the cover with an explicit reason, without falling back', function (Closure $fake, string $reason, int $timeoutSeconds) {
    $fake();

    $image = makeComfyUiGenerator(timeoutSeconds: $timeoutSeconds)->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);

    expect($image['bytes'])->toBeNull()
        ->and($image['reason'])->toBe($reason)
        ->and($image['warning'])->toStartWith('Copertina non disponibile');
})->with([
    'non raggiungibile' => [fn () => Http::fake(fn () => throw new ConnectionException('Connection refused')), 'model_error', 5],
    'workflow rifiutato' => [fn () => Http::fake(['*' => Http::response(['error' => ['type' => 'prompt_outputs_failed_validation'], 'node_errors' => []], 400)]), 'invalid_response', 5],
    'prompt_id assente' => [fn () => Http::fake(['*' => Http::response(['node_errors' => []])]), 'invalid_response', 5],
    'errore HTTP sulla cronologia' => [fn () => Http::fake(['*/prompt' => Http::response(['prompt_id' => 'p-1']), '*/history/*' => Http::response('Internal Server Error', 500)]), 'invalid_response', 5],
    'nessuna immagine entro il timeout' => [fn () => Http::fake(['*/prompt' => Http::response(['prompt_id' => 'p-1']), '*/history/*' => Http::response([])]), 'model_error', 1],
    'risposta non immagine' => [fn () => fakeComfyUi('<html>', 'text/html'), 'no_payload', 5],
]);

test('a failed or imageless execution stops the polling at once', function (array $status, string $reason) {
    Http::fake([
        '*/prompt' => Http::response(['prompt_id' => 'p-1']),
        '*/history/p-1' => Http::response(['p-1' => ['outputs' => [], 'status' => $status]]),
    ]);

    $image = makeComfyUiGenerator(timeoutSeconds: 30)->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);

    expect($image['bytes'])->toBeNull()
        ->and($image['reason'])->toBe($reason);

    // Una sola lettura della cronologia: nessuna attesa fino al timeout.
    Http::assertSentCount(2);
})->with([
    'esecuzione fallita' => [[
        'status_str' => 'error',
        'completed' => false,
        'messages' => [['execution_error', ['node_type' => 'KSampler', 'exception_message' => 'CUDA out of memory']]],
    ], 'model_error'],
    'completata senza immagine' => [['status_str' => 'success', 'completed' => true, 'messages' => []], 'no_payload'],
]);

test('an unusable workflow or base url is reported as not configured and sends nothing', function (Closure $generator) {
    Http::fake();

    $image = $generator()->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);

    expect($image['bytes'])->toBeNull()
        ->and($image['reason'])->toBe('model_not_configured');
    Http::assertNothingSent();
})->with([
    'file assente' => [fn () => makeComfyUiGenerator('/nonexistent/workflow.json')],
    'JSON non valido' => [fn () => makeComfyUiGenerator(temporaryWorkflow('{"3": {'))],
    'formato UI invece di API' => [fn () => makeComfyUiGenerator(temporaryWorkflow('{"nodes": [], "links": [], "version": 0.4}'))],
    'senza segnaposto del prompt' => [fn () => makeComfyUiGenerator(temporaryWorkflow('{"9": {"class_type": "SaveImage", "inputs": {"filename_prefix": "x"}}}'))],
    'URL senza schema' => [fn () => makeComfyUiGenerator(baseUrl: 'comfyui.test:8188')],
]);

test('the local communication adapter maps any cover generator to the same domain value object', function (CoverImageGenerator $generator) {
    fakeComfyUi();
    $adapter = new LocalCommunicationAiAdapter(Mockery::mock(OllamaService::class), $generator);

    $image = $adapter->generateImage('Ferie', 'Empatico', 'Testo informativo', null);

    expect($image->bytes)->not->toBeNull()
        ->and($image->mime)->toBe('image/png')
        ->and($image->reason)->toBeNull();
})->with([
    'mock' => [fn () => new DeterministicCoverGenerator],
    'comfyui' => [fn () => makeComfyUiGenerator()],
]);
