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
