<?php
// src/Application/Exception/BilleteraNoEncontrada.php — Excepción de capa de aplicación
//
# Diferencia con las excepciones de DOMINIO:
#   - Domain: reglas de negocio (FondosInsuficientes, MonedaNoSoportada)
#   - Application: problemas de ejecución del caso de uso (recurso no existe)
#
# El controller convierte esta excepción en un HTTP 404.

declare(strict_types=1);

namespace App\Application\Exception;

/**
 * Se lanza cuando un caso de uso no encuentra la billetera solicitada.
 */
final class BilleteraNoEncontrada extends \DomainException
{
    public function __construct(string $billeteraId)
    {
        parent::__construct(
            "No se encontró la billetera con ID '{$billeteraId}'."
        );
    }
}