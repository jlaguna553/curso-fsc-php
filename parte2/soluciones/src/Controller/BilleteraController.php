<?php
// src/Controller/BilleteraController.php — API Controller para billeteras
//
# Controller RESTful que expone la API de billeteras.
# Cada endpoint corresponde a un use case de la aplicación.
#
# Patrón "Thin Controller":
#   1. Recibe la petición HTTP
#   2. Extrae parámetros
#   3. Llama al Application Service
#   4. Convierte la respuesta a JSON
#   5. Retorna el status code apropiado
#
# NO contiene lógica de negocio, validación de dominio, ni queries SQL.

declare(strict_types=1);

namespace App\Controller;

use App\Application\Service\CrearBilleteraService;
use App\Application\Service\RealizarDepositoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API de billeteras para PrestaFlow.
 *
 * Endpoints:
 *   POST   /api/v1/billeteras              → Crear billetera
 *   GET    /api/v1/billeteras/{id}         → Consultar billetera
 *   POST   /api/v1/billeteras/{id}/deposito → Realizar depósito
 *   GET    /api/v1/billeteras/{id}/movimientos → Historial de movimientos
 */
class BilleteraController extends AbstractController
{
    /**
     * POST /api/v1/billeteras — Crear una billetera nueva.
     *
     * Request body:
     *   {
     *     "usuario_id": "user_001",
     *     "moneda": "MXN"
     *   }
     *
     * Response 201:
     *   {
     *     "id": "uuid",
     *     "usuario_id": "user_001",
     *     "moneda": "MXN",
     *     "saldo": "0.00",
     *     "creado_en": "2026-09-10T15:30:00+00:00"
     *   }
     *
     * Response 400 (moneda inválida):
     *   { "error": { "codigo": "MONEDA_NO_SOPORTADA", "mensaje": "..." } }
     *
     * Response 409 (ya existe billetera para esta moneda):
     *   { "error": { "codigo": "BILLETERA_DUPLICADA", "mensaje": "..." } }
     */
    #[Route('/api/v1/billeteras', name: 'api_billeteras_crear', methods: ['POST'])]
    public function crear(
        Request $request,
        CrearBilleteraService $service,
    ): JsonResponse {
        try {
            // Decodificar el body JSON de la petición.
            // request->toArray() retorna un array asociativo.
            // Si el body no es JSON válido, retorna un array vacío.
            $datos = $request->toArray();

            // Validar campos requeridos.
            // Si falta alguno, retornar 400 Bad Request.
            if (!isset($datos['usuario_id']) || !isset($datos['moneda'])) {
                return $this->json(
                    data: [
                        'error' => [
                            'codigo' => 'CAMPOS_REQUERIDOS',
                            'mensaje' => 'Los campos "usuario_id" y "moneda" son obligatorios',
                        ],
                    ],
                    status: Response::HTTP_BAD_REQUEST,
                );
            }

            // Ejecutar el use case.
            // El service valida la moneda, genera el ID, y persiste.
            $billetera = $service->ejecutar(
                usuarioId: $datos['usuario_id'],
                monedaCode: $datos['moneda'],
            );

            // Retornar 201 Created con los datos de la billetera.
            // El header Location indica la URL del recurso creado.
            return $this->json(
                data: [
                    'id' => $billetera->id(),
                    'usuario_id' => $billetera->usuarioId(),
                    'moneda' => $billetera->moneda()->value,
                    'saldo' => $billetera->balance()->formateadoDecimal(),
                    'creado_en' => $billetera->creadoEn()->format('c'),
                ],
                status: Response::HTTP_CREATED,
                headers: [
                    // Header Location: URL del recurso recién creado.
                    // El cliente puede usar esta URL para GET, PUT, DELETE.
                    'Location' => "/api/v1/billeteras/{$billetera->id()}",
                ],
            );
        } catch (\InvalidArgumentException $e) {
            // Error de validación de negocio.
            // Determinar el código de error basándose en el mensaje.
            $codigo = str_contains($e->getMessage(), 'ya tiene una billetera')
                ? 'BILLETERA_DUPLICADA'
                : 'MONEDA_NO_SOPORTADA';

            return $this->json(
                data: [
                    'error' => [
                        'codigo' => $codigo,
                        'mensaje' => $e->getMessage(),
                    ],
                ],
                status: $codigo === 'BILLETERA_DUPLICADA'
                    ? Response::HTTP_CONFLICT
                    : Response::HTTP_BAD_REQUEST,
            );
        }
    }

