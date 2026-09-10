<?php
// src/Domain/Billetera.orm.php — Mapping de Doctrine para Billetera Entity
//
# Doctrine Mapping: define cómo se mapea una clase PHP a una tabla SQL.
#
# Usamos "XML mapping" en lugar de atributos porque:
#   1. La lógica de dominio queda separada de la persistencia
#   2. El dominio NO importa Doctrine (clean architecture)
#   3. Es más fácil de mantener en proyectos grandes
#
# Nota: Creamos una entity separada (BilleteraEntity) en Infrastructure/
# que mapea la tabla, y el dominio Billetera se mantiene puro.
# Esto es el "Repository Pattern" en arquitectura hexagonal.

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Mapping;

use Doctrine\ORM\Mapping as ORM;
use App\Domain\Billetera;

/**
 * Mapping de Doctrine para la tabla billetera.
 *
 * Esta clase NO es una entity de dominio. Es un "wrapper" que Doctrine
 * usa para persistir la Billetera del dominio.
 *
 * Para simplificar el curso, usamos una sola entity que hereda del dominio.
 * En producción, podrías usar un Mapper dedicado.
 */
#[ORM\Entity(repositoryClass: \App\Infrastructure\Repository\BilleteraRepository::class)]
#[ORM\Table(name: 'billetera')]
class BilleteraEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $usuarioId;

    #[ORM\Column(type: 'string', length: 3)]
    private string $moneda;

    #[ORM\Column(type: 'integer')]
    private int $saldoCentavos;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $creadoEn;

    public function getId(): string
    {
        return $this->id;
    }

    public function getUsuarioId(): string
    {
        return $this->usuarioId;
    }

    public function getMoneda(): string
    {
        return $this->moneda;
    }

    public function getSaldoCentavos(): int
    {
        return $this->saldoCentavos;
    }

    public function getCreadoEn(): \DateTimeImmutable
    {
        return $this->creadoEn;
    }
}
