<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Modules\Core\Jobs\TenantAwareJob;
use Modules\Crm\Models\Contact;

/**
 * Job de prueba: cuenta los contactos VISIBLES para la institucion con la que
 * fue encolado. Sirve para verificar el tapon de la fuga de colas: aunque el
 * worker no tenga contexto ambiente, el job restablece el de su institucion.
 *
 * El resultado se expone en una propiedad ESTATICA porque la cola `sync`
 * serializa el job: la instancia que ejecuta handle() es una copia, no la que
 * tiene la prueba. La estatica es de clase y sobrevive a la (des)serializacion.
 */
final class CountContactsJob extends TenantAwareJob
{
    public static ?int $lastSeenCount = null;

    protected function handleForInstitution(): void
    {
        self::$lastSeenCount = Contact::query()->count();
    }
}
