<?php
// src/Application/Service/CrearBilleteraService.php — Application Service
//
# APPLICATION SERVICE: Orquesta las operaciones de negocio.
# No contiene lógica de dominio (eso está en Billetera.php).
# No conoce la base de datos (eso está en BilleteraRepository.php).
#
# Responsabilidades:
#   1. Recibir el request del controller
#   2. Crear la entidad de dominio
#   3. Persistir a través del repository
#   4. Retornar el resultado al controller
#
# Este es el patrón "Use Case" de Clean Architecture:
#   Controller → Application Service → Domain → Repository → Database

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Billetera;
use App\Domain\Moneda;
use App\Infrastructure\Repository\BilleteraRepository;
use Ramsey\Uuid\Uuid;

/**
 * Servicio de aplicación para crear billeteras nuevas.
 *
 * Cada use case de la aplicación tiene su propio servicio.
 * Esto sigue el Principio de Responsabilidad Única (SRP):
# un servicio, un use case.
 */
final class CrearBilleteraService
{
    /**
     * @param BilleteraRepository $repository Repositorio para persistir la billetera
     */
    public function __construct(
        private readonly BilleteraRepository $repository,
    ) {
    }

    /**
     * Ejecuta el use case: crear una billetera nueva.
     *
     * @param string $usuarioId ID del propietario de la billetera
     * @param string $monedaCode Código de moneda (ej: "MXN", "USD")
     *
     * @return Billetera La billetera creada con ID asignado
     *
     * @throws \InvalidArgumentException Si la moneda no es soportada
     */
    public function ejecutar(string $usuarioId, string $monedaCode): Billetera
    {
        // 1. Validar que la moneda sea soportada.
        // Moneda::from() lanza ValueError si el código no existe.
        // Lo convertimos a InvalidArgumentException para consistencia.
        try {
            $moneda = Moneda::from(strtoupper($monedaCode));
        } catch (\ValueError $e) {
            throw new \InvalidArgumentException(
                "Moneda no soportada: '{$monedaCode}'. "
                . "Use: MXN, USD, o EUR."
            );
        }

        // 2. Verificar que el usuario no tenga ya una billetera en esta moneda.
        // En PrestaFlow, un usuario solo puede tener UNA billetera por moneda.
        $billeterasExistentes = $this->repository->findByUsuario($usuarioId);
        foreach ($billeterasExistentes as $billetera) {
            if ($billetera->moneda() === $moneda) {
                throw new \InvalidArgumentException(
                    "El usuario '{$usuarioId}' ya tiene una billetera en {$moneda->value}"
                );
            }
        }

        // 3. Generar un ID único para la billetera.
        // Uuid::uuid4() genera un UUID v4 (random).
        // Lo convertimos a string para almacenamiento.
        $id = Uuid::uuid4()->toString();

        // 4. Crear la entidad de dominio.
        // El dominio valida que el saldo inicial sea 0 (implícito al no pasar movimientos).
        $billetera = new Billetera(
            id: $id,
            usuarioId: $usuarioId,
            moneda: $moneda,
        );

        // 5. Persistir a través del repository.
        // El repository ejecuta el INSERT en PostgreSQL.
        $this->repository->save($billetera);

        // 6. Retornar la billetera creada.
        // El controller recibirá esta entidad y la convertirá a JSON.
        return $billetera;
    }
}
