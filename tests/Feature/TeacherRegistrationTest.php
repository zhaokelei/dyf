<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function organization(): array
    {
        $college = College::create(['name' => '信息工程学院', 'is_active' => true]);
        $major = Major::create([
            'college_id' => $college->id,
            'name' => '计算机科学与技术',
            'is_active' => true,
        ]);

        return [$college, $major];
    }

    private function enableTeacherRegistration(): void
    {
        Setting::put('teacher_registration_enabled', '1');
        Setting::put('teacher_registration_code', 'TEACHER2026');
    }

    public function test_teacher_can_register_with_invite_code_and_is_active_immediately(): void
    {
        [$college] = $this->organization();
        $this->enableTeacherRegistration();

        $response = $this->post('/register/teacher', [
            'employee_id' => 'T2026001',
            'name' => '张老师',
            'college_id' => $college->id,
            'invite_code' => 'TEACHER2026',
            'password' => 'teacher123',
            'password_confirmation' => 'teacher123',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHas('success');

        $teacher = User::where('employee_id', 'T2026001')->firstOrFail();

        $this->assertSame('teacher', $teacher->role);
        $this->assertSame($college->id, $teacher->college_id);
        $this->assertNull($teacher->major_id);
        $this->assertNull($teacher->class_id);
        $this->assertNull($teacher->grade);
        $this->assertTrue($teacher->is_active);

        $this->post('/login', [
            'account' => 'T2026001',
            'password' => 'teacher123',
        ])->assertRedirect('/dashboard');
    }

    public function test_teacher_registration_rejects_wrong_invite_code(): void
    {
        [$college, $major] = $this->organization();
        $this->enableTeacherRegistration();

        $response = $this->from('/register/teacher')->post('/register/teacher', [
            'employee_id' => 'T2026002',
            'name' => '李老师',
            'college_id' => $college->id,
            'major_id' => $major->id,
            'invite_code' => 'WRONG-CODE',
            'password' => 'teacher123',
            'password_confirmation' => 'teacher123',
        ]);

        $response->assertRedirect('/register/teacher');
        $response->assertSessionHasErrors('invite_code');
        $this->assertDatabaseMissing('users', ['employee_id' => 'T2026002']);
    }

    public function test_student_registration_cannot_create_teacher_role(): void
    {
        [$college, $major] = $this->organization();
        $class = SchoolClass::create([
            'college_id' => $college->id,
            'major_id' => $major->id,
            'name' => '计算机科学与技术2601班',
            'grade' => '2026级',
            'is_active' => true,
        ]);
        Setting::put('registration_enabled', '1');
        Setting::put('registration_requires_approval', '0');

        $this->post('/register/student', [
            'role' => 'teacher',
            'student_id' => '2026001',
            'name' => '王同学',
            'college_id' => $college->id,
            'major_id' => $major->id,
            'grade' => '2026级',
            'class_id' => $class->id,
            'password' => 'student123',
            'password_confirmation' => 'student123',
        ])->assertRedirect('/dashboard');

        $this->assertDatabaseHas('users', [
            'student_id' => '2026001',
            'role' => 'student',
        ]);
    }

    public function test_teacher_registration_is_closed_when_disabled(): void
    {
        [$college, $major] = $this->organization();
        Setting::put('teacher_registration_enabled', '0');
        Setting::put('teacher_registration_code', 'TEACHER2026');

        $response = $this->from('/register/teacher')->post('/register/teacher', [
            'employee_id' => 'T2026003',
            'name' => '赵老师',
            'college_id' => $college->id,
            'major_id' => $major->id,
            'invite_code' => 'TEACHER2026',
            'password' => 'teacher123',
            'password_confirmation' => 'teacher123',
        ]);

        $response->assertRedirect('/register/teacher');
        $response->assertSessionHasErrors('employee_id');
        $this->assertDatabaseMissing('users', ['employee_id' => 'T2026003']);
    }
}
