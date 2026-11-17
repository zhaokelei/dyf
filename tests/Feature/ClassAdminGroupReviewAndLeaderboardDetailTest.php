<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\College;
use App\Models\GroupApplication;
use App\Models\GroupApplicationMember;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\ScoreApplication;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClassAdminGroupReviewAndLeaderboardDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private College $college;

    private College $otherCollege;

    private Major $major;

    private SchoolClass $classA;

    private SchoolClass $classB;

    private SchoolClass $classC;

    private Semester $semester;

    private Semester $previousSemester;

    private ScoreCategory $addCategory;

    private ScoreCategory $deductCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeUser('admin-main', '系统管理员', 'admin');
        $this->college = College::create(['name' => '信息工程学院', 'is_active' => true]);
        $this->otherCollege = College::create(['name' => '文法学院', 'is_active' => true]);
        $this->major = Major::create([
            'college_id' => $this->college->id,
            'name' => '计算机科学与技术',
            'is_active' => true,
        ]);
        $this->classA = $this->makeClass('计算机2601班', '2026级', $this->college, $this->major);
        $this->classB = $this->makeClass('计算机2602班', '2026级', $this->college, $this->major);
        $this->classC = $this->makeClass('软件2601班', '2026级', $this->college, $this->major);
        $this->semester = Semester::create([
            'academic_year' => '2026-2027',
            'term' => 1,
            'name' => '2026-2027学年第一学期',
            'is_current' => true,
        ]);
        $this->previousSemester = Semester::create([
            'academic_year' => '2025-2026',
            'term' => 2,
            'name' => '2025-2026学年第二学期',
            'is_current' => false,
        ]);
        $this->addCategory = ScoreCategory::create(['name' => '校级活动', 'type' => 'add']);
        $this->deductCategory = ScoreCategory::create(['name' => '纪律扣分', 'type' => 'deduct']);
    }

    public function test_class_admin_can_review_only_groups_fully_inside_managed_classes(): void
    {
        $counselor = $this->makeUser('counselor-review', '辅导员', 'class_admin');
        $this->assignClassAdmin($counselor, $this->classA);

        $studentA = $this->makeStudent('2603001', '本班学生', $this->classA);
        $outsider = $this->makeStudent('2603002', '别班学生', $this->classB);

        $ownGroup = $this->makeGroup([$studentA]);
        $mixedGroup = $this->makeGroup([$studentA, $outsider]);
        $otherGroup = $this->makeGroup([$outsider]);

        $this->actingAs($counselor)
            ->get('/admin/review?tab=group')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.can_review_groups', true)
                ->has('groups.data', 1)
                ->where('groups.data.0.id', $ownGroup->id));

        $this->actingAs($counselor)
            ->put("/admin/group-applications/{$ownGroup->id}/approve")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $ownGroup->fresh()->status);
        $this->assertSame($counselor->id, $ownGroup->fresh()->reviewer_id);
        $this->assertDatabaseHas('score_records', [
            'user_id' => $studentA->id,
            'source_type' => 'group',
            'source_id' => $ownGroup->id,
            'score' => 2,
        ]);

        $this->actingAs($counselor)
            ->put("/admin/group-applications/{$ownGroup->id}/cancel")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('pending', $ownGroup->fresh()->status);
        $this->assertSame(0, ScoreRecord::where('source_type', 'group')
            ->where('source_id', $ownGroup->id)
            ->whereNull('revoked_at')
            ->count());

        $this->actingAs($counselor)
            ->put("/admin/group-applications/{$mixedGroup->id}/approve")
            ->assertForbidden();
        $this->actingAs($counselor)
            ->put("/admin/group-applications/{$otherGroup->id}/reject")
            ->assertForbidden();

        $this->assertSame('pending', $mixedGroup->fresh()->status);
        $this->assertSame('pending', $otherGroup->fresh()->status);
    }

    public function test_class_admin_bulk_review_of_groups_is_scoped_to_managed_classes(): void
    {
        $counselor = $this->makeUser('counselor-bulk', '辅导员批量', 'class_admin');
        $this->assignClassAdmin($counselor, $this->classA);

        $studentA = $this->makeStudent('2603003', '本班学生', $this->classA);
        $outsider = $this->makeStudent('2603004', '别班学生', $this->classB);

        $ownGroup = $this->makeGroup([$studentA]);
        $otherGroup = $this->makeGroup([$outsider]);

        $this->actingAs($counselor)
            ->post('/admin/review/bulk', [
                'type' => 'group',
                'action' => 'approve',
                'ids' => [$ownGroup->id, $otherGroup->id],
            ])
            ->assertRedirect();

        $this->assertSame('approved', $ownGroup->fresh()->status);
        $this->assertSame('pending', $otherGroup->fresh()->status);
    }

    public function test_review_and_appeal_lists_support_class_filter_within_scope(): void
    {
        $counselor = $this->makeUser('counselor-filter', '辅导员筛选', 'class_admin');
        $this->assignClassAdmin($counselor, $this->classA);
        $this->assignClassAdmin($counselor, $this->classC);

        $studentA = $this->makeStudent('2603005', '一班学生', $this->classA);
        $studentB = $this->makeStudent('2603006', '二班学生', $this->classB);
        $studentC = $this->makeStudent('2603007', '三班学生', $this->classC);

        $applicationA = $this->makeApplication($studentA);
        $this->makeApplication($studentB);
        $this->makeApplication($studentC);

        $groupA = $this->makeGroup([$studentA]);
        $this->makeGroup([$studentB]);
        $this->makeGroup([$studentC]);

        $recordA = $this->makeRecord($studentA, $this->semester, -1, 'manual');
        $recordB = $this->makeRecord($studentB, $this->semester, -1, 'manual');
        $this->makeRecord($studentC, $this->semester, -1, 'manual');

        $this->makeAppeal($studentA, $recordA);
        $this->makeAppeal($studentB, $recordB);

        $this->actingAs($counselor)
            ->get('/admin/review?status=all&manual_status=all&class_id='.$this->classA->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('classes', 2)
                ->where('filters.class_id', $this->classA->id)
                ->has('personal.data', 1)
                ->where('personal.data.0.id', $applicationA->id)
                ->where('personal.data.0.user.student_id', '2603005')
                ->has('groups.data', 1)
                ->where('groups.data.0.id', $groupA->id)
                ->has('manual.data', 1)
                ->where('manual.data.0.user.student_id', '2603005'));

        $this->actingAs($counselor)
            ->get('/admin/review?status=all&manual_status=all&class_id='.$this->classB->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.class_id', null)
                ->has('personal.data', 2)
                ->has('groups.data', 2)
                ->has('manual.data', 2));

        $this->actingAs($this->admin)
            ->get('/admin/review?status=all&manual_status=all&class_id='.$this->classB->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('classes', 3)
                ->where('filters.class_id', $this->classB->id)
                ->has('personal.data', 1)
                ->has('groups.data', 1));

        $this->actingAs($counselor)
            ->get('/admin/appeals?status=all&class_id='.$this->classA->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('classes', 2)
                ->where('filters.class_id', $this->classA->id)
                ->has('appeals.data', 1)
                ->where('appeals.data.0.user.student_id', '2603005'));
    }

    public function test_leaderboard_student_detail_is_visible_to_roles_and_scoped(): void
    {
        $counselor = $this->makeUser('counselor-detail', '辅导员详情', 'class_admin');
        $this->assignClassAdmin($counselor, $this->classA);

        $collegeAdmin = $this->makeUser('college-detail', '学院管理员', 'college_admin');
        DB::table('college_admin_user')->insert([
            'user_id' => $collegeAdmin->id,
            'college_id' => $this->college->id,
            'granted_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $teacher = $this->makeUser('teacher-detail', '教师', 'teacher', [
            'employee_id' => 'T3001',
            'college_id' => $this->college->id,
        ]);

        $studentA = $this->makeStudent('2603008', '一班学生', $this->classA);
        $otherCollege = College::create(['name' => '外国语学院', 'is_active' => true]);
        $otherMajor = Major::create([
            'college_id' => $otherCollege->id,
            'name' => '英语',
            'is_active' => true,
        ]);
        $otherClass = $this->makeClass('英语2601班', '2026级', $otherCollege, $otherMajor);
        $studentB = $this->makeStudent('2603009', '外院学生', $otherClass, $otherCollege, $otherMajor);

        $this->makeRecord($studentA, $this->semester, 2);
        $this->makeRecord($studentA, $this->previousSemester, 3);
        $this->makeRecord($studentB, $this->semester, 4);

        $this->actingAs($counselor)
            ->get("/leaderboard/students/{$studentA->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Students/Show')
                ->where('period.value', 'all')
                ->where('summary.record_count', 2)
                ->where('detailUrl', "/leaderboard/students/{$studentA->id}"));

        $this->actingAs($counselor)
            ->get("/leaderboard/students/{$studentA->id}?period=semester:{$this->semester->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.value', "semester:{$this->semester->id}")
                ->where('summary.record_count', 1));

        $this->actingAs($counselor)
            ->get("/leaderboard/students/{$studentB->id}")
            ->assertForbidden();

        $this->actingAs($collegeAdmin)
            ->get("/leaderboard/students/{$studentA->id}")
            ->assertOk();
        $this->actingAs($collegeAdmin)
            ->get("/leaderboard/students/{$studentB->id}")
            ->assertForbidden();

        $this->actingAs($teacher)
            ->get("/leaderboard/students/{$studentA->id}")
            ->assertOk();
        $this->actingAs($teacher)
            ->get("/leaderboard/students/{$studentB->id}")
            ->assertOk();
        $this->actingAs($teacher)
            ->get("/admin/students/{$studentA->id}")
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get("/leaderboard/students/{$studentB->id}")
            ->assertOk();

        $this->actingAs($studentB)
            ->get("/leaderboard/students/{$studentA->id}")
            ->assertForbidden();

        $this->actingAs($teacher)
            ->get("/leaderboard/students/{$this->admin->id}")
            ->assertNotFound();
    }

    public function test_review_center_student_records_are_scoped_by_role(): void
    {
        $counselor = $this->makeUser('counselor-records', '辅导记录', 'class_admin');
        $this->assignClassAdmin($counselor, $this->classA);

        $studentA = $this->makeStudent('2603010', '一班记录', $this->classA);
        $studentB = $this->makeStudent('2603011', '二班记录', $this->classB);

        $this->makeRecord($studentA, $this->semester, 2);
        $this->makeRecord($studentA, $this->previousSemester, -1);

        $this->actingAs($counselor)
            ->getJson("/admin/review/students/{$studentA->id}/records")
            ->assertOk()
            ->assertJsonPath('student.id', $studentA->id)
            ->assertJsonCount(2, 'summary.records');

        $this->actingAs($counselor)
            ->getJson("/admin/review/students/{$studentB->id}/records")
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->getJson("/admin/review/students/{$studentB->id}/records")
            ->assertOk();

        $officer = $this->makeStudent('2603012', '班干部记录', $this->classA);
        DB::table('class_officer_user')->insert([
            'user_id' => $officer->id,
            'class_id' => $this->classA->id,
            'granted_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($officer)
            ->getJson("/officer/review/students/{$studentA->id}/records")
            ->assertOk()
            ->assertJsonPath('student.id', $studentA->id);

        $this->actingAs($officer)
            ->getJson("/officer/review/students/{$studentB->id}/records")
            ->assertForbidden();

        $this->actingAs($officer)
            ->getJson("/officer/review/students/{$officer->id}/records")
            ->assertOk();

        $this->actingAs($studentA)
            ->getJson("/admin/review/students/{$studentA->id}/records")
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->getJson("/admin/review/students/{$this->admin->id}/records")
            ->assertNotFound();
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

    private function makeClass(string $name, string $grade, College $college, Major $major): SchoolClass
    {
        return SchoolClass::create([
            'college_id' => $college->id,
            'major_id' => $major->id,
            'name' => $name,
            'grade' => $grade,
            'is_active' => true,
        ]);
    }

    private function makeStudent(
        string $studentId,
        string $name,
        SchoolClass $class,
        ?College $college = null,
        ?Major $major = null
    ): User {
        $college ??= $this->college;
        $major ??= $this->major;

        return $this->makeUser($studentId, $name, 'student', [
            'student_id' => $studentId,
            'college_id' => $college->id,
            'college' => $college->name,
            'major_id' => $major->id,
            'class_id' => $class->id,
            'class_name' => $class->name,
            'grade' => $class->grade,
        ]);
    }

    private function makeApplication(User $student): ScoreApplication
    {
        return ScoreApplication::create([
            'user_id' => $student->id,
            'category_id' => $this->addCategory->id,
            'semester_id' => $this->semester->id,
            'activity_name' => '班级活动',
            'description' => '参与班级活动',
            'applied_on' => now()->toDateString(),
            'score' => 3,
            'status' => 'pending',
        ]);
    }

    private function makeGroup(array $students): GroupApplication
    {
        $application = GroupApplication::create([
            'teacher_id' => $this->admin->id,
            'category_id' => $this->addCategory->id,
            'semester_id' => $this->semester->id,
            'title' => '集体活动',
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

    private function makeRecord(
        User $student,
        Semester $semester,
        float $score,
        string $sourceType = 'manual'
    ): ScoreRecord {
        $category = $score < 0 ? $this->deductCategory : $this->addCategory;

        return ScoreRecord::create([
            'user_id' => $student->id,
            'category_id' => $category->id,
            'semester_id' => $semester->id,
            'score' => $score,
            'applied_on' => now()->toDateString(),
            'source_type' => $sourceType,
            'description' => '测试记录',
            'created_by' => $this->admin->id,
        ]);
    }

    private function makeAppeal(User $student, ScoreRecord $record): Appeal
    {
        return Appeal::create([
            'score_record_id' => $record->id,
            'user_id' => $student->id,
            'reason' => '对扣分有异议',
            'evidence_path' => 'appeals/fake.jpg',
            'evidence_name' => 'fake.jpg',
            'status' => 'pending',
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
