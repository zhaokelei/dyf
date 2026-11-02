<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\GroupApplication;
use App\Models\GroupApplicationMember;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\User;
use App\Services\ImportService;
use App\Services\ScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ConsistencyFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private College $college;

    private Major $major;

    private ScoreCategory $category;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin-consistency', '系统管理员', 'admin');
        $this->college = College::create(['name' => '信息工程学院', 'is_active' => true]);
        $this->major = Major::create([
            'college_id' => $this->college->id,
            'name' => '计算机科学与技术',
            'is_active' => true,
        ]);
        $this->category = ScoreCategory::create(['name' => '校级活动', 'type' => 'add']);
        $this->semester = Semester::create([
            'academic_year' => '2026-2027',
            'term' => 1,
            'name' => '2026-2027学年第一学期',
            'is_current' => true,
        ]);
    }

    public function test_weekly_leaderboard_filters_by_applied_on(): void
    {
        $student = $this->makeStudent('4001', '张三');
        $this->makeRecord($student, '本周加分项', 5, now()->toDateString(), now()->subDays(30));
        $this->makeRecord($student, '上周加分项', 7, now()->subWeek()->startOfWeek()->addDay()->toDateString(), now());

        $page = app(ScoreService::class)->leaderboard([
            'semester_ids' => [$this->semester->id],
            'rank_period' => 'week',
            'start' => now()->startOfWeek(),
            'end' => now()->endOfWeek(),
        ], 100);

        $row = collect($page->items())->firstWhere('id', $student->id);

        $this->assertNotNull($row);
        $this->assertSame(5.0, $row['period_add_score']);
        $this->assertSame(0.0, $row['period_deduct_score']);
    }

    public function test_export_details_use_applied_on_window(): void
    {
        $student = $this->makeStudent('4002', '李四');
        $this->makeRecord($student, '本周加分项', 5, now()->toDateString(), now()->subDays(30));
        $this->makeRecord($student, '上周加分项', 7, now()->subWeek()->startOfWeek()->addDay()->toDateString(), now());

        $response = $this->actingAs($this->admin)->get('/admin/leaderboard/export?rank_period=week');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'dyf_consistency_').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        try {
            $detail = IOFactory::load($path)->getSheetByName('加减分明细表');

            $this->assertSame('【校级活动】本周加分项', $detail->getCell('F4')->getValue());
            $this->assertEmpty($detail->getCell('F5')->getValue());
        } finally {
            @unlink($path);
        }
    }

    public function test_personal_export_uses_applied_on_as_report_time(): void
    {
        $student = $this->makeStudent('4011', '上报时间学生');
        $this->makeRecord($student, '志愿服务活动', 3, '2026-09-15', now());

        $response = $this->actingAs($student)->get('/my-scores/export');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'dyf_personal_').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            $this->assertSame('上报时间', $sheet->getCell('H4')->getValue());
            $this->assertSame('2026-09-15', $sheet->getCell('H5')->getValue());
        } finally {
            @unlink($path);
        }
    }

    public function test_student_summary_orders_by_applied_on(): void
    {
        $student = $this->makeStudent('4003', '王五');
        $older = $this->makeRecord($student, '较早上报', 1, now()->subDays(2)->toDateString(), now());
        $newer = $this->makeRecord($student, '较晚上报', 1, now()->toDateString(), now()->subDays(5));

        $summary = app(ScoreService::class)->studentSummary($student, [$this->semester->id]);
        $ids = collect($summary['records'])->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_application_generated_record_cannot_be_edited(): void
    {
        $student = $this->makeStudent('4004', '赵六');
        $record = ScoreRecord::create([
            'user_id' => $student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'score' => 4,
            'applied_on' => now()->toDateString(),
            'source_type' => 'personal',
            'source_id' => 99,
            'description' => '审核生成',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/records/{$record->id}", $this->recordPayload($student, 6))
            ->assertSessionHas('error');

        $this->assertSame(4.0, $record->fresh()->score);
        $this->assertSame('审核生成', $record->fresh()->description);
    }

    public function test_manual_record_can_still_be_edited(): void
    {
        $student = $this->makeStudent('4005', '钱七');
        $record = $this->makeRecord($student, '手工录入', 2, now()->toDateString(), now());

        $this->actingAs($this->admin)
            ->put("/admin/records/{$record->id}", $this->recordPayload($student, 3, '修改后的说明'))
            ->assertSessionHas('success');

        $this->assertSame(3.0, $record->fresh()->score);
        $this->assertSame('修改后的说明', $record->fresh()->description);
    }

    public function test_import_keeps_disabled_status_and_manager_role(): void
    {
        $teacher = $this->makeUser('T100', '王老师', 'class_admin', [
            'employee_id' => 'T100',
            'college_id' => $this->college->id,
            'is_active' => false,
        ]);

        $file = $this->xlsxUpload('教师', [
            ['工号', '姓名', '学院'],
            ['T100', '王老师', '信息工程学院'],
        ]);

        app(ImportService::class)->importUsers($file);

        $teacher->refresh();
        $this->assertFalse($teacher->is_active);
        $this->assertSame('class_admin', $teacher->role);
    }

    public function test_college_with_majors_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->delete("/admin/organization/colleges/{$this->college->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('colleges', ['id' => $this->college->id]);
    }

    public function test_assigning_class_admin_keeps_original_class_identity(): void
    {
        $classA = $this->makeClass('计算机科学与技术2601班');
        $classB = $this->makeClass('计算机科学与技术2602班');

        $student = $this->makeUser('4006', '辅导员测试', 'student', [
            'student_id' => '4006',
            'college_id' => $this->college->id,
            'major_id' => $this->major->id,
            'class_id' => $classA->id,
            'class_name' => $classA->name,
            'grade' => $classA->grade,
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/organization/class-admins', [
                'user_id' => $student->id,
                'class_id' => $classB->id,
            ])
            ->assertRedirect();

        $student->refresh();

        $this->assertSame('class_admin', $student->role);
        $this->assertSame($classA->id, $student->class_id);
        $this->assertSame($classA->name, $student->class_name);
        $this->assertEquals([$classB->id], $student->managedClasses()->pluck('classes.id')->all());
    }

    public function test_reset_password_invalidates_other_sessions(): void
    {
        config(['session.driver' => 'database']);

        $student = $this->makeStudent('4007', '孙八');

        DB::table('sessions')->insert([
            'id' => 'other-session',
            'user_id' => $student->id,
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/users/{$student->id}/reset-password")
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
    }

    public function test_password_change_logs_out_and_requires_new_password(): void
    {
        $student = $this->makeStudent('4010', '改密学生');
        $student->forceFill(['remember_token' => 'old-remember-token'])->save();

        $this->actingAs($student)
            ->put('/profile/password', [
                'current_password' => 'secret123',
                'password' => 'new-secret-456',
                'password_confirmation' => 'new-secret-456',
            ])
            ->assertRedirect('/login')
            ->assertSessionHas('success');

        $this->assertGuest();

        $student->refresh();
        $this->assertTrue(Hash::check('new-secret-456', $student->password));
        $this->assertFalse(Hash::check('secret123', $student->password));
        $this->assertNotSame('old-remember-token', $student->remember_token);

        $this->get('/dashboard')->assertRedirect('/login');

        $this->post('/login', [
            'account' => '4010',
            'password' => 'secret123',
        ])->assertSessionHasErrors('password');

        $this->post('/login', [
            'account' => '4010',
            'password' => 'new-secret-456',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_admin_reset_password_invalidates_other_devices(): void
    {
        $student = $this->makeStudent('4011', '被重置学生');
        $student->forceFill(['remember_token' => 'old-remember-token'])->save();

        $hashKey = 'password_hash_web';
        $oldHash = $student->fresh()->password;

        $this->actingAs($student->fresh())
            ->withSession([$hashKey => $oldHash])
            ->get('/dashboard')
            ->assertOk();

        $this->actingAs($this->admin)
            ->withSession([$hashKey => $this->admin->password])
            ->post("/admin/users/{$student->id}/reset-password")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotSame('old-remember-token', $student->fresh()->remember_token);

        $this->actingAs($student->fresh())
            ->withSession([$hashKey => $oldHash])
            ->get('/dashboard')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_admin_resetting_own_password_logs_out(): void
    {
        $this->actingAs($this->admin)
            ->post("/admin/users/{$this->admin->id}/reset-password")
            ->assertRedirect('/login')
            ->assertSessionHas('success');

        $this->assertGuest();
        $this->assertTrue(Hash::check('admin-consistency', $this->admin->fresh()->password));
    }

    public function test_admin_updating_user_password_forces_logout(): void
    {
        $student = $this->makeStudent('4012', '被编辑学生');
        $student->forceFill(['remember_token' => 'old-remember-token'])->save();
        $oldHash = $student->fresh()->password;

        $this->actingAs($this->admin)
            ->put("/admin/users/{$student->id}", [
                'role' => 'student',
                'name' => '被编辑学生',
                'username' => '4012',
                'student_id' => '4012',
                'password' => 'changed-123',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $student->refresh();
        $this->assertTrue(Hash::check('changed-123', $student->password));
        $this->assertNotSame('old-remember-token', $student->remember_token);

        $this->actingAs($student)
            ->withSession(['password_hash_web' => $oldHash])
            ->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_invite_code_can_be_cleared(): void
    {
        Setting::put('teacher_registration_code', 'ABC123');

        $this->actingAs($this->admin)
            ->put('/admin/settings', [
                'base_score' => 40,
                'system_name' => '德育分系统',
                'registration_enabled' => true,
                'registration_requires_approval' => false,
                'teacher_registration_enabled' => false,
                'teacher_registration_code' => '',
            ])
            ->assertSessionHas('success');

        $this->assertSame('', Setting::get('teacher_registration_code'));
    }

    public function test_future_applied_on_is_rejected(): void
    {
        $student = $this->makeStudent('4008', '周九');
        $tomorrow = now()->addDay()->toDateString();

        $this->actingAs($student)
            ->post('/applications', [
                'category_id' => $this->category->id,
                'activity_name' => '未来活动',
                'applied_on' => $tomorrow,
                'score' => 1,
                'description' => '未来日期测试',
                'evidence' => [UploadedFile::fake()->image('proof.jpg')],
            ])
            ->assertSessionHasErrors('applied_on');

        $this->assertDatabaseCount('score_applications', 0);

        $this->actingAs($this->admin)
            ->withSession(['password_hash_web' => $this->admin->password])
            ->post('/admin/records', $this->recordPayload($student, 1, '未来日期测试', $tomorrow))
            ->assertSessionHasErrors('applied_on');

        $this->assertDatabaseCount('score_records', 0);
    }

    public function test_export_writes_text_cells_as_plain_strings(): void
    {
        $student = $this->makeStudent('4009', '=1+1');
        $this->makeRecord($student, '=2+2', 3, now()->toDateString(), now());

        $response = $this->actingAs($this->admin)->get('/admin/leaderboard/export');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'dyf_formula_').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($path);
            $ranking = $spreadsheet->getSheetByName('德育分排行榜');
            $detail = $spreadsheet->getSheetByName('加减分明细表');

            $this->assertSame('=1+1', $ranking->getCell('B4')->getValue());
            $this->assertSame('s', $ranking->getCell('B4')->getDataType());
            $this->assertSame('【校级活动】=2+2', $detail->getCell('F4')->getValue());
            $this->assertSame('s', $detail->getCell('F4')->getDataType());
        } finally {
            @unlink($path);
        }
    }

    public function test_member_template_uses_placeholder_student(): void
    {
        $teacher = $this->makeUser('T200', '李老师', 'teacher', ['employee_id' => 'T200']);

        $response = $this->actingAs($teacher)->get('/teacher/group-applications/template');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'dyf_template_').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            $this->assertSame('2026000001', $sheet->getCell('A2')->getValue());
            $this->assertSame('示例学生', $sheet->getCell('B2')->getValue());
        } finally {
            @unlink($path);
        }
    }

    public function test_overview_excludes_inactive_students_and_fully_scopes_groups(): void
    {
        $active = $this->makeStudent('4010', '在读学生');
        $inactive = $this->makeStudent('4011', '停用学生');
        $inactive->update(['is_active' => false]);

        $this->makeGroupApplication('approved', [$active]);
        $this->makeGroupApplication('approved', [$active, $inactive]);

        $overview = app(ScoreService::class)->overview(null, [$active->id]);

        $this->assertSame(1, $overview['student_count']);
        $this->assertSame(1, $overview['approved_applications']);
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

    private function makeStudent(string $studentId, string $name): User
    {
        return $this->makeUser($studentId, $name, 'student', [
            'student_id' => $studentId,
            'college_id' => $this->college->id,
            'college' => $this->college->name,
            'major_id' => $this->major->id,
            'class_name' => '计算机科学与技术2601班',
            'grade' => '2026级',
        ]);
    }

    private function makeClass(string $name): SchoolClass
    {
        return SchoolClass::create([
            'college_id' => $this->college->id,
            'major_id' => $this->major->id,
            'name' => $name,
            'grade' => '2026级',
            'is_active' => true,
        ]);
    }

    private function makeRecord(
        User $student,
        string $description,
        float $score,
        string $appliedOn,
        $createdAt
    ): ScoreRecord {
        $record = ScoreRecord::create([
            'user_id' => $student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'score' => $score,
            'applied_on' => $appliedOn,
            'source_type' => 'manual',
            'description' => $description,
            'created_by' => $this->admin->id,
        ]);

        $record->forceFill(['created_at' => $createdAt])->save();

        return $record;
    }

    private function recordPayload(User $student, float $score, string $description = '测试说明', ?string $appliedOn = null): array
    {
        return [
            'user_id' => $student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'score' => $score,
            'applied_on' => $appliedOn ?? now()->toDateString(),
            'description' => $description,
        ];
    }

    /**
     * @param  array<int, User>  $students
     */
    private function makeGroupApplication(string $status, array $students): GroupApplication
    {
        $teacher = $this->makeUser('T'.uniqid(), '集体活动教师', 'teacher', [
            'teacher_college_id' => $this->college->id,
        ]);

        $application = GroupApplication::create([
            'teacher_id' => $teacher->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'title' => '集体活动测试',
            'description' => '集体活动说明',
            'status' => $status,
        ]);

        foreach ($students as $student) {
            GroupApplicationMember::create([
                'group_application_id' => $application->id,
                'student_id' => $student->id,
                'score' => 2,
            ]);
        }

        return $application;
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function xlsxUpload(string $sheetTitle, array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle($sheetTitle)->fromArray($rows);

        $path = tempnam(sys_get_temp_dir(), 'dyf-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
