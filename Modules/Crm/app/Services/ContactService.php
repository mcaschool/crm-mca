<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use Modules\Crm\Models\Contact;

/**
 * Alta/enriquecimiento de contactos respetando la invariante UNIQUE
 * (institution_id, email). Si el correo ya existe en la institucion, NO duplica:
 * enriquece el contacto existente con lo nuevo (nunca borra con vacios).
 */
class ContactService
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function createOrUpdate(array $data): Contact
    {
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));

        $contact = Contact::query()->where('email', $email)->first();

        if ($contact === null) {
            $contact = new Contact;
            $contact->email = $email;
        }

        // Enriquecimiento: solo se escriben los campos que llegan con valor.
        foreach (['first_name', 'last_name', 'phone', 'country', 'preferred_language'] as $field) {
            if (isset($data[$field]) && trim((string) $data[$field]) !== '') {
                $contact->{$field} = $data[$field];
            }
        }

        // Consentimiento (D2): se sella una sola vez, cuando llega. Si la fuente aporta la
        // fecha real del consentimiento (consent_at), se respeta; si no, se sella ahora.
        // Nunca se inventa consentimiento: solo se sella si `consent` llega verdadero.
        if (! empty($data['consent']) && $contact->consent_at === null) {
            $consentAt = null;
            if (! empty($data['consent_at'])) {
                try {
                    $consentAt = \Illuminate\Support\Carbon::parse((string) $data['consent_at']);
                } catch (\Throwable) {
                    $consentAt = null;
                }
            }
            $contact->consent_at = $consentAt ?? now();
            $contact->consent_source = (string) ($data['consent_source'] ?? 'widget');
        }

        // Baja (unsubscribe): se registra el momento.
        if (! empty($data['unsubscribed']) && $contact->unsubscribed_at === null) {
            $contact->unsubscribed_at = now();
        }

        $contact->save();

        return $contact;
    }
}
