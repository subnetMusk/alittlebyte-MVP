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

function makeComfyUiGenerator(string $checkpoint = 'model.safetensors', int $timeoutSeconds = 5): ComfyUiCoverGenerator
{
    return new ComfyUiCoverGenerator(
        app(HttpFactory::class),
        new WorkflowTaskHeartbeat(Mockery::mock(SfnClient::class), app(MetricsRecorder::class)),
        'http://comfyui.test:8188',
        resource_path('ai/comfyui/cover-workflow.json'),
        $checkpoint,
        $timeoutSeconds,
        pollIntervalMilliseconds: 1,
    );
}

function fakeComfyUi(string $imageBytes = "\x89PNG-fake"): void
{
    Http::fake([
        'comfyui.test:8188/prompt' => Http::response(['prompt_id' => 'p-1', 'number' => 0]),
        'comfyui.test:8188/history/p-1' => Http::sequence()
            ->push([])
            ->push(['p-1' => ['outputs' => ['9' => ['images' => [['filename' => 'alittlebyte-cover_00001_.png', 'subfolder' => '', 'type' => 'output']]]]]]),
        'comfyui.test:8188/view*' => Http::response($imageBytes, 200, ['Content-Type' => 'image/png']),
    ]);
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

test('ComfyUI receives the shared art direction and returns the generated image', function () {
    fakeComfyUi();

    $image = makeComfyUiGenerator()->generate('Ferie 2027', 'Empatico', 'Testo informativo', 'calendar on a desk');

    expect($image)->toBe(['bytes' => "\x89PNG-fake", 'mime' => 'image/png', 'warning' => null, 'reason' => null]);

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/prompt')) {
            return false;
        }

        $graph = $request['prompt'];

        return $graph['4']['inputs']['ckpt_name'] === 'model.safetensors'
            && $graph['6']['inputs']['text'] === CoverImagePrompts::forCommunication('Ferie 2027', 'Empatico', 'Testo informativo', 'calendar on a desk')
            && $graph['7']['inputs']['text'] === CoverImagePrompts::NEGATIVE
            && is_int($graph['3']['inputs']['seed'])
            && $graph['5']['inputs']['width'] === 1280
            && $graph['5']['inputs']['height'] === 720;
    });
});

test('ComfyUI uses the same seed for the same request', function () {
    $seeds = [];
    Http::fake(function (Request $request) use (&$seeds) {
        if (str_ends_with($request->url(), '/prompt')) {
            $seeds[] = $request['prompt']['3']['inputs']['seed'];
        }

        return Http::response(['prompt_id' => '']);
    });

    makeComfyUiGenerator()->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);
    makeComfyUiGenerator()->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);

    expect($seeds)->toHaveCount(2)
        ->and($seeds[0])->toBe($seeds[1]);
});

test('ComfyUI failures degrade the cover with an explicit reason, without falling back', function (Closure $fake, string $reason) {
    $fake();

    $image = makeComfyUiGenerator(timeoutSeconds: 0)->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);

    expect($image['bytes'])->toBeNull()
        ->and($image['reason'])->toBe($reason)
        ->and($image['warning'])->toStartWith('Copertina non disponibile');
})->with([
    'non raggiungibile' => [fn () => Http::fake(fn () => throw new ConnectionException('Connection refused')), 'model_error'],
    'workflow rifiutato' => [fn () => Http::fake(['*' => Http::response(['error' => 'invalid prompt'], 400)]), 'invalid_response'],
    'nessuna immagine entro il timeout' => [fn () => Http::fake(['*/prompt' => Http::response(['prompt_id' => 'p-1']), '*/history/*' => Http::response([])]), 'model_error'],
]);

test('ComfyUI without a checkpoint is reported as not configured and sends nothing', function () {
    Http::fake();

    $image = makeComfyUiGenerator(checkpoint: '')->generate('Ferie', 'Tecnico', 'Aggiornamento breve', null);

    expect($image['reason'])->toBe('model_not_configured');
    Http::assertNothingSent();
});

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
