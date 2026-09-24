<?php

namespace App\Support;

/**
 * Icinde bulunulan kurumu (tenant) tutan tekil nesne.
 *
 * Global scope bu degeri okur. Kuyruk isçisi gibi HTTP istegi olmayan
 * baglamlarda tenant elle set edilir: Tenancy::use($id).
 */
final class Tenancy
{
    private static ?int $tenantId = null;

    public static function use(?int $tenantId): void
    {
        self::$tenantId = $tenantId;
    }

    public static function id(): ?int
    {
        return self::$tenantId;
    }

    public static function has(): bool
    {
        return self::$tenantId !== null;
    }

    /** Verilen isi gecici olarak baska bir tenant altinda calistirir. */
    public static function forget(): void
    {
        self::$tenantId = null;
    }
}
