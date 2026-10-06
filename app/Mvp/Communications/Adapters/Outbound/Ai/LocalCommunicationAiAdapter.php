<?php

namespace App\Mvp\Communications\Adapters\Outbound\Ai;

use App\Mvp\Ai\Cover\CoverImageGenerator;
use App\Mvp\Ai\OllamaService;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationAiGatewayPort;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationImage;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationText;

/**
 * Adapter secondario del profilo di esecuzione locale (ADR 0014): implementa
 * {@see CommunicationAiGatewayPort} con il testo da {@see OllamaService} e la
 * copertina dal {@see CoverImageGenerator} scelto in configurazione.
 */
class LocalCommunicationAiAdapter implements CommunicationAiGatewayPort
{
    public function __construct(
        private readonly OllamaService $ollama,
        private readonly CoverImageGenerator $covers,
    ) {}

    public function generateText(string $prompt, string $tone, string $style): GeneratedCommunicationText
    {
        $generated = $this->ollama->generateCommunication($prompt, $tone, $style);

        return new GeneratedCommunicationText(
            title: $generated['title'],
            body: $generated['body'],
            imagePrompt: $generated['image_prompt'] ?? null,
        );
    }

    public function generateImage(string $prompt, string $tone, string $style, ?string $modelImagePrompt): GeneratedCommunicationImage
    {
        $image = $this->covers->generate($prompt, $tone, $style, $modelImagePrompt);

        return new GeneratedCommunicationImage(
            bytes: $image['bytes'],
            mime: $image['mime'],
            warning: $image['warning'],
            reason: $image['reason'],
        );
    }
}
