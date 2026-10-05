<?php

namespace App\Mvp\Support;

/**
 * Generatore della copertina nel profilo di esecuzione locale (ADR 0014).
 */
enum LocalCoverProvider: string
{
    /** Copertina deterministica, senza modelli: il default. */
    case Mock = 'mock';

    /** Generazione con un server ComfyUI locale, opzionale. */
    case ComfyUi = 'comfyui';

    /**
     * @throws \InvalidArgumentException su un valore non previsto.
     */
    public static function fromConfig(mixed $value): self
    {
        $provider = is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;

        if ($provider === null) {
            throw new \InvalidArgumentException(sprintf(
                'LOCAL_COVER_PROVIDER non valido: "%s". Valori ammessi: %s.',
                is_scalar($value) ? (string) $value : get_debug_type($value),
                implode(', ', array_column(self::cases(), 'value')),
            ));
        }

        return $provider;
    }
}
