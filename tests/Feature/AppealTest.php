<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\User;
use App\Services\AppealService;
use App\Services\ScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppealTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private ScoreRecord $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'username' => 'appeal-admin',
            'name' => '申诉管理员',
            'password' => 'secret123',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->student = User::create([
            'username' => '4001',
            'name' => '申诉学生',
            'password' => 'secret123',
            'role' => 'student',
            'student_id' => '4001',
            'is_active' => true,
        ]);

        $semester = Semester::create([
            'academic_year' => '2026-2027',
            'term' => 1,
            'name' => '2026-2027学年第一学期',
            'is_current' => true,
        ]);

        $deduct = ScoreCategory::create([
            'name' => '扣分',
            'type' => 'deduct',
        ]);

        $this->record = ScoreRecord::create([
            'user_id' => $this->student->id,
            'category_id' => $deduct->id,
            'semester_id' => $semester->id,
            'score' => -2,
            'source_type' => 'manual',
            'description' => '测试扣分',
        ]);
    }

    public function test_student_can_submit_appeal_for_own_deduction(): void
    {
        $appeal = app(AppealService::class)->submit(
            $this->student,
            $this->record,
            '扣分有误',
            'appeals/test.png',
            'test.png'
        );

        $this->assertSame('pending', $appeal->status);
        $this->assertDatabaseHas('appeals', [
            'score_record_id' => $this->record->id,
            'user_id' => $this->student->id,
            'status' => 'pending',
        ]);
    }

    public function test_student_cannot_appeal_other_students_record(): void
    {
        $other = User::create([
            'username' => '4002',
            'name' => '其他学生',
            'password' => 'secret123',
            'role' => 'student',
            'student_id' => '4002',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(AppealService::class)->submit($other, $this->record, '不是本人', null, null);
    }

    public function test_approving_appeal_revokes_deduction(): void
    {
        $service = app(AppealService::class);
        $appeal = $service->submit($this->student, $this->record, '情况属实', null, null);

        $service->approve($appeal, $this->admin, '同意撤销');

        $this->assertSame('approved', $appeal->fresh()->status);
        $this->assertNotNull($this->record->fresh()->revoked_at);
        $this->assertSame('扣分申诉通过：同意撤销', $this->record->fresh()->revoke_reason);

        $summary = app(ScoreService::class)->studentSummary($this->student);
        $this->assertSame(0.0, $summary['deduct_score']);
    }

    public function test_duplicate_appeal_is_rejected(): void
    {
        $service = app(AppealService::class);
        $service->submit($this->student, $this->record, '第一次申诉', null, null);

        $this->expectException(ValidationException::class);
        $service->submit($this->student, $this->record, '重复申诉', null, null);
    }

    public function test_rejecting_appeal_keeps_deduction(): void
    {
        $service = app(AppealService::class);
        $appeal = $service->submit($this->student, $this->record, '申诉理由', null, null);

        $service->reject($appeal, $this->admin, '证据不足');

        $this->assertSame('rejected', $appeal->fresh()->status);
        $this->assertNull($this->record->fresh()->revoked_at);
        $this->assertSame(-2.0, $this->record->fresh()->score);
        $this->assertSame('rejected', Appeal::find($appeal->id)->status);
    }
}
