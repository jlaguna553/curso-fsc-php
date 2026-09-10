<?php
// src/Infrastructure/Repository/BilleteraRepository.php — Adapter de Doctrine (Parte 3)
//
# MODIFICADO en la Parte 3: ahora `implements` el puerto de dominio.
# La Parte 2 lo dejaba como clase libre; aquí formalizamos el contrato.
#
# Diferencia conceptual:
#   - Antes:  el controller usaba esta clase concreta (acoplamiento)
#   - Ahora:  el handler usa App\Domain\Repository\BilleteraRepository (interfaz)
#             y Symfony inyecta esta implementación automáticamente (autowiring)
#
# Patrón ADAPTER: convierte la API de Doctrine en la API que el dominio espera.

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Billetera;
use App\Domain\Repository\BilleteraRepository as BilleteraRepositoryPort;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Implementación concreta del puerto BilleteraRepository usando Doctrine ORM.
 *
 * @extends ServiceEntityRepository<Billetera>
 */
final class BilleteraRepository extends ServiceEntityRepository implements BilleteraRepositoryPort
{
    /**
     * @param ManagerRegistry $registry Registry de Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Billetera::class);
    }

    /**
     * Guarda una billetera (INSERT o UPDATE según tenga ID o no).
     *
     * @param Billetera $billetera La billetera a persistir
     */
    public function save(Billetera $billetera): void
    {
        $this->getEntityManager()->persist($billetera);
        $this->getEntityManager()->flush();
    }

    /**
     * Busca una billetera por su ID UUID.
     *
     * @param string $id ID de la billetera
     *
     * @return Billetera|null La billetera encontrada o null
     */
    public function findById(string $id): ?Billetera
    {
        return $this->find($id);
    }
}