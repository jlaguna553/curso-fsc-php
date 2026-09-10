<?php
// tests/Functional/BilleteraControllerTest.php — Tests funcionales de la API
//
# TEST FUNCIONAL: Ejecuta la aplicación COMPLETA (HTTP → Controller → Service → DB → Response).
# Simula peticiones HTTP reales y verifica la respuesta.
#
# ¿Qué lo diferencia de un test de integración?
#   - Test de integración: llama al servicio directamente (sin HTTP)
#   - Test funcional: simula una petición HTTP completa
#
# ¿Qué lo diferencia de un test E2E?
#   - Test E2E: usa un navegador real (Playwright, Selenium)
#   - Test funcional: usa WebTestCase de Symfony (sin navegador)
#
# En Symfony, usamos WebTestCase que:
#   1. Crea el kernel de la aplicación
#   2. Crea un cliente HTTP (simula requests)
#   3. Ejecuta la petición y retorna la respuesta
#   4. Nos permite asertar sobre la respuesta (status, headers, body)

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests funcionales del BilleteraController.
 *
 * Cada test simula una petición HTTP y verifica:
#   1. Código de estado HTTP (200, 201, 400, 404, etc.)
#   2. Headers de respuesta
#   3. Contenido del body JSON
#   4. Efectos secundarios (datos en la BD)
 */
final class BilleteraControllerTest extends WebTestCase
{
    /**
     * Cliente HTTP de Symfony.
     * Simula un navegador/cliente API sin abrir un socket real.
     * Se crea UNA vez por test (setUp) y se limpia después (tearDown).
     */
    private KernelBrowser $client;

    /**
     * setUp: se ejecuta ANTES de CADA test.
     * Aquí preparamos el entorno de testing.
     *
     * Cada test tiene su propia instancia del cliente.
     * Esto garantiza aislamiento: un test no afecta a otro.
     */
    protected function setUp(): void
    {
        // createClient(): crea un cliente HTTP de testing.
        // Este cliente NO abre un socket real (no necesita servidor web).
        // Ejecuta el kernel directamente en memoria.
        $this->client = static::createClient();
    }

    /**
     * Test: GET /salud retorna estado "ok".
     *
     * Este es el test más básico: verificar que el health check funciona.
     * Si este test falla, hay un problema fundamental con el servidor.
     */
    public function test_salud_returns_ok(): void
    {
        // request(): simula una petición HTTP.
        // Primer parámetro: método HTTP (GET, POST, PUT, DELETE)
        // Segundo parámetro: URI (la ruta del endpoint)
        $this->client->request('GET', '/salud');

        // Fancybox client->getResponse(): obtiene la respuesta HTTP.
        $response = $this->client->getResponse();

        // assertResponseStatusCodeSame(): verifica el código de status.
        // 200 OK: la petición fue exitosa.
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        // assertResponseHeaderSame(): verifica un header específico.
        // Content-Type debe ser application/json.
        $this->assertResponseHeaderSame(
            'content-type',
            'application/json'
        );

        // getResponseContent(): retorna el body como string.
        // json_decode(): convierte el string JSON a un array PHP.
        $contenido = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // assertArrayHasKey(): verifica que el array tenga cierta clave.
        $this->assertArrayHasKey('estado', $contenido);
        // assertEquals(): compara que el valor sea exactamente el esperado.
        $this->assertEquals('ok', $contenido['estado']);
    }

    /**
     * Test: POST /api/v1/billeteras crea una billetera nueva.
     *
     * Este test verifica el flujo completo:
     *   1. Enviar petición POST con datos
     *   2. Recibir 201 Created
     *   3. Verificar que el ID se generó
     *   4. Verificar que el saldo es 0
     */
    public function test_crear_billetera(): void
    {
        // Datos de la petición.
        // En Symfony, pasar un array como 3er argumento de request()
        // lo convierte automáticamente a JSON y agrega Content-Type header.
        $datos = [
            'usuario_id' => 'user_test_001',
            'moneda' => 'MXN',
        ];

        // POST request con JSON body
        $this->client->request(
            method: 'POST',
            uri: '/api/v1/billeteras',
            // parameters: datos del body (Symfony los serializa a JSON)
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: json_encode($datos, JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        // Verificar 201 Created
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Verificar header Location
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $contenido = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Verificar que se generó un ID
        $this->assertArrayHasKey('id', $contenido);
        $this->assertNotEmpty($contenido['id']);

        // Verificar que el saldo es "0.00"
        $this->assertEquals('0.00', $contenido['saldo']);

        // Verificar que la moneda es MXN
        $this->assertEquals('MXN', $contenido['moneda']);

        // Verificar que el usuario_id coincide
        $this->assertEquals('user_test_001', $contenido['usuario_id']);
    }

    /**
     * Test: POST /api/v1/billeteras sin campos requeridos retorna 400.
     *
     * Verifica que la validación funcione correctamente.
     */
    public function test_crear_billetera_campos_faltantes(): void
    {
        // Enviar petición SIN campos requeridos
        $this->client->request(
            method: 'POST',
            uri: '/api/v1/billeteras',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            // Body vacío: no hay campos requeridos
            content: json_encode([], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        // Verificar 400 Bad Request
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $contenido = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Verificar que la respuesta tiene error con código
        $this->assertArrayHasKey('error', $contenido);
        $this->assertEquals('CAMPOS_REQUERIDOS', $contenido['error']['codigo']);
    }

    /**
     * Test: GET /api/v1/billeteras/{id} retorna 404 si no existe.
     */
    public function test_obtener_billetera_no_existe(): void
    {
        // ID inexistente
        $this->client->request('GET', '/api/v1/billeteras/id_inexistente');

        $response = $this->client->getResponse();

        // Verificar 404 Not Found
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
