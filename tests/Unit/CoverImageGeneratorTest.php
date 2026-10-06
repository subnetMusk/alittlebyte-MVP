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

function versionedComfyUiWorkflow(string $name = 'sdxl-lightning.json'): string
{
    return resource_path("ai/comfyui/{$name}");
}

dataset('versioned ComfyUI workflows', [
    'z-image-turbo' => ['z-image-turbo.json', ['%prompt%', '%seed%', '%width%', '%height%', '%filename_prefix%']],
    'sdxl-lightning' => ['sdxl-lightning.json', ['%prompt%', '%negative_prompt%', '%seed%', '%width%', '%height%']],
]);

function makeComfyUiGenerator(?string $workflowPath = null, int $timeoutSeconds = 5, string $baseUrl = 'http://comfyui.test:8188', bool $releaseMemory = true): ComfyUiCoverGenerator
{
    return new ComfyUiCoverGenerator(
        app(HttpFactory::class),
        new WorkflowTaskHeartbeat(Mockery::mock(SfnClient::class), app(MetricsRecorder::class)),
        $baseUrl,
        $workflowPath ?? versionedComfyUiWorkflow(),
        $timeoutSeconds,
        pollIntervalMilliseconds: 1,
        releaseMemoryAfterUse: $releaseMemory,
    );
}

function sentToComfyUi(string $path): int
{
    return Http::recorded(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?? '', $path))->count();
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
        'comfyui.test:8188/free' => Http::response(),
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

test('the versioned workflow is a portable API graph with one placeholder per dynamic input', function (string $name, array $placeholders) {
    $contents = (string) file_get_contents(versionedComfyUiWorkflow($name));
    $workflow = json_decode($contents, true);

    expect($workflow)->toBeArray()->not->toBeEmpty();

    foreach ($workflow as $node) {
        expect($node)->toHaveKeys(['class_type', 'inputs']);
    }

    foreach ($placeholders as $placeholder) {
        expect(substr_count($contents, "\"{$placeholder}\""))->toBe(1, $placeholder);
    }

    // Solo nomi di file dei modelli, nessun percorso della macchina che ha
    // esportato il workflow.
    expect($contents)->not->toMatch('#[A-Za-z]:\\\\\\\\|/Users/|/home/#');
})->with('versioned ComfyUI workflows');

test('ComfyUI receives the versioned workflow with only the dynamic inputs filled', function (string $name) {
    fakeComfyUi();

    $image = makeComfyUiGenerator(versionedComfyUiWorkflow($name))->generate('Ferie 2027', 'Empatico', 'Testo informativo', 'calendar on a desk');

    expect($image)->toBe(['bytes' => "\x89PNG-fake", 'mime' => 'image/png', 'warning' => null, 'reason' => null]);

    $positive = CoverImagePrompts::forCommunication('Ferie 2027', 'Empatico', 'Testo informativo', 'calendar on a desk');
    $expected = [
        '%prompt%' => $positive,
        '%negative_prompt%' => CoverImagePrompts::NEGATIVE,
        '%seed%' => unpack('N', hash('sha256', $positive, true))[1],
        '%width%' => 1280,
        '%height%' => 720,
        '%filename_prefix%' => 'alittlebyte/cover',
    ];
    $template = json_decode((string) file_get_contents(versionedComfyUiWorkflow($name)), true);

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
})->with('versioned ComfyUI workflows');

test('COMFYUI_WORKFLOW selects a versioned workflow by name or any file by path', function (?string $value, string $expected) {
    $previous = $_SERVER['COMFYUI_WORKFLOW'] ?? null;

    if ($value === null) {
        unset($_SERVER['COMFYUI_WORKFLOW']);
    } else {
        $_SERVER['COMFYUI_WORKFLOW'] = $value;
    }

    try {
        $workflow = (require config_path('services.php'))['local_cover']['comfyui']['workflow'];
    } finally {
        if ($previous === null) {
            unset($_SERVER['COMFYUI_WORKFLOW']);
        } else {
            $_SERVER['COMFYUI_WORKFLOW'] = $previous;
        }
    }

    expect($workflow)->toBe(str_starts_with($expected, '/') ? $expected : resource_path("ai/comfyui/{$expected}"));
})->with([
    'non impostato' => [null, 'sdxl-lightning.json'],
    'vuoto' => ['', 'sdxl-lightning.json'],
    'nome versionato' => ['z-image-turbo.json', 'z-image-turbo.json'],
    'percorso' => ['/srv/comfyui/custom.json', '/srv/comfyui/custom.json'],
]);

