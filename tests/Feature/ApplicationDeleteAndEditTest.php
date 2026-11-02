<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\GroupApplication;
use App\Models\GroupApplicationMember;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\ScoreApplication;
use App\Models\ScoreApplicationEvidence;
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
use Tests\TestCase;

class ApplicationDeleteAndEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private College $college;

    private Major $major;

    private SchoolClass $classA;

    private SchoolClass $classB;

    private Semester $semester;

    private ScoreCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = $this->makeUser('admin-delete-edit', '系统管理员', 'admin');
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
        $this->category = ScoreCategory::create(['name' => '校级活动', 'type' => 'add']);
    }

    public function test_student_can_edit_pending_application_and_evidence(): void
    {
        $student = $this->makeStudent('2603001', '张同学', $this->classA);
        $application = $this->makeApplication($student, 'pending');
        $first = $this->addEvidence($application, 'first.jpg');
        $second = $this->addEvidence($application, 'second.jpg');

        $this->actingAs($student)
            ->post("/applications/{$application->id}", [
                '_method' => 'put',
                'activity_name' => '修改后的活动名称',
                'applied_on' => '2026-10-18',
                'description' => '修改后的说明',
                'keep_evidence' => [$first->id],
                'evidence' => [UploadedFile::fake()->image('new.jpg')],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->assertSame('修改后的活动名称', $application->activity_name);
        $this->assertSame('2026-10-18', $application->applied_on->toDateString());
        $this->assertSame('修改后的说明', $application->description);
        $this->assertSame('pending', $application->status);
        $this->assertSame($first->path, $application->evidence_path);
        $this->assertCount(2, $application->evidences);
        $this->assertDatabaseHas('score_application_evidences', ['id' => $first->id, 'score_application_id' => $application->id]);
        $this->assertDatabaseMissing('score_application_evidences', ['id' => $second->id]);
        Storage::disk('local')->assertMissing($second->path);
        Storage::disk('local')->assertExists($first->path);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'action' => 'update_application',
            'target_id' => $application->id,
        ]);
    }

    public function test_rejected_edit_returns_to_pending_and_approved_or_foreign_application_is_locked(): void
    {
        $student = $this->makeStudent('2603002', '李同学', $this->classA);
        $other = $this->makeStudent('2603003', '其他同学', $this->classA);

        $rejected = $this->makeApplication($student, 'rejected');
        $this->addEvidence($rejected, 'rejected.jpg');
        $rejected->update([
            'reviewer_id' => $this->admin->id,
            'review_comment' => '材料不足',
            'reviewed_at' => now(),
        ]);

        $this->actingAs($student)
            ->post("/applications/{$rejected->id}", [
                '_method' => 'put',
                'activity_name' => '补充材料',
                'applied_on' => '2026-10-19',
                'description' => '已补充材料',
                'keep_evidence' => $rejected->evidences->pluck('id')->all(),
                'evidence' => [],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $rejected->refresh();
        $this->assertSame('pending', $rejected->status);
        $this->assertNull($rejected->reviewer_id);
        $this->assertNull($rejected->review_comment);
        $this->assertNull($rejected->reviewed_at);

        $approved = $this->makeApplication($student, 'pending');
        $this->addEvidence($approved, 'approved.jpg');
        app(ScoreService::class)->approveApplication($approved, $this->admin, 2);

        $this->actingAs($student)
            ->post("/applications/{$approved->id}", [
                '_method' => 'put',
                'activity_name' => '试图修改已通过',
                'applied_on' => '2026-10-19',
                'description' => '不应成功',
                'keep_evidence' => $approved->evidences->pluck('id')->all(),
            ])
            ->assertForbidden();

        $this->assertNotSame('试图修改已通过', $approved->fresh()->activity_name);
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame(2.0, $approved->fresh()->final_score);

        $this->actingAs($other)
            ->post("/applications/{$rejected->id}", [
                '_method' => 'put',
                'activity_name' => '越权修改',
                'applied_on' => '2026-10-19',
                'description' => '不应成功',
                'keep_evidence' => $rejected->evidences->pluck('id')->all(),
            ])
            ->assertForbidden();

        $this->assertSame('补充材料', $rejected->fresh()->activity_name);
    }

    public function test_student_can_edit_pending_group_application_as_initiator_only(): void
    {
        $initiator = $this->makeStudent('2603004', '发起人', $this->classA);
        $member = $this->makeStudent('2603005', '成员', $this->classA);
        $group = $this->makeGroupApplication($initiator, [$member]);
        $evidence = $this->addGroupEvidence($group, 'group.jpg');

        $this->actingAs($initiator)
            ->post("/applications/group/{$group->id}", [
                '_method' => 'put',
                'title' => '修改后的集体活动',
                'description' => '修改后的集体说明',
                'applied_on' => '2026-10-17',
                'keep_evidence' => [$evidence->id],
                'evidence' => [],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $group->refresh();
        $this->assertSame('修改后的集体活动', $group->title);
        $this->assertSame('修改后的集体说明', $group->description);
        $this->assertSame('2026-10-17', $group->applied_on->toDateString());
        $this->assertSame('pending', $group->status);
        $this->assertCount(1, $group->evidences);

        $this->actingAs($member)
            ->post("/applications/group/{$group->id}", [
                '_method' => 'put',
                'title' => '成员越权修改',
                'description' => '不应成功',
                'applied_on' => '2026-10-17',
                'keep_evidence' => [$evidence->id],
            ])
            ->assertForbidden();

        app(ScoreService::class)->approveGroup($group->fresh(), $this->admin, []);
        $this->assertSame('approved', $group->fresh()->status);

        $this->actingAs($initiator)
            ->post("/applications/group/{$group->id}", [
                '_method' => 'put',
                'title' => '通过后修改',
                'description' => '不应成功',
                'applied_on' => '2026-10-17',
                'keep_evidence' => [$evidence->id],
            ])
            ->assertForbidden();
    }

    public function test_counselor_can_delete_approved_application_and_revoke_score(): void
    {
        $counselor = $this->makeUser('counselor-delete', '辅导员', 'class_admin');
        $this->assignClassAdmin($counselor, $this->classA);
        $student = $this->makeStudent('2603006', '本班学生', $this->classA);
        $outsider = $this->makeStudent('2603007', '别班学生', $this->classB);

        $application = $this->makeApplication($student, 'pending');
        $evidence = $this->addEvidence($application, 'proof.jpg');
        app(ScoreService::class)->approveApplication($application, $this->admin, 3);

        $this->actingAs($counselor)
            ->delete("/admin/applications/{$application->id}")
            ->assertRedirect(route('review.index'));

        $this->assertDatabaseMissing('score_applications', ['id' => $application->id]);
        $this->assertDatabaseMissing('score_application_evidences', ['id' => $evidence->id]);
        Storage::disk('local')->assertMissing($evidence->path);
        $this->assertNotNull(
            ScoreRecord::where('source_type', 'personal')->where('source_id', $application->id)->first()?->revoked_at
        );
        $this->assertSame(0.0, app(ScoreService::class)->studentSummary($student)['add_score']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $counselor->id,
            'action' => 'delete_application',
            'target_id' => $application->id,
        ]);

        $outsideApplication = $this->makeApplication($outsider, 'pending');

        $this->actingAs($counselor)
            ->delete("/admin/applications/{$outsideApplication->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('score_applications', ['id' => $outsideApplication->id]);
    }

    public function test_officer_can_delete_classmate_application_but_not_self_or_other_class(): void
    {
        $officer = $this->makeStudent('2603008', '班长', $this->classA);
        $classmate = $this->makeStudent('2603009', '同班同学', $this->classA);
        $outsider = $this->makeStudent('2603010', '别班同学', $this->classB);
        $this->appointOfficer($officer, $this->classA);

        $classmateApplication = $this->makeApplication($classmate, 'pending');
        app(ScoreService::class)->approveApplication($classmateApplication, $this->admin, 2);
        $ownApplication = $this->makeApplication($officer, 'pending');
        $outsiderApplication = $this->makeApplication($outsider, 'pending');

        $this->actingAs($officer)
            ->delete("/officer/applications/{$classmateApplication->id}")
            ->assertRedirect(route('officer.review.index'));

        $this->assertDatabaseMissing('score_applications', ['id' => $classmateApplication->id]);
        $this->assertSame(0.0, app(ScoreService::class)->studentSummary($classmate)['add_score']);

        $this->actingAs($officer)
            ->delete("/officer/applications/{$ownApplication->id}")
            ->assertForbidden();

        $this->actingAs($officer)
            ->delete("/officer/applications/{$outsiderApplication->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('score_applications', ['id' => $ownApplication->id]);
        $this->assertDatabaseHas('score_applications', ['id' => $outsiderApplication->id]);
    }

    public function test_manager_can_delete_approved_group_application_and_revoke_all_members(): void
    {
        $first = $this->makeStudent('2603011', '成员甲', $this->classA);
        $second = $this->makeStudent('2603012', '成员乙', $this->classA);
        $group = $this->makeGroupApplication($first, [$second]);
        $evidence = $this->addGroupEvidence($group, 'group-proof.jpg');

        $memberIds = $group->members()->pluck('id')->all();
        $finalScores = [];

        foreach ($memberIds as $memberId) {
            $finalScores[$memberId] = 2;
        }

        app(ScoreService::class)->approveGroup($group, $this->admin, $finalScores);

        $this->actingAs($this->admin)
            ->delete("/admin/group-applications/{$group->id}")
            ->assertRedirect(route('review.index'));

        $this->assertDatabaseMissing('group_applications', ['id' => $group->id]);
        $this->assertDatabaseMissing('group_application_members', ['group_application_id' => $group->id]);
        $this->assertDatabaseMissing('group_application_evidences', ['id' => $evidence->id]);
        Storage::disk('local')->assertMissing($evidence->path);
        $this->assertSame(0, ScoreRecord::where('source_type', 'group')->where('source_id', $group->id)->whereNull('revoked_at')->count());
        $this->assertSame(0.0, app(ScoreService::class)->studentSummary($first)['add_score']);
        $this->assertSame(0.0, app(ScoreService::class)->studentSummary($second)['add_score']);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'delete_group_application',
            'target_id' => $group->id,
        ]);
    }

    public function test_pending_and_withdrawn_applications_delete_without_score_changes(): void
    {
        $student = $this->makeStudent('2603013', '学生', $this->classA);
        $pending = $this->makeApplication($student, 'pending');
        $withdrawn = $this->makeApplication($student, 'withdrawn');

        $this->actingAs($this->admin)
            ->delete("/admin/applications/{$pending->id}")
            ->assertRedirect(route('review.index'));

        $this->actingAs($this->admin)
            ->delete("/admin/applications/{$withdrawn->id}")
            ->assertRedirect(route('review.index'));

        $this->assertDatabaseMissing('score_applications', ['id' => $pending->id]);
        $this->assertDatabaseMissing('score_applications', ['id' => $withdrawn->id]);
        $this->assertSame(0, ScoreRecord::count());
    }

    public function test_deleting_approved_volunteer_application_revokes_bonus_when_below_limit(): void
    {
        $student = $this->makeStudent('2603014', '志愿者', $this->classA);
        $volunteer = ScoreCategory::firstOrCreate(
            ['code' => 'volunteer'],
            ['name' => '志愿服务', 'type' => 'add']
        );

        $applications = [];

        for ($i = 0; $i < VolunteerRule::REQUIRED_COUNT; $i++) {
            $application = ScoreApplication::create([
                'user_id' => $student->id,
                'category_id' => $volunteer->id,
                'semester_id' => $this->semester->id,
                'activity_name' => '志愿活动 '.$i,
                'applied_on' => '2026-10-20',
                'description' => '志愿服务',
                'score' => 0,
                'status' => 'pending',
            ]);
            app(ScoreService::class)->approveApplication($application, $this->admin, 0);
            $applications[] = $application;
        }

        $this->assertSame(6.0, app(ScoreService::class)->studentSummary($student)['add_score']);

        $this->actingAs($this->admin)
            ->delete("/admin/applications/{$applications[0]->id}")
            ->assertRedirect(route('review.index'));

        $this->assertSame(0.0, app(ScoreService::class)->studentSummary($student)['add_score']);
        $this->assertSame(
            0,
            ScoreRecord::where('source_type', VolunteerRule::BONUS_SOURCE)
                ->where('user_id', $student->id)
                ->whereNull('revoked_at')
                ->count()
        );
    }

    private function makeApplication(User $student, string $status): ScoreApplication
    {
        return ScoreApplication::create([
            'user_id' => $student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'activity_name' => '班级活动',
            'applied_on' => '2026-10-20',
            'description' => '参与班级活动',
            'score' => 3,
            'status' => $status,
        ]);
    }

    private function addEvidence(ScoreApplication $application, string $name): ScoreApplicationEvidence
    {
        $path = UploadedFile::fake()->image($name)->store('evidence', 'local');

        return $application->evidences()->create([
            'path' => $path,
            'name' => $name,
            'sort_order' => $application->evidences()->count(),
        ]);
    }

    private function addGroupEvidence(GroupApplication $application, string $name)
    {
        $path = UploadedFile::fake()->image($name)->store('evidence', 'local');

        return $application->evidences()->create([
            'path' => $path,
            'name' => $name,
            'sort_order' => $application->evidences()->count(),
        ]);
    }

    private function makeGroupApplication(User $initiator, array $members): GroupApplication
    {
        $application = GroupApplication::create([
            'teacher_id' => $initiator->id,
            'origin' => 'student',
            'initiator_id' => $initiator->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'title' => '集体活动',
            'description' => '集体活动说明',
            'applied_on' => '2026-10-20',
            'status' => 'pending',
        ]);

        GroupApplicationMember::create([
            'group_application_id' => $application->id,
            'student_id' => $initiator->id,
            'score' => 2,
        ]);

        foreach ($members as $member) {
            GroupApplicationMember::create([
                'group_application_id' => $application->id,
                'student_id' => $member->id,
                'score' => 2,
            ]);
        }

        return $application;
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
