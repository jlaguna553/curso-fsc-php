---
title: "Soluciones — Parte 1 — DDD y PHP Moderno"
description: "Código fuente de las soluciones de la Parte 1"
sidebar: {"label":"Soluciones","order":100}
---


> 📁 **16 archivos** de código de referencia.

## Makefile

```makefile
# Makefile — Comandos de desarrollo para wallet-service
#
# MAKE: Herramienta de automatización que ejecuta "targets" (objetivos).
# Cada target es un comando que puedes ejecutar con `make <target>`.
#
# ¿Por qué Makefile?
# Porque memorizar comandos largos de PHPUnit, PHPStan, etc. es propenso a errores.
# Con Make, solo necesitas: make test, make stan, make ci.
#
# Uso:
#   make test          → Ejecuta tests unitarios
#   make stan          → Análisis estático con PHPStan
#   make fix           → Auto-formatea código con CS-Fixer
#   make ci            → Ejecuta todo lo anterior (como en CI)
#   make serve         → Inicia servidor de desarrollo
#   make all           → limpiar + ci + serve

# .PHONY le dice a Make que estos targets NO son archivos.
# Sin esto, Make buscaría archivos llamados "test", "stan", etc.
# y si existieran, no ejecutaría el comando.
.PHONY: test stan fix ci serve clean all help

# Variables — evitan repetir rutas largas en cada target.
# El $() de Make reemplaza las variables en tiempo de ejecución.
PHP = php                     # Binario de PHP
PHPUNIT = vendor/bin/phpunit  # Binario de PHPUnit (dentro de vendor/)
PHPSTAN = vendor/bin/phpstan  # Binario de PHPStan
FIXER = vendor/bin/php-cs-fixer  # Binario de PHP-CS-Fixer
SYMFONY = symfony             # CLI de Symfony

# ------------------------------------------------------------------------------
# TARGET: test
# Ejecuta todos los tests del proyecto.
#
# --colors=forced: muestra colores siempre (útil en CI, que no es terminal)
# --testsuite unit: solo ejecuta la suite "unit" (los más rápidos)
# --testdox: muestra resultados en formato legible (verbose, pero claro)
#
# Ejemplo de salida:
#   App\Domain\DineroTest
#    ✓ Money creation from cents                        0.002s
#    ✓ Money addition same currency                     0.001s
#    ✓ Money subtraction insufficient funds             0.001s
#   Tests: 15 passed, 15 total
# ------------------------------------------------------------------------------
test:
	$(PHP) $(PHPUNIT) --colors=forced --testsuite unit --testdox

# ------------------------------------------------------------------------------
# TARGET: stan
# Análisis estático con PHPStan nivel 6.
#
# PHPStan analiza el código SIN ejecutarlo.
# Detecta: tipos incorrectos, argumentos faltantes, nulos no manejados, etc.
#
# Nivel 6 es el equilibrio entre:
#   - Nivel 0: casi nada (no vale la pena)
#   - Nivel 6: cubre la mayoría de bugs comunes (nuestro objetivo)
#   - Nivel 10: máximo, pero demasiado estricto para empezar
#
# --no-progress: no muestra barra de progreso (mejor para CI)
# --ansi: muestra colores ANSI (para terminals que los soporten)
#
# Ejemplo de salida exitosa:
#   [OK] No errors
#
# Ejemplo de salida con errores:
#   ------ --------------------------------------------------------------- 
#   Line   src/Domain/Billetera.php
#   ------ --------------------------------------------------------------- 
#   42     Method Billetera::balance() should return Dinero but returns int.
#   ------ --------------------------------------------------------------- 
# ------------------------------------------------------------------------------
stan:
	$(PHP) $(PHPSTAN) analyse src tests --level=6 --no-progress --ansi

# ------------------------------------------------------------------------------
# TARGET: fix
# Auto-formatea el código con PHP-CS-Fixer.
#
# PHP-CS-Fixer reescribe el código para que cumpla el estándar PSR-12.
# En CI se usa --dry-run (no modifica archivos, solo reporta).
# Aquí en local sí modificamos para convenience.
#
# --verbose: muestra qué reglas aplicó.
# --diff: muestra el diff de cada archivo modificado.
# --allow-risky=yes: permite reglas que pueden cambiar comportamiento
#   (ej: agregar return types, strict_types, etc.)
# ------------------------------------------------------------------------------
fix:
	$(PHP) $(FIXER) fix --verbose --diff --allow-risky=yes

# ------------------------------------------------------------------------------
# TARGET: fix-check
# Verifica si hay código sin formatear (sin modificar archivos).
# Usado en CI: si hay cambios pendientes, CI falla.
#
# --dry-run: NO modifica archivos, solo retorna exit code 1 si hay cambios.
# ------------------------------------------------------------------------------
fix-check:
	$(PHP) $(FIXER) fix --dry-run --diff --allow-risky=yes

# ------------------------------------------------------------------------------
# TARGET: ci
# Ejecuta TODA la pipeline de calidad de código.
# Equivalente a lo que GitHub Actions ejecuta en cada PR.
#
# Secuencia:
#   1. Tests (la cosa más importante: ¿el código funciona?)
#   2. PHPStan (¿el código es类型安全 y no tiene bugs sutiles?)
#   3. CS-Fixer dry-run (¿el código es consistente y legible?)
#
# Si CUALQUIERA falla, `make ci` falla. No se sigue al siguiente paso.
# ------------------------------------------------------------------------------
ci: test stan fix-check
	@echo ""
	@echo "══════════════════════════════════════════"
	@echo "  ✅ CI PASSED — Todos los checks verdes"
	@echo "══════════════════════════════════════════"

# ------------------------------------------------------------------------------
# TARGET: serve
# Inicia el servidor de desarrollo de Symfony.
#
# symfony serve usa PHP built-in server (para desarrollo).
# -d: directorio de document root (public/)
# --no-clean: no limpia la caché antes de iniciar
#
# El servidor escucha en https://127.0.0.1:8000
# Symfony CLI genera un certificado SSL automáticamente.
#
# IMPORTANTE: Este servidor NO es para producción.
# En producción usamos Nginx + PHP-FPM (Parte 0 Lección 0.2).
# ------------------------------------------------------------------------------
serve:
	$(SYMFONY) serve -d public --no-clean

# ------------------------------------------------------------------------------
# TARGET: clean
# Limpia caches del proyecto.
#
# cache:clear: limpia el cache de Symfony (var/cache/)
# Esto es útil después de cambiar configuración (services.yaml, etc.)
# ------------------------------------------------------------------------------
clean:
	$(SYMFONY) cache:clear

# ------------------------------------------------------------------------------
# TARGET: all
# Ejecuta todo: limpiar + calidad + servidor.
# Uso: `make all` para empezar una sesión de desarrollo limpia.
# ------------------------------------------------------------------------------
all: clean ci serve

# ------------------------------------------------------------------------------
# TARGET: help
# Muestra todos los targets disponibles con sus descripciones.
# Uso: `make help`
# ------------------------------------------------------------------------------
help:
	@echo "Comandos disponibles para wallet-service:"
	@echo ""
	@echo "  make test        Ejecutar tests unitarios"
	@echo "  make stan        Análisis estático con PHPStan nivel 6"
	@echo "  make fix         Auto-formatear código"
	@echo "  make ci          Pipeline completa (test + stan + fix-check)"
	@echo "  make serve       Iniciar servidor de desarrollo"
	@echo "  make clean       Limpiar cache de Symfony"
	@echo "  make all         Limpiar + CI + servidor"
	@echo ""

```

## composer.json

```json
{
  "name": "prestaflow/wallet-service",
  "description": "Servicio de billeteras digitales para la plataforma PrestaFlow",
  "type": "project",
  "license": "proprietary",
  "minimum-stability": "stable",
  "prefer-stable": true,
  "require": {
    "php": ">=8.3",
    "ext-ctype": "*",
    "ext-iconv": "*",
    "symfony/console": "7.4.*",
    "symfony/dotenv": "7.4.*",
    "symfony/flex": "^2",
    "symfony/framework-bundle": "7.4.*",
    "symfony/runtime": "7.4.*",
    "symfony/yaml": "7.4.*"
  },
  "require-dev": {
    "phpstan/phpstan": "^2.0",
    "phpstan/phpstan-symfony": "^2.0",
    "php-cs-fixer/shim": "^3.65",
    "phpunit/phpunit": "^11.0",
    "symfony/maker-bundle": "^1.60",
    "symfony/browser-kit": "7.4.*",
    "symfony/css-selector": "7.4.*",
    "symfony/phpunit-bridge": "7.4.*"
  },
  "config": {
    "allow-plugins": {
      "symfony/flex": true,
      "symfony/runtime": true
    },
    "sort-packages": true,
    "platform": {
      "php": "8.3.0"
    }
  },
  "autoload": {
    "psr-4": {
      "App\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "App\\Tests\\": "tests/"
    }
  },
  "scripts": {
    "auto-scripts": {
      "cache:clear": "symfony-cmd",
      "assets:install %PUBLIC_DIR%": "symfony-cmd"
    },
    "post-install-cmd": [
      "@auto-scripts"
    ],
    "post-update-cmd": [
      "@auto-scripts"
    ]
  },
  "extra": {
    "symfony": {
      "allow-contrib": false,
      "require": "7.4.*"
    }
  }
}

```

