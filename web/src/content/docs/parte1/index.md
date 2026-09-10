---
title: "PARTE 1: PHP 8.2+ Moderno, POO Avanzada y Fundamentos"
description: "Objetivo"
sidebar: {"order":1}
---


> **Objetivo**: Construir el primer microservicio del ecosistema PrestaFlow
> aplicando PHP moderno, Value Objects, Aggregates y TDD desde la primera línea.
>
> **Duración estimada**: 15–20 horas
>
> **Resultado**: `wallet-service` con dominio rico, tests unitarios y análisis estático pasando.
>
> **Prerrequisito**: Parte 0 completada (doctor.sh → READY)

---

## Índice

- [1.1 Primer Contacto con Symfony](#11-primer-contacto-con-symfony)
- [1.2 PHP 8.3 Esencial](#12-php-83-esencial)
- [1.3 Objetos de Valor de Dinero](#13-objetos-de-valor-de-dinero)
- [1.4 El Aggregate Billetera](#14-el-aggregate-billetera)
- [1.5 Puerta de Entrada: Controller y Health Check](#15-puerta-de-entrada-controller-y-health-check)
- [1.6 Tests de Arquitectura y Quality Gates](#16-tests-de-arquitectura-y-quality-gates)
- [Ejercicios de la Parte 1](#ejercicios-de-la-parte-1)

---

## 1.1 Primer Contacto con Symfony

### Crear el Proyecto

Symfony es un framework PHP que provee las piezas básicas para construir
aplicaciones web: routing (URLs a controllers), dependency injection (container),
y una arquitectura organizada por capas.

```bash
# Crear un nuevo proyecto Symfony con el skeleton mínimo.
# --version=7.4.*: usar la última versión 7.4 LTS
# --no-git: no inicializar git todavía (lo haremos manualmente para controlar commits)
# --webapp: NO usar esta opción (instala demasiadas dependencias innecesarias)
# --full: NO usar esta opción (instala Doctrine, Twig, etc. que no necesitamos aún)
cd ~/fsc-php-curso
symfony new wallet-service --version=7.4.* --no-git
```

**Salida esperada:**

```
 INFO  Creating a new Symfony 7.4 project
 WARN  Your lock file needs an update, but --no-update was passed, skipping the update.

                                 _   __
                                | | / /
  _ __ ___   __ _ _ __   __ _  | |/ /  _   _
 | '_ ` _ \ / _` | '_ \ / _` | |    \ | | | |
 | | | | | | (_| | | | | (_| | | |\  \| |_| |
 |_| |_| |_|\__,_|_| |_|\__, | \_| \_/\___ /
                         __/ |
                        |___/

 SUCCESS  wallet-service/ project was successfully created.
```

### Explorar la Estructura

```bash
# Ver la estructura de archivos creada por Symfony
tree wallet-service/ -I 'var|vendor'
```

**Salida esperada (simulada):**

```
wallet-service/
├── .editorconfig              # Formato de archivos (ya lo conocemos)
├── .gitignore                 # Archivos que Git ignora
├── composer.json              # Dependencias PHP
├── composer.lock              # Versiones exactas de dependencias
├── config/                    # Configuración de Symfony
│   ├── bundles.php            # Bundles activos (plugins de Symfony)
│   ├── packages/              # Config por paquete (framework.yaml, etc.)
│   ├── routes/                # Rutas (archivos YAML o attributes)
│   ├── services.yaml          # Registro de servicios (dependency injection)
│   └── preload.php            # Pre-carga de clases para performance
├── composer.json              # Dependencias del proyecto
├── phpunit.xml.dist           # Configuración de PHPUnit (¡lo crearemos!)
├── public/                    # Directorio raíz del servidor web
│   └── index.php              # Punto de entrada HTTP
├── src/                       # Código fuente
│   ├── Controller/            # Controllers (reciben HTTP, retornan respuestas)
│   ├── Kernel.php             # Kernel de Symfony (bootstrap)
│   └── ...                    # Crearemos más directorios
├── tests/                     # Tests
│   ├── bootstrap.php          # Bootstrap de PHPUnit
│   └── ...
└── vendor/                    # Dependencias instaladas (gitignored)
    ├── autoload.php           # Autoloader de Composer
    └── ...
```

### Symfony Flex: El Sistema de Recetas

Cuando instalas un paquete Symfony, Flex ejecuta una "receta" que configura
automáticamente el paquete (archivos de configuración, archivos boilerplate).

```bash
# Ver las recetas instaladas
cat composer.json | python3 -c "
import json, sys
data = json.load(sys.stdin)
extra = data.get('extra', {}).get('symfony', {})
print('Recetas instaladas:')
for key, value in extra.get('recipes', {}).items():
    print(f'  - {key}')
"
```

### Flex: Demystified

```yaml
# composer.json - la sección "extra" controla Flex
"extra": {
    "symfony": {
        "allow-contrib": false,  # No instalar bundles contribuidos
        "require": "7.4.*"       # Versión exacta de Symfony a usar
    }
}
```

### Directorios Clave que Crearemos

```
wallet-service/
├── src/
│   ├── Domain/              # ← Nuestro dominio puro (PHP sin framework)
│   │   ├── Dinero.php       # Value Object de dinero
│   │   ├── Moneda.php       # Enum de monedas
│   │   ├── Billetera.php    # Aggregate Root
│   │   ├── Movimiento.php   # Value Object de transacción
│   │   └── Exception/       # Excepciones de dominio
│   ├── Controller/          # ← Controllers delgados (HTTP → Domain)
│   └── Kernel.php           # ← Bootstrap de Symfony
├── tests/
│   ├── Unit/                # ← Tests unitarios (dominio puro)
│   └── Architecture/        # ← Tests de arquitectura
├── phpunit.xml.dist
├── phpstan.neon
├── .php-cs-fixer.dist.php
└── Makefile
```

> **Regla de oro**: El directorio `src/Domain/` NO puede importar
> nada de Symfony, Doctrine, ni ningún framework. Esto es lo que
> significa "Arquitectura Hexagonal" y lo verificaremos con tests.

### Checkpoint 1.1 ✓

```bash
# Verificar que el proyecto se creó correctamente
ls wallet-service/src/
# Debe mostrar: Kernel.php

# Verificar que PHPStan funciona
cd wallet-service
composer require --dev phpstan/phpstan phpstan/phpstan-symfony
vendor/bin/phpstan analyse src --level=0
# Debe mostrar: [OK] No errors
```

---

## 1.2 PHP 8.3 Esencial

PHP 8.3 trae mejoras significativas para el desarrollo de sistemas fintech.
Cada feature que veremos se aplicará inmediatamente al wallet-service.

### strict_types: La Regla #1

```php
<?php
// SIN strict_types: PHP hace "type coercion" silenciosa.
// Esto es PELIGROSO en fintech:
$suma = "5" + "3";        // PHP convierte strings a int: 8 (¿queríamos esto?)
$suma = "5" + "abc";      // PHP convierte "abc" a 0: 5 (¡ERROR SILENCIOSO!)

// CON strict_types: PHP lanza TypeError si los tipos no coinciden.
declare(strict_types=1);  // ← SIEMPRE la primera línea de CADA archivo

$suma = "5" + "3";        // TypeError: unsupported operand types
// Esto es BUENO: falla ruidosamente en vez de fallar silenciosamente
```

### Typed Properties y Constructor Promotion

```php
<?php
declare(strict_types=1);

// ❌ ANTES de PHP 8.0: properties sin tipos
class UsuarioViejo
{
    public $nombre;    // mixed (cualquier cosa)
    public $email;     // mixed (cualquier cosa)
    public $saldo;     // mixed (¡un string podría llegar aquí!)

    public function __construct($nombre, $email, $saldo)
    {
        $this->nombre = $nombre;  // Sin validación, sin tipo
        $this->email = $email;
        $this->saldo = $saldo;
    }
}

// ✅ DESPUÉS de PHP 8.0+: constructor promotion + tipos
class UsuarioNuevo
{
    // public function __construct() declaration con parámetros tipados.
    // PHP crea automáticamente las properties y asigna los valores.
    // No necesitas escribir: $this->nombre = $nombre;
    public function __construct(
        public readonly string $nombre,     // string obligatorio, no modificable
        public readonly string $email,      // string obligatorio, no modificable
        public readonly int $saldoCentavos, // int obligatorio, no modificable
    ) {
        // El body del constructor está VACÍO.
        // PHP crea las properties y asigna los valores automáticamente.
        // Esto reduce ~60% de código repetitivo.
    }
}

// Uso:
$usuario = new UsuarioNuevo('Juan', 'juan@email.com', 150000);
echo $usuario->nombre;        // "Juan"
$usuario->nombre = 'Pedro';   // ❌ Error: readonly property
```

### match: El switch Moderno

```php
<?php
declare(strict_types=1);

// ❌ ANTES: switch verbose y propenso a errores
function estadoAnterior(string $estado): string
{
    switch ($estado) {
        case 'pendiente':
            return 'Pendiente de procesamiento';
        case 'completada':
            return 'Completada exitosamente';
        case 'fallida':
            return 'Falló el procesamiento';
        default:
            return 'Estado desconocido';
    }
}

// ✅ DESPUÉS: match expresivo, conciso, type-safe
function estadoActual(string $estado): string
{
    // match() es una EXPRESIÓN (retorna valor), no una statement.
    // Usa comparación ESTRICTA (===), no loose (==).
    // El compilador verifica que TODOS los casos estén cubiertos.
    return match ($estado) {
        'pendiente' => 'Pendiente de procesamiento',
        'completada' => 'Completada exitosamente',
        'fallida' => 'Falló el procesamiento',
        // default es OPCIONAL. Sin él, PHP lanza UnhandledMatchError
        // si el valor no coincide con ningún caso.
        default => 'Estado desconocido',
    };
}
```

### Enums (PHP 8.1+)

```php
<?php
declare(strict_types=1);

// Enums: tipos nativos que reemplazan constantes de clase.
// Ventaja principal: TYPE SAFETY. No puedes pasar un valor inválido.

// BackedEnum: tiene un valor (string o int) asociado a cada caso.
enum Moneda: string
{
    case MXN = 'MXN';
    case USD = 'USD';
    case EUR = 'EUR';
}

// Uso:
$moneda = Moneda::MXN;
echo $moneda->value;     // "MXN" (el valor string)
echo Moneda::from('USD'); // Moneda::USD (convierte string → enum)
echo Moneda::tryFrom('BTC'); // null (no lanza excepción)

// ¿Por qué no constantes de clase?
// class MonedaConstantes { const MXN = 'MXN'; const USD = 'USD'; }
// Porque: MonedaConstantes::MXN === 'MXN' es true (comparación por valor)
// Pero: Moneda::MXN === Moneda::USD es false (comparación por caso)
// Y: Moneda::from('BTC') lanza ValueError (validación automática)
```

### Named Arguments

```php
<?php
declare(strict_types=1);

// Named arguments: pasar argumentos por nombre, no por posición.
// Esto hace el código más legible y menos propenso a errores de orden.

// Sin named arguments (¿qué es el tercer parámetro?):
ạoar('Juan', 'juan@email.com', true, 30, 'admin');

// Con named arguments (autoexplicativo):
crearUsuario(
    nombre: 'Juan',
    email: 'juan@email.com',
    activo: true,
    edad: 30,
    rol: 'admin',
);

// Ventaja enබාdia: puedes SKIPEAR parámetros con default.
// Sin named args: crearUsuario('Juan', 'juan@email.com', true, 30, 'admin')
// Con named args: crearUsuario(nombre: 'Juan', email: 'juan@email.com')
//                 (activo, edad, rol usan sus valores por defecto)
```

### Nullsafe Operator (PHP 8.0+)

```php
<?php
declare(strict_types=1);

// El operador ?-> permite encadenar llamadas a métodos que pueden retornar null.
// Sin nullsafe: necesitas verificar null en cada paso.
// Con nullsafe: si CUALQUIER paso retorna null, toda la cadena retorna null.

// ❌ SIN nullsafe:
$ciudad = null;
if ($usuario !== null) {
    $direccion = $usuario->direccion();
    if ($direccion !== null) {
        $ciudad = $direccion->ciudad();
    }
}

// ✅ CON nullsafe:
// Si $usuario es null, toda la expresión retorna null (sin error).
// Si $usuario->direccion() retorna null, toda la expresión retorna null.
// Un solo null en la cadena propaga null al resultado final.
$ciudad = $usuario?->direccion()?->ciudad();
```

### Readonly Classes (PHP 8.2+)

```php
<?php
declare(strict_types=1);

// readonly class: TODAS las properties son readonly automáticamente.
// Esto es ideal para Value Objects que deben ser inmutables.

// ❌ SIN readonly (debes marcar cada property individualmente):
class DineroViejo
{
    public function __construct(
        private readonly int $centavos,    // readonly manual
        private readonly string $moneda,   // readonly manual
    ) {}
}

// ✅ CON readonly class (todas son readonly automáticamente):
final readonly class DineroNuevo
{
    public function __construct(
        private int $centavos,    // Ya es readonly por la clase
        private string $moneda,   // Ya es readonly por la clase
    ) {}
}

// Intentar modificar lanza Error:
// $dinero->centavos = 100; // Error: Cannot modify readonly property
```

### Checkpoint 1.2 ✓

```bash
# Crear un archivo de prueba para verificar que PHP 8.3 funciona
cat > /tmp/php83-test.php << 'EOF'
<?php
declare(strict_types=1);

// Test: constructor promotion + readonly + enum + match
enum Estado: string {
    case PENDIENTE = 'pendiente';
    case COMPLETADA = 'completada';
}

final readonly class Transaccion {
    public function __construct(
        public string $id,
        public int $montoCentavos,
        public Estado $estado,
    ) {}
}

$t = new Transaccion(id: 'txn_1', montoCentavos: 150000, estado: Estado::PENDIENTE);
echo match($t->estado) {
    Estado::PENDIENTE => "Pendiente",
    Estado::COMPLETADA => "Completada",
} . PHP_EOL;

echo "PHP " . PHP_VERSION . " funciona correctamente" . PHP_EOL;
EOF

php /tmp/php83-test.php
# Salida: Pendiente
#         PHP 8.3.x funciona correctamente
```

---

## 1.3 Objetos de Valor de Dinero

### ¿Qué es un Value Object?

Un Value Object es un objeto que representa un concepto del dominio y se
identifica por sus **valores**, no por un ID.

```
Entity:                    Value Object:
├─ Tiene ID único          ├─ No tiene ID
├─ Dos instancias =        ├─ Dos instancias con mismos valores =
│  diferentes (distinto ID)│  equivalentes (mismo contenido)
├─ Puede ser mutable       ├─ SIEMPRE inmutable
└─ Ej: Billetera (wallet   └─ Ej: Dinero ($1500 MXN),
    con ID wallet_001)         Moneda (MXN), Movimiento
```

### Diseñar el Value Object Dinero

Crearemos el VO Dinero paso a paso con TDD. Primero los tests, luego la
implementación.

#### Paso 1: Definir el Enum Moneda

```php
<?php
// src/Domain/Moneda.php

declare(strict_types=1);

namespace App\Domain;

/**
 * Monedas soportadas por PrestaFlow.
 *
 * Enum con valor string (BackedEnum).
 * Cada caso tiene el código ISO 4217 como valor.
 *
 * ¿Por qué enum y no constantes?
 *   - Type-safe: Moneda::from('BTC') lanza ValueError
 *   - Auto-completado: el IDE conoce todos los valores
 *   - Comparación: $moneda === Moneda::MXN (no strings)
 */
enum Moneda: string
{
    case MXN = 'MXN';
    case USD = 'USD';
    case EUR = 'EUR';

    /**
     * Número de decimales para esta moneda.
     * Todas las fiat usan 2 (centavos).
     */
    public function decimales(): int
    {
        return 2;
    }

    /**
     * Símbolo visual de la moneda.
     * Solo para UI, nunca para lógica de negocio.
     */
    public function simbolo(): string
    {
        return match ($this) {
            self::MXN => '$',
            self::USD => '$',
            self::EUR => '€',
        };
    }
}
```

#### Paso 2: Escribir el Test Primero (TDD)

```php
<?php
// tests/Unit/Domain/DineroTest.php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Dinero;
use App\Domain\Moneda;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DineroTest extends TestCase
{
    // Test: crear dinero desde monto decimal
    #[Test]
    public function test_crear_dinero_desde_monto_decimal(): void
    {
        $dinero = Dinero::crear('1500.00', Moneda::MXN);

        $this->assertSame(150000, $dinero->centavos());
        $this->assertSame(Moneda::MXN, $dinero->moneda());
    }

    // Test: monto negativo debe fallar
    #[Test]
    public function test_monto_negativo_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dinero::crear('-100.00', Moneda::MXN);
    }

    // Test: DataProvider con múltiples montos
    #[Test]
    #[DataProvider('montonParaCentavos')]
    public function test_conversion_centavos(string $monto, int $centavosEsperados): void
    {
        $dinero = Dinero::crear($monto, Moneda::MXN);
        $this->assertSame($centavosEsperados, $dinero->centavos());
    }

    public static function montonParaCentavos(): array
    {
        return [
            'un centavo' => ['0.01', 1],
            'un peso' => ['1.00', 100],
            'quinientos' => ['500.00', 50000],
            'sin decimales' => ['100', 10000],
        ];
    }
}
```

#### Paso 3: Implementar (GREEN)

```php
<?php
// src/Domain/Dinero.php

declare(strict_types=1);

namespace App\Domain;

final readonly class Dinero
{
    private function __construct(
        private int $centavos,
        private Moneda $moneda,
    ) {}

    public static function crear(string $monto, Moneda $moneda): self
    {
        $valor = filter_var($monto, FILTER_VALIDATE_FLOAT);
        if ($valor === false) {
            throw new \InvalidArgumentException("Monto inválido: '{$monto}'");
        }
        if ($valor < 0) {
            throw new \InvalidArgumentException("Monto negativo: '{$monto}'");
        }
        return new self((int) round($valor * 100), $moneda);
    }

    public static function desdeCentavos(int $centavos, Moneda $moneda): self
    {
        if ($centavos < 0) {
            throw new \InvalidArgumentException("Centavos negativos: {$centavos}");
        }
        return new self($centavos, $moneda);
    }

    public function centavos(): int
    {
        return $this->centavos;
    }

    public function formateadoDecimal(): string
    {
        return number_format($this->centavos / 100, 2, '.', '');
    }

    public function moneda(): Moneda
    {
        return $this->moneda;
    }

    public function sumar(Dinero $otro): self
    {
        if ($this->moneda !== $otro->moneda) {
            throw new \InvalidArgumentException("Monedas distintas");
        }
        return new self($this->centavos + $otro->centavos, $this->moneda);
    }

    public function restar(Dinero $otro): self
    {
        if ($this->moneda !== $otro->moneda) {
            throw new \InvalidArgumentException("Monedas distintas");
        }
        $diferencia = $this->centavos - $otro->centavos;
        if ($diferencia < 0) {
            throw new \Exception('Fondos insuficientes');
        }
        return new self($diferencia, $this->moneda);
    }

    public function __toString(): string
    {
        return "{$this->formateadoDecimal()} {$this->moneda->value}";
    }
}
```

#### Paso 4: Ejecutar y Verificar (GREEN)

```bash
cd wallet-service
./vendor/bin/phpunit tests/Unit/Domain/DineroTest.php --testdox
```

**Salida esperada:**

```
App\Tests\Unit\Domain\DineroTest
 ✓ Crear dinero desde monto decimal
 ✓ Monto negativo lanza excepción
 ✓ Conversion centavos (un centavo)
 ✓ Conversion centavos (un peso)
 ✓ Conversion centavos (quinientos)
 ✓ Conversion centavos (sin decimales)

Tests: 6 passed, 6 assertions
```

### Checkpoint 1.3 ✓

```bash
./vendor/bin/phpunit tests/Unit/Domain/DineroTest.php
# Todos los tests pasan (verde)
git add src/Domain/ tests/
git commit -m "test: Dinero VO tests (TDD red→green)"
```

---

## 1.4 El Aggregate Billetera

### ¿Qué es un Aggregate Root?

Un Aggregate Root es el "gatekeeper" de un cluster de objetos relacionados.
Todas las modificaciones al cluster pasan por el Aggregate Root.

```
┌─────────────────────────────────────────────────────┐
│                  Billetera (Aggregate Root)          │
│                                                     │
│  ID: wallet_001                                     │
│  Usuario: user_001                                  │
│  Moneda: MXN                                        │
│                                                     │
│  ┌─────────────────────────────────────────────┐    │
│  │  Movimientos[] (Value Objects)              │    │
│  │                                             │    │
│  │  [0] Depósito: $500.00 MXN                 │    │
│  │  [1] Retiro:   $200.00 MXN                 │    │
│  │  [2] Depósito: $1000.00 MXN                │    │
│  │                                             │    │
│  │  Balance calculado: $1300.00 MXN            │    │
│  └─────────────────────────────────────────────┘    │
│                                                     │
│  ✓ balance() → Dinero                               │
│  ✓ depositar(monto, desc) → Movimiento              │
│  ✓ retirar(monto, desc) → Movimiento | FondosInsuf │
└─────────────────────────────────────────────────────┘
```

**Reglas de negocio encapsuladas:**

1. No se puede depositar monto cero
2. No se puede depositar moneda diferente a la de la billetera
3. No se puede retirar más que el saldo actual
4. El balance se calcula recursivamente (no se almacena)

### Crear la Excepción de Dominio

```php
<?php
// src/Domain/Exception/FondosInsuficientes.php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Dinero;

/**
 * Excepción de dominio: fondos insuficientes para la transacción.
 *
 * Carry datos del contexto para que el controller pueda:
 *   1. Retornar 422 con información descriptiva
 *   2. Loggear el intento fallido
 *   3. Notificar al usuario
 */
final class FondosInsuficientes extends \DomainException
{
    public function __construct(
        public readonly Dinero $saldoActual,
        public readonly Dinero $montoSolicitado,
    ) {
        parent::__construct(
            "Fondos insuficientes: solicitado {$montoSolicitado}, "
            . "disponible {$saldoActual}"
        );
    }

    public function deficit(): Dinero
    {
        return Dinero::desdeCentavos(
            $this->montoSolicitado->centavos() - $this->saldoActual->centavos(),
            $this->montoSolicitado->moneda(),
        );
    }
}
```

### Crear el Aggregate Billetera (TDD)

```bash
# Ejecutar tests de Billetera
cd wallet-service
./vendor/bin/phpunit tests/Unit/Domain/BilleteraTest.php --testdox
```

**Salida esperada:**

```
App\Tests\Unit\Domain\BilleteraTest
 ✓ Billetera nueva tiene balance cero
 ✓ Depositar incrementa balance
 ✓ Multiples depositos acumulan balance
 ✓ Depositar monto cero lanza excepción
 ✓ Depositar moneda diferente lanza excepción
 ✓ Retirar decrementa balance
 ✓ Retirar saldo insuficiente lanza FondosInsuficientes
 ✓ Retirar monto cero lanza excepción
 ✓ Movimientos se mantienen en orden
 ✓ Movimientos retorna copia

Tests: 10 passed, 14 assertions
```

### Checkpoint 1.4 ✓

```bash
./vendor/bin/phpunit tests/Unit/Domain/BilleteraTest.php
# Todos los tests pasan
git add src/Domain/ tests/
git commit -m "feat: implementar aggregate Billetera (TDD red→green)"
```

---

## 1.5 Puerta de Entrada: Controller y Health Check

### Patrón Thin Controller

En arquitectura hexagonal, el controller es delgado:
recibe HTTP, delega al dominio, retorna respuesta.

```
HTTP Request → Controller → [Domain Logic] → Response
    │              │              │              │
    │         Solo recibe    Donde vive      Solo retorna
    │         parámetros     la lógica       JSON/status
    │         del request     de negocio
```

### Implementar SaludController

```php
<?php
// src/Controller/SaludController.php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class SaludController extends AbstractController
{
    #[Route('/salud', name: 'api_salud', methods: ['GET'])]
    public function salud(): JsonResponse
    {
        return $this->json([
            'servicio' => 'wallet-service',
            'estado' => 'ok',
            'version' => '1.0.0',
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ]);
    }
}
```

### Ejecutar y Probar

```bash
# Iniciar el servidor de desarrollo
symfony serve -d public

# En otra terminal, probar el endpoint
curl -s https://127.0.0.1:8000/salud | python3 -m json.tool
```

**Salida esperada:**

```json
{
    "servicio": "wallet-service",
    "estado": "ok",
    "version": "1.0.0",
    "timestamp": "2026-09-10T15:30:00+00:00"
}
```

### Checkpoint 1.5 ✓

```bash
# Verificar que el servidor responde
curl -s https://127.0.0.1:8000/salud | jq '.estado'
# Salida: "ok"
```

---

## 1.6 Tests de Arquitectura y Quality Gates

### ¿Por qué Tests de Arquitectura?

Los tests unitarios verifican **comportamiento** (¿hace lo correcto?).
Los tests de arquitectura verifican **estructura** (¿está bien organizado?).

```
┌──────────────────────────────────────────────────────┐
│           Test金字塔 en PrestaFlow                    │
│                                                      │
│                    /\                                 │
│                   /  \        Tests de Arquitectura   │
│                  / 2  \       (Dominio puro, Readonly)│
│                 /______\                              │
│                /        \      Tests Unitarios        │
│               /   15     \    (Billetera, Dinero)     │
│              /____________\                           │
│             /              \   Tests de Integración   │
│            /      50        \  (HTTP + DB) [Parte 2]  │
│           /__________________\                        │
│                                                      │
│  NOTA: Más tests arriba = más rápidos y baratos.      │
│  Menos tests abajo = más lentos pero más realistas.   │
└──────────────────────────────────────────────────────┘
```

### Ejecutar Todos los Quality Gates

```bash
cd wallet-service

# 1. Tests unitarios
make test

# 2. Análisis estático
make stan

# 3. Formateo de código
make fix-check

# 4. Pipeline completa (como CI)
make ci
```

**Salida esperada de make ci:**

```
PHPUnit 11.3.0 by Sebastian Bergmann and contributors.

.....................                                           21 / 21 (100%)

Time: 00:00.045, Memory: 4.12 MB

OK (21 tests, 25 assertions)

 ------ -----------------------------------------------
  Line   src/Domain/Billetera.php
 ------ -----------------------------------------------
  [OK] No errors
 ------ -----------------------------------------------

PHP-CS-Fixer v3.65.0 by Fabien Potencier and contributors.
Using cache file ".php-cs-fixer.cache".
Loaded fixture configuration from rule sets.

   0 changes applied to 21 files

══════════════════════════════════════════
  ✅ CI PASSED — Todos los checks verdes
══════════════════════════════════════════
```

### Checkpoint Final de la Parte 1 ✓

```bash
# Ejecutar CI completo
make ci

# Tag de checkpoint
git tag parte1

# Verificar estructura
tree src/Domain/
```

**Salida esperada:**

```
src/Domain/
├── Billetera.php
├── Dinero.php
├── Moneda.php
├── Movimiento.php
├── TipoMovimiento.php
└── Exception/
    ├── FondosInsuficientes.php
    └── MonedaNoSoportada.php
```

---

## Ejercicios de la Parte 1

Los ejercicios se encuentran en la carpeta `ejercicios/`. Cada uno tiene:
- Enunciado con contexto
- Código de partida o instrucciones
- Entregable concreto
- Verificación automática

---

### Ejercicio 1.1: Crear el Symfony Skeleton

**Objetivo**: Crear el wallet-service con las dependencias correctas.

**Instrucciones**:

1. Crear proyecto con `symfony new wallet-service --version=7.4.* --no-git`
2. Instalar dependencias de testing: `composer require --dev phpunit/phpunit phpstan/phpstan phpstan/phpstan-symfony php-cs-fixer/shim symfony/phpunit-bridge`
3. Crear `phpunit.xml.dist` según la plantilla de la Parte 1
4. Crear `phpstan.neon` con nivel 6
5. Crear `.php-cs-fixer.dist.php` con reglas PSR-12 + PHP83Migration
6. Crear `Makefile` con targets: test, stan, fix, ci, serve

**Verificación**:

```bash
cd wallet-service
make ci  # Debe pasar sin errores
```

---

### Ejercicio 1.2: Crear el Enum Moneda

**Objetivo**: Implementar el enum de monedas soportadas.

**Instrucciones**:

1. Crear `src/Domain/Moneda.php` con casos: MXN, USD, EUR
2. Implementar método `decimales()` que retorne 2 para todas
3. Implementar método `simbolo()` que retorne el símbolo visual
4. Crear test `tests/Unit/Domain/MonedTest.php` con:
   - Test de que MXN tiene decimales = 2
   - Test de que el símbolo de EUR es "€"
   - Test de que from('BTC') lanza ValueError

**Verificación**:

```bash
./vendor/bin/phpunit tests/Unit/Domain/MonedTest.php --testdox
```

---

### Ejercicio 1.3: Crear el Value Object Dinero (TDD)

**Objetivo**: Implementar Dinero con TDD completo.

**Instrucciones**:

1. Escribir tests primero (RED)
2. Implementar Dinero::crear(), desdeCentavos(), centavos(), sumar(), restar()
3. Verificar que todos los tests pasan (GREEN)
4. Refactorizar si es necesario

**Entregable**: `src/Domain/Dinero.php` + `tests/Unit/Domain/DineroTest.php`

**Puntos**:

| Criterio | Puntos |
|---|---|
| Clase es final readonly | 1 |
| crear() valida monto no negativo | 1 |
| desdeCentavos() funciona correctamente | 1 |
| sumar() valida moneda igual | 1 |
| restar() lanza FondosInsuficientes | 1 |
| 8+ tests pasan | 1 |
| **Total** | **6** |

---

### Ejercicio 1.4: Crear TipoMovimiento y Movimiento

**Objetivo**: Implementar los Value Objects de tipo y movimiento.

**Instrucciones**:

1. Crear enum `TipoMovimiento` con casos DEPOSITO y RETIRO
2. Implementar `esAcreditacion()` y `etiqueta()`
3. Crear `Movimiento` con factory methods `deposito()` y `retiro()`
4. Tests unitarios para ambos

**Verificación**:

```bash
./vendor/bin/phpunit tests/Unit/Domain/MovimientoTest.php --testdox
```

---

### Ejercicio 1.5: Crear el Aggregate Billetera (TDD)

**Objetivo**: Implementar la Billetera con todas las reglas de negocio.

**Instrucciones**:

1. Escribir tests para: balance, depositar, retirar, historial
2. Implementar Billetera con factory method o constructor
3. Implementar validaciones de negocio en depositar() y retirar()
4. Crear excepción FondosInsuficientes carry data

**Entregable**: `src/Domain/Billetera.php` + tests

**Puntos**:

| Criterio | Puntos |
|---|---|
| Balance calculado correctamente | 2 |
| Depósito valida moneda y monto | 2 |
| Retiro valida fondos suficientes | 2 |
| FondosInsuficientes carry saldo + déficit | 1 |
| Historial mantiene orden | 1 |
| 8+ tests pasan | 2 |
| **Total** | **10** |

---

### Ejercicio 1.6: SaludController

**Objetivo**: Crear el health check endpoint.

**Instrucciones**:

1. Crear `SaludController` con GET /salud
2. Retornar JSON con: servicio, estado, version, timestamp
3. Crear test funcional con `WebTestCase`

**Verificación**:

```bash
symfony serve -d public
curl -s https://127.0.0.1:8000/salud | jq '.estado'
# Salida: "ok"
```

---

### Ejercicio 1.7: Test de Arquitectura

**Objetivo**: Verificar que el dominio es puro.

**Instrucciones**:

1. Crear `tests/Architecture/DominioPuroTest.php`
2. Test que verifique que src/Domain/ no importa Symfony
3. Test que verifique que los Value Objects son readonly
4. Test que verifique que todos los archivos usan strict_types

**Verificación**:

```bash
./vendor/bin/phpunit tests/Architecture/DominioPuroTest.php --testdox
```

---

### Ejercicio 1.8: Quality Gates

**Objetivo**: Configurar y ejecutar la pipeline completa de calidad.

**Instrucciones**:

1. Configurar PHPStan nivel 6 en phpstan.neon
2. Configurar CS-Fixer en .php-cs-fixer.dist.php
3. Crear Makefile con targets: test, stan, fix, ci
4. Ejecutar `make ci` y verificar que pasa

**Verificación**:

```bash
make ci
# Debe mostrar: ✅ CI PASSED
```

---

### Ejercicio 1.9: Commit con Conventional Commits

**Objetivo**: Documentar el historial correctamente.

**Instrucciones**:

1. Crear al menos 5 commits con mensajes Convencionales
2. El historial debe mostrar el ciclo TDD claramente
3. Usar tags de checkpoint

**Verificación**:

```bash
git log --oneline
# Salida esperada:
# e5f6g7h test: test de arquitectura dominio puro
# d4e5f6g feat: implementar aggregate Billetera
# c3d4e5f test: tests de Billetera (TDD red→green)
# b2c3d4e feat: implementar VO Dinero
# a1b2c3d test: tests de Dinero (TDD red→green)
# 9z0a1b2 chore: configurar PHPStan + CS-Fixer + Makefile
```

---

### Ejercicio 1.10: Documentar con README

**Objetivo**: Crear un README.md para el wallet-service.

**Instrucciones**:

1. Crear `wallet-service/README.md` con:
   - Descripción del servicio
   - Stack tecnológico
   - Cómo instalar y ejecutar
   - Cómo ejecutar tests
   - Estructura de directorios
   - API endpoints (solo /salud por ahora)

**Verificación**: El README contiene todas las secciones solicitadas.

---

## Siguiente Paso

Una vez completados todos los ejercicios de la Parte 1, avanza a la **Parte 2: Symfony Framework y Arquitectura Hexagonal** donde:
- Conectaremos PostgreSQL con Doctrine ORM
- Configuraremos Docker Compose con servicios múltiples
- Implementaremos controllers con lógica real
- Agregaremos más endpoints a la API de PrestaFlow
