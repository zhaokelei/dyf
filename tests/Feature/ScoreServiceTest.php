<?php

namespace Tests\Feature;

use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\User;
use App\Services\ScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    private function student(): User
    {
        return User::create([
            'username' => '2001',
            'name' => '分数测试学生',
            'password' => 'secret123',
            'role' => 'student',
            'student_id' => '2001',
            'is_active' => true,
        ]);
    }

    private function semester(): Semester
    {
        return Semester::create([
            'academic_year' => '2026-2027',
            'term' => 1,
            'name' => '2026-2027学年第一学期',
            'is_current' => true,
        ]);
    }

    public function test_total_score_is_base_plus_add_minus_deduct(): void
    {
        Setting::put('base_score', '40');
        $semester = $this->semester();
        $add = ScoreCategory::create(['name' => '校级活动', 'type' => 'add']);
        $deduct = ScoreCategory::create(['name' => '扣分', 'type' => 'deduct']);
        $student = $this->student();

        ScoreRecord::create([
            'user_id' => $student->id,
            'category_id' => $add->id,
            'semester_id' => $semester->id,
            'score' => 5,
            'source_type' => 'manual',
            'description' => '加分记录',
        ]);

        ScoreRecord::create([
            'user_id' => $student->id,
            'category_id' => $deduct->id,
            'semester_id' => $semester->id,
            'score' => -2,
            'source_type' => 'manual',
            'description' => '扣分记录',
        ]);

        $summary = app(ScoreService::class)->studentSummary($student, [$semester->id]);

        $this->assertSame(40, $summary['base_score']);
        $this->assertSame(5.0, $summary['add_score']);
        $this->assertSame(2.0, $summary['deduct_score']);
        $this->assertSame(43.0, $summary['total_score']);
        $this->assertSame(2, $summary['record_count']);
    }

    public function test_total_score_never_drops_below_zero(): void
    {
        Setting::put('base_score', '0');
        $semester = $this->semester();
        $deduct = ScoreCategory::create(['name' => '扣分', 'type' => 'deduct']);
        $student = $this->student();

        ScoreRecord::create([
            'user_id' => $student->id,
            'category_id' => $deduct->id,
            'semester_id' => $semester->id,
            'score' => -10,
            'source_type' => 'manual',
        ]);

        $summary = app(ScoreService::class)->studentSummary($student, [$semester->id]);

        $this->assertSame(0.0, $summary['total_score']);
    }

    public function test_revoked_records_are_excluded_from_totals(): void
    {
        Setting::put('base_score', '40');
        $semester = $this->semester();
        $deduct = ScoreCategory::create(['name' => '扣分', 'type' => 'deduct']);
        $student = $this->student();

        $record = ScoreRecord::create([
            'user_id' => $student->id,
            'category_id' => $deduct->id,
            'semester_id' => $semester->id,
            'score' => -3,
            'source_type' => 'manual',
            'revoked_at' => now(),
            'revoke_reason' => '申诉通过',
        ]);

        $summary = app(ScoreService::class)->studentSummary($student, [$semester->id]);

        $this->assertSame(0.0, $summary['deduct_score']);
        $this->assertSame(40.0, $summary['total_score']);
        $this->assertTrue($summary['records']->first()['is_revoked']);
        $this->assertSame(1, $summary['revoked_count']);
        $this->assertSame($record->id, $summary['records']->first()['id']);
    }

    public function test_leaderboard_supports_date_range(): void
    {
        Setting::put('base_score', '40');
        $semester = $this->semester();
        $category = ScoreCategory::create(['name' => '志愿服务', 'type' => 'add']);
        $student = $this->student();

        $record = ScoreRecord::create([
            'user_id' => $student->id,
            'category_id' => $category->id,
            'semester_id' => $semester->id,
            'score' => 6,
            'source_type' => 'manual',
        ]);
        $record->forceFill(['created_at' => now()->subDays(10)])->save();

        $service = app(ScoreService::class);

        $week = $service->leaderboard([
            'semester_ids' => [$semester->id],
            'rank_period' => 'week',
            'start' => now()->startOfWeek(),
            'end' => now()->endOfWeek(),
        ]);
        $weekRow = $week->getCollection()->firstWhere('student_id', '2001');
        $this->assertSame(0.0, $weekRow['period_score']);
        $this->assertSame(46.0, $weekRow['total_score']);

        $all = $service->leaderboard([
            'semester_ids' => [$semester->id],
            'rank_period' => 'all',
        ]);
        $allRow = $all->getCollection()->firstWhere('student_id', '2001');
        $this->assertSame(46.0, $allRow['total_score']);
    }
}
