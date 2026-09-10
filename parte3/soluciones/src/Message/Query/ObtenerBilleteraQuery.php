<?php
// src/Message/Query/ObtenerBilleteraQuery.php — Query de CQRS
//
# QUERY: Representa una CONSULTA (lectura) del estado del sistema.
#
# Diferencias con Command:
#   - Command: modifica el estado (INSERT/UPDATE/DELETE)
#   - Query: solo lee (SELECT)
#
# Beneficio clave de separar Commands y Queries:
#   - Commands pueden ser validados y auditados (¿quién hizo esto?)
#   - Queries pueden ser cacheadas (resultado de una query no cambia
#     necesariamente en cada llamada)

declare(strict_types=1);

namespace App\Message\Query;

/**
 * Query: obtener los datos de una billetera por su ID.
 *
 * El QueryHandler retorna un QueryResult con los datos.
 */
final readonly class ObtenerBilleteraQuery
{
    public function __construct(
        public string $billeteraId,
    ) {
    }
}