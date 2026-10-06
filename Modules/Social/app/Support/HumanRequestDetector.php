<?php

declare(strict_types=1);

namespace Modules\Social\Support;

use Illuminate\Support\Str;

/**
 * Detecta, sin IA, que la persona pide EXPRESAMENTE hablar con alguien del equipo («quiero hablar
 * con una persona», «asesor humano», «talk to a human»…). Si el canal transfiere a una persona,
 * la conversación pasa a «Esperando a una persona» sin consultar al asesor.
 */
final class HumanRequestDetector
{
    /** Frases normalizadas (minúsculas, sin tildes). */
    private const PATTERNS = [
        'hablar con una persona', 'hablar con alguien', 'hablar con un humano', 'hablar con un asesor',
        'hablar con una asesora', 'hablar con un agente', 'asesor humano', 'asesora humana', 'persona real',
        'atencion humana', 'quiero un humano', 'pasame con', 'paseme con', 'comunicarme con alguien',
        'talk to a human', 'talk to a person', 'speak to a human', 'speak to a person', 'real person',
        'human agent', 'talk to someone', 'speak with someone',
    ];

    public function wantsPerson(?string $text): bool
    {
        $normalized = ' '.trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii((string) $text)))).' ';

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