## public/index.php

```php
<?php
// public/index.php — Punto de entrada HTTP de la aplicación Symfony
//
// Este es el primer archivo que ejecuta el servidor web (Nginx/Apache/PHP).
// Recibe TODAS las peticiones HTTP y las delega al kernel de Symfony.
//
// Flujo:
//   HTTP Request → public/index.php → Symfony Kernel → Router → Controller → Response
//
// ¿Por qué existe este archivo?
// Porque los servidores web (Nginx) necesitan un archivo PHP específico
// para ejecutar. No pueden ejecutar "el framework" directamente.

declare(strict_types=1);

use App\Kernel;

// require_once carga el autoloader de Composer.
// Esto hace disponibles todas las clases del proyecto y de Symfony.
// El path relativo (../vendor/autoload.php) funciona porque
// public/index.php está en /public/ y vendor/ está en la raíz.
require_once dirname(__DIR__).'/vendor/autoload.php';

// El bootstrap de Symfony FrameworkBundle carga el entorno,
// configura el container de servicios, y prepara el kernel.
// El parámetro "debug" controla si se muestran errores detallados.
// En producción (APP_ENV=prod), debug=false para seguridad.
return function (array $context): Kernel {
    // Crear y retornar una instancia del Kernel.
    // El kernel es el corazón de Symfony: coordina routing,
    // container, middlewares, y el ciclo de vida completo.
    return new Kernel(
        environment: $context['APP_ENV'],  // "dev", "test", o "prod"
        debug: $context['APP_DEBUG'],       // true en dev, false en prod
    );
};

```

## src/Controller/SaludController.php

```php
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

```

## src/Domain/Billetera.php

```php
<?php
// src/Domain/Billetera.php — Aggregate Root del dominio de billeteras
//
// AGGREGATE ROOT: Objeto que encapsula un conjunto de entidades/Value Objects
// y garantiza la consistencia de los datos dentro de él.
//
// La Billetera es el Aggregate Root porque:
//   1. Contiene la lista de Movimientos (Value Objects)
//   2. Calcula el balance basándose en los movimientos
//   3. Enforce las reglas de negocio (fondos suficientes, moneda válida)
//   4. Solo se puede acceder/modificar a través de la Billetera
//
// Reglas de negocio implementadas:
//   - No se puede depositar monto cero
//   - No se puede retirar más que el saldo actual
//   - Todos los movimientos deben ser en la misma moneda
//   - El balance se calcula recursivamente desde los movimientos

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\FondosInsuficientes;
use DateTimeImmutable;

/**
 * Billetera digital que mantiene un registro de movimientos financieros.
 *
 * Diseño hexagonal:
 *   - Esta clase NO depende de Symfony, Doctrine, ni ningún framework
 *   - Es puro PHP: se puede testear sin base de datos, HTTP, ni containers
 *   - Se puede copiar a otro proyecto sin dependencias externas
 *
 * Esto es lo que significa "Dominio Rico": la lógica de negocio vive aquí,
 * no en controllers ni en repositorios.
 */
final class Billetera
{
    /**
     * @param string              $id         ID único de la billetera (UUID)
     * @param string              $usuarioId  ID del propietario
     * @param Moneda              $moneda     Moneda de la billetera
     * @param array<Movimiento>   $movimientos Lista de movimientos (registros históricos)
     * @param DateTimeImmutable   $creadoEn   Timestamp de creación
     */
    public function __construct(
        public readonly string $id,
        public readonly string $usuarioId,
        public readonly Moneda $moneda,
        private array $movimientos = [],
        public readonly DateTimeImmutable $creadoEn = new DateTimeImmutable(),
    ) {
        // El array de movimientos se pasa como parámetro con valor default [].
        // Esto permite crear billeteras nuevas (sin movimientos) o reconstruirlas
        // desde base de datos (con movimientos existentes).
        //
        // NOTA: El parámetro $movimientos es private, no readonly.
        // ¿Por qué? Porque necesitamos agregar movimientos internamente.
        // Pero como es private, NINGÚN código externo puede modificarlo
        // directamente. Solo se modifica a través de depositar() y retirar().
    }

    /**
     * Calcula el balance actual de la billetera.
     *
     * El balance se deriva de los movimientos (no se almacena separadamente).
     * Esto garantiza consistencia: si los movimientos cambian, el balance cambia.
     *
     * Fórmula: balance = Σ(depositos) - Σ(retiros)
     *
     * @return Dinero Balance actual de la billetera
     */
    public function balance(): Dinero
    {
        // Empezamos con cero centavos en la moneda de la billetera.
        // Este es nuestro "punto de partida" para la suma.
        $totalCentavos = 0;

        // Iteramos sobre cada movimiento usando un foreach.
        // Cada movimiento puede sumar (depósito) o restar (retiro) al total.
        foreach ($this->movimientos as $movimiento) {
            // tipoMovimiento().esAcreditacion() retorna true para depósitos.
            // Si es depósito, sumamos centavos. Si es retiro, restamos.
            if ($movimiento->tipo->esAcreditacion()) {
                $totalCentavos += $movimiento->monto->centavos();
            } else {
                $totalCentavos -= $movimiento->monto->centavos();
            }
        }

        // Crear un Dinero con el total calculado.
        // NOTA: Si el total es negativo, algo salió mal en las validaciones
        // de retiro. Esto es un "fail fast" adicional.
        return Dinero::desdeCentavos($totalCentavos, $this->moneda);
    }

    /**
     * Registra un depósito en la billetera.
     *
     * Este es un COMMAND METHOD: ejecuta una acción de negocio.
     * Valida reglas de negocio y modifica el estado interno.
     *
     * @param Dinero $monto       Monto a depositar (debe ser positivo)
     * @param string $descripcion Descripción legible del depósito
     *
     * @return Movimiento El movimiento registrado (para auditoría/log)
     *
     * @throws \InvalidArgumentException Si el monto es cero o de moneda incorrecta
     */
    public function depositar(Dinero $monto, string $descripcion): Movimiento
    {
        // REGLA 1: No permitir depósitos de monto cero.
        // Un depósito de $0 no tiene sentido de negocio y puede indicar error.
        if ($monto->centavos() === 0) {
            throw new \InvalidArgumentException(
                "No se puede depositar un monto de cero. Use un monto mayor a cero."
            );
        }

        // REGLA 2: El depósito debe ser en la misma moneda que la billetera.
        // Esto previene errores como depositar USD en una billetera MXN.
        if ($monto->moneda() !== $this->moneda) {
            throw new \InvalidArgumentException(
                "No se puede depositar {$monto->moneda()->value} "
                . "en una billetera de {$this->moneda->value}"
            );
        }

        // Crear el movimiento de depósito.
        // El factory method deposito() establece el tipo automáticamente.
        $movimiento = Movimiento::deposito(
            // uniqid() genera un ID pseudo-único. En producción usaríamos
            // ramsey/uuid o Symfony UID, pero para el curso es suficiente.
            id: uniqid('mov_', more_entropy: true),
            monto: $monto,
            descripcion: $descripcion,
        );

        // Agregar el movimiento al array interno.
        // array_push() agrega al final del array.
        // Como $this->movimientos es private, este es el ÚNICO lugar
        // donde se modifican los movimientos (Single Responsibility).
        $this->movimientos[] = $movimiento;

        // Retornar el movimiento creado para que el caller pueda
        // hacer logging, notificaciones, etc.
        return $movimiento;
    }

    /**
     * Registra un retiro en la billetera.
     *
     * Valida que haya fondos suficientes antes de procesar.
     *
     * @param Dinero $monto       Monto a retirar (debe ser positivo)
     * @param string $descripcion Descripción legible del retiro
     *
     * @return Movimiento El movimiento registrado
     *
     * @throws FondosInsuficientes Si el saldo es menor al monto solicitado
     * @throws \InvalidArgumentException Si el monto es cero o de moneda incorrecta
     */
    public function retirar(Dinero $monto, string $descripcion): Movimiento
    {
        // REGLA 1: No permitir retiros de monto cero.
        if ($monto->centavos() === 0) {
            throw new \InvalidArgumentException(
                "No se puede retirar un monto de cero."
            );
        }

        // REGLA 2: El retiro debe ser en la misma moneda que la billetera.
        if ($monto->moneda() !== $this->moneda) {
            throw new \InvalidArgumentException(
                "No se puede retirar {$monto->moneda()->value} "
                . "de una billetera de {$this->moneda->value}"
            );
        }

        // REGLA 3: Verificar fondos suficientes.
        // Calculamos el balance actual y comparamos con el monto solicitado.
        // Si el balance es menor, lanzamos FondosInsuficientes (excepción de dominio).
        // Esta excepción carry datos (saldo actual, monto solicitado, déficit)
        // para que el controller pueda retornar una respuesta descriptiva.
        $balanceActual = $this->balance();
        if ($balanceActual->esMenorOIgualQue($monto)) {
            throw new FondosInsuficientes(
                saldoActual: $balanceActual,
                montoSolicitado: $monto,
            );
        }

        // Crear el movimiento de retiro.
        $movimiento = Movimiento::retiro(
            id: uniqid('mov_', more_entropy: true),
            monto: $monto,
            descripcion: $descripcion,
        );

        // Agregar al historial.
        $this->movimientos[] = $movimiento;

        return $movimiento;
    }

    /**
     * Retorna el historial completo de movimientos.
     *
     * @return array<Movimiento> Copia del array de movimientos
     */
    public function movimientos(): array
    {
        // Retornamos una copia del array para evitar que el caller
        // modifique el array interno directamente.
        // Sin esto, $billetera->movimientos()[] = $movimiento
        // modificaría el array interno sin pasar por las validaciones.
        return $this->movimientos;
    }

    /**
     * Cuenta el total de movimientos registrados.
     *
     * @return int Número de movimientos
     */
    public function totalMovimientos(): int
    {
        return count($this->movimientos);
    }
}

```

