<?php

namespace App\Mvp\Ai\Cover;

/**
 * Generatore della copertina nel profilo di esecuzione locale (ADR 0014).
 * L'implementazione si sceglie nel composition root.
 */
interface CoverImageGenerator
{
    /**
     * Stesso esito di BedrockService::generateCommunicationImageWithMeta(): i
     * byte dell'immagine, oppure un avviso per l'operatore e un motivo
     * leggibile dalle metriche quando la copertina non e' disponibile.
     *
     * @param  ?string  $modelImagePrompt  Direzione visiva prodotta dal modello testuale.
     * @return array{bytes: ?string, mime: string, warning: ?string, reason: ?string}
     */
    public function generate(string $prompt, string $tone, string $style, ?string $modelImagePrompt): array;
}
