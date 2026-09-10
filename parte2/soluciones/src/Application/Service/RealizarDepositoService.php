<?php
// src/Application/Service/RealizarDepositoService.php — Application Service
//
# Use case: depositar dinero en una billetera.
# Este servicio orquesta: buscar billetera → validar → depositar → persistir → retornar

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Billetera;
use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Infrastructure\Repository\BilleteraRepository;

/**
 * Servicio de aplicación para realizar depósitos.
 */
final class RealizarDepositoService
{
    public function __construct(
        private readonly BilleteraRepository $repository,
    ) {
    }

    /**
     * Ejecuta el use case: depositar dinero en una billetera.
     *
     * @param string $billeteraId ID de la billetera destino
     * @param string $monto       Monto a depositar (string decimal)
     * @param string $monedaCode  Código de moneda
     * @param string $descripcion Descripción del depósito
     *
     * @return array{billetera: Billetera, balance_anterior: string, balance_nuevo: string}
     *
     * @throws \RuntimeException Si la billetera no existe
     * @throws \InvalidArgumentException Si el monto o moneda son inválidos
     */
    public function ejecutar(
        string $billeteraId,
        string $monto,
        string $monedaCode,
        string $descripcion,
    ): array {
        // 1. Buscar la billetera en la base de datos.
        $billetera = $this->repository->findById($billeteraId);

        if ($billetera === null) {
            throw new \RuntimeException(
                "Billetera no encontrada: '{$billeteraId}'"
            );
        }

        // 2. Capturar el balance ANTES del depósito.
        // Esto es importante para la respuesta: el cliente necesita saber
        # el estado antes y después de la operación.
        $balanceAnterior = $billetera->balance();

        // 3. Validar la moneda.
        $moneda = Moneda::from(strtoupper($monedaCode));
        if ($moneda !== $billetera->moneda()) {
            throw new \InvalidArgumentException(
                "Moneda inválida: la billetera es {$billetera->moneda()->value}, "
                . "pero se solicitó {$moneda->value}"
            );
        }

        // 4. Crear el Value Object Dinero.
        $dinero = Dinero::crear($monto, $moneda);

        // 5. Ejecutar el depósito en la entidad de dominio.
        // Aquí se ejecutan TODAS las reglas de negocio:
        #   - No se puede depositar monto cero
        #   - La moneda debe coincidir
        // Si alguna regla falla, se lanza una excepción de dominio.
        $billetera->depositar($dinero, $descripcion);

        // 6. Persistir los cambios.
        // Doctrine detecta que la entidad fue modificada y ejecuta un UPDATE.
        $this->repository->save($billetera);

        // 7. Retornar el resultado con contexto.
        // El controller usará esta información para construir la respuesta HTTP.
        return [
            'billetera' => $billetera,
            'balance_anterior' => $balanceAnterior->formateadoDecimal(),
            'balance_nuevo' => $billetera->balance()->formateadoDecimal(),
        ];
    }
}
