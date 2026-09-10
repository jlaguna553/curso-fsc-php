<?php
// src/Infrastructure/Persistence/Doctrine/Type/UuidType.php — Tipo custom para Doctrine
//
# Doctrine ORM necesita tipos personalizados para manejar valores que no son
# tipos nativos de PHP/SQL (como UUIDs, Money, etc.).
#
# Este tipo convierte:
#   PHP → SQL: string UUID → string en columna VARCHAR
#   SQL → PHP: string de DB → string UUID en la entity
#
# Sin este tipo, Doctrine no sabría cómo guardar/recuperar UUIDs.

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Tipo Doctrine para identificadores UUID.
 *
 * Almacena UUID como strings en la base de datos (VARCHAR).
 * En el futuro se podría usar un tipo nativo de PostgreSQL (uuid).
 */
final class UuidType extends Type
{
    /**
     * Nombre del tipo que se usa en los #[Column] de las entidades.
     * Ejemplo: #[Column(type: 'uuid')]
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        // VARCHAR(36): espacio suficiente para un UUID v4.
        // UUID v4 tiene exactamente 36 caracteres: 8-4-4-4-12
        // Ejemplo: "550e8400-e29b-41d4-a716-446655440000"
        return $platform->getStringTypeDeclarationSQL([
            'length' => 36,
        ]);
    }

    /**
     * Nombre del tipo para Doctrine.
     * Se usa en: #[Column(type: 'uuid')]
     */
    public function getName(): string
    {
        return 'uuid';
    }

    /**
     * Convierung PHP → SQL.
     * Doctrine llama a este método antes de insertar/actualizar.
     *
     * @param string $value UUID como string en PHP
     * @param AbstractPlatform $platform Plataforma de BD
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        // Si el valor es null, retornar null (columnas nullable).
        if ($value === null) {
            return null;
        }

        // Retornar el string tal cual (ya es un UUID válido).
        // Doctrine valida que no esté vacío.
        return (string) $value;
    }

    /**
     * Convierung SQL → PHP.
     * Doctrine llama a este método al leer de la base de datos.
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Indica que este tipo es comparable.
     * Doctrine puede comparar valores de este tipo en queries DQL.
     */
    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
