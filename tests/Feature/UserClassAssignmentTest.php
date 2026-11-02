<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserClassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private College $college;

    private Major $major;

    private Major $otherMajor;

    private SchoolClass $classA;

    private SchoolClass $classB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin-class', '系统管理员', 'admin');
        $this->college = College::create(['name' => '信息工程学院', 'is_active' => true]);
        $this->major = Major::create([
            'college_id' => $this->college->id,
            'name' => '计算机科学与技术',
            'is_active' => true,
        ]);
        $this->otherMajor = Major::create([
            'college_id' => $this->college->id,
            'name' => '软件工程',
            'is_active' => true,
        ]);
        $this->classA = $this->makeClass('计算机2601班', $this->major, '2026级');
        $this->classB = $this->makeClass('计算机2602班', $this->major, '2026级');
    }

    public function test_user_list_passes_major_id_for_class_dropdown(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Index')
                ->where('classes.0.major_id', $this->major->id)
                ->where('classes.0.college_id', $this->college->id));
    }

    public function test_admin_can_assign_class_and_grade_is_filled_from_class(): void
    {
        $student = $this->makeStudent('2608001', '杨子路');

        $this->actingAs($this->admin)
            ->put("/admin/users/{$student->id}", $this->studentPayload($student, [
                'class_id' => $this->classB->id,
                'major_id' => $this->major->id,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $student->refresh();

        $this->assertSame($this->classB->id, $student->class_id);
        $this->assertSame($this->classB->name, $student->class_name);
        $this->assertSame('2026级', $student->grade);
        $this->assertSame($this->major->id, $student->major_id);
    }

    public function test_assigning_class_fills_missing_major_from_class(): void
    {
        $student = $this->makeStudent('2608002', '无专业学生', [
            'major_id' => null,
            'class_id' => null,
            'class_name' => null,
            'grade' => null,
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/users/{$student->id}", $this->studentPayload($student, [
                'class_id' => $this->classA->id,
                'major_id' => '',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $student->refresh();

        $this->assertSame($this->classA->id, $student->class_id);
        $this->assertSame($this->major->id, $student->major_id);
        $this->assertSame('2026级', $student->grade);
    }

    public function test_admin_can_unassign_class(): void
    {
        $student = $this->makeStudent('2608003', '已分班学生', [
            'class_id' => $this->classA->id,
            'class_name' => $this->classA->name,
            'grade' => $this->classA->grade,
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/users/{$student->id}", $this->studentPayload($student, [
                'class_id' => '',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $student->refresh();

        $this->assertNull($student->class_id);
        $this->assertNull($student->class_name);
        $this->assertNull($student->grade);
        $this->assertSame($this->major->id, $student->major_id);
    }

    public function test_class_admin_can_move_student_between_own_classes_only(): void
    {
        $outside = $this->makeClass('软件2601班', $this->otherMajor, '2026级');
        $classAdmin = $this->makeClassAdmin($this->classA);
        $student = $this->makeStudent('2608004', '班级学生', [
            'class_id' => $this->classA->id,
            'class_name' => $this->classA->name,
            'grade' => $this->classA->grade,
        ]);

        $this->actingAs($classAdmin)
            ->put("/admin/users/{$student->id}", $this->studentPayload($student, [
                'class_id' => $this->classB->id,
            ]));

        $this->assertSame($this->classB->id, $student->fresh()->class_id);

        $this->actingAs($classAdmin)
            ->put("/admin/users/{$student->id}", $this->studentPayload($student, [
                'class_id' => $outside->id,
            ]))
            ->assertSessionHasErrors('class_id');

        $this->assertSame($this->classB->id, $student->fresh()->class_id);
    }

    public function test_college_admin_cannot_assign_class_from_other_college(): void
    {
        $otherCollege = College::create(['name' => '商学院', 'is_active' => true]);
        $otherMajor = Major::create([
            'college_id' => $otherCollege->id,
            'name' => '工商管理',
            'is_active' => true,
        ]);
        $outside = $this->makeClass('工商2601班', $otherMajor, '2026级');
        $collegeAdmin = $this->makeCollegeAdmin();
        $student = $this->makeStudent('2608005', '本院学生');

        $this->actingAs($collegeAdmin)
            ->put("/admin/users/{$student->id}", $this->studentPayload($student, [
                'class_id' => $outside->id,
            ]))
            ->assertSessionHasErrors('class_id');

        $this->assertNull($student->fresh()->class_id);
    }

    private function makeClass(string $name, Major $major, string $grade): SchoolClass
    {
        return SchoolClass::create([
            'college_id' => $major->college_id,
            'major_id' => $major->id,
            'name' => $name,
            'grade' => $grade,
            'is_active' => true,
        ]);
    }

    private function makeUser(string $username, string $name, string $role, array $attributes = []): User
    {
        return User::create(array_merge([
            'username' => $username,
            'name' => $name,
            'password' => 'secret123',
            'role' => $role,
            'is_active' => true,
        ], $attributes));
    }

    private function makeStudent(string $studentId, string $name, array $attributes = []): User
    {
        return $this->makeUser($studentId, $name, 'student', array_merge([
            'student_id' => $studentId,
            'college_id' => $this->college->id,
            'college' => $this->college->name,
            'major_id' => $this->major->id,
            'class_id' => null,
            'class_name' => null,
            'grade' => null,
        ], $attributes));
    }

    private function makeClassAdmin(SchoolClass $class): User
    {
        $user = $this->makeUser('ca-'.$class->id, '班级管理员', 'class_admin', [
            'college_id' => $class->college_id,
        ]);

        foreach ([$this->classA, $this->classB] as $item) {
            DB::table('class_admin_user')->insert([
                'user_id' => $user->id,
                'class_id' => $item->id,
                'granted_by' => $this->admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function makeCollegeAdmin(): User
    {
        $user = $this->makeUser('ca-college', '学院管理员', 'college_admin', [
            'college_id' => $this->college->id,
        ]);

        DB::table('college_admin_user')->insert([
            'user_id' => $user->id,
            'college_id' => $this->college->id,
            'granted_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function studentPayload(User $student, array $overrides = []): array
    {
        return array_merge([
            'role' => 'student',
            'name' => $student->name,
            'username' => $student->username,
            'student_id' => $student->student_id,
            'employee_id' => '',
            'class_name' => $student->class_name ?? '',
            'class_id' => $student->class_id ?? '',
            'major_id' => $student->major_id ?? '',
            'college' => $student->college ?? '',
            'college_id' => $student->college_id ?? '',
            'grade' => $student->grade ?? '',
            'teacher_college' => '',
            'teacher_college_id' => '',
            'password' => '',
            'is_active' => true,
        ], $overrides);
    }
}