## src/Domain/Dinero.php

```php
<?php
// src/Domain/Dinero.php — Value Object de dinero
//
// VALUE OBJECT: Objeto inmutable identificado por sus valores, no por ID.
// Dos instancias de Dinero con el mismo monto y moneda son equivalentes:
//   Dinero::crear(1500, Moneda::MXN) === Dinero::crear(1500, Moneda::MXN)
//
// ¿Por qué Value Object y no un simple array/string?
//   1. Validez garantizada: no puedes crear Dinero con monto negativo
//   2. Operaciones encapsuladas: sumar, restar, comparar
//   3. Inmutabilidad: modificar el monto crea un NUEVO objeto
//   4. Type safety: no puedes sumar Dinero MXN con Dinero EUR sin conversión
//
// Diseño: Trabajamos internamente en centavos (integer) para evitar
// errores de punto flotante. El usuario ve "1500.00", el sistema almacena 150000.

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\MonedaNoSoportada;

/**
 * Value Object inmutable que representa una cantidad de dinero.
 *
 * Reglas de negocio:
 *   - El monto siempre se almacena en centavos (entero)
 *   - No puede ser negativo (usar FondosInsuficientes para esa validación)
 *   - Las monedas se validan contra el enum Moneda
 *   - La suma de dos Dinero solo funciona si tienen la misma moneda
 */
final readonly class Dinero
{
    /**
     * Constructor privado: solo se puede crear mediante los métodos estáticos
     * crear() o desdeCentavos(). Esto fuerza al consumidor a pasar por la
     * validación de dominio.
     *
     * @param int    $centavos  Cantidad en centavos (entero positivo)
     * @param Moneda $moneda    Moneda del monto
     */
    private function __construct(
        private int $centavos,
        private Moneda $moneda,
    ) {
        // El constructor es privado + validación interna = factory pattern.
        // Nadie puede crear un Dinero inválido sin pasar por crear() o desdeCentavos().
    }

    /**
     * Crea una instancia de Dinero desde un monto decimal (string).
     *
     * Recibe el monto como string para evitar problemas de float:
     *   Dinero::crear('1500.00', Moneda::MXN)  // ✅ Correcto
     *   Dinero::crear(1500.00, Moneda::MXN)     // ❌ Float: impreciso
     *
     * @param string $monto Monto en formato decimal (ej: "1500.00")
     * @param Moneda $moneda Moneda del monto
     *
     * @return self Nueva instancia de Dinero
     *
     * @throws \InvalidArgumentException Si el monto no es un número válido o es negativo
     */
    public static function crear(string $monto, Moneda $moneda): self
    {
        // filter_var valida que el string sea un número flotante válido.
        // Retorna false si no lo es (ej: "abc", "", null).
        $valor = filter_var($monto, FILTER_VALIDATE_FLOAT);

        // Si filter_var retorna false, el input es inválida.
        // La triple negación (!!false) convierte a boolean, pero aquí usamos
        // la comparación explícita para claridad.
        if ($valor === false) {
            throw new \InvalidArgumentException(
                "Monto inválido: '{$monto}'. Se esperaba un número decimal positivo."
            );
        }

        // Un depósito no puede ser negativo. Para retiros negativos,
        // usamos otro método o patrón (esto se verá en la Parte 2).
        if ($valor < 0) {
            throw new \InvalidArgumentException(
                "El monto no puede ser negativo: '{$monto}'. Use DesdeCentavos() con enteros."
            );
        }

        // Convertir a centavos usando round() para evitar:
        //   1.005 * 100 = 100.49999... → 100 (sin round) vs 100 (con round)
        // El resultado de round() es float, por eso castamos a int.
        $centavos = (int) round($valor * 100);

        return new self($centavos, $moneda);
    }

    /**
     * Crea una instancia de Dinero desde centavos (entero).
     *
     * Método alternativo cuando ya se tiene el monto en centavos.
     * Útil para reconstruir objetos desde base de datos.
     *
     * @param int    $centavos Cantidad en centavos
     * @param Moneda $moneda   Moneda del monto
     *
     * @return self Nueva instancia de Dinero
     *
     * @throws \InvalidArgumentException Si los centavos son negativos
     */
    public static function desdeCentavos(int $centavos, Moneda $moneda): self
    {
        if ($centavos < 0) {
            throw new \InvalidArgumentException(
                "Los centavos no pueden ser negativos: {$centavos}"
            );
        }

        return new self($centavos, $moneda);
    }

    /**
     * Retorna el monto en centavos (entero).
     *
     * Este es el valor almacenado internamente.
     * Para mostrar al usuario, usar formateadoDecimal().
     *
     * @return int Cantidad en centavos
     */
    public function centavos(): int
    {
        return $this->centavos;
    }

    /**
     * Retorna el monto en formato decimal (string).
     *
     * Convierte centavos a decimal para visualización:
     *   150000 centavos → "1500.00"
     *
     * ¿Por qué retorna string y no float?
     * Porque el formato "1500.00" es exacto. El float 1500.00 puede
     * ser internamente 1499.99999999... en algunos contextos.
     *
     * @return string Monto en formato decimal con 2 decimales
     */
    public function formateadoDecimal(): string
    {
        // number_format convierte 150000 → "1,500.00" con comas.
        // El segundo parámetro (2) = decimales.
        // El tercer parámetro ('') = separador de miles vacío.
        // El cuarto parámetro ('.') = separador decimal.
        // Nos quedamos solo con la parte decimal: "1500.00"
        return number_format($this->centavos / 100, 2, '.', '');
    }

    /**
     * Retorna la moneda de este dinero.
     *
     * @return Moneda Enum de la moneda
     */
    public function moneda(): Moneda
    {
        return $this->moneda;
    }

    /**
     * Suma dos instancias de Dinero.
     *
     * Solo permite sumar si tienen la misma moneda.
     * Esto es una regla de dominio: no puedes sumar MXN + USD.
     *
     * @param Dinero $otro El otro monto a sumar
     *
     * @return self Nueva instancia con la suma (inmutabilidad: no modifica el original)
     *
     * @throws \InvalidArgumentException Si las monedas no coinciden
     */
    public function sumar(Dinero $otro): self
    {
        // Verificar que ambas cantidades tengan la misma moneda.
        // Si no, lanzamos excepción. Esto previene errores silenciosos
        // como sumar 1500 MXN + 100 USD = 1600 ??? (sin conversión)
        if ($this->moneda !== $otro->moneda) {
            throw new \InvalidArgumentException(
                "No se pueden sumar montos de monedas distintas: "
                . "{$this->moneda->value} + {$otro->moneda->value}"
            );
        }

        // Crear nueva instancia (inmutabilidad).
        // El original NO se modifica. Esta es la diferencia clave
        // entre Value Objects y objetos mutables.
        return new self(
            $this->centavos + $otro->centavos,
            $this->moneda,
        );
    }

    /**
     * Resta dos instancias de Dinero.
     *
     * No permite que el resultado sea negativo (lanza FondosInsuficientes).
     * Para permitir saldo negativo, usar restarSinLimite().
     *
     * @param Dinero $otro El monto a restar
     *
     * @return self Nueva instancia con la resta
     *
     * @throws \InvalidArgumentException Si las monedas no coinciden
     * @throws Exception\FondosInsuficientes Si el resultado sería negativo
     */
    public function restar(Dinero $otro): self
    {
        if ($this->moneda !== $otro->moneda) {
            throw new \InvalidArgumentException(
                "No se pueden restar montos de monedas distintas: "
                . "{$this->moneda->value} - {$otro->moneda->value}"
            );
        }

        // Calcular la diferencia en centavos
        $diferencia = $this->centavos - $otro->centavos;

        // Si la diferencia es negativa, no hay fondos suficientes.
        // Usamos exception de dominio (no genérica) para que el
        // manejador de errores pueda distinguir este caso.
        if ($diferencia < 0) {
            throw new Exception\FondosInsuficientes(
                saldoActual: $this,
                montoSolicitado: $otro,
            );
        }

        return new self($diferencia, $this->moneda);
    }

    /**
     * Compara si este dinero es igual a otro (mismo monto y moneda).
     *
     * PHP 8.2 permite readonly classes en comparaciones de objetos.
     * Sin este método, dos Dinero con el mismo valor serían "diferentes"
     * porque PHP compara por referencia (instancia) por defecto.
     *
     * @param Dinero $otro El otro dinero a comparar
     *
     * @return bool true si ambos tienen el mismo monto y moneda
     */
    public function esIgualA(Dinero $otro): bool
    {
        return $this->centavos === $otro->centavos
            && $this->moneda === $otro->moneda;
    }

    /**
     * Compara si este dinero es mayor que otro.
     *
     * Solo compara dentro de la misma moneda.
     *
     * @param Dinero $otro El otro dinero a comparar
     *
     * @return bool true si este monto es mayor
     */
    public function esMayorQue(Dinero $otro): bool
    {
        if ($this->moneda !== $otro->moneda) {
            throw new \InvalidArgumentException(
                "No se pueden comparar montos de monedas distintas"
            );
        }

        return $this->centavos > $otro->centavos;
    }

    /**
     * Compara si este dinero es menor o igual que otro.
     *
     * @param Dinero $otro El otro dinero a comparar
     *
     * @return bool true si este monto es menor o igual
     */
    public function esMenorOIgualQue(Dinero $otro): bool
    {
        if ($this->moneda !== $otro->moneda) {
            throw new \InvalidArgumentException(
                "No se pueden comparar montos de monedas distintas"
            );
        }

        return $this->centavos <= $otro->centavos;
    }

    /**
     * Representación string del dinero.
     *
     * PHP llama automáticamente a __toString() cuando se hace echo o concatena.
     * Ejemplo: echo $dinero → "1500.00 MXN"
     *
     * @return string Representación legible del dinero
     */
    public function __toString(): string
    {
        return "{$this->formateadoDecimal()} {$this->moneda->value}";
    }
}

```

