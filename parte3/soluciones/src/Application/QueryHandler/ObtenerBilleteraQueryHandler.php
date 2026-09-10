<?php
// src/Application/QueryHandler/ObtenerBilleteraQueryHandler.php — Query handler
//
# Los QUERY HANDLERS NUNCA modifican estado. Solo leen y modelan la respuesta.
#
# Si CQRS se lleva al extremo, la lectura ni siquiera toca la BD de escritura:
# se usa un "read model" (proyección) o caché. En este curso, leemos
# del mismo repositorio para mantener la sencillez, PERO la separación
# de buses ya nos permite migrar a un read model sin tocar controllers.
#
# El retorno es un ARRAY (snapshot), no la entidad. ¿Por qué?
#   - La entidad expone comportamiento; el snapshot expone datos
#   - El controller serializa el array directamente a JSON
#   - El consumidor (HTTP client) no puede mutar la entidad

declare(strict_types=1);

namespace App\Application\QueryHandler;

use App\Application\Exception\BilleteraNoEncontrada;
use App\Domain\Repository\BilleteraRepository;
use App\Message\Query\ObtenerBilleteraQuery;

/**
 * Maneja la consulta "dame la billetera X" (solo lectura).
 */
final readonly class ObtenerBilleteraQueryHandler
{
    public function __construct(
        private BilleteraRepository $billeteras,
    ) {
    }

    /**
     * Ejecuta la consulta y devuelve un snapshot serializable.
     *
     * @param ObtenerBilleteraQuery $query La consulta del bus
     *
     * @return array<string, string|int> Snapshot de la billetera
     *
     * @throws BilleteraNoEncontrada Si no existe
     */
    public function __invoke(ObtenerBilleteraQuery $query): array
    {
        $billetera = $this->billeteras->findById($query->billeteraId);

        if ($billetera === null) {
            throw new BilleteraNoEncontrada($query->billeteraId);
        }

        // Modelo el snapshot con las claves EXACTAS que el API expone.
        // El namespace del JSON (camel_case) es el contrato público.
        return [
            'id' => $billetera->id,
            'usuario_id' => $billetera->usuarioId,
            'moneda' => $billetera->moneda->value,
            'balance' => $billetera->balance()->formateadoDecimal(),
            'total_movimientos' => $billetera->totalMovimientos(),
        ];
    }
}