<?php
// src/Domain/Repository/BilleteraRepository.php — PUERTO (interfaz) de persistencia
//
# En arquitectura hexagonal, los PUERTOS (ports) definen los contratos
# que la capa de aplicación necesita de la infraestructura.
#
# Aquí NO hay implementación. Solo la firma de los métodos.
# La implementación concreta vive en Infrastructure\Repository.
#
# ¿Por qué el puerto vive en src/Domain/ y la implementación en src/Infrastructure/?
# Porque la Regla de Dependencia dice: "las dependencias apuntan HACIA DENTRO".
#
#   Capa Aplicación (use cases / handlers)  → usa el PUERTO
#   Capa Infraestructura (Doctrine)         → implementa el PUERTO
#
# Ventajas:
#   1. Los use cases no saben si los datos viven en PostgreSQL, MongoDB o memoria
#   2. Los tests unitarios pueden usar un puerto FALSO (in-memory) sin BD
#   3. Cambiar de ORM no toca la capa de aplicación

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Billetera;

/**
 * Contrato de persistencia para el agregado Billetera.
 *
 * Cualquier clase que `implements` esta interfaz ES un repositorio
 * de billeteras, sin importar la tecnología detrás.
 */
interface BilleteraRepository
{
    /**
     * Guarda (inserta o actualiza) una billetera.
     *
     * @param Billetera $billetera La billetera a persistir
     */
    public function save(Billetera $billetera): void;

    /**
     * Busca una billetera por su ID.
     *
     * @param string $id ID UUID de la billetera
     *
     * @return Billetera|null La billetera o null si no existe
     */
    public function findById(string $id): ?Billetera;
}