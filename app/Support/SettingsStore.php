<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SettingsStore
{
    private const CACHE_KEY = 'settings.all';

    private ?array $values = null;

    /**
     * @return array<string, string|null>
     */
    public function all(): array
    {
        if ($this->values === null) {
            $this->values = Cache::rememberForever(
                self::CACHE_KEY,
                fn () => DB::table('settings')->pluck('value', 'key')->all()
            );
        }

        return $this->values;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    public function forget(): void
    {
        $this->values = null;
        Cache::forget(self::CACHE_KEY);
    }
}
