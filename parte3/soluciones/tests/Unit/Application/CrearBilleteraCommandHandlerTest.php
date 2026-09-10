<?php
// tests/Unit/Application/CrearBilleteraCommandHandlerTest.php — Test del use case
//
# TDD: probamos el handler SIN base de datos, SIN Doctrine, SIN RabbitMQ.
# El puerto BilleteraRepository se sustituye por un FAKE (clase anónima).
# Esto prueba que el handler es verdaderamente independiente de la
# infraestructura (arquitectura hexagonal en acción).

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\CommandHandler\CrearBilleteraCommandHandler;
use App\Domain\Billetera;
use App\Domain\Exception\MonedaNoSoportada;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Message\Command\CrearBilleteraCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Verifica el caso de uso "crear billetera" de forma aislada.
 */
final class CrearBilleteraCommandHandlerTest extends TestCase
{
    private array $guardadas = [];
    private MessageBusInterface $eventBus;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake del event bus: captura los eventos despachados sin enviarlos.
        $this->eventBus = new class implements MessageBusInterface {
            public array $despachados = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->despachados[] = $message;

                return new Envelope($message);
            }
        };
    }

    #[Test]
    public function crea_billetera_y_retorna_id(): void
    {
        // Prepara un repositorio fake que guarda en memoria.
        $repositorio = $this->repositorioFake();

        // GIVEN: un command para crear una billetera MXN de la usuaria "ana".
        $command = CrearBilleteraCommand::crear(
            usuarioId: 'ana',
            moneda: 'MXN',
        );

        // WHEN: se ejecuta el handler.
        $handler = new CrearBilleteraCommandHandler($repositorio, $this->eventBus);
        $id = $handler($command);

        // THEN: se retornó un ID no vacío.
        self::assertNotEmpty($id);

        // THEN: la billetera fue persistida con los datos correctos.
        self::assertCount(1, $this->guardadas);
        $billeteraGuardada = $this->guardadas[0];
        self::assertSame('ana', $billeteraGuardada->usuarioId);
        self::assertSame(Moneda::MXN, $billeteraGuardada->moneda);
        self::assertSame($id, $billeteraGuardada->id);
    }

    #[Test]
    public function publica_evento_billetera_creada(): void
    {
        // WHEN: se ejecuta el handler.
        $handler = new CrearBilleteraCommandHandler(
            $this->repositorioFake(),
            $this->eventBus,
        );
        $handler(CrearBilleteraCommand::crear('bob', 'USD'));

        // THEN: se publicó exactamente UN evento de tipo BilleteraCreada.
        self::assertCount(1, $this->eventBus->despachados);
        $evento = $this->eventBus->despachados[0];

        self::assertInstanceOf(\App\Message\Event\BilleteraCreadaEvent::class, $evento);
        self::assertSame('USD', $evento->moneda);
        self::assertSame('bob', $evento->usuarioId);
        self::assertSame('billetera.creada', $evento->eventType);
    }

    #[Test]
    public function rechaza_moneda_no_soportada(): void
    {
        // EXPECT: moneda BTC lanza excepción de dominio.
        $this->expectException(MonedaNoSoportada::class);

        $handler = new CrearBilleteraCommandHandler(
            $this->repositorioFake(),
            $this->eventBus,
        );
        $handler(CrearBilleteraCommand::crear('carol', 'BTC'));
    }

    /**
     * Repositorio fake en memoria (implementa el puerto de dominio).
     *
     * @return BilleteraRepository Puerto fake
     */
    private function repositorioFake(): BilleteraRepository
    {
        return new class($this->guardadas) implements BilleteraRepository {
            public function __construct(private array &$storage)
            {
            }

            public function save(Billetera $billetera): void
            {
                // Guardamos por referencia con el ID como clave.
                $this->storage[$billetera->id] = $billetera;
            }

            public function findById(string $id): ?Billetera
            {
                return $this->storage[$id] ?? null;
            }
        };
    }
}