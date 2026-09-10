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
