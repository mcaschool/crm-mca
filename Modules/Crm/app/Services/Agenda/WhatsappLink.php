<?php

declare(strict_types=1);

namespace Modules\Crm\Services\Agenda;

use Modules\Core\Support\PhoneNumber;
use Modules\Crm\Enums\ActivityMedium;
use Modules\Crm\Models\Activity;

/**
 * Enlace wa.me seguro hacia el número de WhatsApp del asesor (copiado en la actividad al
 * crearla), con un texto inicial breve (nombre y hora). Solo dígitos en la ruta y texto
 * codificado: nunca envía nada por sí mismo ni abre una sala de vídeo.
 */
final class WhatsappLink
{
    public function build(?string $number, string $text = ''): ?string
    {
        $canonical = PhoneNumber::normalize($number);
        if ($canonical === null) {
            return null;
        }

        $url = 'https://wa.me/'.ltrim($canonical, '+');
        $text = trim(mb_substr($text, 0, 300));

        return $text === '' ? $url : $url.'?text='.rawurlencode($text);
    }

    public function forActivity(Activity $activity, string $locale = 'es', ?string $name = null): ?string
    {
        if ($activity->medium !== ActivityMedium::WhatsApp) {
            return null;
        }

        $name ??= $activity->relatedName();
        $when = $activity->localStart()->format('d/m/Y H:i').' ('.$activity->timezone.')';
        $text = $locale === 'en'
            ? trim('Hello'.($name ? ', this is '.$name : '').'. About our appointment on '.$when.'.')
            : trim('Hola'.($name ? ', soy '.$name : '').'. Sobre nuestra cita del '.$when.'.');

        return $this->build($activity->meeting_link, $text);
    }
}
