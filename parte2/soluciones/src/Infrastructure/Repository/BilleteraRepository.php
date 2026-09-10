<?php
// src/Infrastructure/Repository/BilleteraRepository.php — Repositorio de Billetera
//
# REPOSITORY: Capa de persistencia que abstrae la base de datos.
# El dominio NO sabe CÓMO se guardan los datos.
# El Repository es el "translator" entre el dominio y la BD.
#
# Patrón Repository:
#   - El dominio define la INTERFACE (qué operaciones de consulta necesitamos)
#   - La infraestructura define la IMPLEMENTACIÓN (cómo se ejecutan en PostgreSQL)
#   - El controller usa la interface (no conoce la implementación)
#
# Esto es Inversión de Dependencias: el dominio no depende de la BD,
# la BD depende del dominio.

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Billetera;
use App\Domain\Movimiento;
use App\Domain\Moneda;
use App\Domain\Dinero;
use App\Domain\TipoMovimiento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTimeImmutable;

/**
 * Repositorio de Billetera con Doctrine ORM.
 *
 * Hereda de ServiceEntityRepository que provee:
 *   - find(): buscar por ID
 *   - findAll(): buscar todos
 *   - findBy(): buscar con criterios
 *   - persist(): guardar entidad nueva
 *   - flush(): persistir cambios a la BD
 *
 * @extends ServiceEntityRepository<Billetera>
 */
final class BilleteraRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry Registry de Doctrine (container de servicios)
     */
    public function __construct(ManagerRegistry $registry)
    {
        // parent::__construct: inicializa el repositorio con la entity class.
        // Doctrine sabe qué tabla usar basándose en la clase.
        parent::__construct($registry, Billetera::class);
    }

    /**
     * Guarda una billetera nueva en la base de datos.
     *
     * Doctrine workflow:
     *   1. persist(): marca la entidad para persistir (no toca la BD aún)
     *   2. flush(): ejecuta las queries pendientes (INSERT/UPDATE/DELETE)
     *
     * ¿Por qué separar persist y flush?
     *   - Puedes hacer múltiples persist() y un solo flush() (batch)
     *   - El flush es el "commit" de Doctrine
     *   - Si algo falla antes del flush, no se guardó nada
     *
     * @param Billetera $billetera La billetera a guardar
     */
    public function save(Billetera $billetera): void
    {
        // Obtener el EntityManager (el "manager" de Doctrine).
        // El EntityManager coordina todas las operaciones de persistencia.
        $entityManager = $this->getEntityManager();

        // persist(): marca la entidad para persistir.
        // Doctrine calcula automáticamente si es INSERT o UPDATE
        // basándose en si la entidad tiene ID o no.
        $entityManager->persist($billetera);

        // flush(): ejecuta las queries SQL pendientes.
        // Esto SÍ toca la base de datos.
        // Si la billetera tiene movimientos nuevos, Doctrine los guarda también
        // (cascade persist).
        $entityManager->flush();
    }

    /**
     * Busca una billetera por su ID.
     *
     * @param string $id ID de la billetera (UUID)
     * @return Billetera|null La billetera encontrada, o null si no existe
     */
    public function findById(string $id): ?Billetera
    {
        // find(): método genérico de Doctrine.
        // Primer parámetro: ID de la entidad.
        // Doctrine ejecuta: SELECT * FROM billetera WHERE id = ?
        // El resultado se hidrata automáticamente en una instancia de Billetera.
        return $this->find($id);
    }

    /**
     * Busca todas las billeteras de un usuario.
     *
     * @param string $usuarioId ID del usuario propietario
     * @return Billetera[] Array de billeteras del usuario
     */
    public function findByUsuario(string $usuarioId): array
    {
        // findBy(): busca con un criterio simple.
        // Doctrine ejecuta: SELECT * FROM billetera WHERE usuario_id = ?
        // El resultado es un array de entidades hidratadas.
        return $this->findBy(
            criteria: ['usuarioId' => $usuarioId],
            orderBy: ['creadoEn' => 'DESC'],
        );
    }

    /**
     * Cuenta el total de billeteras en el sistema.
     *
     * @return int Número total de billeteras
     */
    public function countAll(): int
    {
        // createQueryBuilder(): crea un query builder DQL.
        // DQL es como SQL pero usa nombres de entities, no tablas.
        //
        // select('COUNT(b.id)'): cuenta el número de IDs (ignora NULLs).
        // from(): desde qué entity (no tabla).
        // getSingleScalarResult(): retorna UN solo valor (el count).
        $queryBuilder = $this->createQueryBuilder('b');

        return (int) $queryBuilder
            ->select('COUNT(b.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