## src/Domain/Exception/FondosInsuficientes.php

```php
<?php
// src/Domain/Exception/FondosInsuficientes.php — Excepción de dominio
//
// EXCEPCIÓN DE DOMINIO: Error que representa una violación de una regla de negocio.
//
// ¿Por qué no usar \RuntimeException o \InvalidArgumentException genéricas?
// Porque en un sistema fintech, necesitamos distinguir:
//   - Error de validación (campo faltante) → InvalidArgumentException
//   - Fondos insuficientes (regla de negocio) → FondosInsuficientes
//   - Error técnico (base de datos caída) → RuntimeException
//
// Cada tipo de excepción se maneja diferente:
//   - FondosInsuficientes → 422 Unprocessable Entity + notificación al usuario
//   - RuntimeException → 500 Internal Server Error + alerta a DevOps
//
// Las excepciones de dominio NO deben contener lógica de presentación.
// Solo datos relevantes para que el manejador de errores tome decisiones.

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Dinero;

/**
 * Se lanza cuando se intenta debitar un monto mayor al saldo disponible.
 *
 * Carry datos del contexto para que el controller/service pueda:
 *   1. Retornar una respuesta HTTP descriptiva (422)
 *   2. Loggear el intento fallido
 *   3. Notificar al usuario del saldo disponible
 */
final class FondosInsuficientes extends \DomainException
{
    /**
     * @param Dinero $saldoActual     Saldo actual de la billetera
     * @param Dinero $montoSolicitado Monto que se intentó debitar
     */
    public function __construct(
        public readonly Dinero $saldoActual,
        public readonly Dinero $montoSolicitado,
    ) {
        // Calcular el déficit para incluirlo en el mensaje de error.
        // Esto ayuda al desarrollador a entender qué pasó sin tener que
        // inspeccionar el debugger.
        $deficit = $montoSolicitado->centavos() - $saldoActual->centavos();

        // El mensaje de la excepción padre (\DomainException) es el que
        // aparece en logs y mensajes de error. Debe ser descriptivo.
        parent::__construct(
            "Fondos insuficientes: solicitado {$montoSolicitado}, "
            . "disponible {$saldoActual}. "
            . "Déficit: {$deficit} centavos."
        );
    }

    /**
     * Calcula el déficit exacto entre lo solicitado y lo disponible.
     *
     * @return Dinero Diferencia que falta para completar la transacción
     */
    public function deficit(): Dinero
    {
        // Crear un Dinero con la diferencia en centavos.
        // Usamos la misma moneda que el monto solicitado.
        return Dinero::desdeCentavos(
            $this->montoSolicitado->centavos() - $this->saldoActual->centavos(),
            $this->montoSolicitado->moneda(),
        );
    }
}

```

## src/Domain/Exception/MonedaNoSoportada.php

```php
<?php
// src/Domain/Exception/MonedaNoSoportada.php — Excepción de dominio
//
// Se lanza cuando se intenta usar una moneda que PrestaFlow no soporta.
// Ejemplo: someone intenta depositar en Bitcoin cuando solo soportamos fiat.

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Excepción lanzada al intentar operar con una moneda no soportada.
 */
final class MonedaNoSoportada extends \DomainException
{
    /**
     * @param string $codigoMoneda Código de moneda intentada (ej: "BTC")
     * @param array<string> $monedasSoportadas Lista de monedas válidas
     */
    public function __construct(
        public readonly string $codigoMoneda,
        public readonly array $monedasSoportadas,
    ) {
        // Implantamos la lista de monedas soportadas en el mensaje
        // para que el desarrollador sepa inmediatamente qué valores usar.
        $lista = implode(', ', $this->monedasSoportadas);

        parent::__construct(
            "Moneda no soportada: '{$codigoMoneda}'. "
            . "Monedas disponibles: [{$lista}]"
        );
    }
}

```

## src/Domain/Moneda.php

```php
<?php
// src/Domain/Moneda.php — Enum de monedas soportadas
//
// ENUM (PHP 8.1+): Tipo nativo que reemplaza clases con constantes.
// Un enum solo puede tener los casos definidos explícitamente.
// Ventajas sobre constantes de clase:
//   1. Type-safe: no puedes pasar "BTC" como moneda si no existe como caso
//   2. Comparación directa: Moneda::MXN === Moneda::MXN (no strings)
//   3. Métodos: puedes tener lógica en los enums
//   4. Auto-completado: el IDE conoce todos los valores posibles
//
// ¿Por qué no usar strings "MXN", "USD", "EUR"?
// Porque en fintech, una moneda inválida = error potencialmente catastrófico.
// Un enum garantiza en tiempo de compilación que solo existen monedas válidas.

declare(strict_types=1);

namespace App\Domain;

/**
 * Monedas soportadas por PrestaFlow.
 *
 * Cada caso tiene:
 *   - valor: código ISO 4217 de 3 letras (para APIs externas)
 *   - decimales: precisiones decimales (MXN usa 2, pero crypto puede usar 8)
 *   - nombre: nombre legible para humanos
 *
 * Nota: Usamos BackedEnum (string) porque:
 *   1. El valor se serializa directamente a JSON
//   2. Se puede guardar en base de datos como string
//   3. Se puede comparar con strings de APIs externas
 */
enum Moneda: string
{
    // MXN: Peso mexicano. Moneda base de PrestaFlow.
    // 2 decimales: 1 MXN = 100 centavos
    case MXN = 'MXN';

    // USD: Dolar estadounidense. Para transacciones internacionales.
    case USD = 'USD';

    // EUR: Euro. Para clientes europeos.
    case EUR = 'EUR';

    /**
     * Retorna la cantidad de decimales para esta moneda.
     *
     * ¿Por qué no siempre 2? Porque algunas monedas (como BTC)
     * usan más decimales. En nuestro caso todas usan 2, pero
     * el método existe para futuras expansiones.
     *
     * @return int Número de decimales (2 para monedas fiat)
     */
    public function decimales(): int
    {
        // Todas las monedas fiat usan 2 decimales.
        // Si en el futuro agregamos crypto, este método se extiende
        // con un match: return match($this) { ... }
        return 2;
    }

    /**
     * Retorna el símbolo visual de la moneda.
     *
     * Útil para presentación en interfaces de usuario.
     * En APIs siempre usamos el código ISO, no el símbolo.
     *
     * @return string Símbolo de la moneda
     */
    public function simbolo(): string
    {
        // Usamos match (PHP 8.0+) en lugar de switch.
        // match es una expresión (retorna valor) y usa comparación estricta (===).
        // El compilador verifica que todos los casos estén cubiertos.
        return match ($this) {
            self::MXN => '$',
            self::USD => '$',
            self::EUR => '€',
        };
    }

    /**
     * Busca una moneda por su código ISO.
     *
     * Método estático que actúa como factory: convierte un string
     * a un enum. Si el string no corresponde a ningún caso,
     * lanza una excepción.
     *
     * @param string $codigo Código ISO de 3 letras (ej: "MXN")
     *
     * @return self Instancia del enum
     *
     * @throws \ValueError Si el código no corresponde a una moneda soportada
     */
    public static function desdeCodigo(string $codigo): self
    {
        // from() es un método nativo de BackedEnum.
        // Lanza \ValueError si el valor no existe como caso.
        // Lo encapsulamos en nuestro método para dar un mensaje
        // más descriptivo al desarrollador.
        return self::from(strtoupper($codigo));
    }
}

```

