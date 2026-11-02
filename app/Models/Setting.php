<?php

namespace App\Models;

use App\Support\SettingsStore;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        return app(SettingsStore::class)->get($key, $default);
    }

    public static function put(string $key, string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        app(SettingsStore::class)->forget();
    }
}
