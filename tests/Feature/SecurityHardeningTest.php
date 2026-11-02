<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\GroupApplication;
use App\Models\GroupApplicationMember;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\User;
use App\Services\ScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private College $collegeA;

    private College $collegeB;

    private ScoreCategory $category;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin-hardening', '系统管理员', 'admin');
        $this->collegeA = College::create(['name' => '信息工程学院', 'is_active' => true]);
        $this->collegeB = College::create(['name' => '商学院', 'is_active' => true]);

        $this->category = ScoreCategory::create(['name' => '校级活动', 'type' => 'add']);
        $this->semester = Semester::create([
            'academic_year' => '2026-2027',
            'term' => 1,
            'name' => '2026-2027学年第一学期',
            'is_current' => true,
        ]);
    }

    public function test_deleting_record_soft_revokes_it(): void
    {
        $student = $this->makeStudent('3001', '张三', $this->collegeA);
        $record = $this->makeRecord($student, 5);

        $this->actingAs($this->admin)
            ->delete("/admin/records/{$record->id}")
            ->assertRedirect();

        $record->refresh();

        $this->assertNotNull($record->revoked_at);
        $this->assertSame($this->admin->id, $record->revoked_by);
        $this->assertSame('管理员撤销', $record->revoke_reason);
        $this->assertDatabaseHas('score_records', ['id' => $record->id]);

        $summary = app(ScoreService::class)->studentSummary($student, [$this->semester->id]);

        $this->assertSame(0.0, $summary['add_score']);
        $this->assertSame(40.0, $summary['total_score']);
        $this->assertSame(1, $summary['revoked_count']);
    }

    public function test_revoking_twice_keeps_original_revoke_audit(): void
    {
        $student = $this->makeStudent('3002', '李四', $this->collegeA);
        $record = $this->makeRecord($student, 3);

        $this->actingAs($this->admin)->delete("/admin/records/{$record->id}");
        $revokedAt = $record->fresh()->revoked_at;

        $this->actingAs($this->admin)
            ->delete("/admin/records/{$record->id}")
            ->assertSessionHas('error');

        $this->assertEquals($revokedAt, $record->fresh()->revoked_at);
    }

    public function test_record_cannot_be_reassigned_to_student_outside_scope(): void
    {
        $studentA = $this->makeStudent('3003', '本院学生', $this->collegeA);
        $studentB = $this->makeStudent('3004', '外院学生', $this->collegeB);
        $record = $this->makeRecord($studentA, 4);
        $collegeAdmin = $this->makeCollegeAdmin($this->collegeA);

        $this->actingAs($collegeAdmin)
            ->put("/admin/records/{$record->id}", [
                'user_id' => $studentB->id,
                'category_id' => $this->category->id,
                'semester_id' => $this->semester->id,
                'score' => 4,
                'applied_on' => '2026-10-20',
                'description' => '越权转移测试',
            ])
            ->assertForbidden();

        $this->assertSame($studentA->id, $record->fresh()->user_id);
    }

    public function test_group_application_rejects_members_from_other_college(): void
    {
        $teacher = $this->makeUser('T900', '王老师', 'teacher', [
            'employee_id' => 'T900',
            'teacher_college_id' => $this->collegeA->id,
        ]);
        $studentA = $this->makeStudent('3010', '本院成员', $this->collegeA);
        $studentB = $this->makeStudent('3011', '外院成员', $this->collegeB);

        $this->actingAs($teacher)
            ->post('/teacher/group-applications', [
                'title' => '跨院集体活动',
                'description' => '成员范围测试',
                'category_id' => $this->category->id,
                'members_file' => $this->memberFile([
                    [$studentA->student_id, $studentA->name, 2],
                    [$studentB->student_id, $studentB->name, 2],
                ]),
            ])
            ->assertSessionHasErrors('members_file');

        $this->assertDatabaseCount('group_applications', 0);

        $this->actingAs($teacher)
            ->post('/teacher/group-applications', [
                'title' => '本院集体活动',
                'description' => '成员范围测试',
                'category_id' => $this->category->id,
                'members_file' => $this->memberFile([
                    [$studentA->student_id, $studentA->name, 2],
                ]),
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('group_applications', 1);
        $this->assertDatabaseCount('group_application_members', 1);
    }

    public function test_mixed_college_group_cannot_be_viewed_or_approved_by_college_admin(): void
    {
        $studentA = $this->makeStudent('3020', '本院成员', $this->collegeA);
        $studentB = $this->makeStudent('3021', '外院成员', $this->collegeB);
        $application = $this->makeGroupApplication([$studentA, $studentB]);
        $collegeAdmin = $this->makeCollegeAdmin($this->collegeA);

        $this->actingAs($collegeAdmin)
            ->get("/admin/review/groups/{$application->id}")
            ->assertForbidden();

        $this->actingAs($collegeAdmin)
            ->put("/admin/group-applications/{$application->id}/approve")
            ->assertForbidden();

        $this->assertSame('pending', $application->fresh()->status);
        $this->assertDatabaseCount('score_records', 0);

        $this->actingAs($this->admin)
            ->put("/admin/group-applications/{$application->id}/approve")
            ->assertRedirect();

        $this->assertSame('approved', $application->fresh()->status);
        $this->assertDatabaseCount('score_records', 2);
    }

    public function test_college_admin_can_approve_group_entirely_within_own_college(): void
    {
        $studentA = $this->makeStudent('3030', '成员一', $this->collegeA);
        $studentB = $this->makeStudent('3031', '成员二', $this->collegeA);
        $application = $this->makeGroupApplication([$studentA, $studentB]);
        $collegeAdmin = $this->makeCollegeAdmin($this->collegeA);

        $this->actingAs($collegeAdmin)
            ->put("/admin/group-applications/{$application->id}/approve")
            ->assertRedirect();

        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame(2, ScoreRecord::where('source_type', 'group')->count());
    }

    public function test_group_application_cannot_be_approved_twice(): void
    {
        $studentA = $this->makeStudent('3040', '成员一', $this->collegeA);
        $application = $this->makeGroupApplication([$studentA]);
        $service = app(ScoreService::class);

        $service->approveGroup($application, $this->admin);

        $this->expectException(ValidationException::class);
        $service->approveGroup($application->fresh(), $this->admin);
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

    private function makeStudent(string $studentId, string $name, College $college): User
    {
        return $this->makeUser($studentId, $name, 'student', [
            'student_id' => $studentId,
            'college_id' => $college->id,
            'college' => $college->name,
        ]);
    }

    private function makeCollegeAdmin(College $college): User
    {
        $user = $this->makeUser('ca-'.$college->id, $college->name.'管理员', 'college_admin', [
            'college_id' => $college->id,
        ]);

        DB::table('college_admin_user')->insert([
            'user_id' => $user->id,
            'college_id' => $college->id,
            'granted_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function makeRecord(User $student, float $score): ScoreRecord
    {
        return ScoreRecord::create([
            'user_id' => $student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'score' => $score,
            'applied_on' => '2026-10-20',
            'source_type' => 'manual',
            'description' => '测试记录',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * @param  array<int, User>  $students
     */
    private function makeGroupApplication(array $students): GroupApplication
    {
        $teacher = $this->makeUser('T'.uniqid(), '集体活动教师', 'teacher', [
            'teacher_college_id' => $this->collegeA->id,
        ]);

        $application = GroupApplication::create([
            'teacher_id' => $teacher->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'title' => '集体活动测试',
            'description' => '集体活动说明',
            'status' => 'pending',
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
     * @param  array<int, array{0: string, 1: string, 2: float}>  $rows
     */
    private function memberFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['学号', '姓名', '分值'], ...$rows]);

        $path = tempnam(sys_get_temp_dir(), 'dyf-members-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'members.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
