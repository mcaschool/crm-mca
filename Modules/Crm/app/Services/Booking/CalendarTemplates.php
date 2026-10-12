<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Booking;

use Modules\Crm\Models\CalendarTemplate;

/**
 * Correos personalizables del Calendario (por institución e idioma). Las plantillas son TEXTO
 * PLANO con variables autorizadas {{ variable }}: al renderizar, el texto y los valores se escapan
 * y solo las URL https se convierten en enlaces. No se ejecuta código ni se accede a variables
 * internas: una variable desconocida se rechaza al guardar y nunca se sustituye.
 */
class CalendarTemplates
{
    public const KEYS = ['booking_confirmation', 'booking_rescheduled', 'booking_cancelled', 'advisor_new_booking'];

    public const VARIABLES = [
        'nombre', 'institucion', 'asesor', 'tipo_reunion', 'fecha', 'hora', 'zona_horaria', 'duracion',
        'medio', 'enlace', 'ubicacion', 'programa', 'enlace_gestion', 'correo_visitante', 'telefono_visitante',
        'notas', 'enlace_crm',
    ];

    public static function label(string $key): string
    {
        return match ($key) {
            'booking_confirmation' => __('Confirmación de reserva'),
            'booking_rescheduled' => __('Reprogramación'),
            'booking_cancelled' => __('Cancelación'),
            default => __('Nueva reserva (aviso al asesor)'),
        };
    }

    /** @return array{subject:string,body:string,custom:bool} */
    public function get(string $key, string $locale): array
    {
        $locale = $locale === 'en' ? 'en' : 'es';
        $row = CalendarTemplate::query()->where('key', $key)->where('locale', $locale)->first();

        return $row !== null
            ? ['subject' => $row->subject, 'body' => $row->body, 'custom' => true]
            : $this->default($key, $locale) + ['custom' => false];
    }

    /**
     * @param  array<string,string|null>  $vars
     * @return array{subject:string,html:string}
     */
    public function render(string $key, string $locale, array $vars): array
    {
        $tpl = $this->get($key, $locale);

        return ['subject' => $this->renderSubject($tpl['subject'], $vars), 'html' => $this->renderBody($tpl['body'], $vars)];
    }

    /** @param  array<string,string|null>  $vars */
    public function renderSubject(string $subject, array $vars): string
    {
        $out = $this->replace($subject, $vars, fn (string $v) => $v);

        return trim(preg_replace('/\s+/u', ' ', $out) ?? $out);
    }

    /** @param  array<string,string|null>  $vars */
    public function renderBody(string $body, array $vars): string
    {
        $html = $this->replace(e($body), $vars, fn (string $v) => e($v));
        $html = preg_replace_callback('~https://[^\s<>"\']+~u', function (array $m): string {
            $url = $m[0];

            return '<a href="'.$url.'" style="color:#1E5AA8">'.$url.'</a>';
        }, $html) ?? $html;

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#13253D;max-width:600px">'
            .nl2br($html, false).'</div>';
    }

    /**
     * Variables usadas que NO están autorizadas (vacío = plantilla válida).
     *
     * @return array<int,string>
     */
    public function unknownVariables(string $text): array
    {
        preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/u', $text, $m);

        return array_values(array_unique(array_filter($m[1], fn (string $v) => ! in_array($v, self::VARIABLES, true))));
    }

    /**
     * Valores de ejemplo para la vista previa.
     *
     * @return array<string,string>
     */
    public function sample(string $locale): array
    {
        $en = $locale === 'en';

        return [
            'nombre' => 'Laura Pérez', 'institucion' => 'MCA School', 'asesor' => 'Ana García',
            'tipo_reunion' => $en ? 'Admissions consultation' : 'Consulta de admisiones',
            'fecha' => $en ? 'Monday, October 19, 2026' : 'lunes 19 de octubre de 2026', 'hora' => '10:00',
            'zona_horaria' => 'America/Bogota', 'duracion' => '30 min', 'medio' => 'Google Meet',
            'enlace' => 'https://meet.google.com/abc-defg-hij', 'ubicacion' => '', 'programa' => 'Micro MBA',
            'enlace_gestion' => 'https://crm.example.org/reservar/gestion/…', 'correo_visitante' => 'laura@example.org',
            'telefono_visitante' => '+57 300 000 0000', 'notas' => $en ? 'I would like to know the start dates.' : 'Quiero conocer las fechas de inicio.',
            'enlace_crm' => 'https://crm.example.org/crm/agenda/1',
        ];
    }

