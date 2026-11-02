<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Appeal;
use App\Models\GroupApplication;
use App\Models\ScoreApplication;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Services\ScoreService;
use App\Support\AccessScope;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ScoreService $scores,
        private readonly AccessScope $access
    ) {
    }

    private function scopeLabel($user): string
    {
        if ($user->isManager() && $user->isAdmin()) {
            return '全校';
        }

        if ($user->isCollegeAdmin()) {
            return \App\Models\College::whereIn('id', $this->access->collegeIds($user) ?? [])
                ->pluck('name')
                ->implode('、') ?: '未授权学院';
        }

        if ($user->isClassAdmin()) {
            $grades = collect($this->access->gradeScopes($user))->pluck('label')->filter()->values();

            if ($grades->isNotEmpty()) {
                return $grades->implode('、');
            }

            return \App\Models\SchoolClass::whereIn('id', $this->access->classIds($user) ?? [])
                ->pluck('name')
                ->implode('、') ?: '未授权班级';
        }

        return '全校';
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $currentSemester = Semester::current();
        $semesterIds = $currentSemester ? [$currentSemester->id] : [];

        $announcements = Announcement::query()
            ->with('author:id,name')
            ->orderByDesc('is_important')
            ->orderByDesc('published_at')
            ->limit(4)
            ->get()
            ->map(fn (Announcement $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'content' => HtmlSanitizer::toHtml($item->content),
                'is_important' => $item->is_important,
                'is_popup' => $item->is_popup,
                'author' => $item->author?->name,
                'published_at' => $item->published_at?->toIso8601String(),
            ]);

        if ($user->isStudent()) {
            $popupAnnouncement = Announcement::query()
                ->with('author:id,name')
                ->where('is_popup', true)
                ->whereNotNull('published_at')
                ->orderByDesc('published_at')
                ->first();

            $officerClasses = $user->officerClasses()->get(['classes.id', 'classes.name']);
            $officerClassIds = $officerClasses->pluck('id')->map(fn ($id) => (int) $id)->all();
            $classmates = $officerClassIds === [] ? [] : \App\Models\User::query()
                ->where('role', 'student')
                ->whereIn('class_id', $officerClassIds)
                ->whereKeyNot($user->id)
                ->pluck('id')
                ->all();

            return Inertia::render('Dashboard', [
                'mode' => 'student',
                'currentSemester' => $currentSemester?->only(['id', 'name']),
                'summary' => $this->scores->studentSummary($user, $semesterIds),
                'recentApplications' => $user->applications()
                    ->with(['category:id,name,type', 'semester:id,name'])
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get(),
                'classInfo' => [
                    'college' => $user->college ?? $user->college()->value('name'),
                    'major' => $user->major?->name,
                    'class_name' => $user->class_name ?? $user->schoolClass?->name,
                    'grade' => $user->grade ?? $user->schoolClass?->grade,
                    'is_officer' => $officerClassIds !== [],
                    'officer_titles' => $officerClasses->map(fn ($class) => [
                        'class_name' => $class->name,
                        'title' => $class->pivot->title ?? '班干部',
                    ])->values(),
                    'officer_pending' => $classmates === [] ? 0 : ScoreApplication::whereIn('user_id', $classmates)
                        ->where('status', 'pending')
                        ->count(),
                ],
                'popupAnnouncement' => $popupAnnouncement ? [
                    'id' => $popupAnnouncement->id,
                    'title' => $popupAnnouncement->title,
                    'content' => HtmlSanitizer::toHtml($popupAnnouncement->content),
                    'is_important' => $popupAnnouncement->is_important,
                    'author' => $popupAnnouncement->author?->name,
                    'published_at' => $popupAnnouncement->published_at?->toIso8601String(),
                ] : null,
                'announcements' => $announcements,
            ]);
        }

        if ($user->isTeacher()) {
            $applications = $user->groupApplications();

            return Inertia::render('Dashboard', [
                'mode' => 'teacher',
                'currentSemester' => $currentSemester?->only(['id', 'name']),
                'stats' => [
                    'total' => (clone $applications)->count(),
                    'pending' => (clone $applications)->where('status', 'pending')->count(),
                    'approved' => (clone $applications)->where('status', 'approved')->count(),
                    'rejected' => (clone $applications)->where('status', 'rejected')->count(),
                    'members' => ScoreRecord::where('source_type', 'group')
                        ->whereIn('source_id', (clone $applications)->where('status', 'approved')->pluck('id'))
                        ->count(),
                ],
                'recentApplications' => $applications
                    ->with(['category:id,name,type', 'semester:id,name'])
                    ->withCount('members')
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get(),
                'announcements' => $announcements,
            ]);
        }

        $studentIds = $this->access->studentIds($user);
        $overview = $this->scores->overview($semesterIds, $studentIds);

        $personalScope = ScoreApplication::query();
        $this->access->scopePersonalApplications($personalScope, $user);
        $groupScope = GroupApplication::query();
        $this->access->scopeGroupApplications($groupScope, $user);
        $overview['pending_applications'] = (clone $personalScope)->where('status', 'pending')->count()
            + (clone $groupScope)->where('status', 'pending')->count();
        $overview['approved_applications'] = (clone $personalScope)->where('status', 'approved')->count()
            + (clone $groupScope)->where('status', 'approved')->count();

        $categoryStats = ScoreRecord::query()
            ->selectRaw('category_id, COUNT(*) as record_count, SUM(score) as total_score')
            ->with('category:id,name,type')
            ->whereNull('revoked_at')
            ->when($studentIds !== null, fn ($q) => $q->whereIn('user_id', $studentIds))
            ->when($semesterIds, fn ($q) => $q->whereIn('semester_id', $semesterIds))
            ->groupBy('category_id')
            ->get()
            ->map(fn (ScoreRecord $row) => [
                'name' => $row->category?->name ?? '其他',
                'type' => $row->category?->type ?? 'add',
                'count' => (int) $row->record_count,
                'score' => (float) $row->total_score,
            ]);

        $monthly = ScoreApplication::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total")
            ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->when($studentIds !== null, fn ($q) => $q->whereIn('user_id', $studentIds))
            ->when($semesterIds, fn ($q) => $q->whereIn('semester_id', $semesterIds))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return Inertia::render('Dashboard', [
            'mode' => 'admin',
            'currentSemester' => $currentSemester?->only(['id', 'name']),
            'overview' => $overview,
            'categoryStats' => $categoryStats,
            'monthlyTrend' => $monthly,
            'pendingApplications' => ScoreApplication::query()
                ->tap(fn ($q) => $this->access->scopePersonalApplications($q, $user))
                ->with(['user:id,name,student_id,class_name', 'category:id,name', 'semester:id,name'])
                ->where('status', 'pending')
                ->latest()
                ->limit(5)
                ->get(),
            'pendingGroups' => GroupApplication::query()
                ->tap(fn ($q) => $this->access->scopeGroupApplications($q, $user))
                ->with(['teacher:id,name', 'category:id,name', 'semester:id,name'])
                ->withCount('members')
                ->where('status', 'pending')
                ->latest()
                ->limit(5)
                ->get(),
            'pendingAppeals' => Appeal::query()
                ->tap(fn ($q) => $this->access->scopeAppeals($q, $user))
                ->with([
                    'user:id,name,student_id,class_name',
                    'scoreRecord:id,score,description,semester_id',
                    'scoreRecord.category:id,name',
                ])
                ->where('status', 'pending')
                ->latest()
                ->limit(5)
                ->get(),
            'topStudents' => $this->scores->leaderboard(array_merge([
                'semester_ids' => $semesterIds,
                'rank_period' => 'all',
            ], $this->access->restriction($user)), 5)->items(),
            'scopeLabel' => $this->scopeLabel($user),
            'announcements' => $announcements,
        ]);
    }
}
