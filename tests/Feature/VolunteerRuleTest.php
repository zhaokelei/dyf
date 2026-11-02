<?php

namespace Tests\Feature;

use App\Models\ScoreApplication;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\User;
use App\Services\ScoreService;
use App\Support\SemesterStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VolunteerRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private ScoreCategory $volunteer;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'username' => 'admin-volunteer',
            'name' => '志愿服务管理员',
            'password' => 'secret123',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->student = User::create([
            'username' => '3101',
            'name' => '志愿服务学生',
            'password' => 'secret123',
            'role' => 'student',
            'student_id' => '3101',
            'is_active' => true,
        ]);

        $this->volunteer = ScoreCategory::where('code', 'volunteer')->firstOrFail();

        $this->semester = Semester::create([
            'academic_year' => '2026-2027',
            'term' => 1,
            'name' => '2026-2027学年第一学期',
            'is_current' => true,
        ]);

        app(SemesterStore::class)->forget();
    }

    public function test_volunteer_application_is_saved_with_zero_score(): void
    {
        Storage::fake('local');
        $this->actingAs($this->student);

        $this->post('/applications', [
            'category_id' => $this->volunteer->id,
            'activity_name' => '迎新志愿服务',
            'applied_on' => '2026-10-20',
            'description' => '参加迎新志愿服务',
            'evidence' => [UploadedFile::fake()->image('proof.jpg')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('score_applications', [
            'user_id' => $this->student->id,
            'category_id' => $this->volunteer->id,
            'score' => 0,
            'status' => 'pending',
        ]);
    }

    public function test_volunteer_category_page_shows_progress(): void
    {
        $this->actingAs($this->student)
            ->get('/applications/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applications/Create')
                ->where('volunteer.required_count', 4)
                ->where('volunteer.bonus_score', 6)
                ->where('volunteer.approved_count', 0)
                ->where('volunteer.limit_reached', false));
    }

    public function test_four_approved_volunteer_applications_award_six_points_once(): void
    {
        $service = app(ScoreService::class);

        for ($i = 1; $i <= 4; $i++) {
            $application = $this->makeApplication("志愿活动 {$i}");

            $service->approveApplication($application, $this->admin, 99);
        }

        $applications = ScoreApplication::where('user_id', $this->student->id)
            ->where('category_id', $this->volunteer->id)
            ->where('status', 'approved')
            ->get();

        $this->assertCount(4, $applications);
        $this->assertSame([0.0], $applications->pluck('final_score')->unique()->values()->all());

        $bonus = ScoreRecord::where('user_id', $this->student->id)
            ->where('source_type', 'volunteer_bonus')
            ->get();

        $this->assertCount(1, $bonus);
        $this->assertSame(6.0, $bonus->first()->score);
        $this->assertSame($this->semester->id, (int) $bonus->first()->semester_id);

        $summary = app(ScoreService::class)->studentSummary($this->student, [$this->semester->id]);
        $this->assertSame(6.0, $summary['add_score']);
    }

    public function test_fifth_volunteer_application_can_be_submitted_but_not_approved_and_new_submissions_are_blocked(): void
    {
        $service = app(ScoreService::class);

        for ($i = 1; $i <= 4; $i++) {
            $service->approveApplication($this->makeApplication("已通过志愿 {$i}"), $this->admin, 0);
        }

        $fifth = $this->makeApplication('第五条志愿申请');

        try {
            $service->approveApplication($fifth, $this->admin, 0);
            $this->fail('第五条志愿服务申请不应被通过。');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame('pending', $fifth->fresh()->status);

        $this->actingAs($this->student)
            ->post('/applications', [
                'category_id' => $this->volunteer->id,
                'activity_name' => '超出上限的申请',
                'applied_on' => '2026-10-20',
                'description' => '应被拦截',
                'evidence' => [UploadedFile::fake()->image('proof.jpg')],
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseMissing('score_applications', [
            'user_id' => $this->student->id,
            'activity_name' => '超出上限的申请',
        ]);
    }

    public function test_volunteer_limit_resets_in_new_semester(): void
    {
        $service = app(ScoreService::class);

        for ($i = 1; $i <= 4; $i++) {
            $service->approveApplication($this->makeApplication("上学期志愿 {$i}"), $this->admin, 0);
        }

        $this->semester->update(['is_current' => false]);

        Semester::create([
            'academic_year' => '2026-2027',
            'term' => 2,
            'name' => '2026-2027学年第二学期',
            'is_current' => true,
        ]);

        app(SemesterStore::class)->forget();

        $this->actingAs($this->student)
            ->get('/applications/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('volunteer.approved_count', 0)
                ->where('volunteer.limit_reached', false));

        $this->post('/applications', [
            'category_id' => $this->volunteer->id,
            'activity_name' => '新学期志愿服务',
            'applied_on' => '2026-10-20',
            'description' => '新学期可以正常申请',
            'evidence' => [UploadedFile::fake()->image('proof.jpg')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('score_applications', [
            'user_id' => $this->student->id,
            'activity_name' => '新学期志愿服务',
            'score' => 0,
        ]);
    }

    public function test_review_endpoint_does_not_require_final_score_for_volunteer(): void
    {
        $application = $this->makeApplication('免填分值志愿');

        $this->actingAs($this->admin)
            ->put("/admin/applications/{$application->id}/approve", [
                'review_comment' => '志愿服务核定 0 分',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame(0.0, $application->fresh()->final_score);
        $this->assertDatabaseHas('score_records', [
            'source_type' => 'personal',
            'source_id' => $application->id,
            'score' => 0,
        ]);
    }

    private function makeApplication(string $name): ScoreApplication
    {
        return ScoreApplication::create([
            'user_id' => $this->student->id,
            'category_id' => $this->volunteer->id,
            'semester_id' => $this->semester->id,
            'activity_name' => $name,
            'applied_on' => '2026-10-20',
            'description' => $name,
            'score' => 0,
            'status' => 'pending',
        ]);
    }
}
