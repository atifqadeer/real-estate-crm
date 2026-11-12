<?php
namespace App\Support;

use App\Models\Organization;

/**
 * Holds the active tenant for the current request/job.
 * Set ONLY by ResolveTenant middleware (HTTP) or explicitly by jobs,
 * after membership has been validated. Never set from raw user input.
 */
class TenantContext
{
    private static ?Organization $organization = null;

    public static function set(?Organization $organization): void { self::$organization = $organization; }
    public static function organization(): ?Organization { return self::$organization; }
    public static function id(): ?int { return self::$organization?->id; }
    public static function clear(): void { self::$organization = null; }
}
