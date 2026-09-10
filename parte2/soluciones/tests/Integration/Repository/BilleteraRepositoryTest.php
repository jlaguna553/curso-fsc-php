<?php
// tests/Integration/Repository/BilleteraRepositoryTest.php — Tests de integración
//
# TEST DE INTEGRACIÓN: Verifica que el Repository funcione con la BD real.
# A diferencia de los tests unitarios, estos tests:
#   1. Conectan a una base de datos real (SQLite en testing)
#   2. Ejecutan queries SQL reales
#   3. Persisten y recuperan datos
#   4. Verifican que el ORM funcione correctamente
#
# ¿Por qué SQLite para testing y no PostgreSQL?
#   - SQLite es IN-MEMORY: no necesita servidor, es ultrarrápido
#   - Cada test crea una BD nueva: aislamiento total
#   - La sintaxis SQL es casi idéntica para operaciones básicas
#   - Para tests de integración, la velocidad es más importante que
#     la compatibilidad exacta de PostgreSQL

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Domain\Billetera;
use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Infrastructure\Repository\BilleteraRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Tests de integración del BilleteraRepository.
 *
 * KernelTestCase: crea el kernel completo de Symfony (container, services, etc.)
 * Pero NO abre un servidor web (no necesitamos HTTP).
 */
final class BilleteraRepositoryTest extends KernelTestCase
{
    private ?EntityManager $entityManager = null;
    private ?BilleteraRepository $repository = null;

    /**
     * setUp: se ejecuta ANTES de CADA test.
     *
     * Crea una base de datos SQLite en memoria y configura Doctrine
     * para usarla durante el test.
     */
    protected function setUp(): void
    {
        //bootKernel(): inicia el kernel de Symfony.
        // Esto carga el container de servicios, la configuración, etc.
        $kernel = self::bootKernel();

        // Obtener el EntityManager del container.
        // Doctrine_registry es el servicio que Doctrine provee.
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        // Obtener el repositorio del container.
        // Symfony crea automáticamente el repositorio porque
        // está registrado en services.yaml.
        $this->repository = $this->entityManager
            ->getRepository(Billetera::class);

        // Crear las tablas en la BD de testing.
        // SchemaTool genera el SQL desde el mapping de Doctrine.
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()
            ->getAllMetadata();
        $schemaTool->createSchema($metadata);
    }

    /**
     * tearDown: se ejecuta DESPUÉS de CADA test.
     *
     * Limpia el EntityManager para evitar datos entre tests.
     */
    protected function tearDown(): void
    {
        if ($this->entityManager !== null) {
            // close(): cierra el EntityManager y libera recursos.
            $this->entityManager->close();
        }

        // parent::tearDown(): método del padre que limpia el kernel.
        parent::tearDown();
    }

    /**
     * Test: guardar y recuperar una billetera.
     *
     * Flujo:
     *   1. Crear billetera de dominio
     *   2. Guardar con el repository
     *   3. Recuperar por ID
     *   4. Verificar que los datos son idénticos
     */
    public function test_guardar_y_recuperar_billetera(): void
    {
        // ARRANGE: crear billetera de dominio
        $billetera = new Billetera(
            id: 'test-uuid-001',
            usuarioId: 'user_test',
            moneda: Moneda::MXN,
        );

        // ACT: guardar en la base de datos
        $this->repository->save($billetera);

        // Limpiar el EntityManager para forzar lectura desde la BD.
        // Sin esto, Doctrine podría retornar la misma instancia
        // en memoria en lugar de leer de la BD.
        $this->entityManager->clear();

        // Recuperar la billetera por ID
        $recuperada = $this->repository->findById('test-uuid-001');

        // ASSERT: verificar que los datos son correctos
        $this->assertNotNull($recuperada);
        $this->assertEquals('test-uuid-001', $recuperada->id());
        $this->assertEquals('user_test', $recuperada->usuarioId());
        $this->assertEquals(Moneda::MXN, $recuperada->moneda());
        // Balance debe ser 0 (sin movimientos)
        $this->assertEquals(0, $recuperada->balance()->centavos());
    }

    /**
     * Test: guardar billetera con depósito y verificar saldo.
     *
     * Este test verifica que Doctrine persista correctamente
     * los movimientos de la billetera.
     */
    public function test_guardar_billetera_con_deposito(): void
    {
        // Crear billetera
        $billetera = new Billetera(
            id: 'test-uuid-002',
            usuarioId: 'user_test',
            moneda: Moneda::MXN,
        );

        // Realizar un depósito
        $billetera->depositar(
            Dinero::crear('500.00', Moneda::MXN),
            'Test depósito',
        );

        // Guardar
        $this->repository->save($billetera);

        // Limpiar y recuperar
        $this->entityManager->clear();
        $recuperada = $this->repository->findById('test-uuid-002');

        // Verificar que el balance se guardó correctamente
        $this->assertNotNull($recuperada);
        $this->assertEquals(50000, $recuperada->balance()->centavos());
        // Verificar que hay 1 movimiento
        $this->assertEquals(1, $recuperada->totalMovimientos());
    }

    /**
     * Test: contar billeteras.
     */
    public function test_contar_billeteras(): void
    {
        // Guardar 2 billeteras
        $this->repository->save(new Billetera(
            id: 'test-uuid-003',
            usuarioId: 'user_a',
            moneda: Moneda::MXN,
        ));
        $this->repository->save(new Billetera(
            id: 'test-uuid-004',
            usuarioId: 'user_b',
            moneda: Moneda::USD,
        ));

        // Verificar el conteo
        $total = $this->repository->countAll();
        $this->assertEquals(2, $total);
    }

    /**
     * Test: buscar billeteras por usuario.
     */
    public function test_buscar_por_usuario(): void
    {
        // Guardar 2 billeteras del mismo usuario
        $this->repository->save(new Billetera(
            id: 'test-uuid-005',
            usuarioId: 'user_multi',
            moneda: Moneda::MXN,
        ));
        $this->repository->save(new Billetera(
            id: 'test-uuid-006',
            usuarioId: 'user_multi',
            moneda: Moneda::USD,
        ));

        // Buscar por usuario
        $billeteras = $this->repository->findByUsuario('user_multi');

        // Verificar que retorna 2 billeteras
        $this->assertCount(2, $billeteras);
    }

    /**
     * Test: retorna null cuando no existe la billetera.
     */
    public function test_retorna_null_cuando_no_existe(): void
    {
        $resultado = $this->repository->findById('id_inexistente');
        $this->assertNull($resultado);
    }
}
