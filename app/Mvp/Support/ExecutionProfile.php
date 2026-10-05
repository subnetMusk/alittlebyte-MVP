<?php

namespace App\Mvp\Support;

/**
 * Profilo di esecuzione (ADR 0014): quali provider soddisfano le porte AI e
 * OCR. Lo legge solo il composition root; dominio e applicazione non sanno
 * quale profilo e' attivo.
 */
enum ExecutionProfile: string
{
    /** Provider dell'MVP: Bedrock, Textract e copertine da Bedrock. Il default. */
    case Standard = 'standard';

    /** Provider locali: modello testuale su Ollama, OCR locale, copertina locale. */
    case Local = 'local';

    /**
     * @throws \InvalidArgumentException su un valore non previsto: nessun
     *                                   ripiego silenzioso su un altro profilo.
     */
    public static function fromConfig(mixed $value): self
    {
        $profile = is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;

        if ($profile === null) {
            throw new \InvalidArgumentException(sprintf(
                'MVP_EXECUTION_PROFILE non valido: "%s". Valori ammessi: %s.',
                is_scalar($value) ? (string) $value : get_debug_type($value),
                implode(', ', array_column(self::cases(), 'value')),
            ));
        }

        return $profile;
    }
}
