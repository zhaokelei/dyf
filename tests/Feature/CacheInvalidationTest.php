<?php

namespace Tests\Feature;

use App\Models\Semester;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_cache_is_cleared_after_update(): void
    {
        Setting::put('cache_probe', 'first');
        $this->assertSame('first', Setting::get('cache_probe'));

        Setting::put('cache_probe', 'second');
        $this->assertSame('second', Setting::get('cache_probe'));

        Setting::query()->where('key', 'cache_probe')->delete();
    }

    public function test_semester_cache_is_cleared_when_current_semester_changes(): void
    {
        $admin = User::create([
            'username' => 'admin',
            'name' => '系统管理员',
            'password' => 'admin123',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $first = Semester::create([
            'academic_year' => '2026-2027',
            'term' => 1,
            'name' => '2026-2027学年第一学期',
            'is_current' => true,
        ]);
        $second = Semester::create([
            'academic_year' => '2026-2027',
            'term' => 2,
            'name' => '2026-2027学年第二学期',
            'is_current' => false,
        ]);

        $this->assertSame($first->id, Semester::current()?->id);

        $this->actingAs($admin)
            ->post("/admin/semesters/{$second->id}/current")
            ->assertRedirect();

        $this->assertSame($second->id, Semester::current()?->id);
    }
}