## src/Domain/Movimiento.php

```php
<?php
// src/Domain/Movimiento.php — Value Object que representa un movimiento financiero
//
// MOVIMIENTO: Registro inmutable de una transacción en la billetera.
// Cada movimiento es un evento financiero con:
//   - Un monto (Dinero)
//   - Un tipo (depósito o retiro)
//   - Un timestamp (cuándo ocurrió)
//   - Una descripción (para el usuario)
//
// ¿Por qué es un Value Object y no una Entity?
// Porque dos movimientos con los mismos datos son intercambiables.
// No necesitamos un ID para distinguirlos (aunque sí lo almacenamos para referencia).

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Registro inmutable de un movimiento financiero.
 *
 * Design patterns aplicados:
 *   - Value Object: identificado por sus valores, no por ID
 *   - Immutable: readonly properties + constructor privado
 *   - Self-Documenting: cada propiedad tiene un tipo explícito
 */
final readonly class Movimiento
{
    /**
     * @param string          $id          ID único del movimiento (UUID)
     * @param Dinero          $monto       Monto del movimiento
     * @param TipoMovimiento  $tipo        Tipo (depósito o retiro)
     * @param string          $descripcion Descripción legible para el usuario
     * @param DateTimeImmutable $creadoEn  Timestamp de creación
     */
    public function __construct(
        public string $id,
        public Dinero $monto,
        public TipoMovimiento $tipo,
        public string $descripcion,
        public DateTimeImmutable $creadoEn,
    ) {
        // Constructor público: el controller/service crea instancias directamente.
        // La validación de negocio ocurre en Billetera::depositar() y ::retirar(),
        // no en el constructor de Movimiento (que es un simple registro).
    }

    /**
     * Crea un movimiento de depósito.
     *
     * Factory method que simplifica la creación de depósitos.
     * El tipo se establece automáticamente como DEPOSITO.
     *
     * @param string $id          ID único (UUID)
     * @param Dinero $monto       Monto a depositar
     * @param string $descripcion Descripción del depósito
     *
     * @return self Nuevo movimiento de depósito
     */
    public static function deposito(
        string $id,
        Dinero $monto,
        string $descripcion,
    ): self {
        return new self(
            id: $id,
            monto: $monto,
            tipo: TipoMovimiento::DEPOSITO,
            descripcion: $descripcion,
            // immutable(): retorna DateTimeImmutable con la fecha/hora actual.
            // Usamos immutable en lugar de new DateTimeImmutable() para brevedad.
            // DateTimeImmutable es preferido sobre DateTime porque:
            //   1. Los métodos modificadores retornan NUEVO objeto
            //   2. No se puede modificar accidentalmente después de creado
            //   3. Seguro para concurrent access (sin bloqueos)
            creadoEn: new DateTimeImmutable(),
        );
    }

    /**
     * Crea un movimiento de retiro.
     *
     * Factory method que simplifica la creación de retiros.
     *
     * @param string $id          ID único (UUID)
     * @param Dinero $monto       Monto a retirar
     * @param string $descripcion Descripción del retiro
     *
     * @return self Nuevo movimiento de retiro
     */
    public static function retiro(
        string $id,
        Dinero $monto,
        string $descripcion,
    ): self {
        return new self(
            id: $id,
            monto: $monto,
            tipo: TipoMovimiento::RETIRO,
            descripcion: $descripcion,
            creadoEn: new DateTimeImmutable(),
        );
    }
}

```

## src/Domain/TipoMovimiento.php

```php
<?php
// src/Domain/TipoMovimiento.php — Enum de tipos de movimiento financiero
//
// Este enum categoriza las transacciones en la billetera.
// Cada tipo tiene reglas de negocio diferentes:
//   - Depósito: incrementa el saldo
//   - Retiro: decrementa el saldo (verificar fondos)
//   - Transferencia: decrementa origen, incrementa destino (próximamente)

declare(strict_types=1);

namespace App\Domain;

/**
 * Tipos de movimiento que puede tener una billetera.
 *
 * Cada caso define si el movimiento afecta positiva o negativamente
 * el saldo de la billetera.
 */
enum TipoMovimiento: string
{
    /**
     * Depósito: incrementa el saldo de la billetera.
     * Ejemplo: transferencia bancaria recibida, depósito en efectivo.
     */
    case DEPOSITO = 'deposito';

    /**
     * Retiro: decrementa el saldo de la billetera.
     * Ejemplo: retiro en cajero, pago a comercio.
     */
    case RETIRO = 'retiro';

    /**
     * Retorna si este tipo de movimiento incrementa el saldo.
     *
     * @return bool true si el movimiento suma dinero a la billetera
     */
    public function esAcreditacion(): bool
    {
        // match como expresión: retorna el valor del caso coincidente.
        // Solo DEPOSITO es acreditación. RETIRO es débito.
        return match ($this) {
            self::DEPOSITO => true,
            self::RETIRO => false,
        };
    }

    /**
     * Retorna la etiqueta legible del tipo de movimiento.
     *
     * @return string Nombre en español para interfaces de usuario
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::DEPOSITO => 'Depósito',
            self::RETIRO => 'Retiro',
        };
    }
}

```

## tests/Architecture/DominioPuroTest.php

