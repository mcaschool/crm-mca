<?php

declare(strict_types=1);

namespace Modules\Crm\Exceptions;

use RuntimeException;

/**
 * Datos de contacto que NO pueden guardarse tal cual (tipo, formato o longitud fuera de los
 * límites del esquema). Lo lanza la capa común ContactDataNormalizer ANTES de tocar la base
 * de datos, para que cada canal responda de forma controlada (422, error junto al campo,
 * recibo fallido…) en vez de con una excepción SQL.
 *
 * Solo transporta el NOMBRE del campo y un motivo legible: nunca el valor recibido (puede
 * ser un dato personal o basura de un proveedor), así es seguro de registrar y de mostrar.
 */
final class InvalidContactDataException extends RuntimeException
{
    /**
     * @param  array<string, string>  $errors  campo => motivo legible (sin el valor)
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Datos de contacto no válidos: '.implode(', ', array_keys($errors)).'.');
    }

    /**
     * Campos rechazados (para registros y métricas, sin valores).
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return array_keys($this->errors);
    }

    /** Primer motivo, «campo: motivo», para canales que solo muestran un mensaje. */
    public function summary(): string
    {
        $field = (string) array_key_first($this->errors);

        return $field.': '.($this->errors[$field] ?? '');
    }
}
