<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\ScoreApplication;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\User;
use App\Services\ScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReviewExportAndScoreEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private College $college;

    private Major $major;

    private SchoolClass $classA;

    private SchoolClass $classB;

    private Semester $semester;

    private ScoreCategory $addCategory;

    private ScoreCategory $deductCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin-export-edit', '系统管理员', 'admin');
        $this->college = College::create(['name' => '信息工程学院', 'is_active' => true]);
        $this->major = Major::create([
            'college_id' => $this->college->id,
            'name' => '计算机科学与技术',
            'is_active' => true,
        ]);
        $this->classA = $this->makeClass('计算机2601班', '2026级');
        $this->classB = $this->makeClass('计算机2602班', '2026级');
        $this->semester = Semester::create([
            'academic_year' => '2026-2027',
            'term' => 1,
            'name' => '2026-2027学年第一学期',
            'is_current' => true,
        ]);
        $this->addCategory = ScoreCategory::create(['name' => '校级活动', 'type' => 'add']);
        $this->deductCategory = ScoreCategory::create(['name' => '纪律扣分', 'type' => 'deduct']);
    }

    public function test_officer_can_export_own_class_leaderboard_only(): void
    {
        $officer = $this->makeStudent('2602001', '班长', $this->classA);
        $classmate = $this->makeStudent('2602002', '同班同学', $this->classA);
        $outsider = $this->makeStudent('2602003', '别班同学', $this->classB);
        $this->appointOfficer($officer, $this->classA);

        $this->makeApprovedRecord($classmate, 3);
        $this->makeApprovedRecord($officer, 1);
        $this->makeApprovedRecord($outsider, 20);

        $response = $this->actingAs($officer)
            ->get('/officer/review/leaderboard/export?board_class_id='.$this->classA->id);

        $response->assertOk();

        $spreadsheet = $this->loadSpreadsheet($response->streamedContent());

        $this->assertSame(['德育分排行榜', '加减分明细表', '学期汇总表'], $spreadsheet->getSheetNames());

        $ranking = $spreadsheet->getSheetByName('德育分排行榜');
        $names = [];

        for ($row = 4; $row <= $ranking->getHighestRow(); $row++) {
            $names[] = $ranking->getCell("B{$row}")->getValue();
        }

        $this->assertEqualsCanonicalizing(['同班同学', '班长'], $names);

        $summary = $spreadsheet->getSheetByName('学期汇总表');
        $summaryText = '';

        for ($row = 1; $row <= $summary->getHighestRow(); $row++) {
            $summaryText .= (string) $summary->getCell("A{$row}")->getValue();
        }

        $this->assertStringContainsString('计算机2601班', $summaryText);
        $this->assertStringNotContainsString('计算机2602班', $summaryText);

        $spreadsheet->disconnectWorksheets();

        $this->actingAs($officer)
            ->get('/officer/review/leaderboard/export?board_class_id='.$this->classB->id)
            ->assertForbidden();
    }

    public function test_class_admin_can_export_assigned_class_only(): void
    {
        $counselor = $this->makeUser('counselor-export', '辅导员', 'class_admin');
        $student = $this->makeStudent('2602004', '本班学生', $this->classA);
        $outsider = $this->makeStudent('2602005', '别班学生', $this->classB);
        $this->assignClassAdmin($counselor, $this->classA);

        $this->makeApprovedRecord($student, 5);
        $this->makeApprovedRecord($outsider, 15);

        $response = $this->actingAs($counselor)->get('/admin/review/leaderboard/export');
        $response->assertOk();

        $spreadsheet = $this->loadSpreadsheet($response->streamedContent());
        $ranking = $spreadsheet->getSheetByName('德育分排行榜');
        $names = [];

        for ($row = 4; $row <= $ranking->getHighestRow(); $row++) {
            $names[] = $ranking->getCell("B{$row}")->getValue();
        }

        $this->assertSame(['本班学生'], array_values(array_filter($names)));

        $spreadsheet->disconnectWorksheets();

        $this->actingAs($counselor)
            ->get('/admin/review/leaderboard/export?board_class_id='.$this->classB->id)
            ->assertForbidden();

        $this->actingAs($student)
            ->get('/admin/review/leaderboard/export')
            ->assertForbidden();
    }

    public function test_class_admin_can_modify_approved_score_and_record(): void
    {
        $counselor = $this->makeUser('counselor-edit', '辅导员', 'class_admin');
        $student = $this->makeStudent('2602006', '本班学生', $this->classA);
        $this->assignClassAdmin($counselor, $this->classA);

        $application = $this->approve($student, 1);

        $this->actingAs($counselor)
            ->put("/admin/applications/{$application->id}/update", [
                'final_score' => 2.5,
                'review_comment' => '复核后调整为 2.5 分',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2.5, $application->fresh()->final_score);
        $this->assertSame('复核后调整为 2.5 分', $application->fresh()->review_comment);

        $record = ScoreRecord::query()
            ->where('source_type', 'personal')
            ->where('source_id', $application->id)
            ->first();

        $this->assertNotNull($record);
        $this->assertSame(2.5, $record->score);
        $this->assertSame(2.5, app(ScoreService::class)->studentSummary($student)['add_score']);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $counselor->id,
            'action' => 'update_application_score',
            'target_id' => $application->id,
        ]);

        $outsider = $this->makeStudent('2602007', '别班学生', $this->classB);
        $outsiderApplication = $this->approve($outsider, 1);

        $this->actingAs($counselor)
            ->put("/admin/applications/{$outsiderApplication->id}/update", [
                'final_score' => 2,
            ])
            ->assertForbidden();
    }

    public function test_officer_can_modify_classmate_approved_score_only(): void
    {
        $officer = $this->makeStudent('2602008', '班长', $this->classA);
        $classmate = $this->makeStudent('2602009', '同班同学', $this->classA);
        $outsider = $this->makeStudent('2602010', '别班同学', $this->classB);
        $this->appointOfficer($officer, $this->classA);

        $classmateApplication = $this->approve($classmate, 1);
        $ownApplication = $this->approve($officer, 1);
        $outsiderApplication = $this->approve($outsider, 1);

        $this->actingAs($officer)
            ->put("/officer/applications/{$classmateApplication->id}/update", [
                'final_score' => 0.5,
                'review_comment' => '按班级活动标准核定',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0.5, $classmateApplication->fresh()->final_score);
        $this->assertDatabaseHas('score_records', [
            'source_type' => 'personal',
            'source_id' => $classmateApplication->id,
            'score' => 0.5,
        ]);

        $this->actingAs($officer)
            ->put("/officer/applications/{$ownApplication->id}/update", ['final_score' => 1])
            ->assertForbidden();

        $this->actingAs($officer)
            ->put("/officer/applications/{$outsiderApplication->id}/update", ['final_score' => 1])
            ->assertForbidden();
    }

    public function test_approved_volunteer_application_can_be_updated_with_zero_score(): void
    {
        $counselor = $this->makeUser('counselor-volunteer', '辅导员', 'class_admin');
        $student = $this->makeStudent('2602013', '本班学生', $this->classA);
        $this->assignClassAdmin($counselor, $this->classA);

        $volunteer = ScoreCategory::firstOrCreate(
            ['code' => 'volunteer'],
            ['name' => '志愿服务', 'type' => 'add']
        );

        $application = ScoreApplication::create([
            'user_id' => $student->id,
            'category_id' => $volunteer->id,
            'semester_id' => $this->semester->id,
            'activity_name' => '志愿活动',
            'applied_on' => now()->toDateString(),
            'description' => '参与志愿活动',
            'score' => 0,
            'status' => 'pending',
        ]);

        app(ScoreService::class)->approveApplication($application, $this->admin, 0);

        $this->actingAs($counselor)
            ->put("/admin/applications/{$application->id}/update", [
                'final_score' => 0,
                'review_comment' => '志愿服务按规则计 0 分',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, $application->fresh()->final_score);
        $this->assertSame('志愿服务按规则计 0 分', $application->fresh()->review_comment);
        $this->assertDatabaseHas('score_records', [
            'source_type' => 'personal',
            'source_id' => $application->id,
            'score' => 0,
        ]);
    }

    public function test_pending_application_cannot_be_modified(): void
    {
        $counselor = $this->makeUser('counselor-pending', '辅导员', 'class_admin');
        $student = $this->makeStudent('2602011', '本班学生', $this->classA);
        $this->assignClassAdmin($counselor, $this->classA);

        $application = $this->makeApplication($student);

        $this->actingAs($counselor)
            ->put("/admin/applications/{$application->id}/update", ['final_score' => 2])
            ->assertForbidden();
    }

    public function test_admin_has_deduction_entry_and_can_deduct_any_student(): void
    {
        $student = $this->makeStudent('2602012', '学生甲', $this->classA);

        $this->actingAs($this->admin)
            ->get('/admin/review')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deduction.available', true)
                ->where('deduction.store_url', '/admin/deductions')
                ->where('canModifyApproved', true));

        $this->actingAs($this->admin)
            ->post('/admin/deductions', [
                'student_id' => $student->id,
                'category_id' => $this->deductCategory->id,
                'score' => 2,
                'applied_on' => now()->toDateString(),
                'description' => '晚归扣分',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('score_records', [
            'user_id' => $student->id,
            'category_id' => $this->deductCategory->id,
            'score' => -2,
            'source_type' => 'manual',
            'created_by' => $this->admin->id,
        ]);
    }

    private function approve(User $student, float $score): ScoreApplication
    {
        $application = $this->makeApplication($student);
        app(ScoreService::class)->approveApplication($application, $this->admin, $score);

        return $application;
    }

    private function makeApplication(User $student): ScoreApplication
    {
        return ScoreApplication::create([
            'user_id' => $student->id,
            'category_id' => $this->addCategory->id,
            'semester_id' => $this->semester->id,
            'activity_name' => '班级活动',
            'applied_on' => now()->toDateString(),
            'description' => '参与班级活动',
            'score' => 3,
            'status' => 'pending',
        ]);
    }

    private function makeApprovedRecord(User $student, float $score): void
    {
        ScoreRecord::create([
            'user_id' => $student->id,
            'category_id' => $this->addCategory->id,
            'semester_id' => $this->semester->id,
            'score' => $score,
            'applied_on' => now()->toDateString(),
            'source_type' => 'manual',
            'description' => '排行测试',
            'created_by' => $this->admin->id,
        ]);
    }

    private function loadSpreadsheet(string $content)
    {
        $path = tempnam(sys_get_temp_dir(), 'dyf_review_export_').'.xlsx';
        file_put_contents($path, $content);

        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    private function appointOfficer(User $officer, SchoolClass $class): void
    {
        DB::table('class_officer_user')->insert([
            'user_id' => $officer->id,
            'class_id' => $class->id,
            'title' => '班长',
            'granted_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignClassAdmin(User $counselor, SchoolClass $class): void
    {
        DB::table('class_admin_user')->insert([
            'user_id' => $counselor->id,
            'class_id' => $class->id,
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
