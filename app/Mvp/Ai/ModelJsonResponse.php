<?php

namespace App\Mvp\Ai;

use App\Exceptions\InvalidAiOutputException;

/**
 * Decodifica il JSON restituito da un modello testuale, qualunque sia il
 * provider: i modelli racchiudono spesso il JSON in fence Markdown o in brevi
 * frasi, e scrivono ritorni a capo veri dentro le stringhe.
 */
final class ModelJsonResponse
{
    /**
     * @return array<int|string, mixed>
     *
     * @throws InvalidAiOutputException quando il testo non contiene JSON decodificabile.
     */
    public static function decode(string $text, string $operation): array
    {
        // Strip optional ```json fences before decoding.
        $cleanJson = preg_replace('/^```(?:json)?\s*|```\s*$/m', '', trim($text));

        // If the model adds prose, isolate the first JSON object or array.
        if (! str_starts_with($cleanJson, '{') && ! str_starts_with($cleanJson, '[')) {
            preg_match('/([\{\[].*[\}\]])/s', $cleanJson, $matches);
            $cleanJson = $matches[1] ?? $cleanJson;
        }

        $decoded = json_decode($cleanJson, true);

        // Seconda occasione: il modello scrive spesso i ritorni a capo dei
        // paragrafi cosi' come sono, e dentro una stringa JSON quelli sono
        // caratteri di controllo che invalidano l'intero documento. Il testo
        // che portano e' buono: si ripara la forma, non il contenuto.
        if (! is_array($decoded)) {
            $decoded = json_decode(self::escapeControlCharactersInStrings($cleanJson), true);
        }

        if (! is_array($decoded)) {
            throw new InvalidAiOutputException($operation, ['la risposta del modello non è JSON decodificabile']);
        }

        return $decoded;
    }

    /**
     * Scrive come sequenze di escape i ritorni a capo e le tabulazioni rimasti
     * dentro le stringhe di un JSON.
     *
     * La scansione e' byte per byte: in UTF-8 nessun byte di un carattere
     * multibyte coincide con la virgoletta, la barra rovesciata o un carattere
     * di controllo ASCII, quindi non c'e' modo di spezzare una lettera accentata
     * a meta'.
     */
    private static function escapeControlCharactersInStrings(string $json): string
    {
        $repaired = '';
        $inString = false;
        $escaped = false;

        foreach (str_split($json) as $char) {
            if ($escaped) {
                $repaired .= $char;
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $repaired .= $char;
                $escaped = true;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;
                $repaired .= $char;

                continue;
            }

            $repaired .= $inString ? match ($char) {
                "\n" => '\\n',
                "\r" => '',
                "\t" => '\\t',
                default => $char,
            } : $char;
        }

        return $repaired;
    }
}
