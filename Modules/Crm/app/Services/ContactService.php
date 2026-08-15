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

        // Consentimiento (D2): se sella una sola vez, cuando llega.
        if (! empty($data['consent']) && $contact->consent_at === null) {
            $contact->consent_at = now();
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
