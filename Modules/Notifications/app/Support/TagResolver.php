<?php

declare(strict_types=1);

namespace Modules\Notifications\Support;

use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Lead;

/**
 * Etiquetas dinámicas del editor de correo. Se escriben como [Etiqueta] y al enviar
 * se reemplazan por el dato REAL del destinatario (lead/contacto). Si falta el dato,
 * se usa un FALLBACK claro (nunca se deja "[Nombre]" crudo ni un saludo roto).
 *
 * Los valores se insertan ESCAPADOS en el cuerpo HTML (un dato del contacto —p. ej.
 * un nombre con <…>— no puede inyectar HTML).
 */
class TagResolver
{
    /** Etiqueta => fallback cuando no hay dato. */
    private const TAGS = [
        'Nombre' => 'estimado/a',
        'Nombre completo' => 'estimado/a',
        'Correo' => '',
        'Área' => 'tu área de interés',
        'Programa' => 'nuestros programas',
    ];

    /**
     * Valores CRUDOS de cada etiqueta (vacío si no hay dato).
     *
     * @return array<string, string>
     */
    private function rawValues(Contact $contact, ?Lead $lead): array
    {
        $area = '';
        $program = '';
        if ($lead !== null) {
            $area = (string) ($lead->area ?? '');
            if ($lead->program !== null) {
                $program = (string) ($lead->program->translate('name') ?? '');
            }
        }

        return [
            'Nombre' => (string) $contact->first_name,
            'Nombre completo' => trim($contact->first_name.' '.(string) $contact->last_name),
            'Correo' => (string) $contact->email,
            'Área' => $area,
            'Programa' => $program,
        ];
    }

    /**
     * Mapa etiqueta => valor a usar al enviar (con fallback aplicado).
     *
     * @return array<string, string>
     */
    public function map(Contact $contact, ?Lead $lead = null): array
    {
        $raw = $this->rawValues($contact, $lead);
        $out = [];
        foreach (self::TAGS as $tag => $fallback) {
            $value = trim($raw[$tag] ?? '');
            $out[$tag] = $value !== '' ? $value : $fallback;
        }

        return $out;
    }

    /**
     * Etiquetas DISPONIBLES para el menú del editor: solo las que tienen dato real
     * para este destinatario ("las que apliquen"), con una vista previa del valor.
     *
     * @return array<int, array{tag: string, preview: string}>
     */
    public function available(Contact $contact, ?Lead $lead = null): array
    {
        $out = [];
        foreach ($this->rawValues($contact, $lead) as $tag => $value) {
            $value = trim($value);
            if ($value !== '') {
                $out[] = ['tag' => $tag, 'preview' => $value];
            }
        }

        return $out;
    }

    /**
     * Reemplaza las etiquetas en HTML (valores ESCAPADOS).
     *
     * @param  array<string, string>  $map
     */
    public function resolveHtml(string $html, array $map): string
    {
        return $this->replace($html, $map, true);
    }

    /**
     * Reemplaza las etiquetas en texto plano (asunto).
     *
     * @param  array<string, string>  $map
     */
    public function resolveText(string $text, array $map): string
    {
        return $this->replace($text, $map, false);
    }

    /**
     * @param  array<string, string>  $map
     */
    private function replace(string $subject, array $map, bool $escape): string
    {
        foreach ($map as $tag => $value) {
            $replacement = $escape ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
            $subject = str_replace('['.$tag.']', $replacement, $subject);
        }

        return $subject;
    }
}
