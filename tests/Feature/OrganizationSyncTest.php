<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_replace_a_counselors_class_assignments(): void
    {
        $admin = User::create([
            'username' => 'admin',
            'name' => '系统管理员',
            'password' => 'admin123',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $college = College::create(['name' => '信息工程学院', 'is_active' => true]);
        $major = Major::create([
            'college_id' => $college->id,
            'name' => '计算机科学与技术',
            'is_active' => true,
        ]);

        $classes = collect(['计算机科学与技术2601班', '计算机科学与技术2602班', '计算机科学与技术2603班'])
            ->map(fn ($name) => SchoolClass::create([
                'college_id' => $college->id,
                'major_id' => $major->id,
                'name' => $name,
                'grade' => '2026级',
                'is_active' => true,
            ]));

        $teacher = User::create([
            'username' => 'T001',
            'name' => '辅导员张老师',
            'password' => 'teacher123',
            'role' => 'teacher',
            'employee_id' => 'T001',
            'college_id' => $college->id,
            'major_id' => $major->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post('/admin/organization/class-admins/sync', [
            'user_id' => $teacher->id,
            'college_id' => $college->id,
            'class_ids' => [$classes[0]->id, $classes[1]->id],
        ])->assertRedirect();

        $teacher->refresh();
        $this->assertSame('class_admin', $teacher->role);
        $this->assertEqualsCanonicalizing(
            [$classes[0]->id, $classes[1]->id],
            $teacher->managedClasses()->pluck('classes.id')->all(),
        );

        $this->actingAs($admin)->post('/admin/organization/class-admins/sync', [
            'user_id' => $teacher->id,
            'college_id' => $college->id,
            'class_ids' => [$classes[1]->id, $classes[2]->id],
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing(
            [$classes[1]->id, $classes[2]->id],
            $teacher->fresh()->managedClasses()->pluck('classes.id')->all(),
        );

        $this->actingAs($admin)->post('/admin/organization/class-admins/sync', [
            'user_id' => $teacher->id,
            'college_id' => $college->id,
            'class_ids' => [],
        ])->assertRedirect();

        $teacher->refresh();
        $this->assertSame('teacher', $teacher->role);
        $this->assertSame(0, $teacher->managedClasses()->count());
    }
}