test('ComfyUI uses the same seed for the same request', function () {
    $seeds = [];
    Http::fake(function (Request $request) use (&$seeds) {
        if (str_ends_with($request->url(), '/prompt')) {
            $seeds[] = $request['prompt']['7']['inputs']['seed'];
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
    'errore HTTP sulla cronologia' => [fn () => Http::fake(['*/prompt' => Http::response(['prompt_id' => 'p-1']), '*/history/*' => Http::response('Internal Server Error', 500), '*' => Http::response()]), 'invalid_response', 5],
    'nessuna immagine entro il timeout' => [fn () => Http::fake(['*/prompt' => Http::response(['prompt_id' => 'p-1']), '*/history/*' => Http::response([]), '*' => Http::response()]), 'model_error', 1],
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

    // Una sola lettura della cronologia: nessuna attesa fino al timeout, e
    // nulla da annullare in ComfyUI.
    expect(sentToComfyUi('/history/p-1'))->toBe(1);
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/interrupt'));
})->with([
    'esecuzione fallita' => [[
        'status_str' => 'error',
        'completed' => false,
        'messages' => [['execution_error', ['node_type' => 'KSampler', 'exception_message' => 'CUDA out of memory']]],
    ], 'model_error'],
    'completata senza immagine' => [['status_str' => 'success', 'completed' => true, 'messages' => []], 'no_payload'],
]);

test('a slow answer from a busy ComfyUI does not stop the wait', function () {
    $historyCalls = 0;
    Http::fake(function (Request $request) use (&$historyCalls) {
        if (str_ends_with($request->url(), '/prompt')) {
            return Http::response(['prompt_id' => 'p-1']);
        }

        if (str_contains($request->url(), '/history/')) {
            // ComfyUI puo' non rispondere mentre carica i modelli.
            if (++$historyCalls === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(comfyUiImageEntry());
        }

        return Http::response("\x89PNG-fake", 200, ['Content-Type' => 'image/png']);
    });

    $image = makeComfyUiGenerator()->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);

    expect($image['reason'])->toBeNull()
        ->and($image['bytes'])->toBe("\x89PNG-fake")
        ->and($historyCalls)->toBe(2);
});

test('ComfyUI unloads its models after every cover unless configured otherwise', function (bool $releaseMemory, int $expectedReleases) {
    fakeComfyUi();

    $image = makeComfyUiGenerator(releaseMemory: $releaseMemory)->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);

    expect($image['reason'])->toBeNull()
        ->and(sentToComfyUi('/free'))->toBe($expectedReleases);

    if ($expectedReleases > 0) {
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/free')
            && $request['unload_models'] === true
            && $request['free_memory'] === true);
    }
})->with([
    'predefinito' => [true, 1],
    'disattivato' => [false, 0],
]);

test('a failed release of the models does not change the cover', function () {
    Http::fake([
        '*/prompt' => Http::response(['prompt_id' => 'p-1']),
        '*/history/*' => Http::response(comfyUiImageEntry()),
        '*/view*' => Http::response("\x89PNG-fake", 200, ['Content-Type' => 'image/png']),
        '*/free' => Http::response('busy', 500),
    ]);

    $image = makeComfyUiGenerator()->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);

    expect($image['reason'])->toBeNull()
        ->and($image['bytes'])->not->toBeNull();
});

test('a generation that outlives the timeout is removed from ComfyUI', function () {
    Http::fake([
        '*/prompt' => Http::response(['prompt_id' => 'p-1']),
        // Nessuna risposta utile fino alla scadenza.
        '*/history/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        '*/queue' => Http::response(),
        '*/interrupt' => Http::response(),
        '*/free' => Http::response(),
    ]);

    $image = makeComfyUiGenerator(timeoutSeconds: 1)->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);

    expect($image['reason'])->toBe('model_error')
        ->and($image['warning'])->toContain('entro 1 secondi');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/queue') && $request['delete'] === ['p-1']);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/interrupt') && $request['prompt_id'] === 'p-1');
    expect(sentToComfyUi('/free'))->toBe(1);
});

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
