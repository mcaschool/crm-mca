<?php

declare(strict_types=1);

namespace Modules\Crm\Services;

use Modules\Core\Support\PhoneNumber;
use Modules\Crm\Exceptions\ContactIdentityConflictException;
use Modules\Crm\Exceptions\InvalidContactDataException;
use Modules\Crm\Models\Contact;
use Modules\Crm\Support\ContactDataNormalizer;

/**
 * Alta/enriquecimiento de contactos con identidad por EMAIL O TELÉFONO, respetando las
 * invariantes UNIQUE (institution_id, email) y (institution_id, phone_normalized). El scope
 * global de institución acota TODA búsqueda: nunca se deduplica entre instituciones.
 *
 * Resolución de identidad (dentro de la institución activa):
 *  1) Si llega email → se busca por email (normalizado a minúsculas). El email manda.
 *  2) Si no hay email → se busca por TELÉFONO NORMALIZADO (nunca por el teléfono crudo).
 *  3) Si email y teléfono normalizado apuntan a contactos DISTINTOS y ya existentes:
 *     - modo estricto (endpoint público): se lanza ContactIdentityConflictException (no se
 *       fusiona ni reasigna);
 *     - modo normal (widget, panel, incompany): el email manda y NO se reasigna el teléfono
 *       normalizado del otro contacto (se conserva el crudo como presentación).
 *
 * Nunca se sobrescribe un email existente por otro distinto, ni se persiste '' (se guarda
 * NULL). El teléfono CRUDO se conserva como valor de presentación; solo el normalizado
 * (no nulo) participa en la deduplicación.
 *
 * TODA entrada pasa antes por ContactDataNormalizer (la capa común de todos los canales):
 * tipos, formatos y longitudes del esquema, marcadores sintéticos de proveedores → null, sin
 * truncar. Lo inválido se rechaza con InvalidContactDataException ANTES de buscar o escribir
 * nada, así un dato externo nunca llega a provocar un error SQL.
 */
class ContactService
{
    /**
     * @param  array<string,mixed>  $data  email, phone, phone_assume_international, first_name,
     *                                     last_name, country, preferred_language, consent, ...
     * @param  bool  $strictIdentityConflict  lanza excepción si email y teléfono chocan (no fusiona)
     *
     * @throws ContactIdentityConflictException
     * @throws InvalidContactDataException
     */
    public function createOrUpdate(array $data, bool $strictIdentityConflict = false): Contact
    {
        $data = ContactDataNormalizer::normalize($data);

        $email = $data['email'] ?? null;
        $phoneRaw = $data['phone'] ?? null;
        $assumeInternational = (bool) ($data['phone_assume_international'] ?? false);
        $phoneNormalized = $phoneRaw !== null ? PhoneNumber::normalize($phoneRaw, $assumeInternational) : null;

        $byEmail = $email !== null ? Contact::query()->where('email', $email)->first() : null;
        $byPhone = $phoneNormalized !== null ? Contact::query()->where('phone_normalized', $phoneNormalized)->first() : null;

        // Conflicto: email y teléfono apuntan a DOS contactos existentes distintos.
        if ($byEmail !== null && $byPhone !== null && $byEmail->getKey() !== $byPhone->getKey()) {
            if ($strictIdentityConflict) {
                throw new ContactIdentityConflictException((int) $byEmail->getKey(), (int) $byPhone->getKey());
            }
            // No estricto: el email manda; no se reasigna el teléfono normalizado del otro.
        }

        // El email manda como identidad cuando está presente; si no, identifica el teléfono.
        $contact = $email !== null ? ($byEmail ?? new Contact) : ($byPhone ?? new Contact);

        // Un contacto NUEVO necesita una identidad (correo o teléfono): un teléfono sintético o
        // vacío sin correo no crea un contacto anónimo.
        if (! $contact->exists && $email === null && $phoneRaw === null) {
            throw new InvalidContactDataException(['email' => __('Hace falta un correo o un teléfono válido para identificar el contacto.')]);
        }

        // Email: se fija solo si el contacto no tiene uno (nunca se pisa uno distinto, ni con '').
        if ($email !== null && ($contact->email === null || $contact->email === '')) {
            $contact->email = $email;
        }

        // Enriquecimiento: solo se escriben los campos que llegan con valor.
        foreach (['first_name', 'last_name', 'country', 'preferred_language'] as $field) {
            if (($data[$field] ?? null) !== null) {
                $contact->{$field} = $data[$field];
            }
        }
        // El nombre es obligatorio en el esquema: un contacto nuevo sin nombre real (formulario
        // sin ese campo, o nombre sintético del proveedor) entra como «Sin nombre».
        if (! $contact->exists && ($contact->first_name ?? '') === '') {
            $contact->first_name = __('Sin nombre');
        }

        // Teléfono CRUDO = presentación (siempre que llegue).
        if ($phoneRaw !== null) {
            $contact->phone = $phoneRaw;
        }
        // Teléfono NORMALIZADO = clave de dedup: solo si no pertenece a OTRO contacto
        // (evita violar el índice único; el conflicto ya se resolvió arriba).
        if ($phoneNormalized !== null && ($byPhone === null || $byPhone->getKey() === $contact->getKey())) {
            $contact->phone_normalized = $phoneNormalized;
        }

        // Consentimiento (D2): se sella una sola vez, cuando llega verdadero. Si la fuente
        // aporta la fecha real (consent_at), se respeta; si no, se sella ahora. Nunca se
        // inventa: solo se sella si `consent` llega verdadero.
        if (! empty($data['consent']) && $contact->consent_at === null) {
            $contact->consent_at = $data['consent_at'] ?? now(); // ya validada por el normalizador
            $contact->consent_source = $data['consent_source'] ?? 'widget';
        }

        // Baja (unsubscribe): se registra el momento.
        if (! empty($data['unsubscribed']) && $contact->unsubscribed_at === null) {
            $contact->unsubscribed_at = now();
        }

        $contact->save();

        return $contact;
    }
}