```php
<?php
// tests/Architecture/DominioPuroTest.php — Test de arquitectura
//
// TEST DE ARQUITECTURA: Verifica que el código cumple reglas estructurales.
// Estos tests NO verifican comportamiento (eso lo hacen los unit tests).
// Verifican RESTRICCIONES de diseño:
//   - "El dominio no puede importar Symfony"
//   - "Los controllers no pueden tener lógica de negocio"
//   - "Los Value Objects deben ser readonly"
//
// ¿Por qué un test y no una convención documentada?
// Porque las convenciones se rompen. Los tests no.
// Si alguien importa Symfony en el dominio, este test falla en CI
// y el PR no se puede merge. Simple.

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test de arquitectura: dominio puro sin dependencias de framework.
 *
 * La carpeta src/Domain/ debe ser COPIABLE a otro proyecto.
// Si tiene imports de Symfony, Doctrine, o cualquier framework,
// no es puro y el patrón hexagonal se rompe.
 */
final class DominioPuroTest extends TestCase
{
    /**
     * Verifica que Ningún archivo en src/Domain/ importe clases de Symfony.
     *
     * Esto garantiza que el dominio es PORTABLE: se puede copiar
    // a cualquier proyecto PHP 8.3+ sin instalar Symfony.
    *
     * ¿Cómo funciona?
    *   1. Escanea todos los archivos .php en src/Domain/
    *   2. Lee cada archivo y busca líneas que empiecen con "use App\\"
    *   3. Verifica que NINGUNO importe de namespaces de framework
    *
    // Si falla: significa que alguien metió lógica de framework en el dominio.
    // Solución: mover esa lógica al directorio Infrastructure/ o Application/.
     */
    #[Test]
    public function test_dominio_no_importa_symfony(): void
    {
        // Obtener el directorio raíz del proyecto (2 niveles arriba de tests/)
        $projectRoot = dirname(__DIR__, 2);
        $domainDir = $projectRoot.'/src/Domain';

        // Si el directorio no existe, el test pasa (no hay código que violar).
        // Esto es útil durante el desarrollo temprano del curso.
        if (!is_dir($domainDir)) {
            $this->markTestSkipped('Directorio src/Domain/ no existe aún');
        }

        // recursiveGlob: buscar TODOS los archivos PHP recursivamente.
        // El patrón **/*.php busca en todos los subdirectorios.
        $phpFiles = glob($domainDir.'/**/*.php', GLOB_BRACE);

        // Lista de namespaces prohibidos en el dominio.
        // Cada uno代表 una dependencia de framework/infraestructura.
        $forbiddenNamespaces = [
            'Symfony\\',            // Framework HTTP, container, routing
            'Doctrine\\',           // ORM, persistencia
            'Psr\\',                // PSR interfaces (dependen del ecosistema)
            'Twig\\',               // Motor de plantillas
            'GuzzleHttp\\',         // Cliente HTTP
            'Monolog\\',            // Logging (el dominio no loguea)
            'Ramsey\\Uuid\\',       // UUID generation (usamos uniqid en su lugar)
        ];

        $violations = [];

        // Escanear cada archivo del dominio
        foreach ($phpFiles as $file) {
            // file_get_contents lee el archivo completo como string
            $content = file_get_contents($file);

            // Buscar cada namespace prohibido en el contenido
            foreach ($forbiddenNamespaces as $namespace) {
                // strstr busca el namespace en el contenido del archivo.
                // Si lo encuentra, significa que el dominio importa framework.
                if (strstr($content, 'use '.$namespace) !== false) {
                    // Calcular la ruta relativa para el mensaje de error
                    // Esto ayuda al developer a encontrar el archivo que viola
                    $relativePath = str_replace($projectRoot.'/', '', $file);
                    $violations[] = "{$relativePath} importa {$namespace}";
                }
            }
        }

        // Verificar que no hay violaciones.
        // Si las hay, el test falla con un mensaje descriptivo que lista
        // TODOS los archivos que violan la regla (no solo el primero).
        $this->assertEmpty(
            $violations,
            "Dominio puro violado. Los siguientes archivos importan dependencias de framework:\n"
            . implode("\n", $violations)
            . "\n\nSolución: mover la lógica de framework a src/Infrastructure/ o src/Application/"
        );
    }

    /**
     * Verifica que los Value Objects en el dominio sean readonly.
     *
     * Los Value Objects deben ser inmutables por diseño.
     * En PHP 8.2+, la palabra clave "readonly" en una clase
     * fuerza que todas las propiedades sean readonly.
     *
     * Esto previene bugs como:
     *   $dinero->centavos = -100;  // ← Esto no debería compilar
     *
     * ¿Cómo funciona?
     *   1. Busca clases que extiendan de un Value Object conocido
     *   2. Verifica que tengan "readonly" en su declaración
     */
    #[Test]
    public function test_value_objects_son_readonly(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $domainDir = $projectRoot.'/src/Domain';

        if (!is_dir($domainDir)) {
            $this->markTestSkipped('Directorio src/Domain/ no existe aún');
        }

        // Value Objects conocidos que DEBEN ser readonly.
        // Si agregas un nuevo VO, agrégalo aquí.
        $requiredReadonlyClasses = [
            'Dinero.php',     // Value Object de dinero
            'Movimiento.php', // Value Object de movimiento
        ];

        foreach ($requiredReadonlyClasses as $classFile) {
            $filePath = $domainDir.'/'.$classFile;

            // Si el archivo no existe, saltar (se creará en ejercicios)
            if (!file_exists($filePath)) {
                $this->markTestSkipped("Archivo {$classFile} no existe aún");
            }

            $content = file_get_contents($filePath);

            // Verificar que la declaración de clase contiene "readonly".
            // La sintaxis es: "final readonly class Dinero"
            // strstr busca "readonly class" en el contenido del archivo.
            $this->assertStringContainsString(
                'readonly class',
                $content,
                "La clase {$classFile} debe ser 'readonly class' "
                . "(Value Objects son inmutables por diseño)"
            );
        }
    }

    /**
     * Verifica que todos los archivos PHP usen strict_types.
     *
     * Sin strict_types, PHP hace "type coercion" silenciosa:
     *   function sumar(int $a, int $b): int { ... }
     *   sumar("5", "3");  // PHP convierte strings a ints automáticamente
     *
     * Esto causa bugs difíciles de encontrar. Con strict_types:
     *   sumar("5", "3");  // TypeError: Argument #1 must be of type int
     */
    #[Test]
    public function test_todos_archivos_php_usan_strict_types(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $srcDir = $projectRoot.'/src';

        if (!is_dir($srcDir)) {
            $this->markTestSkipped('Directorio src/ no existe aún');
        }

        $phpFiles = glob($srcDir.'/**/*.php', GLOB_BRACE);

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            $relativePath = str_replace($projectRoot.'/', '', $file);

            // Verificar que el archivo contiene declare(strict_types=1).
            // Lo buscamos como string porque es la forma más robusta
            // (no depende de AST parsing).
            $this->assertStringContainsString(
                'declare(strict_types=1)',
                $content,
                "El archivo {$relativePath} no tiene declare(strict_types=1)"
            );
        }
    }
}

```

## tests/Unit/Domain/BilleteraTest.php

```php
<?php
// tests/Unit/Domain/BilleteraTest.php — Tests unitarios del Aggregate Billetera
//
// Billetera es el Aggregate Root: el punto de entrada para todas las
// operaciones de la billetera. Estos tests verifican:
//   - Creación de billeteras
//   - Depósitos (happy path + edge cases)
//   - Retiros (happy path + fondos insuficientes)
//   - Balance calculado correctamente
//   - Historial de movimientos
//
// TODOS estos tests son UNITARIOS: no usan base de datos, HTTP, ni I/O.
// Son rápidos (< 100ms total) y ejecutables en cualquier entorno.

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Billetera;
use App\Domain\Dinero;
use App\Domain\Exception\FondosInsuficientes;
use App\Domain\Moneda;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BilleteraTest extends TestCase
{
    // =========================================================================
    // TESTS DE CREACIÓN
    // =========================================================================

    /**
     * Verifica que una billetera nueva tenga balance cero.
     *
     * Al crear una billetera sin movimientos, el balance debe ser 0.
     * Esto es el estado inicial de cualquier wallet en fintech.
     */
    #[Test]
    public function test_billetera_nueva_tiene_balance_cero(): void
    {
        // Crear billetera vacía (sin movimientos = []
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        // Verificar que el balance es cero
        $balance = $billetera->balance();
        $this->assertSame(0, $balance->centavos());
        $this->assertSame(Moneda::MXN, $balance->moneda());
    }

    // =========================================================================
    // TESTS DE DEPÓSITO
    // =========================================================================

    /**
     * Verifica que un depósito incremente el balance correctamente.
     *
     * Antes: balance = 0
     * Depósito: 1500.00 MXN (150000 centavos)
     * Después: balance = 1500.00 MXN
     */
    #[Test]
    public function test_depositar_incremente_balance(): void
    {
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        $monto = Dinero::crear('1500.00', Moneda::MXN);

        // Ejecutar el depósito
        $movimiento = $billetera->depositar($monto, 'Depósito inicial');

        // Verificar que el movimiento se creó correctamente
        $this->assertSame('Depósito inicial', $movimiento->descripcion);

        // Verificar que el balance se actualizó
        $this->assertSame(150000, $billetera->balance()->centavos());

        // Verificar que hay 1 movimiento registrado
        $this->assertSame(1, $billetera->totalMovimientos());
    }

    /**
     * Verifica que múltiples depósitos acumulen correctamente.
     *
     * Depósito 1: 1000.00
     * Depósito 2: 500.00
     * Balance esperado: 1500.00
     */
    #[Test]
    public function test_multiples_depositos_acumulan_balance(): void
    {
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        // Primer depósito
        $billetera->depositar(
            Dinero::crear('1000.00', Moneda::MXN),
            'Transferencia bancaria',
        );

        // Segundo depósito
        $billetera->depositar(
            Dinero::crear('500.00', Moneda::MXN),
            'Depósito en efectivo',
        );

        // Verificar balance acumulado
        $this->assertSame(150000, $billetera->balance()->centavos());
        // Verificar que hay 2 movimientos
        $this->assertSame(2, $billetera->totalMovimientos());
    }

    /**
     * Verifica que no se pueda depositar monto cero.
     *
     * Un depósito de $0 no tiene sentido de negocio.
     */
    #[Test]
    public function test_depositar_monto_cero_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        $billetera->depositar(
            Dinero::crear('0.00', Moneda::MXN),
            'Depósito vacío',
        );
    }

    /**
     * Verifica que no se pueda depositar moneda diferente a la billetera.
     *
     * Si la billetera es MXN, no acepta depósitos en USD.
     */
    #[Test]
    public function test_depositar_moneda_diferente_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        // Intentar depositar USD en billetera MXN
        $billetera->depositar(
            Dinero::crear('100.00', Moneda::USD),
            'Depósito en USD',
        );
    }

    // =========================================================================
    // TESTS DE RETIRO
    // =========================================================================

    /**
     * Verifica que un retiro decremente el balance correctamente.
     *
     * Balance: 5000.00
     * Retiro: 1500.00
     * Balance esperado: 3500.00
     */
    #[Test]
    public function test_retirar_decrementa_balance(): void
    {
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        // Primero depositar (para tener saldo)
        $billetera->depositar(
            Dinero::crear('5000.00', Moneda::MXN),
            'Depósito inicial',
        );

        // Retirar
        $billetera->retirar(
            Dinero::crear('1500.00', Moneda::MXN),
            'Pago a comercio',
        );

        // Balance: 5000 - 1500 = 3500 (350000 centavos)
        $this->assertSame(350000, $billetera->balance()->centavos());
        // 2 movimientos: 1 depósito + 1 retiro
        $this->assertSame(2, $billetera->totalMovimientos());
    }

    /**
     * Verifica que un retiro mayor al saldo lance FondosInsuficientes.
     *
     * Balance: 500.00
     * Retiro: 1000.00
     * Resultado: FondosInsuficientes con deficit de 500.00
     */
    #[Test]
    public function test_retirar_saldo_insuficiente_lanza_fondos_insuficientes(): void
    {
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        // Depositamos solo 500
        $billetera->depositar(
            Dinero::crear('500.00', Moneda::MXN),
            'Depósito inicial',
        );

        try {
            // Intentar retirar 1000 (más de lo que hay)
            $billetera->retirar(
                Dinero::crear('1000.00', Moneda::MXN),
                'Retiro excesivo',
            );

            // Si llegamos aquí, el test FALLA porque no se lanzó excepción
            $this->fail('Se esperaba FondosInsuficientes pero no se lanzó');
        } catch (FondosInsuficientes $e) {
            // Verificar que la excepción carry los datos correctos
            $this->assertSame(50000, $e->saldoActual->centavos());
            $this->assertSame(100000, $e->montoSolicitado->centavos());
            // Déficit = 100000 - 50000 = 50000 centavos
            $this->assertSame(50000, $e->deficit()->centavos());
        }
    }

    /**
     * Verifica que no se pueda retirar monto cero.
     */
    #[Test]
    public function test_retirar_monto_cero_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        $billetera->retirar(
            Dinero::crear('0.00', Moneda::MXN),
            'Retiro vacío',
        );
    }

    // =========================================================================
    // TESTS DE HISTORIAL
    // =========================================================================

    /**
     * Verifica que los movimientos se mantengan en orden cronológico.
     *
     * Los movimientos se agregan al final del array, así que el primero
     * siempre es el más viejo y el último el más reciente.
     */
    #[Test]
    public function test_movimientos_se_mantienen_en_orden(): void
    {
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        // Ejecutar 3 movimientos
        $billetera->depositar(Dinero::crear('100.00', Moneda::MXN), 'Depósito 1');
        $billetera->retirar(Dinero::crear('30.00', Moneda::MXN), 'Retiro 1');
        $billetera->depositar(Dinero::crear('200.00', Moneda::MXN), 'Depósito 2');

        $movimientos = $billetera->movimientos();

        // Verificar que hay 3 movimientos
        $this->assertCount(3, $movimientos);

        // Verificar orden: depósito, retiro, depósito
        $this->assertSame('Depósito 1', $movimientos[0]->descripcion);
        $this->assertSame('Retiro 1', $movimientos[1]->descripcion);
        $this->assertSame('Depósito 2', $movimientos[2]->descripcion);
    }

    /**
     * Verifica que movimientos() retorne una copia, no la referencia interna.
     *
     * Si retornara la referencia, el caller podría modificar el array
     * directamente, saltándose las validaciones de negocio.
     */
    #[Test]
    public function test_movimientos_retorna_copia(): void
    {
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        $movimientos = $billetera->movimientos();

        // Intentar agregar un movimiento a la referencia
        // (esto no debería afectar a la billetera)
        $movimientos[] = 'fake';

        // La billetera no debe tener movimientos
        $this->assertSame(0, $billetera->totalMovimientos());
    }
}

```

