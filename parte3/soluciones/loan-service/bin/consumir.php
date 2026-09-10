<?php
// loan-service/bin/consumir.php — Punto de entrada del worker
//
# Este script es el "bin/console" del loan-service: conecta las piezas
# y arranca el bucle de consumo. Se ejecuta con:
#   docker compose up loan-service
# (Dockerfile ejecuta este archivo como ENTRYPOINT)

declare(strict_types=1);

// Cargar el autoloader de Composer (lo instala `composer install`).
require __DIR__ . '/../vendor/autoload.php';

use LoanService\Application\EvaluadorElegibilidad;
use LoanService\Consumer\ConsumidorTransacciones;
use LoanService\Infrastructure\EventosProcesadosRepository;

// ----------------------------------------------------------------------
// CONFIGURACIÓN por variables de entorno (12-factor app).
// Cada una tiene un valor por defecto razonable para desarrollo local.
// ----------------------------------------------------------------------
$rabbitHost = getenv('RABBITMQ_HOST') ?: 'localhost';
$rabbitPort = (int) (getenv('RABBITMQ_PORT') ?: 5672);
$rabbitUser = getenv('RABBITMQ_USER') ?: 'prestaflow';
$rabbitPass = getenv('RABBITMQ_PASSWORD') ?: 'secret';

$dbDsn = getenv('LOAN_DB_DSN') ?: 'pgsql:host=localhost;port=5432;dbname=prestaflow_loan';
$dbUser = getenv('LOAN_DB_USER') ?: 'prestaflow';
$dbPass = getenv('LOAN_DB_PASSWORD') ?: 'secret';

// ----------------------------------------------------------------------
// WIRING: construir las dependencias a mano (sin contenedor de servicios).
// Un microservicio pequeño no necesita Symfony: PDO + amqplib + lógica pura.
// ----------------------------------------------------------------------

// Logger simple a STDOUT con timestamp (suficiente para el curso).
$logger = new class implements Psr\Log\LoggerInterface {
    public function info(string $message, array $context = []): void
    {
        printf("[%s] INFO  %s%s", date('c'), $message, PHP_EOL);
    }

    public function error(string $message, array $context = []): void
    {
        printf("[%s] ERROR %s%s", date('c'), $message, PHP_EOL);
    }

    public function debug(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function critical(string $message, array $context = []): void {}
    public function alert(string $message, array $context = []): void {}
    public function emergency(string $message, array $context = []): void {}
    public function log($level, string|\Stringable $message, array $context = []): void {}
};

// Conexión PDO a la BD de resultados (PostgreSQL del loan-service).
$pdo = new PDO($dbDsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // falla fuerte, no silenciosa
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Ensamblar el consumidor con sus dependencias reales.
$consumidor = new ConsumidorTransacciones(
    evaluador: new EvaluadorElegibilidad(),
    procesados: new EventosProcesadosRepository($pdo),
    logger: $logger,
);

$logger->info('Arrancando loan-service...');

// Bucle infinito (bloquea el proceso).
$consumidor->ejecutar(
    host: $rabbitHost,
    port: $rabbitPort,
    user: $rabbitUser,
    password: $rabbitPass,
    vhost: '/',
);