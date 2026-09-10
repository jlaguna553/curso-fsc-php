<?php
// src/Domain/Repository/IdempotenciaRepository.php — PUERTO de idempotencia
//
# La idempotencia es el "cinturón de seguridad" de los sistemas financieros.
# Permite procesar el MISMO comando N veces con el mismo resultado,
# sin duplicar transacciones.
#
# La tabla `idempotencia` guarda las claves ya procesadas.
# Este puerto define el contrato mínimo necesario.

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Contrato de persistencia para claves de idempotencia.
 */
interface IdempotenciaRepository
{
    /**
     * ¿Existe ya esta clave en la tabla de idempotencia?
     *
     * @param string $clave Clave única de la operación
     *
     * @return bool true si la operación ya se procesó
     */
    public function existe(string $clave): bool;

    /**
     * Registra una clave como procesada.
     *
     * Este método debe ser INCREMENTAL: si la clave ya existe,
     * simplemente no hace nada (o no lanza error).
     *
     * @param string $clave Clave única de la operación
     */
    public function registrar(string $clave): void;
}