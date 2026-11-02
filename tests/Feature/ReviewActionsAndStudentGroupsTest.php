<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\GroupApplication;
use App\Models\GroupApplicationEvidence;
use App\Models\GroupApplicationMember;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\ScoreApplication;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\User;
use App\Services\ScoreService;
use App\Support\VolunteerRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReviewActionsAndStudentGroupsTest extends TestCase
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

        $this->admin = $this->makeUser('admin-actions', '系统管理员', 'admin');
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

    public function test_personal_and_group_rejection_accepts_empty_reason(): void
    {
        $student = $this->makeStudent('2601001', '张同学', $this->classA);
        $personal = $this->makeApplication($student);
        $group = $this->makeGroupApplication([$student]);

        $this->actingAs($this->admin)
            ->put("/admin/applications/{$personal->id}/reject")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $personal->fresh()->status);
        $this->assertNull($personal->fresh()->review_comment);

        $this->actingAs($this->admin)
            ->put("/admin/group-applications/{$group->id}/reject")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $group->fresh()->status);
        $this->assertNull($group->fresh()->review_comment);

        $another = $this->makeApplication($student);

        $this->actingAs($this->admin)
            ->post('/admin/review/bulk', [
                'type' => 'personal',
                'action' => 'reject',
                'ids' => [$another->id],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $another->fresh()->status);
        $this->assertNull($another->fresh()->review_comment);
    }

    public function test_cancel_personal_approval_revokes_score_and_restores_pending(): void
    {
        $student = $this->makeStudent('2601002', '李同学', $this->classA);
        $application = $this->makeApplication($student);
        $service = app(ScoreService::class);

        $service->approveApplication($application, $this->admin, 0.5);

        $this->assertSame(0.5, app(ScoreService::class)->studentSummary($student)['add_score']);

        $this->actingAs($this->admin)
            ->put("/admin/applications/{$application->id}/cancel", [
                'cancel_reason' => '材料存疑',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame('pending', $application->status);
        $this->assertNull($application->final_score);
        $this->assertNull($application->reviewer_id);
        $this->assertNull($application->reviewed_at);
        $this->assertSame(0.0, $service->studentSummary($student)['add_score']);

        $record = ScoreRecord::where('source_type', 'personal')
            ->where('source_id', $application->id)
            ->firstOrFail();
        $this->assertNotNull($record->revoked_at);
        $this->assertSame('材料存疑', $record->revoke_reason);

        $service->approveApplication($application->fresh(), $this->admin, 1);

        $this->assertSame(1.0, $service->studentSummary($student)['add_score']);
        $this->assertSame(1, ScoreRecord::where('source_type', 'personal')
            ->where('source_id', $application->id)
            ->whereNull('revoked_at')
            ->count());
    }

    public function test_officer_can_cancel_classmate_approval_but_not_own(): void
    {
        $officer = $this->makeStudent('2601003', '王班长', $this->classA);
        $classmate = $this->makeStudent('2601004', '赵同学', $this->classA);
        $this->appointOfficer($officer, $this->classA);

        $classmateApplication = $this->makeApplication($classmate);
        $ownApplication = $this->makeApplication($officer);
        $service = app(ScoreService::class);

        $service->approveApplication($classmateApplication, $this->admin, 2);
        $service->approveApplication($ownApplication, $this->admin, 2);

        $this->actingAs($officer)
            ->put("/officer/applications/{$classmateApplication->id}/cancel")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('pending', $classmateApplication->fresh()->status);

        $this->actingAs($officer)
            ->put("/officer/applications/{$ownApplication->id}/cancel")
            ->assertForbidden();

        $this->assertSame('approved', $ownApplication->fresh()->status);
    }

    public function test_cancel_group_approval_revokes_every_member_score(): void
    {
        $studentA = $this->makeStudent('2601005', '成员甲', $this->classA);
        $studentB = $this->makeStudent('2601006', '成员乙', $this->classA);
        $group = $this->makeGroupApplication([$studentA, $studentB]);
        $service = app(ScoreService::class);

        $service->approveGroup($group, $this->admin);

        $this->assertSame(2, ScoreRecord::where('source_type', 'group')
            ->where('source_id', $group->id)
            ->whereNull('revoked_at')
            ->count());

        $this->actingAs($this->admin)
            ->put("/admin/group-applications/{$group->id}/cancel")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $group->refresh();
        $this->assertSame('pending', $group->status);
        $this->assertNull($group->reviewer_id);
        $this->assertSame(0, ScoreRecord::where('source_type', 'group')
            ->where('source_id', $group->id)
            ->whereNull('revoked_at')
            ->count());
        $this->assertSame(0.0, $service->studentSummary($studentA)['add_score']);
        $this->assertSame(0.0, $service->studentSummary($studentB)['add_score']);
        $this->assertSame(0, GroupApplicationMember::where('group_application_id', $group->id)
            ->whereNotNull('final_score')
            ->count());
    }

    public function test_cancelling_volunteer_approval_revokes_bonus_and_approval_can_award_it_again(): void
    {
        $student = $this->makeStudent('2601007', '志愿同学', $this->classA);
        $volunteer = ScoreCategory::where('code', 'volunteer')->firstOrFail();
        $service = app(ScoreService::class);
        $applications = [];

        for ($i = 1; $i <= 4; $i++) {
            $applications[] = $this->makeApplication($student, [
                'category_id' => $volunteer->id,
                'score' => 0,
                'activity_name' => "志愿活动 {$i}",
            ]);
            $service->approveApplication($applications[$i - 1], $this->admin, 0);
        }

        $this->assertSame(6.0, $service->studentSummary($student)['add_score']);
        $this->assertTrue(VolunteerRule::bonusAwarded($student->id, $this->semester->id));

        $this->actingAs($this->admin)
            ->put("/admin/applications/{$applications[0]->id}/cancel")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, $service->studentSummary($student)['add_score']);
        $this->assertFalse(VolunteerRule::bonusAwarded($student->id, $this->semester->id));

        $service->approveApplication($applications[0]->fresh(), $this->admin, 0);

        $this->assertTrue(VolunteerRule::bonusAwarded($student->id, $this->semester->id));
        $this->assertSame(6.0, $service->studentSummary($student)['add_score']);
    }

    public function test_approved_application_exposes_final_score_in_student_and_review_lists(): void
    {
        $student = $this->makeStudent('2601008', '分值同学', $this->classA);
        $application = $this->makeApplication($student, ['score' => 1]);

        $this->actingAs($this->admin)
            ->put("/admin/applications/{$application->id}/approve", [
                'final_score' => 0.5,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($student)
            ->get('/applications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('applications.data.0.score', 1)
                ->where('applications.data.0.final_score', 0.5));

        $this->actingAs($this->admin)
            ->get('/admin/review?status=approved')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('personal.data.0.score', 1)
                ->where('personal.data.0.final_score', 0.5));
    }

    public function test_class_admin_review_page_contains_class_leaderboard_only_for_assigned_class(): void
    {
        $counselor = $this->makeUser('counselor-1', '辅导员', 'class_admin');
        $studentA = $this->makeStudent('2601010', '同班甲', $this->classA);
        $studentB = $this->makeStudent('2601011', '同班乙', $this->classA);
        $outsider = $this->makeStudent('2601012', '别班丙', $this->classB);
        $this->assignClassAdmin($counselor, $this->classA);

        $this->makeApprovedRecord($studentA, 4);
        $this->makeApprovedRecord($studentB, 8);
        $this->makeApprovedRecord($outsider, 20);

        $this->actingAs($counselor)
            ->get('/admin/review')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leaderboard.available', true)
                ->where('leaderboard.class_id', $this->classA->id)
                ->has('leaderboard.classes', 1)
                ->has('leaderboard.rows', 2)
                ->where('leaderboard.rows.0.student_id', '2601011')
                ->where('leaderboard.rows.1.student_id', '2601010')
                ->where('deduction.available', true)
                ->where('deduction.store_url', '/admin/deductions'));
    }

    public function test_officer_review_page_contains_own_class_leaderboard(): void
    {
        $officer = $this->makeStudent('2601013', '班长', $this->classA);
        $classmate = $this->makeStudent('2601014', '同班同学', $this->classA);
        $outsider = $this->makeStudent('2601015', '别班同学', $this->classB);
        $this->appointOfficer($officer, $this->classA);

        $this->makeApprovedRecord($officer, 2);
        $this->makeApprovedRecord($classmate, 6);
        $this->makeApprovedRecord($outsider, 30);

        $this->actingAs($officer)
            ->get('/officer/review')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leaderboard.available', true)
                ->has('leaderboard.rows', 2)
                ->where('leaderboard.rows.0.id', $classmate->id)
                ->where('leaderboard.self_id', $officer->id)
                ->where('deduction.available', true)
                ->where('deduction.store_url', '/officer/deductions')
                ->has('deduction.students', 1)
                ->where('deduction.students.0.id', $classmate->id));
    }

    public function test_officer_can_deduct_classmate_but_not_self_or_other_class(): void
    {
        $officer = $this->makeStudent('2601016', '扣分班长', $this->classA);
        $classmate = $this->makeStudent('2601017', '同班同学', $this->classA);
        $outsider = $this->makeStudent('2601018', '别班同学', $this->classB);
        $this->appointOfficer($officer, $this->classA);

        $payload = [
            'category_id' => $this->deductCategory->id,
            'score' => 1.5,
            'applied_on' => now()->toDateString(),
            'description' => '课堂纪律',
        ];

        $this->actingAs($officer)
            ->post('/officer/deductions', $payload + ['student_id' => $classmate->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('score_records', [
            'user_id' => $classmate->id,
            'category_id' => $this->deductCategory->id,
            'score' => -1.5,
            'source_type' => 'manual',
            'created_by' => $officer->id,
        ]);

        $this->actingAs($officer)
            ->post('/officer/deductions', $payload + ['student_id' => $officer->id])
            ->assertForbidden();

        $this->actingAs($officer)
            ->post('/officer/deductions', $payload + ['student_id' => $outsider->id])
            ->assertForbidden();
    }

    public function test_class_admin_deduction_is_limited_to_assigned_classes(): void
    {
        $counselor = $this->makeUser('counselor-2', '辅导员乙', 'class_admin');
        $student = $this->makeStudent('2601019', '本班学生', $this->classA);
        $outsider = $this->makeStudent('2601020', '别班学生', $this->classB);
        $this->assignClassAdmin($counselor, $this->classA);

        $payload = [
            'category_id' => $this->deductCategory->id,
            'score' => 2,
            'applied_on' => now()->toDateString(),
            'description' => '宿舍卫生',
        ];

        $this->actingAs($counselor)
            ->post('/admin/deductions', $payload + ['student_id' => $student->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('score_records', [
            'user_id' => $student->id,
            'score' => -2,
            'source_type' => 'manual',
        ]);

        $this->actingAs($counselor)
            ->post('/admin/deductions', $payload + ['student_id' => $outsider->id])
            ->assertForbidden();
    }

    public function test_student_can_submit_group_application_with_classmates(): void
    {
        Storage::fake('local');

        $initiator = $this->makeStudent('2601021', '发起人', $this->classA);
        $classmate = $this->makeStudent('2601022', '同班成员', $this->classA);

        $this->actingAs($initiator)
            ->post('/applications/group', [
                'title' => '班级志愿清扫',
                'description' => '共同完成教学楼清扫',
                'category_id' => $this->addCategory->id,
                'applied_on' => '2026-10-20',
                'score' => 1.5,
                'member_ids' => [$classmate->id],
                'evidence' => [
                    UploadedFile::fake()->image('one.jpg'),
                    UploadedFile::fake()->image('two.jpg'),
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $application = GroupApplication::firstOrFail();

        $this->assertSame('student', $application->origin);
        $this->assertSame($initiator->id, $application->initiator_id);
        $this->assertSame($initiator->id, $application->teacher_id);
        $this->assertSame('2026-10-20', $application->applied_on?->toDateString());
        $this->assertSame(2, GroupApplicationMember::where('group_application_id', $application->id)->count());
        $this->assertSame(2, $application->evidences()->count());
        $this->assertDatabaseHas('group_application_members', [
            'group_application_id' => $application->id,
            'student_id' => $initiator->id,
            'score' => 1.5,
        ]);
        $this->assertDatabaseHas('group_application_members', [
            'group_application_id' => $application->id,
            'student_id' => $classmate->id,
            'score' => 1.5,
        ]);

        $this->actingAs($classmate)
            ->get("/applications/group/{$application->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applications/GroupShow')
                ->where('application.origin', 'student')
                ->where('application.initiator_id', $initiator->id)
                ->has('application.members', 2)
                ->where('canWithdraw', false)
                ->where('isInitiator', false));

        $this->actingAs($this->admin)
            ->put("/admin/group-applications/{$application->id}/approve", [
                'review_comment' => '集体活动属实',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ScoreRecord::where('source_type', 'group')
            ->where('source_id', $application->id)
            ->whereNull('revoked_at')
            ->count());
        $this->assertSame(1.5, app(ScoreService::class)->studentSummary($initiator)['add_score']);
        $this->assertSame(1.5, app(ScoreService::class)->studentSummary($classmate)['add_score']);
    }

    public function test_student_group_application_rejects_other_class_volunteer_and_outsider_view(): void
    {
        Storage::fake('local');

        $initiator = $this->makeStudent('2601023', '发起人乙', $this->classA);
        $classmate = $this->makeStudent('2601026', '同班同学乙', $this->classA);
        $outsider = $this->makeStudent('2601024', '别班同学', $this->classB);
        $volunteer = ScoreCategory::where('code', 'volunteer')->firstOrFail();

        $this->actingAs($initiator)
            ->post('/applications/group', [
                'title' => '跨班活动',
                'description' => '不应通过',
                'category_id' => $this->addCategory->id,
                'applied_on' => '2026-10-20',
                'score' => 1,
                'member_ids' => [$outsider->id],
                'evidence' => [UploadedFile::fake()->image('proof.jpg')],
            ])
            ->assertSessionHasErrors('member_ids');

        $this->actingAs($initiator)
            ->post('/applications/group', [
                'title' => '志愿服务集体申请',
                'description' => '不应通过',
                'category_id' => $volunteer->id,
                'applied_on' => '2026-10-20',
                'score' => 1,
                'member_ids' => [$classmate->id],
                'evidence' => [UploadedFile::fake()->image('proof.jpg')],
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('group_applications', 0);

        $group = $this->makeGroupApplication([$initiator]);

        $this->actingAs($outsider)
            ->get("/applications/group/{$group->id}")
            ->assertForbidden();
    }

    public function test_initiator_can_withdraw_pending_student_group_application(): void
    {
        $initiator = $this->makeStudent('2601025', '撤回发起人', $this->classA);
        $group = $this->makeGroupApplication([$initiator], [
            'origin' => 'student',
            'initiator_id' => $initiator->id,
            'teacher_id' => $initiator->id,
        ]);

        $this->actingAs($initiator)
            ->post("/applications/group/{$group->id}/withdraw")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('withdrawn', $group->fresh()->status);
    }

    public function test_officer_review_center_lists_groups_fully_within_class(): void
    {
        $officer = $this->makeStudent('2602001', '班长甲', $this->classA);
        $mateA = $this->makeStudent('2602002', '同班甲', $this->classA);
        $mateB = $this->makeStudent('2602003', '同班乙', $this->classA);
        $outsider = $this->makeStudent('2602004', '别班丙', $this->classB);
        $this->appointOfficer($officer, $this->classA);

        $ownGroup = $this->makeGroupApplication([$mateA, $mateB]);
        $mixedGroup = $this->makeGroupApplication([$mateA, $outsider]);
        $otherGroup = $this->makeGroupApplication([$outsider]);

        $this->actingAs($officer)
            ->get('/officer/review?tab=group')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/Index')
                ->where('officerMode', true)
                ->has('groups.data', 1)
                ->where('groups.data.0.id', $ownGroup->id)
                ->where('counts.group_pending', 1));

        $this->actingAs($officer)
            ->get("/officer/review/groups/{$ownGroup->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/GroupShow')
                ->where('officerMode', true)
                ->where('canDelete', false));

        $this->actingAs($officer)->get("/officer/review/groups/{$mixedGroup->id}")->assertForbidden();
        $this->actingAs($officer)->get("/officer/review/groups/{$otherGroup->id}")->assertForbidden();
        $this->actingAs($officer)->get("/admin/review/groups/{$ownGroup->id}")->assertForbidden();
    }

    public function test_class_admin_review_center_lists_groups_of_assigned_classes(): void
    {
        $counselor = $this->makeUser('counselor-group', '辅导员', 'class_admin');
        $studentA = $this->makeStudent('2602005', '本班学生', $this->classA);
        $outsider = $this->makeStudent('2602006', '别班学生', $this->classB);
        $this->assignClassAdmin($counselor, $this->classA);

        $ownGroup = $this->makeGroupApplication([$studentA]);
        $mixedGroup = $this->makeGroupApplication([$studentA, $outsider]);
        $otherGroup = $this->makeGroupApplication([$outsider]);

        $this->actingAs($counselor)
            ->get('/admin/review?tab=group')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('groups.data', 1)
                ->where('groups.data.0.id', $ownGroup->id)
                ->where('counts.group_pending', 1));

        $this->actingAs($counselor)->get("/admin/review/groups/{$ownGroup->id}")->assertOk();
        $this->actingAs($counselor)->get("/admin/review/groups/{$mixedGroup->id}")->assertForbidden();
        $this->actingAs($counselor)->get("/admin/review/groups/{$otherGroup->id}")->assertForbidden();
    }

    public function test_officer_can_preview_group_evidence_of_class_but_not_other_class(): void
    {
        Storage::fake('local');

        $officer = $this->makeStudent('2602007', '班长乙', $this->classA);
        $mate = $this->makeStudent('2602008', '同班成员', $this->classA);
        $outsider = $this->makeStudent('2602009', '别班成员', $this->classB);
        $this->appointOfficer($officer, $this->classA);

        $group = $this->makeGroupApplication([$mate]);
        $otherGroup = $this->makeGroupApplication([$outsider]);

        $evidence = $this->attachEvidence($group, 'group-evidence/own.jpg');
        $otherEvidence = $this->attachEvidence($otherGroup, 'group-evidence/other.jpg');

        $this->actingAs($officer)
            ->get("/group-applications/{$group->id}/evidences/{$evidence->id}/preview")
            ->assertOk();
        $this->actingAs($officer)
            ->get("/group-applications/{$group->id}/evidences/{$evidence->id}/download")
            ->assertOk();
        $this->actingAs($officer)
            ->get("/group-applications/{$otherGroup->id}/evidences/{$otherEvidence->id}/preview")
            ->assertForbidden();
        $this->actingAs($officer)
            ->get("/group-applications/{$otherGroup->id}/evidences/{$otherEvidence->id}/download")
            ->assertForbidden();
    }

    private function attachEvidence(GroupApplication $group, string $path): GroupApplicationEvidence
    {
        Storage::disk('local')->put($path, 'fake-evidence-bytes');

        return GroupApplicationEvidence::create([
            'group_application_id' => $group->id,
            'path' => $path,
            'name' => basename($path),
            'sort_order' => 0,
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

    private function makeApplication(User $student, array $attributes = []): ScoreApplication
    {
        return ScoreApplication::create(array_merge([
            'user_id' => $student->id,
            'category_id' => $this->addCategory->id,
            'semester_id' => $this->semester->id,
            'activity_name' => '班级活动',
            'description' => '参与班级活动',
            'applied_on' => now()->toDateString(),
            'score' => 3,
            'status' => 'pending',
        ], $attributes));
    }

    private function makeGroupApplication(array $students, array $attributes = []): GroupApplication
    {
        $application = GroupApplication::create(array_merge([
            'teacher_id' => $this->admin->id,
            'category_id' => $this->addCategory->id,
            'semester_id' => $this->semester->id,
            'title' => '集体活动',
            'description' => '集体活动说明',
            'status' => 'pending',
        ], $attributes));

        foreach ($students as $student) {
            GroupApplicationMember::create([
                'group_application_id' => $application->id,
                'student_id' => $student->id,
                'score' => 2,
            ]);
        }

        return $application;
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
}
