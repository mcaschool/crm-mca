<?php

declare(strict_types=1);

namespace Modules\Social\Support;

use Modules\Core\Support\PhoneNumber;
use Modules\Crm\Models\Contact;
use Modules\Social\Models\SocialConversation;

/**
 * Relación SEGURA de una conversación social con un contacto del CRM, calculada al mostrarla
 * (no se guarda nada, no se crea ningún contacto y no se toca la recepción ni el envío).
 *
 * - WhatsApp: el identificador del contacto es su wa_id (número con código de país, sin «+»).
 *   Se normaliza a E.164 con PhoneNumber (sin adivinar país) y se busca el contacto de la MISMA
 *   institución (scope global) con ese phone_normalized exacto. La restricción
 *   UNIQUE(institution_id, phone_normalized) garantiza que como mucho hay uno: no puede haber
 *   coincidencias múltiples por teléfono.
 * - Instagram y Messenger: sus identificadores (IGSID/PSID) son propios de Meta y por página; el
 *   CRM no los guarda en Contactos, así que no hay identificador verificable y no se vincula.
 * - Nunca se compara por nombre.
 */
final class ConversationContactLink
{
    public const LINKED = 'linked';

    public const NONE = 'none';

    public const UNSUPPORTED = 'unsupported';

    /** @return array{state: string, contact: ?Contact, phone: ?string} */
    public static function resolve(SocialConversation $conversation): array
    {
        if ($conversation->provider !== 'whatsapp') {
            return ['state' => self::UNSUPPORTED, 'contact' => null, 'phone' => null];
        }

        $phone = PhoneNumber::normalize((string) $conversation->contact_external_id, assumeInternational: true);
        if ($phone === null) {
            return ['state' => self::NONE, 'contact' => null, 'phone' => null];
        }

        $contact = Contact::query()->where('phone_normalized', $phone)->first();

        return ['state' => $contact !== null ? self::LINKED : self::NONE, 'contact' => $contact, 'phone' => $phone];
    }
}