## tests/Unit/Domain/DineroTest.php

```php
<?php
// tests/Unit/Domain/DineroTest.php — Tests unitarios del Value Object Dinero
//
// TESTS UNITARIOS: Pruebas que verifican UNA clase en aislamiento total.
// NO dependen de: base de datos, red, filesystem, ni otros servicios.
// Ejecutan en milisegundos (la mayoría < 1ms).
//
// En TDD, estos tests se escriben PRIMERO (antes del código).
// El developer ve los tests fallar (RED), escribe el código mínimo (GREEN),
// y luego limpia (REFACTOR).
//
// PHPUnit 11 usa atributos PHP 8 en lugar de anotaciones docblock:
//   #[Test]            → marca un método como test ejecutable
//   #[DataProvider('x')] → vincula un método que provee datos de prueba
//   #[CoversClass(X)]  → indica qué clase se está testeando (para cobertura)
//   #[Group('unit')]   → agrupa tests para ejecución selectiva

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Dinero;
use App\Domain\Exception\FondosInsuficientes;
use App\Domain\Moneda;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests del Value Object Dinero.
 *
 * Cada test verifica UN comportamiento específico.
 * El nombre del método describe QUÉ se está testeando.
 * Convención: test_sujeto_bajo_prueba_comportamiento_esperado
 */
final class DineroTest extends TestCase
{
    // =========================================================================
    // TESTS DE CREACIÓN
    // =========================================================================

    /**
     * Verifica que Dinero::crear() funcione correctamente con un monto válido.
     *
     * Este es el "happy path": todo funciona como se espera.
     * Si este test falla, hay un problema fundamental.
     */
    #[Test]
    public function test_crear_dinero_desde_monto_decimal(): void
    {
        // ARRANGE (Preparar): crear los datos de prueba
        // Usamos 1500.00 MXN como ejemplo típico de depósito
        $monto = '1500.00';
        $moneda = Moneda::MXN;

        // ACT (Actuar): ejecutar el método que estamos testeando
        $dinero = Dinero::crear($monto, $moneda);

        // ASSERT (Verificar): comprobar que el resultado es el esperado
        // sameInt() es estricto (===): compara tipo Y valor.
        // assertSame(150000, $dinero->centavos()) fallaría si retornara "150000" (string).
        $this->assertSame(150000, $dinero->centavos());
        $this->assertSame($moneda, $dinero->moneda());
    }

    /**
     * Verifica que la creación desde centavos funcione correctamente.
     *
     * Este factory method se usa cuando el monto ya viene en centavos
     * (ej: desde una base de datos que almacena enteros).
     */
    #[Test]
    public function test_crear_dinero_desde_centavos(): void
    {
        // Crear Dinero directamente con 50000 centavos (500.00)
        $dinero = Dinero::desdeCentavos(50000, Moneda::MXN);

        // Verificar que los centavos se almacenan correctamente
        $this->assertSame(50000, $dinero->centavos());
        // Verificar que formateadoDecimal() convierte correctamente
        // 50000 centavos → "500.00"
        $this->assertSame('500.00', $dinero->formateadoDecimal());
    }

    /**
     * Verifica que crear() rechace montos negativos.
     *
     * Un depósito negativo no tiene sentido de negocio.
     * Si no validamos esto, podríamos crear billeteras con saldo negativo
     * por error.
     */
    #[Test]
    public function test_crear_dinero_monto_negativo_lanza_excepcion(): void
    {
        // PHPUnit expectException() ASSERT que se lance una excepción específica.
        // Si la función NO lanza la excepción → test FALLA.
        // Si lanza OTRA excepción → test FALLA.
        // Si lanza esta excepción → test PASA.
        $this->expectException(\InvalidArgumentException::class);

        // Intentar crear Dinero con monto negativo
        Dinero::crear('-100.00', Moneda::MXN);
    }

    /**
     * Verifica que crear() rechace montos con formato inválido.
     *
     * "abc" no es un número válido. filter_var debe rechazarlo.
     */
    #[Test]
    public function test_crear_dinero_monto_no_numerico_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Dinero::crear('abc', Moneda::MXN);
    }

    // =========================================================================
    // TESTS DE OPERACIONES ARITMÉTICAS
    // =========================================================================

    /**
     * Verifica que la suma de dos Dinero de la misma moneda funcione.
     *
     * Esta es la operación más básica: 500 + 300 = 800.
     * Los centavos se suman directamente (50000 + 30000 = 80000).
     */
    #[Test]
    public function test_sumar_montos_misma_moneda(): void
    {
        // ARRANGE: dos montos en la misma moneda
        $cincoCientos = Dinero::crear('500.00', Moneda::MXN);
        $tresCientos = Dinero::crear('300.00', Moneda::MXN);

        // ACT: sumar los dos montos
        $resultado = $cincoCientos->sumar($tresCientos);

        // ASSERT: verificar el resultado
        $this->assertSame(80000, $resultado->centavos());
        $this->assertSame('800.00', $resultado->formateadoDecimal());
    }

    /**
     * Verifica que la suma rechace montos de monedas diferentes.
     *
     * Sumar MXN + USD sin conversión es un error de negocio.
     * El cliente debe convertir explícitamente antes de sumar.
     */
    #[Test]
    public function test_sumar_montos_distinta_moneda_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $mxn = Dinero::crear('500.00', Moneda::MXN);
        $usd = Dinero::crear('300.00', Moneda::USD);

        // Intentar sumar MXN + USD debe fallar
        $mxn->sumar($usd);
    }

    /**
     * Verifica que la resta funcione correctamente.
     *
     * 800 - 300 = 500 (en centavos: 80000 - 30000 = 50000)
     */
    #[Test]
    public function test_restar_montos_suficientes(): void
    {
        $ochoCientos = Dinero::crear('800.00', Moneda::MXN);
        $tresCientos = Dinero::crear('300.00', Moneda::MXN);

        $resultado = $ochoCientos->restar($tresCientos);

        $this->assertSame(50000, $resultado->centavos());
    }

    /**
     * Verifica que la resta lance FondosInsuficientes cuando no hay saldo.
     *
     * 300 - 800 = saldo negativo → excepción de dominio.
     *
     * Esta excepción carry datos (saldo actual, monto solicitado, déficit)
     * para que el controller pueda retornar una respuesta HTTP descriptiva.
     */
    #[Test]
    public function test_restar_montos_insuficientes_lanza_fondos_insuficientes(): void
    {
        // Esperar nuestra excepción de dominio, no una genérica
        $this->expectException(FondosInsuficientes::class);

        $tresCientos = Dinero::crear('300.00', Moneda::MXN);
        $ochoCientos = Dinero::crear('800.00', Moneda::MXN);

        // Intentar restar más de lo que se tiene
        $tresCientos->restar($ochoCientos);
    }

    /**
     * Verifica que se pueda restar exactamente el saldo disponible.
     *
     * 500 - 500 = 0 (caso borde: saldo exacto)
     */
    #[Test]
    public function test_restar_exactamente_el_saldo(): void
    {
        $monto = Dinero::crear('500.00', Moneda::MXN);

        $resultado = $monto->restar($monto);

        $this->assertSame(0, $resultado->centavos());
    }

    // =========================================================================
    // TESTS DE COMPARACIÓN
    // =========================================================================

    /**
     * Verifica que esIgualA() funcione correctamente.
     *
     * Dos Dinero con el mismo monto y moneda deben ser considerados iguales.
     * Sin este método, PHP compara por referencia (memoria), no por valor.
     */
    #[Test]
    public function test_dinero_igual_por_valor(): void
    {
        // Crear DOS instancias diferentes con los mismos valores
        $a = Dinero::crear('100.00', Moneda::MXN);
        $b = Dinero::crear('100.00', Moneda::MXN);

        // Verificar que esIgualA() los compara correctamente
        $this->assertTrue($a->esIgualA($b));
    }

    /**
     * Verifica que esMayorQue() funcione correctamente.
     */
    #[Test]
    public function test_dinero_mayor_que(): void
    {
        $mayor = Dinero::crear('500.00', Moneda::MXN);
        $menor = Dinero::crear('300.00', Moneda::MXN);

        $this->assertTrue($mayor->esMayorQue($menor));
        $this->assertFalse($menor->esMayorQue($mayor));
    }

    // =========================================================================
    // TESTS DE DATA PROVIDERS (tests parametrizados)
    // =========================================================================

    /**
     * Verifica la conversión de centavos a decimal con múltiples valores.
     *
     * DataProvider: PHPUnit ejecuta este test UNA VEZ por cada sub-array
     * del método montonParaCentavos(). Esto reemplaza 5 test methods
     * individuales que harían lo mismo con diferentes datos.
     *
     * Ventaja: si el patrón de test es igual pero los datos cambian,
     * un DataProvider es más limpio y mantenible.
     */
    #[Test]
    #[DataProvider('montonParaCentavos')]
    public function test_conversion_centavos_decimal(string $monto, int $centavosEsperados): void
    {
        $dinero = Dinero::crear($monto, Moneda::MXN);

        $this->assertSame($centavosEsperados, $dinero->centavos());
    }

    /**
     * DataProvider para el test anterior.
     *
     * REGLA: el método DEBE ser static (PHPUnit 11 requirement).
     * Si no fuera static, PHPUnit no podría accederlo sin instanciar la clase.
     *
     * Retorna un array de arrays: [input, expected_output]
     * Cada sub-array = un caso de prueba independiente.
     *
     * @return array<string, array{string, int}>
     */
    public static function montonParaCentavos(): array
    {
        return [
            // Nombre del caso    => [monto_input, centavos_esperados]
            'un centavo'          => ['0.01', 1],
            'diez centavos'       => ['0.10', 10],
            'un peso'             => ['1.00', 100],
            'cincocientos pesos'  => ['500.00', 50000],
            'monto sin decimales' => ['100', 10000],
            // Caso borde: el máximo centavo antes de overflow teórico
            'monto grande'        => ['999999.99', 99999999],
        ];
    }

    /**
     * Verifica que __toString() retorne la representación correcta.
     *
     * PHP llama automáticamente a __toString() cuando:
     *   - echo $objeto;
     *   - "string {$objeto}"
     *   - print_r($objeto);
     */
    #[Test]
    public function test_to_string_formateado(): void
    {
        $dinero = Dinero::crear('1500.50', Moneda::MXN);

        // Verificar que el string contiene monto + moneda
        $this->assertSame('1500.50 MXN', (string) $dinero);
    }

    /**
     * Verifica que desdeCentavos lance excepción con centavos negativos.
     */
    #[Test]
    public function test_desde_centavos_negativos_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Dinero::desdeCentavos(-100, Moneda::MXN);
    }
}

```

