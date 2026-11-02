<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\ScoreApplication;
use App\Models\ScoreCategory;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClassOfficerTest extends TestCase
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

        $this->admin = $this->makeUser('admin-officer', '系统管理员', 'admin');
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

    public function test_admin_can_appoint_officer_and_student_role_is_kept(): void
    {
        $student = $this->makeStudent('2609001', '张班长', $this->classA);

        $this->actingAs($this->admin)
            ->post('/admin/organization/class-officers', [
                'user_id' => $student->id,
                'class_id' => $this->classA->id,
                'title' => '班长',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $student->refresh();

        $this->assertSame('student', $student->role);
        $this->assertDatabaseHas('class_officer_user', [
            'user_id' => $student->id,
            'class_id' => $this->classA->id,
            'title' => '班长',
        ]);
        $this->assertTrue($student->isClassOfficer());
    }

    public function test_officer_must_be_a_student_of_that_class(): void
    {
        $otherClassStudent = $this->makeStudent('2609002', '别班学生', $this->classB);
        $teacher = $this->makeUser('t-officer', '教师', 'teacher', [
            'employee_id' => 'T9001',
            'college_id' => $this->college->id,
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/organization/class-officers', [
                'user_id' => $otherClassStudent->id,
                'class_id' => $this->classA->id,
                'title' => '班长',
            ])
            ->assertSessionHasErrors('user_id');

        $this->actingAs($this->admin)
            ->post('/admin/organization/class-officers', [
                'user_id' => $teacher->id,
                'class_id' => $this->classA->id,
                'title' => '班长',
            ])
            ->assertSessionHasErrors('user_id');

        $this->assertDatabaseCount('class_officer_user', 0);
    }

    public function test_officer_review_page_lists_only_classmates(): void
    {
        $officer = $this->makeStudent('2609003', '李班长', $this->classA);
        $classmate = $this->makeStudent('2609004', '同班同学', $this->classA);
        $outsider = $this->makeStudent('2609005', '别班同学', $this->classB);
        $this->appoint($officer, $this->classA);

        $classmateApplication = $this->makeApplication($classmate);
        $this->makeApplication($officer);
        $this->makeApplication($outsider);

        $this->actingAs($officer)
            ->get('/officer/review')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Review/Index')
                ->where('officerMode', true)
                ->has('personal.data', 1)
                ->where('personal.data.0.id', $classmateApplication->id)
                ->where('counts.group_pending', 0)
                ->where('counts.pending_appeals', 0));
    }

    public function test_officer_can_approve_classmate_but_not_self_or_other_class(): void
    {
        $officer = $this->makeStudent('2609006', '王班长', $this->classA);
        $classmate = $this->makeStudent('2609007', '同班同学', $this->classA);
        $outsider = $this->makeStudent('2609008', '别班同学', $this->classB);
        $this->appoint($officer, $this->classA);

        $classmateApplication = $this->makeApplication($classmate);
        $ownApplication = $this->makeApplication($officer);
        $outsiderApplication = $this->makeApplication($outsider);

        $this->actingAs($officer)
            ->put("/officer/applications/{$classmateApplication->id}/approve", [
                'final_score' => 3.5,
                'review_comment' => '材料齐全',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $classmateApplication->fresh()->status);
        $this->assertDatabaseHas('score_records', [
            'user_id' => $classmate->id,
            'source_type' => 'personal',
            'source_id' => $classmateApplication->id,
            'score' => 3.5,
        ]);

        $this->actingAs($officer)
            ->put("/officer/applications/{$ownApplication->id}/approve", ['final_score' => 2])
            ->assertForbidden();

        $this->actingAs($officer)
            ->put("/officer/applications/{$outsiderApplication->id}/reject", ['review_comment' => '不符合'])
            ->assertForbidden();
    }

    public function test_revoked_officer_loses_review_access_but_keeps_student_features(): void
    {
        $officer = $this->makeStudent('2609009', '赵班长', $this->classA);
        $classmate = $this->makeStudent('2609010', '同班同学', $this->classA);
        $this->appoint($officer, $this->classA);
        $application = $this->makeApplication($classmate);

        $this->actingAs($officer)->get('/officer/review')->assertOk();

        $this->actingAs($this->admin)
            ->delete("/admin/organization/class-officers/{$officer->id}/{$this->classA->id}")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('class_officer_user', 0);
        $this->assertFalse(app(\App\Support\AccessScope::class)->isClassOfficer($officer->fresh()));

        $this->actingAs($officer)->get('/officer/review')->assertForbidden();
        $this->actingAs($officer)
            ->put("/officer/applications/{$application->id}/approve", ['final_score' => 2])
            ->assertForbidden();

        $this->actingAs($officer)->get('/applications/create')->assertOk();
        $this->actingAs($officer)->get('/my-scores')->assertOk();
    }

    public function test_student_dashboard_shows_college_major_and_class(): void
    {
        $student = $this->makeStudent('2609011', '陈同学', $this->classA);

        $this->actingAs($student)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('classInfo.college', $this->college->name)
                ->where('classInfo.major', $this->major->name)
                ->where('classInfo.class_name', $this->classA->name)
                ->where('classInfo.grade', '2026级')
                ->where('classInfo.is_officer', false));
    }

    private function appoint(User $student, SchoolClass $class): void
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

    private function makeApplication(User $student, array $attributes = []): ScoreApplication
    {
        return ScoreApplication::create(array_merge([
            'user_id' => $student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'activity_name' => '班级活动',
            'description' => '参与班级活动',
            'applied_on' => now()->toDateString(),
            'score' => 3,
            'status' => 'pending',
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
