<?php

namespace App\Mvp\Ai;

/**
 * Direzione artistica della copertina, condivisa dai provider di generazione
 * immagini: Bedrock e il generatore locale ricevono le stesse istruzioni.
 */
final class CoverImagePrompts
{
    public const NEGATIVE = 'low quality, blur, watermark, text, signature, distorted, unreadable text';

    /**
     * Direzione visiva della copertina: quella scritta dal modello testuale
     * quando disponibile, altrimenti una descrizione corporate generica.
     */
    public static function forCommunication(string $userPrompt, string $tone, string $style, ?string $modelImagePrompt): string
    {
        $subject = $modelImagePrompt ?: "Internal company communication about: {$userPrompt}";

        return 'Create a horizontal cover image for an internal company communication. '
            ."{$subject} "
            ."Tone: {$tone}. Editorial style: {$style}. "
            .'Use a modern corporate art direction with clear focal elements related to the topic. '
            .'No readable text, no logos, no signatures, no watermarks.';
    }
}
