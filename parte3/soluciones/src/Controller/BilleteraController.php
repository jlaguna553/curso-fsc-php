<?php
// src/Controller/BilleteraController.php — Controller API v3 (CQRS)
//
# Modificado en la Parte 3: el controller ya NO toca repositorios ni
# servicios de aplicación directamente. Ahora es un TRANSLATOR:
#   HTTP JSON → Command/Query → bus → handler → resultado → HTTP JSON
#
# Responsabilidades que CONSERVA:
#   - Parsear el request (validar tipos básicos)
#   - Mapear a Command/Query
#   - Traducir resultados a respuestas HTTP (201, 200, 400, 404)
#
# Responsabilidades que CEDE:
#   - Reglas de negocio → dominio (Billetera)
#   - Persistencia → repositorios (infraestructura)
#   - Validación profunda → middlewares/handlers

declare(strict_types=1);

namespace App\Controller;

use App\Application\Exception\BilleteraNoEncontrada;
use App\Domain\Exception\FondosInsuficientes;
use App\Domain\Exception\MonedaNoSoportada;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\QueryBus;
use App\Message\Command\CrearBilleteraCommand;
use App\Message\Command\RealizarDepositoCommand;
use App\Message\Query\ObtenerBilleteraQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * API REST de billeteras sobre buses CQRS.
 */
final class BilleteraController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
    ) {
    }

    /**
     * POST /api/v1/billeteras — Crear una billetera.
     *
     * @param Request $request Request HTTP
     *
     * @return JsonResponse 201 con la billetera creada
     */
    #[Route('/api/v1/billeteras', name: 'billetera_crear', methods: ['POST'])]
    public function crear(Request $request): JsonResponse
    {
        // 1. Leer el body JSON como array asociativo.
        //    json_decode con true → array; si el body es inválido → null.
        $datos = json_decode($request->getContent(), true);

        // 2. Validación de contrato MÍNIMA en el controller:
        //    el resto de la validación vive en handlers/dominio.
        if (!is_array($datos)
            || empty($datos['usuario_id'])
            || empty($datos['moneda'])
        ) {
            return new JsonResponse(
                [
                    'error' => 'Solicitud inválida',
                    'detalle' => 'Campos requeridos: usuario_id (string), moneda (string)',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            // 3. Construir el command y despacharlo en el bus de comandos.
            //    HandleTrait nos devuelve la ID de la billetera creada.
            $billeteraId = $this->commandBus->dispatch(
                CrearBilleteraCommand::crear(
                    usuarioId: $datos['usuario_id'],
                    moneda: strtoupper($datos['moneda']),
                ),
            );
        } catch (MonedaNoSoportada $e) {
            // 4b. Dominio rechazó la moneda → 400 con detalle legible.
            return new JsonResponse(
                ['error' => 'Moneda no soportada', 'detalle' => $e->getMessage()],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // 4. Éxito → 201 Created con Location + ID.
        return new JsonResponse(
            ['id' => $billeteraId],
            Response::HTTP_CREATED,
            ['Location' => "/api/v1/billeteras/{$billeteraId}"],
        );
    }

    /**
     * POST /api/v1/billeteras/{id}/deposito — Depositar saldo.
     *
     * @param string  $id      ID de la billetera (ruta)
     * @param Request $request Request HTTP
     *
     * @return JsonResponse 200 con el balance actualizado
     */
    #[Route('/api/v1/billeteras/{id}/deposito', name: 'billetera_depositar', methods: ['POST'])]
    public function depositar(string $id, Request $request): JsonResponse
    {
        $datos = json_decode($request->getContent(), true);

        if (!is_array($datos)
            || !isset($datos['monto'])
            || !isset($datos['moneda'])
        ) {
            return new JsonResponse(
                [
                    'error' => 'Solicitud inválida',
                    'detalle' => 'Campos requeridos: monto (string), moneda (string), '
                        . 'opcional: descripcion, idempotency_key',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            // Despachar el command; retorna el balance NUEVO como string.
            $balanceNuevo = $this->commandBus->dispatch(
                RealizarDepositoCommand::crear(
                    billeteraId: $id,
                    monto: (string) $datos['monto'],
                    moneda: strtoupper((string) $datos['moneda']),
                    descripcion: $datos['descripcion'] ?? 'Depósito API',
                    idempotencyKey: $datos['idempotency_key'] ?? '',
                ),
            );
        } catch (BilleteraNoEncontrada $e) {
            return new JsonResponse(
                ['error' => 'Billetera no encontrada', 'detalle' => $e->getMessage()],
                Response::HTTP_NOT_FOUND,
            );
        } catch (FondosInsuficientes|\InvalidArgumentException $e) {
            // FondosInsuficientes podría darse en retiros; aquí por robustez.
            // InvalidArgumentException: monto cero, moneda distinta, formato malo.
            return new JsonResponse(
                ['error' => 'Operación rechazada', 'detalle' => $e->getMessage()],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return new JsonResponse(
            ['balance_nuevo' => $balanceNuevo],
        );
    }

    /**
     * GET /api/v1/billeteras/{id} — Obtener billetera.
     *
     * @param string $id ID de la billetera (ruta)
     *
     * @return JsonResponse 200 con el snapshot
     */
    #[Route('/api/v1/billeteras/{id}', name: 'billetera_obtener', methods: ['GET'])]
    public function obtener(string $id): JsonResponse
    {
        // Solo lectura: resultado del query handler (array snapshot).
        $snapshot = $this->queryBus->ask(new ObtenerBilleteraQuery($id));

        if ($snapshot === null) {
            return new JsonResponse(
                ['error' => 'Billetera no encontrada'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse($snapshot);
    }
}