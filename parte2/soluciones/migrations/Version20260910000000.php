<?php
// migrations/Version20260910000000.php — Primera migración de Doctrine
//
# MIGRATION: Script SQL que modifica el esquema de la base de datos.
# Doctrine Migrations es el sistema de version control para tu esquema.
#
# ¿Por qué migraciones y no "doctrine:schema:create"?
#   1. Las migraciones son versionadas (git history del esquema)
#   2. Se pueden ejecutar en producción sin perder datos
#   3. Se pueden revertir si algo sale mal
#   4. El equipo trabaja en el mismo esquema
#
# Flujo:
#   1. Modificar entity (add property, change type, etc.)
#   2. Ejecutar: php bin/console doctrine:migrations:diff
#     Doctrine compara el entity mapping con la BD y genera la migración
#   3. Revisar la migración generada (¡nunca ejecutar sin revisar!)
#   4. Ejecutar: php bin/console doctrine:migrations:migrate

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migración: crear tabla billetera y movimientos.
 *
 * Esta migración crea el esquema inicial para el wallet-service.
 * Se genera automáticamente con doctrine:migrations:diff.
 */
final class Version20260910000000 extends AbstractMigration
{
    /**
     * Descripción de qué hace esta migración.
     * Aparece en la tabla de migraciones y en logs.
     */
    public function getDescription(): string
    {
        return 'Crear tablas billetera y movimiento para wallet-service';
    }

    /**
     * Aplicar la migración (UP).
     *
     * Este método ejecuta las queries SQL para CREAR/MODIFICAR el esquema.
     * Doctrine genera estas queries automáticamente, pero puedes editarlas.
     *
     * @param Schema $schema Esquema actual de la BD (Doctrine lo lee automáticamente)
     */
    public function up(Schema $schema): void
    {
        // ═══════════════════════════════════════════════════════════════════════
        // TABLA: billetera
        // ═══════════════════════════════════════════════════════════════════════

        // addTable(): crea una tabla nueva en el esquema.
        $billeteraTable = $schema->createTable('billetera');

        // addColumn(): agrega una columna a la tabla.
        // El primer argumento es el nombre de la columna.
        // El segundo es la definición (tipo, longitud, nullable, etc.).

        // Columna ID: primary key de la billetera.
        // VARCHAR(36): suficiente para un UUID v4.
        $billeteraTable->addColumn('id', 'string', [
            'length' => 36,
            'notnull' => true,  // La billetera SIEMPRE tiene ID
        ]);

        // Columna usuario_id: referencia al propietario.
        // En la Parte 3 crearemos una tabla usuarios y foreign key.
        $billeteraTable->addColumn('usuario_id', 'string', [
            'length' => 36,
            'notnull' => true,
        ]);

        // Columna moneda: código ISO 4217 de 3 letras.
        // ENUM en PostgreSQL: solo acepta valores definidos.
        // Doctrine genera: CHECK (moneda IN ('MXN', 'USD', 'EUR'))
        $billeteraTable->addColumn('moneda', 'string', [
            'length' => 3,
            'notnull' => true,
        ]);

        // Columna saldo_centavos: saldo actual en centavos (entero).
        // INTEGER en PostgreSQL: rango de -2^31 a 2^31-1.
        // Suficiente para montos hasta $21,474,836.47 MXN.
        $billeteraTable->addColumn('saldo_centavos', 'integer', [
            'notnull' => true,
            'default' => 0,  // Saldo inicial de cualquier billetera es 0
        ]);

        // Columna creado_en: timestamp de creación.
        // La usamos para ordenar billeteras y para auditoría.
        $billeteraTable->addColumn('creado_en', 'datetime_immutable', [
            'notnull' => true,
            // default: Doctrine generará SQL para usar la fecha actual.
            // En PostgreSQL: DEFAULT CURRENT_TIMESTAMP
        ]);

        // Primary key: la columna ID es la llave primaria.
        $billeteraTable->setPrimaryKey(['id']);

        // Index: índice para búsquedas por usuario_id.
        // Sin esto, buscar billeteras de un usuario sería O(n).
        // Con el índice, es O(log n) (~4 pasos para 10,000 billeteras).
        $billeteraTable->addIndex(['usuario_id']);

        // ═══════════════════════════════════════════════════════════════════════
        // TABLA: movimiento
        // ═══════════════════════════════════════════════════════════════════════

        $movimientoTable = $schema->createTable('movimiento');

        $movimientoTable->addColumn('id', 'string', [
            'length' => 36,
            'notnull' => true,
        ]);

        // billetera_id: referencia a la billetera padre.
        // Foreign key: garantiza que cada movimiento pertenezca a una billetera existente.
        $movimientoTable->addColumn('billetera_id', 'string', [
            'length' => 36,
            'notnull' => true,
        ]);

        // monto_centavos: monto del movimiento en centavos.
        // Puede ser positivo (depósito) o negativo (retiro).
        $movimientoTable->addColumn('monto_centavos', 'integer', [
            'notnull' => true,
        ]);

        // moneda: moneda del movimiento (misma que la billetera).
        $movimientoTable->addColumn('moneda', 'string', [
            'length' => 3,
            'notnull' => true,
        ]);

        // tipo: "deposito" o "retiro".
        $movimientoTable->addColumn('tipo', 'string', [
            'length' => 20,
            'notnull' => true,
        ]);

        // descripcion: texto legible para el usuario.
        $movimientoTable->addColumn('descripcion', 'string', [
            'length' => 255,
            'notnull' => true,
        ]);

        // creado_en: timestamp del movimiento.
        $movimientoTable->addColumn('creado_en', 'datetime_immutable', [
            'notnull' => true,
        ]);

        $movimientoTable->setPrimaryKey(['id']);

        // Índices para movimientos.
        // Un usuario consulta "todos los movimientos de billetera X" frecuentemente.
        $movimientoTable->addIndex(['billetera_id']);

        // Foreign key: billetera_id referencia billetera.id.
        // ON DELETE CASCADE: si se elimina la billetera, se eliminan sus movimientos.
        // Sin esto, tendríamos movimientos huérfanos (sin billetera padre).
        $movimientoTable->addForeignKeyConstraint(
            'billetera',            // Tabla referenciada
            ['billetera_id'],       // Columnas de esta tabla
            ['id'],                 // Columnas de la tabla referenciada
            ['onDelete' => 'CASCADE']  // Comportamiento al eliminar
        );
    }

    /**
     * Revertir la migración (DOWN).
     *
     * Este método se ejecuta con doctrine:migrations:migrate --down
     * o doctrine:migrations:rollback.
     * Es el "undo" de la migración.
     *
     * IMPORTANTE: Siempre implementar down() para poder revertir.
     * Sin esto, si la migración falla en producción, no hay forma de volver atrás.
     *
     * @param Schema $schema Esquema actual de la BD
     */
    public function down(Schema $schema): void
    {
        // dropTable(): elimina la tabla y TODOS sus datos.
        // PostgreSQL primero elimina las foreign keys que referencian esta tabla.
        $schema->dropTable('movimiento');
        $schema->dropTable('billetera');
    }
}
