<?php

namespace Tests\Feature;

use App\Models\ScoreApplication;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\User;
use App\Services\ScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ApplicationReviewTest extends TestCase
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
            'username' => 'admin-test',
            'name' => '测试管理员',
            'password' => 'secret123',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->student = User::create([
            'username' => '3001',
            'name' => '申请测试学生',
            'password' => 'secret123',
            'role' => 'student',
            'student_id' => '3001',
            'is_active' => true,
        ]);

        $this->category = ScoreCategory::create([
            'name' => '校级活动',
            'type' => 'add',
        ]);

        $this->semester = Semester::create([
            'academic_year' => '2026-2027',
            'term' => 1,
            'name' => '2026-2027学年第一学期',
            'is_current' => true,
        ]);
    }

    public function test_approving_application_creates_score_record(): void
    {
        $application = ScoreApplication::create([
            'user_id' => $this->student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'description' => '参加校级活动',
            'score' => 4,
            'status' => 'pending',
        ]);

        app(ScoreService::class)->approveApplication($application, $this->admin, 6, '材料齐全');

        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame(6.0, $application->fresh()->final_score);
        $this->assertDatabaseHas('score_records', [
            'user_id' => $this->student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'score' => 6,
            'source_type' => 'personal',
            'source_id' => $application->id,
        ]);
    }

    public function test_approved_application_cannot_be_reviewed_again(): void
    {
        $application = ScoreApplication::create([
            'user_id' => $this->student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'description' => '重复审核测试',
            'score' => 4,
            'status' => 'pending',
        ]);

        $service = app(ScoreService::class);
        $service->approveApplication($application, $this->admin, 4);

        $this->expectException(ValidationException::class);
        $service->approveApplication($application->fresh(), $this->admin, 4);
    }

    public function test_deduct_category_creates_negative_score(): void
    {
        $deduct = ScoreCategory::create([
            'name' => '扣分',
            'type' => 'deduct',
        ]);

        $application = ScoreApplication::create([
            'user_id' => $this->student->id,
            'category_id' => $deduct->id,
            'semester_id' => $this->semester->id,
            'description' => '违纪扣分',
            'score' => 3,
            'status' => 'pending',
        ]);

        app(ScoreService::class)->approveApplication($application, $this->admin, 3);

        $record = ScoreRecord::where('source_id', $application->id)->first();

        $this->assertNotNull($record);
        $this->assertSame(-3.0, $record->score);
    }

    public function test_half_point_application_flow(): void
    {
        $application = ScoreApplication::create([
            'user_id' => $this->student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'description' => '半分测试',
            'score' => 0.5,
            'status' => 'pending',
        ]);

        app(ScoreService::class)->approveApplication($application, $this->admin, 0.5);

        $record = ScoreRecord::where('source_id', $application->id)->first();

        $this->assertNotNull($record);
        $this->assertSame(0.5, $record->score);
        $this->assertSame(0.5, $application->fresh()->final_score);

        $summary = app(ScoreService::class)->studentSummary($this->student, [$this->semester->id]);
        $this->assertSame(0.5, $summary['add_score']);
        $this->assertSame(40.5, $summary['total_score']);
    }

    public function test_application_form_accepts_half_point_only(): void
    {
        $this->actingAs($this->student);

        $common = [
            'category_id' => $this->category->id,
            'activity_name' => '校级志愿服务活动',
            'applied_on' => '2026-10-20',
            'description' => '半分表单测试',
            'evidence' => [UploadedFile::fake()->image('proof.jpg')],
        ];

        $this->post('/applications', ['score' => '0.3'] + $common)
            ->assertSessionHasErrors('score');

        $this->assertDatabaseCount('score_applications', 0);

        $this->post('/applications', ['score' => '0.5'] + $common)
            ->assertRedirect();

        $this->assertDatabaseHas('score_applications', [
            'user_id' => $this->student->id,
            'activity_name' => '校级志愿服务活动',
            'score' => 0.5,
            'status' => 'pending',
        ]);
    }

    public function test_application_form_requires_activity_name_and_date(): void
    {
        $this->actingAs($this->student);

        $this->post('/applications', [
            'category_id' => $this->category->id,
            'score' => '1',
            'description' => '缺少活动名称与日期',
            'evidence' => [UploadedFile::fake()->image('proof.jpg')],
        ])->assertSessionHasErrors(['activity_name', 'applied_on']);

        $this->assertDatabaseCount('score_applications', 0);
    }

    public function test_application_accepts_one_to_three_evidence_images(): void
    {
        Storage::fake('local');
        $this->actingAs($this->student);

        $common = [
            'category_id' => $this->category->id,
            'activity_name' => '校级志愿服务活动',
            'applied_on' => '2026-10-20',
            'description' => '证明材料数量测试',
            'score' => '1',
        ];

        $this->post('/applications', ['evidence' => []] + $common)
            ->assertSessionHasErrors('evidence');

        $four = [];

        for ($i = 1; $i <= 4; $i++) {
            $four[] = UploadedFile::fake()->image("proof{$i}.jpg");
        }

        $this->post('/applications', ['evidence' => $four] + $common)
            ->assertSessionHasErrors('evidence');

        $this->assertDatabaseCount('score_applications', 0);

        $three = [];

        for ($i = 1; $i <= 3; $i++) {
            $three[] = UploadedFile::fake()->image("proof{$i}.jpg");
        }

        $this->post('/applications', ['evidence' => $three] + $common)
            ->assertRedirect();

        $application = ScoreApplication::firstOrFail();
        $this->assertCount(3, $application->evidences);
        $this->assertSame(
            ['proof1.jpg', 'proof2.jpg', 'proof3.jpg'],
            $application->evidences->pluck('name')->all()
        );
    }

    public function test_evidence_preview_is_inline_and_restricted(): void
    {
        Storage::fake('local');
        $path = UploadedFile::fake()->image('proof.jpg')->store('evidence', 'local');

        $application = ScoreApplication::create([
            'user_id' => $this->student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'activity_name' => '证明材料预览测试',
            'applied_on' => '2026-10-20',
            'description' => '预览测试',
            'score' => 1,
            'status' => 'pending',
            'evidence_path' => $path,
            'evidence_name' => 'proof.jpg',
        ]);

        $evidence = $application->evidences()->create([
            'path' => $path,
            'name' => 'proof.jpg',
            'sort_order' => 0,
        ]);

        $preview = $this->actingAs($this->admin)
            ->get("/applications/{$application->id}/evidences/{$evidence->id}/preview");

        $preview->assertOk();
        $this->assertStringStartsWith('image/', (string) $preview->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $preview->headers->get('Content-Disposition'));

        $download = $this->actingAs($this->admin)
            ->get("/applications/{$application->id}/evidences/{$evidence->id}/download");

        $download->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));

        $outsider = User::create([
            'username' => '3002',
            'name' => '其他学生',
            'password' => 'secret123',
            'role' => 'student',
            'student_id' => '3002',
            'is_active' => true,
        ]);

        $this->actingAs($outsider)
            ->get("/applications/{$application->id}/evidences/{$evidence->id}/preview")
            ->assertForbidden();
    }

    public function test_leaderboard_export_contains_detail_and_summary_sheets(): void
    {
        $this->student->update([
            'student_id' => '260806129999',
            'class_name' => '计算机科学与技术2601班',
            'college' => '信息工程学院',
            'grade' => '2026级',
        ]);

        $application = ScoreApplication::create([
            'user_id' => $this->student->id,
            'category_id' => $this->category->id,
            'semester_id' => $this->semester->id,
            'activity_name' => '校级志愿服务活动',
            'applied_on' => '2026-10-20',
            'description' => '参与迎新志愿服务',
            'score' => 0.5,
            'status' => 'pending',
        ]);

        app(ScoreService::class)->approveApplication($application, $this->admin, 0.5);

        $response = $this->actingAs($this->admin)->get('/admin/leaderboard/export');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'dyf_export_').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($path);

            $this->assertSame(
                ['德育分排行榜', '加减分明细表', '学期汇总表'],
                $spreadsheet->getSheetNames()
            );

            $detail = $spreadsheet->getSheetByName('加减分明细表');
            $this->assertSame('260806129999', $detail->getCell('C4')->getValue());
            $this->assertSame('s', $detail->getCell('C4')->getDataType());
            $this->assertSame('@', $detail->getCell('C4')->getStyle()->getNumberFormat()->getFormatCode());
            $this->assertSame('2026-10-20', $detail->getCell('E4')->getValue());
            $this->assertSame('【校级活动】校级志愿服务活动', $detail->getCell('F4')->getValue());
            $this->assertSame(0.5, $detail->getCell('G4')->getValue());

            $summary = $spreadsheet->getSheetByName('学期汇总表');
            $this->assertStringContainsString('计算机科学与技术2601班', (string) $summary->getCell('A3')->getValue());
            $this->assertSame(1, $summary->getCell('A5')->getValue());
            $this->assertSame('申请测试学生', $summary->getCell('B5')->getValue());
            $this->assertSame(40.5, $summary->getCell('C5')->getValue());
        } finally {
            @unlink($path);
        }
    }
}