## tests/Unit/Domain/MovimientoTest.php

```php
<?php
// tests/Unit/Domain/MovimientoTest.php — Tests unitarios del Value Object Movimiento
//
// Estos tests verifican que los factory methods de Movimiento
// creen instancias correctas con los tipos adecuados.

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Domain\Movimiento;
use App\Domain\TipoMovimiento;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MovimientoTest extends TestCase
{
    /**
     * Verifica que el factory method de depósito cree un movimiento correcto.
     *
     * El tipo debe ser DEPOSITO y el monto debe ser el especificado.
     */
    #[Test]
    public function test_factory_deposito(): void
    {
        $monto = Dinero::crear('500.00', Moneda::MXN);

        $movimiento = Movimiento::deposito(
            id: 'mov_001',
            monto: $monto,
            descripcion: 'Transferencia bancaria',
        );

        // Verificar tipo
        $this->assertSame(TipoMovimiento::DEPOSITO, $movimiento->tipo);
        // Verificar monto
        $this->assertSame(50000, $movimiento->monto->centavos());
        // Verificar descripción
        $this->assertSame('Transferencia bancaria', $movimiento->descripcion);
        // Verificar que tiene timestamp
        $this->assertNotNull($movimiento->creadoEn);
    }

    /**
     * Verifica que el factory method de retiro cree un movimiento correcto.
     */
    #[Test]
    public function test_factory_retiro(): void
    {
        $monto = Dinero::crear('200.00', Moneda::MXN);

        $movimiento = Movimiento::retiro(
            id: 'mov_002',
            monto: $monto,
            descripcion: 'Pago a comercio',
        );

        // Verificar tipo
        $this->assertSame(TipoMovimiento::RETIRO, $movimiento->tipo);
        // Verificar monto
        $this->assertSame(20000, $movimiento->monto->centavos());
        // Verificar descripción
        $this->assertSame('Pago a comercio', $movimiento->descripcion);
    }

    /**
     * Verifica que TipoMovimiento::esAcreditacion() funcione correctamente.
     *
     * DEPOSITO es acreditación (suma dinero).
     * RETIRO no es acreditación (resta dinero).
     */
    #[Test]
    public function test_tipo_movimiento_acreditacion(): void
    {
        $this->assertTrue(TipoMovimiento::DEPOSITO->esAcreditacion());
        $this->assertFalse(TipoMovimiento::RETIRO->esAcreditacion());
    }

    /**
     * Verifica que TipoMovimiento::etiqueta() retorne strings legibles.
     *
     * "Depósito" y "Retiro" son para interfaces de usuario,
     * no para comparaciones de código.
     */
    #[Test]
    public function test_tipo_movimiento_etiquetas(): void
    {
        $this->assertSame('Depósito', TipoMovimiento::DEPOSITO->etiqueta());
        $this->assertSame('Retiro', TipoMovimiento::RETIRO->etiqueta());
    }
}

```

## tests/bootstrap.php

```php
<?php
// tests/bootstrap.php — Bootstrap de PHPUnit
//
// Este archivo se ejecuta ANTES de cualquier test.
// Su único trabajo es cargar el autoloader de Composer.
// Sin esto, PHPUnit no encontraría las clases del proyecto.
//
// PHPUnit ejecuta: phpunit.xml.dist → bootstrap="tests/bootstrap.php"
// El bootstrap carga: vendor/autoload.php → todas las clases disponibles

declare(strict_types=1);

// require_once carga el autoloader generado por Composer.
// Este archivo mapea los namespaces (App\, PHPUnit\Framework\, etc.)
// a sus archivos .php correspondientes.
// Si no existiera, cada test necesitaría un require manual de cada clase.
require dirname(__DIR__).'/vendor/autoload.php';

```

