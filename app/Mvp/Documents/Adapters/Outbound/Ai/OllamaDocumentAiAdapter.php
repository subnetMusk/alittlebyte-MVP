<?php

namespace App\Mvp\Documents\Adapters\Outbound\Ai;

use App\Mvp\Ai\OllamaService;
use App\Mvp\Ai\TextModelPrompts;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentAiGatewayPort;

/**
 * Adapter secondario del profilo di esecuzione locale (ADR 0014): implementa
 * {@see DocumentAiGatewayPort} sopra {@see OllamaService}, con gli stessi
 * prompt e lo stesso validatore dell'adapter Bedrock.
 */
class OllamaDocumentAiAdapter implements DocumentAiGatewayPort
{
    public function __construct(private readonly OllamaService $ollama) {}

    public function splitDocument(string $ocrText, int $pageCount, string $boundaryNonce): array
    {
        return $this->ollama->splitDocument($ocrText, $pageCount, $boundaryNonce);
    }

    public function extractFields(string $ocrText): array
    {
        return $this->ollama->extractFields($ocrText);
    }

    public function pageBoundaryMarker(int $page, string $nonce): string
    {
        return TextModelPrompts::pageBoundaryMarker($page, $nonce);
    }

    public function formatUserError(\Throwable $e, string $defaultMessage): string
    {
        return OllamaService::formatUserError($e, $defaultMessage);
    }
}
