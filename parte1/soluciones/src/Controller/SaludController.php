<?php
// src/Controller/SaludController.php — Controller de salud del servicio
//
// HEALTH CHECK: Endpoint que verifica que el servicio está operativo.
// Los load balancers y orquestadores (Kubernetes, Docker) consultan
// este endpoint periódicamente para saber si el servicio está vivo.
//
// Patrón "Thin Controller":
//   - El controller NO contiene lógica de negocio
//   - Solo recibe la petición, delega al dominio, y retorna respuesta
//   - En este caso, "no hacer nada" ya es la lógica (el servicio responde)

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller de salud del wallet-service.
 *
 * Hereda de AbstractController para acceder a helpers de Symfony
 * (como json(), response(), etc.) sin crearlos manualmente.
 *
 * ¿Por qué heredar de AbstractController?
 * Porque provee métodos útiles:
 *   - json(): crea JsonResponse automáticamente
 *   - render(): renderiza plantillas Twig
 *   - redirect(): redirecciones HTTP
 *   - addFlash(): mensajes flash para sesiones
 *
 * Para servicios API puros, json() es el método más usado.
 */
class SaludController extends AbstractController
{
    /**
     * GET /salud — Health check del servicio.
     *
     * Ruta definida con el atributo Route (PHP 8+).
     * Symfony 7+ usa Attributes en lugar de anotaciones YAML/docblock.
     *
     * @return JsonResponse Estado del servicio en formato JSON
     *
     * @Route("/salud", name="api_salud", methods={"GET"})
     *
     * Ejemplo de petición:
     *   GET /salud
     *   Accept: application/json
     *
     * Ejemplo de respuesta:
     *   200 OK
     *   {
     *     "servicio": "wallet-service",
     *     "estado": "ok",
     *     "version": "1.0.0",
     *     "timestamp": "2026-09-10T15:30:00+00:00"
     *   }
     */
    #[Route('/salud', name: 'api_salud', methods: ['GET'])]
    public function salud(): JsonResponse
    {
        // El atributo #[Route] le dice a Symfony:
        //   "Cuando llegue GET /salud, ejecuta este método"
        //
        // El método retorna JsonResponse directamente.
        // Symfony convierte el array a JSON y agrega el header Content-Type automáticamente.
        //
        // El parámetro true del json() indentationa el JSON para debugging.
        // En producción, pasaría false para ahorrar bytes de red.

        return $this->json(
            data: [
                // Nombre del servicio — identifica qué microservicio respondió.
                // En un ecosistema con 10 servicios, esto es esencial para debugging.
                'servicio' => 'wallet-service',

                // Estado del servicio — lo que Kubernetes/docker-compose usa
                // para decidir si el contenedor sigue vivo.
                // "ok" = todo funciona, "degraded" = parcialmente caído.
                'estado' => 'ok',

                // Versión del servicio — para verificar deploy correctamente.
                // En CI/CD, esta versión debería matchear el tag de Git del deploy.
                'version' => '1.0.0',

                // Timestamp en ISO 8601 — para verificar que el servicio
                // no tiene problemas de reloj/sync.
                'timestamp' => (new \DateTimeImmutable())->format('c'),
            ],
            // JSON_PRETTY_PRINT: indentationa el JSON con espacios.
            // Útil en desarrollo. En producción, usar false para performance.
            json: true,
        );
    }

    /**
     * GET /salud/readiness — Readiness probe para Kubernetes.
     *
     * Diferente del health check básico:
     *   - /salud = "¿estás vivo?" (liveness probe)
     *   - /salud/readiness = "¿puedes recibir tráfico?" (readiness probe)
     *
     * En Kubernetes:
     *   - livenessProbe: si falla → reinicia el contenedor
     *   - readinessProbe: si falla → deja de enviarle tráfico (pero no reinicia)
     *
     * Por ahora retornamos "listo" siempre. En la Parte 2, cuando conectemos
     * PostgreSQL, verificaremos la conexión a la base de datos aquí.
     *
     * @return JsonResponse Estado de readiness
     */
    #[Route('/salud/readiness', name: 'api_salud_readiness', methods: ['GET'])]
    public function readiness(): JsonResponse
    {
        return $this->json(
            data: [
                'servicio' => 'wallet-service',
                'readiness' => 'ready',
                'dependencias' => [
                    // Cada dependencia se verifica individualmente.
                    // Si alguna falla, readiness = "not_ready".
                    'base_datos' => 'pending', // Parte 2: se verificará conexión
                    'rabbitmq' => 'pending',   // Parte 3: se verificará conexión
                ],
            ],
            json: true,
        );
    }
}