    /** @return array{subject:string,body:string} */
    public function default(string $key, string $locale): array
    {
        $en = $locale === 'en';

        return match ($key) {
            'booking_confirmation' => $en ? [
                'subject' => 'Confirmed: {{tipo_reunion}} with {{asesor}} — {{fecha}}, {{hora}}',
                'body' => "Hello {{nombre}},\n\nYour meeting with {{asesor}} ({{institucion}}) is confirmed.\n\nMeeting: {{tipo_reunion}}\nDate: {{fecha}}\nTime: {{hora}} ({{zona_horaria}})\nDuration: {{duracion}}\nMedium: {{medio}}\nAccess: {{enlace}}\n{{ubicacion}}\n\nIf you need to reschedule or cancel, use this link:\n{{enlace_gestion}}\n\nSee you soon,\n{{institucion}}",
            ] : [
                'subject' => 'Confirmada: {{tipo_reunion}} con {{asesor}} — {{fecha}}, {{hora}}',
                'body' => "Hola {{nombre}}:\n\nTu reunión con {{asesor}} ({{institucion}}) está confirmada.\n\nReunión: {{tipo_reunion}}\nFecha: {{fecha}}\nHora: {{hora}} ({{zona_horaria}})\nDuración: {{duracion}}\nMedio: {{medio}}\nAcceso: {{enlace}}\n{{ubicacion}}\n\nSi necesitas reprogramar o cancelar, usa este enlace:\n{{enlace_gestion}}\n\nHasta pronto,\n{{institucion}}",
            ],
            'booking_rescheduled' => $en ? [
                'subject' => 'Rescheduled: {{tipo_reunion}} — {{fecha}}, {{hora}}',
                'body' => "Hello {{nombre}},\n\nYour meeting with {{asesor}} has been rescheduled.\n\nNew date: {{fecha}}\nTime: {{hora}} ({{zona_horaria}})\nDuration: {{duracion}}\nMedium: {{medio}}\nAccess: {{enlace}}\n{{ubicacion}}\n\nManage your booking: {{enlace_gestion}}\n\n{{institucion}}",
            ] : [
                'subject' => 'Reprogramada: {{tipo_reunion}} — {{fecha}}, {{hora}}',
                'body' => "Hola {{nombre}}:\n\nTu reunión con {{asesor}} se ha reprogramado.\n\nNueva fecha: {{fecha}}\nHora: {{hora}} ({{zona_horaria}})\nDuración: {{duracion}}\nMedio: {{medio}}\nAcceso: {{enlace}}\n{{ubicacion}}\n\nGestiona tu reserva: {{enlace_gestion}}\n\n{{institucion}}",
            ],
            'booking_cancelled' => $en ? [
                'subject' => 'Cancelled: {{tipo_reunion}} — {{fecha}}, {{hora}}',
                'body' => "Hello {{nombre}},\n\nYour meeting with {{asesor}} on {{fecha}} at {{hora}} ({{zona_horaria}}) has been cancelled.\n\nYou can book a new time whenever you like.\n\n{{institucion}}",
            ] : [
                'subject' => 'Cancelada: {{tipo_reunion}} — {{fecha}}, {{hora}}',
                'body' => "Hola {{nombre}}:\n\nTu reunión con {{asesor}} del {{fecha}} a las {{hora}} ({{zona_horaria}}) se ha cancelado.\n\nPuedes reservar un nuevo horario cuando quieras.\n\n{{institucion}}",
            ],
            default => $en ? [
                'subject' => 'New booking: {{tipo_reunion}} — {{nombre}}, {{fecha}} {{hora}}',
                'body' => "Hello {{asesor}},\n\nYou have a new booking.\n\nVisitor: {{nombre}} ({{correo_visitante}})\nPhone: {{telefono_visitante}}\nMeeting: {{tipo_reunion}}\nDate: {{fecha}}\nTime: {{hora}} ({{zona_horaria}})\nDuration: {{duracion}}\nMedium: {{medio}}\nProgram: {{programa}}\nNotes: {{notas}}\n\nOpen in the CRM: {{enlace_crm}}",
            ] : [
                'subject' => 'Nueva reserva: {{tipo_reunion}} — {{nombre}}, {{fecha}} {{hora}}',
                'body' => "Hola {{asesor}}:\n\nTienes una nueva reserva.\n\nVisitante: {{nombre}} ({{correo_visitante}})\nTeléfono: {{telefono_visitante}}\nReunión: {{tipo_reunion}}\nFecha: {{fecha}}\nHora: {{hora}} ({{zona_horaria}})\nDuración: {{duracion}}\nMedio: {{medio}}\nPrograma: {{programa}}\nNotas: {{notas}}\n\nAbrir en el CRM: {{enlace_crm}}",
            ],
        };
    }

    /**
     * Sustituye SOLO variables autorizadas; las desconocidas quedan tal cual (nunca se evalúan).
     *
     * @param  array<string,string|null>  $vars
     * @param  callable(string):string  $escape
     */
    private function replace(string $text, array $vars, callable $escape): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/u', function (array $m) use ($vars, $escape): string {
            $name = $m[1];
            if (! in_array($name, self::VARIABLES, true)) {
                return $m[0];
            }

            return $escape((string) ($vars[$name] ?? ''));
        }, $text) ?? $text;
    }
}
