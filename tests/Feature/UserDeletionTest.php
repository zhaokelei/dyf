<?php

namespace Tests\Feature;

use App\Models\ScoreApplication;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private ScoreCategory $category;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'username' => 'admin-delete',
            'name' => '删除测试管理员',
            'password' => 'secret123',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->student = User::create([
            'username' => '3201',
            'name' => '待删除学生',
            'password' => 'secret123',
            'role' => 'student',
            'student_id' => '3201',
            'is_active' => true,
        ]);

        $this->category = ScoreCategory::where('code', 'volunteer')->firstOrFail();

        $this->semester = Semester::create([
            'academic_year' => '2026-2027',
            'term' => 1,
            'name' => '2026-2027学年第一学期',
            'is_current' => true,
        ]);
    }

    public function test_super_admin_can_delete_user_and_cleanup_related_data(): void
    {
        Storage::fake('local');

        $path = UploadedFile::fake()->image('proof.jpg')->store('evidence', 'local');

        $application = ScoreApplication::create([
            'user_id' => $this->student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'activity_name' => '待删除申请',
            'applied_on' => '2026-10-20',
            'description' => '删除用户时应一并清理',
            'score' => 0,
            'status' => 'pending',
            'evidence_path' => $path,
            'evidence_name' => 'proof.jpg',
        ]);

        $application->evidences()->create([
            'path' => $path,
            'name' => 'proof.jpg',
            'sort_order' => 0,
        ]);

        ScoreRecord::create([
            'user_id' => $this->student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'score' => 6,
            'source_type' => 'volunteer_bonus',
            'description' => '待删除成绩',
        ]);

        $this->actingAs($this->admin)
            ->delete("/admin/users/{$this->student->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $this->student->id]);
        $this->assertDatabaseMissing('score_applications', ['id' => $application->id]);
        $this->assertDatabaseMissing('score_application_evidences', ['score_application_id' => $application->id]);
        $this->assertDatabaseMissing('score_records', ['user_id' => $this->student->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'delete_user',
        ]);

        Storage::disk('local')->assertMissing($path);
    }

    public function test_college_admin_cannot_delete_user(): void
    {
        $collegeAdmin = User::create([
            'username' => 'college-admin-delete',
            'name' => '学院管理员',
            'password' => 'secret123',
            'role' => 'college_admin',
            'is_active' => true,
        ]);

        $this->actingAs($collegeAdmin)
            ->delete("/admin/users/{$this->student->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $this->student->id]);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $this->actingAs($this->admin)
            ->delete("/admin/users/{$this->admin->id}")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }
}
