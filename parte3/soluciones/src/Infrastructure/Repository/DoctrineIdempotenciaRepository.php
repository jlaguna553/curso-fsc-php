<?php
// src/Infrastructure/Repository/DoctrineIdempotenciaRepository.php — Adapter de idempotencia
//
# Uso SQL nativo (no ORM) porque la tabla `idempotencia` es una simple
# clave + timestamp. No necesita mapeo de entidad.
#
# La UNIQUE CONSTRAINT de la columna `clave` es la verdadera guardia:
#   - Usamos INSERT ... ON CONFLICT DO NOTHING (PostgreSQL)
#   - Si la clave ya existe, la inserción simplemente no hace nada
#   - Esto hace a registrar() idempotente por diseño

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\IdempotenciaRepository;
use Doctrine\ORM\EntityManagerInterface;
use DateTimeImmutable;

/**
 * Implementación del puerto IdempotenciaRepository sobre PostgreSQL.
 */
final class DoctrineIdempotenciaRepository implements IdempotenciaRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function existe(string $clave): bool
    {
        // getConnection(): acceso directo a la conexión PDO/DBAL.
        $connection = $this->entityManager->getConnection();

        // Parámetro :clave evita SQL injection (prepared statement).
        // fetchOne() retorna el primer valor de la primera fila, o false si no hay.
        $resultado = $connection
            ->executeQuery(
                'SELECT 1 FROM idempotencia WHERE clave = :clave',
                ['clave' => $clave],
            )
            ->fetchOne();

        // false → no existe. Cualquier otro valor (1) → existe.
        return $resultado !== false;
    }

    /**
     * {@inheritDoc}
     */
    public function registrar(string $clave): void
    {
        $connection = $this->entityManager->getConnection();

        // INSERT ... ON CONFLICT DO NOTHING:
        //   - PostgreSQL específico (compatible con nuestra BD)
        //   - Si `clave` ya existe (UNIQUE), la operación es un no-op
        //   - Evita la carrera entre `existe()` y `insert()` en concurrencia
        $connection->executeStatement(
            'INSERT INTO idempotencia (clave, procesado_en)
             VALUES (:clave, :procesadoEn)
             ON CONFLICT (clave) DO NOTHING',
            [
                'clave' => $clave,
                'procesadoEn' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }
}