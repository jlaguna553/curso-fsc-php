<?php
// .php-cs-fixer.dist.php — Configuración de PHP-CS-Fixer
//
// PHP-CS-Fixer es un formateador de código PHP.
// Reescribe el código para que cumpla estándares de estilo (PSR-12).
//
// ¿Por qué automático y no manual?
// Porque discutir formato de código es una pérdida de tiempo.
// El formateador decide por nosotros, y todos escribimos igual.
//
// CS = Coding Standards (Estándares de Codificación)

declare(strict_types=1);

// PhpCsFixer\Finder busca archivos PHP en los directorios especificados.
use PhpCsFixer\Finder;
use PhpCsFixer\Config;

// find() retorna un Finder que busca archivos PHP.
$finder = (new Finder())
    // Buscar en el directorio src/ (código fuente)
    ->in(__DIR__.'/src')
    // Buscar en el directorio tests/ (tests)
    ->in(__DIR__.'/tests')
    // También en la raíz (para index.php, etc.)
    ->in(__DIR__)
    // Excluir directorios que no queremos formatear
    ->exclude('vendor')
    ->exclude('var')
    ->exclude('coverage');

// Config: define qué reglas de estilo aplicar.
return (new Config())
    // El finder define QUÉ archivos formatear.
    ->setFinder($finder)
    // @PHP83Migration: reglas de migración a PHP 8.3
    // Incluye: typed properties, constructor promotion, enums, etc.
    // @Symfony: reglas del estándar Symfony (basado en PSR-12)
    ->setRules([
        '@PHP83Migration' => true,
        '@Symfony' => true,
        // Reglas específicas que queremos forzar:

        // single_quote: usar comillas simples en strings.
        // 'string' es preferido sobre "string" en PHP (más rápido, más limpio).
        'single_quote' => true,

        // array_syntax: declarar arrays con [] en lugar de array().
        // $arr = [1, 2, 3]; es preferido sobre $arr = array(1, 2, 3);
        'array_syntax' => ['syntax' => 'short'],

        // no_unused_imports: eliminar imports que no se usan.
        // Si importas DateTimeImmutable pero no lo usas, lo elimina.
        'no_unused_imports' => true,

        // ordered_imports: ordenar imports alfabéticamente.
        // Primeros los de App\, luego los de Symfony\, luego los nativos.
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],

        // trailing_comma_in_multiline: agregar coma al final de arrays multilínea.
        // Esto reduce diffs en Git: agregar un elemento = solo una línea changed.
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'match', 'parameters'],
        ],

        // declare_strict_types: forzar declare(strict_types=1) en todos los archivos.
        // Esto es OBLIGATORIO en nuestro curso. Sin strict types, PHP coerce tipos
        // silenciosamente, causando bugs difíciles de encontrar.
        'declare_strict_types' => true,
    ])
    // Retornar el Config (no es necesario un return explícito en PHP 8.1+,
    // pero lo incluimos por claridad didáctica).
    ->setRiskyAllowed(true);  // Permitir reglas "risky" (pueden cambiar comportamiento)
