<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'username' => '1001',
            'name' => '测试学生',
            'password' => 'secret123',
            'role' => 'student',
            'student_id' => '1001',
            'is_active' => true,
        ], $attributes));
    }

    public function test_student_can_login_with_student_id(): void
    {
        $user = $this->makeUser();

        $response = $this->post('/login', [
            'account' => '1001',
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->makeUser(['is_active' => false]);

        $response = $this->from('/login')->post('/login', [
            'account' => '1001',
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_student_cannot_access_admin_pages(): void
    {
        $student = $this->makeUser();

        $this->actingAs($student)->get('/admin/users')->assertForbidden();
        $this->actingAs($student)->get('/teacher/group-applications')->assertForbidden();
    }

    public function test_teacher_cannot_access_admin_pages(): void
    {
        $teacher = $this->makeUser([
            'username' => 'T001',
            'name' => '测试教师',
            'role' => 'teacher',
            'student_id' => null,
            'employee_id' => 'T001',
        ]);

        $this->actingAs($teacher)->get('/admin/review')->assertForbidden();
        $this->actingAs($teacher)->get('/teacher/group-applications')->assertOk();
    }
}
