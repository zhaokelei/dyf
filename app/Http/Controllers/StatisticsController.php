<?php

namespace App\Http\Controllers;

use App\Models\GroupApplication;
use App\Models\ScoreApplication;
use App\Models\ScoreRecord;
use App\Services\ScoreService;
use App\Support\AccessScope;
use App\Support\PeriodResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    public function __construct(
        private readonly ScoreService $scores,
        private readonly AccessScope $access
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $period = PeriodResolver::resolve($request);
        $semesterIds = $period['semester_ids'];
        $studentIds = $this->access->studentIds($user);

        $categoryStats = ScoreRecord::query()
            ->selectRaw('category_id, COUNT(*) as record_count, SUM(score) as total_score')
            ->with('category:id,name,type')
            ->whereNull('revoked_at')
            ->when($studentIds !== null, fn ($q) => $q->whereIn('user_id', $studentIds))
            ->when($semesterIds, fn ($q) => $q->whereIn('semester_id', $semesterIds), fn ($q) => $q->whereRaw('1 = 0'))
            ->groupBy('category_id')
            ->get()
            ->map(fn (ScoreRecord $row) => [
                'name' => $row->category?->name ?? '其他',
                'type' => $row->category?->type ?? 'add',
                'count' => (int) $row->record_count,
                'score' => (float) $row->total_score,
            ]);

        $collegeStats = ScoreRecord::query()
            ->join('users', 'users.id', '=', 'score_records.user_id')
            ->where('users.role', 'student')
            ->whereNull('revoked_at')
            ->when($studentIds !== null, fn ($q) => $q->whereIn('score_records.user_id', $studentIds))
            ->when($semesterIds, fn ($q) => $q->whereIn('score_records.semester_id', $semesterIds), fn ($q) => $q->whereRaw('1 = 0'))
            ->selectRaw('users.college as name, COUNT(DISTINCT users.id) as students, SUM(score_records.score) as total_score')
            ->groupBy('users.college')
            ->orderByDesc('total_score')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name ?: '未填写',
                'students' => (int) $row->students,
                'score' => (float) $row->total_score,
            ]);

        $monthly = ScoreApplication::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total")
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->when($studentIds !== null, fn ($q) => $q->whereIn('user_id', $studentIds))
            ->when($semesterIds, fn ($q) => $q->whereIn('semester_id', $semesterIds), fn ($q) => $q->whereRaw('1 = 0'))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $overview = $this->scores->overview($semesterIds, $studentIds);
        $personalScope = ScoreApplication::query();
        $this->access->scopePersonalApplications($personalScope, $user);
        $groupScope = GroupApplication::query();
        $this->access->scopeGroupApplications($groupScope, $user);
        $overview['pending_applications'] = (clone $personalScope)->where('status', 'pending')->count()
            + (clone $groupScope)->where('status', 'pending')->count();
        $overview['approved_applications'] = (clone $personalScope)->where('status', 'approved')->count()
            + (clone $groupScope)->where('status', 'approved')->count();

        return Inertia::render('Statistics/Index', [
            'overview' => $overview,
            'categoryStats' => $categoryStats,
            'collegeStats' => $collegeStats,
            'monthlyTrend' => $monthly,
            'topStudents' => $this->scores->leaderboard(array_merge([
                'semester_ids' => $semesterIds,
                'rank_period' => 'all',
            ], $this->access->restriction($user)), 10)->items(),
            'period' => $period,
        ]);
    }
}
