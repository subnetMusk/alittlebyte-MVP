<?php

use App\Mvp\Ai\Cover\ComfyUiCoverGenerator;
use App\Mvp\Ai\Cover\CoverImageGenerator;
use App\Mvp\Ai\Cover\DeterministicCoverGenerator;
use App\Mvp\Communications\Adapters\Outbound\Ai\BedrockCommunicationAiAdapter;
use App\Mvp\Communications\Adapters\Outbound\Ai\LocalCommunicationAiAdapter;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationAiGatewayPort;
use App\Mvp\Documents\Adapters\Outbound\Ai\BedrockDocumentAiAdapter;
use App\Mvp\Documents\Adapters\Outbound\Ai\OllamaDocumentAiAdapter;
use App\Mvp\Documents\Adapters\Outbound\Ocr\LocalPdfOcrAdapter;
use App\Mvp\Documents\Adapters\Outbound\Ocr\TextractOcrAdapter;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentAiGatewayPort;
use App\Mvp\Documents\Domain\Ports\Outbound\OcrGatewayPort;
use App\Mvp\Support\ExecutionProfile;
use App\Providers\AppServiceProvider;

/**
 * Il composition root e' l'unico punto in cui il profilo di esecuzione
 * sceglie gli adapter (ADR 0014).
 */
test('the default execution profile is standard', function () {
    expect(config('mvp.execution_profile'))->toBe('standard')
        ->and(ExecutionProfile::fromConfig(config('mvp.execution_profile')))->toBe(ExecutionProfile::Standard);
});

test('the standard profile resolves the original AWS adapters', function () {
    config(['mvp.execution_profile' => 'standard']);

    expect(app(OcrGatewayPort::class))->toBeInstanceOf(TextractOcrAdapter::class)
        ->and(app(DocumentAiGatewayPort::class))->toBeInstanceOf(BedrockDocumentAiAdapter::class)
        ->and(app(CommunicationAiGatewayPort::class))->toBeInstanceOf(BedrockCommunicationAiAdapter::class);
});

test('the local profile resolves the local adapters', function () {
    config(['mvp.execution_profile' => 'local']);

    expect(app(OcrGatewayPort::class))->toBeInstanceOf(LocalPdfOcrAdapter::class)
        ->and(app(DocumentAiGatewayPort::class))->toBeInstanceOf(OllamaDocumentAiAdapter::class)
        ->and(app(CommunicationAiGatewayPort::class))->toBeInstanceOf(LocalCommunicationAiAdapter::class);
});

test('an invalid execution profile fails explicitly instead of picking a provider', function (mixed $value) {
    config(['mvp.execution_profile' => $value]);

    expect(fn () => app(OcrGatewayPort::class))->toThrow(InvalidArgumentException::class, 'MVP_EXECUTION_PROFILE non valido')
        ->and(fn () => app(DocumentAiGatewayPort::class))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(CommunicationAiGatewayPort::class))->toThrow(InvalidArgumentException::class);
})->with(['cloud', '', null]);

test('an invalid execution profile stops the application at boot', function () {
    config(['mvp.execution_profile' => 'cloud']);

    expect(fn () => (new AppServiceProvider(app()))->boot())
        ->toThrow(InvalidArgumentException::class, 'MVP_EXECUTION_PROFILE non valido');
});

test('the local cover provider selects the generator and defaults to mock', function (?string $provider, string $expected) {
    if ($provider !== null) {
        config(['services.local_cover.provider' => $provider]);
    }

    expect(app(CoverImageGenerator::class))->toBeInstanceOf($expected);
})->with([
    'default' => [null, DeterministicCoverGenerator::class],
    'mock' => ['mock', DeterministicCoverGenerator::class],
    'comfyui' => ['comfyui', ComfyUiCoverGenerator::class],
]);

test('an invalid local cover provider fails explicitly at boot of the local profile', function () {
    config(['mvp.execution_profile' => 'local', 'services.local_cover.provider' => 'sdxl']);

    expect(fn () => app(CoverImageGenerator::class))->toThrow(InvalidArgumentException::class, 'LOCAL_COVER_PROVIDER non valido')
        ->and(fn () => (new AppServiceProvider(app()))->boot())->toThrow(InvalidArgumentException::class, 'LOCAL_COVER_PROVIDER non valido');
});
