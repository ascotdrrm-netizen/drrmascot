<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * The Data Privacy Password: the second, system-wide secret Download Backup,
 * Restore and Clear All Records ask for. The DRRMO Admin sets it on the
 * Backup & Restore page; only its bcrypt hash is stored, in system_secrets
 * (never in a backup). A hash in DATA_PRIVACY_PASSWORD_HASH (config/privacy.php)
 * still works until one is set in the app.
 */
class DataPrivacyPassword
{
    public const KEY = 'data_privacy_password_hash';

    public static function hash(): ?string
    {
        try {
            $stored = DB::table('system_secrets')->where('key', self::KEY)->value('value');
        } catch (Throwable) {
            $stored = null; // Before the migration has run.
        }

        return filled($stored) ? $stored : (filled(config('privacy.data_privacy_password_hash')) ? config('privacy.data_privacy_password_hash') : null);
    }

    public static function isConfigured(): bool
    {
        return static::hash() !== null;
    }

    public static function check(string $plain): bool
    {
        $hash = static::hash();

        return $hash !== null && Hash::check($plain, $hash);
    }

    public static function set(string $plain): void
    {
        DB::table('system_secrets')->updateOrInsert(['key' => self::KEY],
            ['value' => Hash::make($plain), 'updated_at' => now(), 'created_at' => now()]);
    }
}