    /**
     * POST /api/v1/billeteras/{id}/deposito — Realizar un depósito.
     *
     * Request body:
     *   {
     *     "monto": "1500.00",
     *     "descripcion": "Transferencia bancaria"
     *   }
     *
     * Response 200:
     *   {
     *     "transaccion": {
     *       "tipo": "deposito",
     *       "monto": "1500.00",
     *       "moneda": "MXN",
     *       "descripcion": "Transferencia bancaria"
     *     },
     *     "balance_anterior": "5000.00",
     *     "balance_nuevo": "6500.00"
     *   }
     */
    #[Route('/api/v1/billeteras/{id}/deposito', name: 'api_billeteras_deposito', methods: ['POST'])]
    public function deposito(
        string $id,
        Request $request,
        RealizarDepositoService $service,
    ): JsonResponse {
        try {
            $datos = $request->toArray();

            // Validar campos requeridos
            if (!isset($datos['monto']) || !isset($datos['descripcion'])) {
                return $this->json(
                    data: [
                        'error' => [
                            'codigo' => 'CAMPOS_REQUERIDOS',
                            'mensaje' => 'Los campos "monto" y "descripcion" son obligatorios',
                        ],
                    ],
                    status: Response::HTTP_BAD_REQUEST,
                );
            }

            // Ejecutar el depósito.
            // El service busca la billetera, valida, deposita, y persiste.
            $resultado = $service->ejecutar(
                billeteraId: $id,
                monto: $datos['monto'],
                monedaCode: $datos['moneda'] ?? $resultado['billetera']->moneda()->value,
                descripcion: $datos['descripcion'],
            );

            return $this->json(
                data: [
                    'transaccion' => [
                        'tipo' => 'deposito',
                        'monto' => $datos['monto'],
                        'moneda' => $resultado['billetera']->moneda()->value,
                        'descripcion' => $datos['descripcion'],
                    ],
                    'balance_anterior' => $resultado['balance_anterior'],
                    'balance_nuevo' => $resultado['balance_nuevo'],
                ],
                status: Response::HTTP_OK,
            );
        } catch (\RuntimeException $e) {
            return $this->json(
                data: [
                    'error' => [
                        'codigo' => 'BILLETERA_NO_ENCONTRADA',
                        'mensaje' => $e->getMessage(),
                    ],
                ],
                status: Response::HTTP_NOT_FOUND,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(
                data: [
                    'error' => [
                        'codigo' => 'DATOS_INVALIDOS',
                        'mensaje' => $e->getMessage(),
                    ],
                ],
                status: Response::HTTP_BAD_REQUEST,
            );
        }
    }

    /**
     * GET /api/v1/billeteras/{id} — Consultar billetera.
     *
     * Response 200:
     *   {
     *     "id": "uuid",
     *     "usuario_id": "user_001",
     *     "moneda": "MXN",
     *     "saldo": "6500.00",
     *     "total_movimientos": 5,
     *     "creado_en": "2026-09-10T15:30:00+00:00"
     *   }
     */
    #[Route('/api/v1/billeteras/{id}', name: 'api_billeteras_obtener', methods: ['GET'])]
    public function obtener(
        string $id,
        \App\Infrastructure\Repository\BilleteraRepository $repository,
    ): JsonResponse {
        $billetera = $repository->findById($id);

        if ($billetera === null) {
            return $this->json(
                data: [
                    'error' => [
                        'codigo' => 'BILLETERA_NO_ENCONTRADA',
                        'mensaje' => "Billetera no encontrada: '{$id}'",
                    ],
                ],
                status: Response::HTTP_NOT_FOUND,
            );
        }

        return $this->json(
            data: [
                'id' => $billetera->id(),
                'usuario_id' => $billetera->usuarioId(),
                'moneda' => $billetera->moneda()->value,
                'saldo' => $billetera->balance()->formateadoDecimal(),
                'total_movimientos' => $billetera->totalMovimientos(),
                'creado_en' => $billetera->creadoEn()->format('c'),
            ],
            status: Response::HTTP_OK,
        );
    }
}
