<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManualRecordVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private College $college;

    private Major $major;

    private SchoolClass $classA;

    private SchoolClass $classB;

    private ScoreCategory $category;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin-manual', '系统管理员', 'admin');
        $this->college = College::create(['name' => '信息工程学院', 'is_active' => true]);
        $this->major = Major::create([
            'college_id' => $this->college->id,
            'name' => '计算机科学与技术',
            'is_active' => true,
        ]);
        $this->classA = $this->makeClass('计算机2601班', '2026级');
        $this->classB = $this->makeClass('计算机2602班', '2026级');
        $this->category = ScoreCategory::create(['name' => '校级活动', 'type' => 'add']);
        $this->semester = Semester::create([
            'academic_year' => '2026-2027',
            'term' => 1,
            'name' => '2026-2027学年第一学期',
            'is_current' => true,
        ]);
    }

    public function test_student_sees_own_manual_records_including_revoked(): void
    {
        $student = $this->makeStudent('2709001', '张同学', $this->classA);
        $active = $this->makeManualRecord($student, 2, '2026-10-20');
        $revoked = $this->makeManualRecord($student, 3, '2026-10-21', [
            'revoked_at' => now(),
            'revoked_by' => $this->admin->id,
            'revoke_reason' => '重复录入',
        ]);
        $other = $this->makeStudent('2709002', '李同学', $this->classA);
        $this->makeManualRecord($other, 4, '2026-10-22');

        $this->actingAs($student)
            ->get('/applications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applications/Index')
                ->has('manualRecords.data', 2)
                ->where('manualRecords.data.0.id', $revoked->id)
                ->where('manualRecords.data.0.revoke_reason', '重复录入')
                ->where('manualRecords.data.1.id', $active->id)
                ->where('manualRecords.data.0.creator.name', '系统管理员'));
    }

    public function test_officer_sees_classmates_manual_records_but_not_own_or_other_class(): void
    {
        $officer = $this->makeStudent('2709010', '王班长', $this->classA);
        $classmate = $this->makeStudent('2709011', '同班同学', $this->classA);
        $outsider = $this->makeStudent('2709012', '别班同学', $this->classB);
        $this->appointOfficer($officer, $this->classA);

        $this->makeManualRecord($classmate, 2, '2026-10-20');
        $this->makeManualRecord($officer, 1, '2026-10-20');
        $this->makeManualRecord($outsider, 3, '2026-10-20');

        $this->actingAs($officer)
            ->get('/officer/review')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/Index')
                ->where('officerMode', true)
                ->has('manual.data', 1)
                ->where('manual.data.0.user_id', $classmate->id)
                ->where('counts.manual_total', 1)
                ->where('canManageManual', false));
    }

    public function test_class_admin_sees_scoped_manual_records_only(): void
    {
        $counselor = $this->makeUser('counselor-manual', '辅导员', 'class_admin');
        DB::table('class_admin_user')->insert([
            'user_id' => $counselor->id,
            'class_id' => $this->classA->id,
            'granted_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $studentA = $this->makeStudent('2709020', '本班同学', $this->classA);
        $studentB = $this->makeStudent('2709021', '别班同学', $this->classB);
        $recordA = $this->makeManualRecord($studentA, 2, '2026-10-20');
        $this->makeManualRecord($studentB, 3, '2026-10-20');

        $this->actingAs($counselor)
            ->get('/admin/review')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/Index')
                ->where('officerMode', false)
                ->has('manual.data', 1)
                ->where('manual.data.0.id', $recordA->id)
                ->where('canManageManual', false));
    }

    public function test_only_system_admin_can_create_manual_record(): void
    {
        $student = $this->makeStudent('2709030', '学生', $this->classA);
        $collegeAdmin = $this->makeUser('college-manual', '学院管理员', 'college_admin', [
            'college_id' => $this->college->id,
        ]);
        $counselor = $this->makeUser('counselor-create', '辅导员', 'class_admin', [
            'college_id' => $this->college->id,
        ]);

        $this->actingAs($collegeAdmin)
            ->post('/admin/records', $this->payload($student, 2))
            ->assertForbidden();

        $this->actingAs($counselor)
            ->post('/admin/records', $this->payload($student, 2))
            ->assertForbidden();

        $this->assertDatabaseCount('score_records', 0);

        $this->actingAs($this->admin)
            ->post('/admin/records', $this->payload($student, 2))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('score_records', [
            'user_id' => $student->id,
            'source_type' => 'manual',
            'score' => 2,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_soft_revoke_keeps_record_visible_with_revoked_flag(): void
    {
        $student = $this->makeStudent('2709040', '学生', $this->classA);
        $record = $this->makeManualRecord($student, 5);

        $this->actingAs($this->admin)
            ->delete("/admin/records/{$record->id}")
            ->assertRedirect();

        $this->assertNotNull($record->fresh()->revoked_at);

        $this->actingAs($student)
            ->get('/applications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('manualRecords.data', 1)
                ->where('manualRecords.data.0.id', $record->id));
    }

    public function test_only_admin_can_permanently_delete_manual_record(): void
    {
        $student = $this->makeStudent('2709050', '学生', $this->classA);
        $counselor = $this->makeUser('counselor-delete', '辅导员', 'class_admin');
        DB::table('class_admin_user')->insert([
            'user_id' => $counselor->id,
            'class_id' => $this->classA->id,
            'granted_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $record = $this->makeManualRecord($student, 5);

        $this->actingAs($counselor)
            ->delete("/admin/records/{$record->id}/force")
            ->assertForbidden();

        $this->assertDatabaseHas('score_records', ['id' => $record->id]);

        $this->actingAs($this->admin)
            ->delete("/admin/records/{$record->id}/force")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('score_records', ['id' => $record->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'force_delete_score_record',
            'target_id' => $record->id,
        ]);

        $this->actingAs($student)
            ->get('/applications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('manualRecords.data', 0));
    }

    public function test_application_generated_record_cannot_be_permanently_deleted(): void
    {
        $student = $this->makeStudent('2709060', '学生', $this->classA);
        $record = ScoreRecord::create([
            'user_id' => $student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'score' => 3,
            'applied_on' => now()->toDateString(),
            'source_type' => 'personal',
            'source_id' => 99,
            'description' => '审核生成',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete("/admin/records/{$record->id}/force")
            ->assertForbidden();

        $this->assertDatabaseHas('score_records', ['id' => $record->id]);
    }

    private function payload(User $student, float $score): array
    {
        return [
            'user_id' => $student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'score' => $score,
            'applied_on' => now()->toDateString(),
            'description' => '手工录入测试',
        ];
    }

    private function makeManualRecord(User $student, float $score, ?string $appliedOn = null, array $attributes = []): ScoreRecord
    {
        return ScoreRecord::create(array_merge([
            'user_id' => $student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'score' => $score,
            'applied_on' => $appliedOn ?? now()->toDateString(),
            'source_type' => 'manual',
            'description' => '手工录入',
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function appointOfficer(User $student, SchoolClass $class): void
    {
        DB::table('class_officer_user')->insert([
            'user_id' => $student->id,
            'class_id' => $class->id,
            'title' => '班长',
            'granted_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeClass(string $name, string $grade): SchoolClass
    {
        return SchoolClass::create([
            'college_id' => $this->college->id,
            'major_id' => $this->major->id,
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

    private function makeStudent(string $studentId, string $name, SchoolClass $class): User
    {
        return $this->makeUser($studentId, $name, 'student', [
            'student_id' => $studentId,
            'college_id' => $this->college->id,
            'college' => $this->college->name,
            'major_id' => $this->major->id,
            'class_id' => $class->id,
            'class_name' => $class->name,
            'grade' => $class->grade,
        ]);
    }
}
