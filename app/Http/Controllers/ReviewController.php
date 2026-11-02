<?php

namespace App\Http\Controllers;

use App\Models\Semester;
use App\Models\Appeal;
use App\Models\GroupApplication;
use App\Models\SchoolClass;
use App\Models\ScoreApplication;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\User;
use App\Services\ExportService;
use App\Services\LeaderboardExportService;
use App\Services\ScoreService;
use App\Support\AccessScope;
use App\Support\PageSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReviewController extends Controller
{
    public function __construct(
        private readonly ScoreService $scores,
        private readonly ExportService $exports,
        private readonly LeaderboardExportService $exportData,
        private readonly AccessScope $access
    ) {
    }

    public function index(Request $request): Response
    {
        $status = $request->input('status', 'pending');
        $semesterId = $request->input('semester_id');
        $categoryId = $request->input('category_id');
        $search = $request->input('search');
        $page = PageSize::resolve($request, 10);

        $user = $request->user();
        $officerMode = $this->isOfficerMode($request);
        if ($officerMode) {
            abort_unless($this->access->isClassOfficer($user), 403, '你尚未被任命为班干部。');
        }

        $personalQuery = ScoreApplication::query();
        $officerMode
            ? $this->access->scopeOfficerApplications($personalQuery, $user)
            : $this->access->scopePersonalApplications($personalQuery, $user);

        $personal = $personalQuery
            ->with(['user:id,name,student_id,class_name,college,grade', 'category:id,name,code,type', 'semester:id,name', 'reviewer:id,name', 'evidences:id,score_application_id,name,sort_order'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($semesterId, fn ($q) => $q->where('semester_id', $semesterId))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($search, function ($q, $value) {
                $q->whereHas('user', function ($sub) use ($value) {
                    $sub->where('name', 'like', "%{$value}%")
                        ->orWhere('student_id', 'like', "%{$value}%");
                });
            })
            ->latest()
            ->paginate($page['limit'], ['*'], 'personal_page')
            ->withQueryString();

        $groupQuery = GroupApplication::query();
        $officerMode
            ? $this->access->scopeOfficerGroups($groupQuery, $user)
            : $this->access->scopeGroupApplications($groupQuery, $user);

        $groups = $groupQuery
            ->with([
                'teacher:id,name,employee_id',
                'initiator:id,name,student_id,class_name',
                'category:id,name,type',
                'semester:id,name',
                'reviewer:id,name',
            ])
            ->withCount('members')
            ->withMin('members as score_min', DB::raw('coalesce(final_score, score)'))
            ->withMax('members as score_max', DB::raw('coalesce(final_score, score)'))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($semesterId, fn ($q) => $q->where('semester_id', $semesterId))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($search, function ($q, $value) {
                $q->where(function ($sub) use ($value) {
                    $sub->where('title', 'like', "%{$value}%")
                        ->orWhereHas('teacher', fn ($t) => $t->where('name', 'like', "%{$value}%"))
                        ->orWhereHas('initiator', fn ($t) => $t->where('name', 'like', "%{$value}%"));
                });
            })
            ->latest()
            ->paginate($page['limit'], ['*'], 'group_page')
            ->withQueryString();

        $manualStatus = (string) $request->input('manual_status', 'all');
        $manualStatus = in_array($manualStatus, ['all', 'active', 'revoked'], true) ? $manualStatus : 'all';

        $manualQuery = ScoreRecord::query()->where('source_type', 'manual');
        $officerMode
            ? $this->access->scopeOfficerScoreRecords($manualQuery, $user)
            : $this->access->scopeScoreRecords($manualQuery, $user);

        $manual = $manualQuery
            ->with([
                'user:id,name,student_id,class_name,college,grade',
                'category:id,name,type',
                'semester:id,name',
                'creator:id,name',
                'revoker:id,name',
            ])
            ->when($manualStatus === 'active', fn ($q) => $q->whereNull('revoked_at'))
            ->when($manualStatus === 'revoked', fn ($q) => $q->whereNotNull('revoked_at'))
            ->when($semesterId, fn ($q) => $q->where('semester_id', $semesterId))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($search, function ($q, $value) {
                $q->whereHas('user', function ($sub) use ($value) {
                    $sub->where('name', 'like', "%{$value}%")
                        ->orWhere('student_id', 'like', "%{$value}%");
                });
            })
            ->orderByDesc('applied_on')
            ->orderByDesc('id')
            ->paginate($page['limit'], ['*'], 'manual_page')
            ->withQueryString();

        return Inertia::render('Review/Index', [
            'officerMode' => $officerMode,
            'officerClasses' => $officerMode ? $this->officerClassNames($user) : [],
            'personal' => $personal,
            'groups' => $groups,
            'manual' => $manual,
            'perPage' => $page['per_page'],
            'semesters' => Semester::orderByDesc('academic_year')->orderBy('term')->get(['id', 'name', 'is_current']),
            'categories' => ScoreCategory::orderBy('sort_order')->get(['id', 'name', 'code', 'type']),
            'counts' => [
                ...$this->counts($user, $officerMode),
                'manual_total' => $manual->total(),
            ],
            'leaderboard' => $this->reviewerLeaderboard($request, $user, $officerMode),
            'deduction' => $this->deductionOptions($user, $officerMode),
            'canModifyApproved' => $officerMode
                ? $this->access->isClassOfficer($user)
                : $user->isManager(),
            'canDeletePersonal' => $officerMode
                ? $this->access->isClassOfficer($user)
                : $user->isManager(),
            'canDeleteGroups' => ! $officerMode && $user->isManager(),
            'canManageManual' => $user->isAdmin(),
            'filters' => [
                'status' => $status,
                'semester_id' => $semesterId,
                'category_id' => $categoryId,
                'search' => $search,
                'manual_status' => $manualStatus,
                'board_class_id' => $request->integer('board_class_id') ?: null,
            ],
        ]);
    }

    private function isOfficerMode(Request $request): bool
    {
        return $request->routeIs('officer.*');
    }

    private function officerClassNames($user): array
    {
        $ids = $this->access->officerClassIds($user);

        return $ids === [] ? [] : SchoolClass::whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
    }

    private function reviewerLeaderboard(Request $request, User $user, bool $officerMode): array
    {
        if (! $officerMode && ! $user->isClassAdmin()) {
            return ['available' => false];
        }

        $classIds = $officerMode
            ? $this->access->officerClassIds($user)
            : ($this->access->classIds($user) ?? []);
        $classIds = array_values(array_unique(array_map('intval', $classIds)));

        if ($classIds === []) {
            return ['available' => false];
        }

        $classes = SchoolClass::query()
            ->whereIn('id', $classIds)
            ->orderBy('grade')
            ->orderBy('name')
            ->get(['id', 'name', 'grade']);

        if ($classes->isEmpty()) {
            return ['available' => false];
        }

        $selectedId = (int) $request->integer('board_class_id');

        if (! in_array($selectedId, $classIds, true)) {
            $selectedId = (int) $classes->first()->id;
        }

        $semester = Semester::current();
        $rows = $this->scores->leaderboard([
            'semester_ids' => $semester ? [$semester->id] : [],
            'rank_period' => 'all',
            'restrict_class_ids' => [$selectedId],
        ], 100000)->items();

        $ranked = [];

        foreach ($rows as $index => $row) {
            $ranked[] = [
                'rank' => $index + 1,
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'student_id' => $row['student_id'],
                'class_name' => $row['class_name'],
                'total_score' => $row['total_score'],
                'add_score' => $row['add_score'],
                'deduct_score' => $row['deduct_score'],
                'is_self' => (int) $row['id'] === (int) $user->id,
            ];
        }

        return [
            'available' => true,
            'classes' => $classes->map(fn (SchoolClass $class) => [
                'id' => $class->id,
                'name' => $class->name,
                'grade' => $class->grade,
            ])->values()->all(),
            'class_id' => $selectedId,
            'semester' => $semester?->name,
            'rows' => $ranked,
            'self_id' => (int) $user->id,
            'export_url' => ($officerMode ? '/officer/review/leaderboard/export' : '/admin/review/leaderboard/export')
                .'?board_class_id='.$selectedId,
        ];
    }

    public function exportReviewerLeaderboard(Request $request): StreamedResponse
    {
        $user = $request->user();
        $officerMode = $this->isOfficerMode($request);

        if ($officerMode) {
            abort_unless($this->access->isClassOfficer($user), 403, '你尚未被任命为班干部。');
        } else {
            abort_unless($user->isClassAdmin(), 403, '只有辅导员可以导出班级排行榜。');
        }

        $classIds = $officerMode
            ? $this->access->officerClassIds($user)
            : ($this->access->classIds($user) ?? []);
        $classIds = array_values(array_unique(array_map('intval', $classIds)));

        abort_if($classIds === [], 403, '没有可导出的班级。');

        $requestedId = $request->integer('board_class_id');

        if ($requestedId && ! in_array($requestedId, $classIds, true)) {
            abort(403, '无权导出该班级的排行榜。');
        }

        $classes = SchoolClass::query()
            ->whereIn('id', $classIds)
            ->orderBy('grade')
            ->orderBy('name')
            ->get(['id', 'name', 'grade']);

        abort_if($classes->isEmpty(), 403, '没有可导出的班级。');

        $selectedId = $requestedId ?: (int) $classes->first()->id;
        $selected = $classes->firstWhere('id', $selectedId);

        $semester = Semester::current();

        abort_if(! $semester, 404, '系统尚未设置当前学期，暂时无法导出排行榜。');

        $filters = [
            'semester_ids' => [$semester->id],
            'rank_period' => 'all',
            'restrict_class_ids' => [$selectedId],
        ];

        $rows = $this->scores->leaderboard($filters, 100000)->items();
        $period = [
            'scope' => 'semester',
            'label' => $semester->name,
            'academic_year' => $semester->academic_year,
            'term' => $semester->term,
        ];

        return $this->exports->leaderboard(
            $rows,
            $semester->name,
            '总排行',
            'all',
            ($selected?->name ?? '班级').' 班级榜',
            $this->exportData->details($rows, $filters),
            $this->exportData->summaryClasses($rows),
            $this->exportData->summaryPeriodTitle($period)
        );
    }

    private function deductionOptions(User $user, bool $officerMode): array
    {
        $canDeduct = $officerMode ? $this->access->isClassOfficer($user) : $user->isManager();

        if (! $canDeduct) {
            return ['available' => false];
        }

        $studentsQuery = User::query()
            ->where('role', 'student')
            ->where('is_active', true);

        if ($officerMode) {
            $studentsQuery
                ->whereIn('class_id', $this->access->officerClassIds($user))
                ->whereKeyNot($user->id);
        } else {
            $this->access->scopeStudents($studentsQuery, $user);
        }

        $students = $studentsQuery
            ->orderBy('class_name')
            ->orderBy('student_id')
            ->get(['id', 'name', 'student_id', 'class_name']);

        $categories = ScoreCategory::query()
            ->where('type', 'deduct')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'description']);

        $semester = Semester::current();

        return [
            'available' => $students->isNotEmpty() && $categories->isNotEmpty() && $semester !== null,
            'students' => $students,
            'categories' => $categories,
            'semester' => $semester?->only(['id', 'name']),
            'today' => now()->toDateString(),
            'store_url' => $officerMode ? '/officer/deductions' : '/admin/deductions',
        ];
    }

    public function showGroup(Request $request, GroupApplication $groupApplication): Response
    {
        $user = $request->user();
        $officerMode = $this->isOfficerMode($request);

        abort_unless(
            $officerMode
                ? $this->access->canViewOfficerGroup($user, $groupApplication)
                : $this->access->canAccessGroup($user, $groupApplication),
            403,
            '无权查看该集体申请。'
        );

        $groupApplication->load([
            'category:id,name,type',
            'semester:id,name',
            'teacher:id,name,employee_id,teacher_college',
            'initiator:id,name,student_id,class_name',
            'reviewer:id,name',
            'evidences:id,group_application_id,name,sort_order',
            'members' => fn ($q) => $q->with('student:id,name,student_id,class_name,college,grade'),
        ]);

        return Inertia::render('Review/GroupShow', [
            'application' => $groupApplication,
            'officerMode' => $officerMode,
            'canDelete' => ! $officerMode && $this->access->canAccessGroup($user, $groupApplication),
        ]);
    }

    public function approvePersonal(Request $request, ScoreApplication $application): RedirectResponse
    {
        abort_unless($this->canReviewPersonal($request, $application), 403, '无权审核该申请。');

        $application->loadMissing('category');
        $isVolunteer = $application->category?->isVolunteer() ?? false;

        $data = $request->validate([
            'final_score' => $isVolunteer
                ? ['nullable', 'numeric']
                : ['required', 'numeric', 'min:0.5', 'max:1000', 'multiple_of:0.5'],
            'review_comment' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'final_score' => '核定分值',
            'review_comment' => '审核意见',
        ]);

        $this->scores->approveApplication(
            $application,
            $request->user(),
            $isVolunteer ? 0 : (float) $data['final_score'],
            $data['review_comment'] ?? null
        );

        return back()->with('success', '审核通过，已生成德育分记录。');
    }

    public function rejectPersonal(Request $request, ScoreApplication $application): RedirectResponse
    {
        abort_unless($this->canReviewPersonal($request, $application), 403, '无权审核该申请。');

        $data = $request->validate([
            'review_comment' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'review_comment' => '驳回原因',
        ]);

        $this->scores->rejectApplication($application, $request->user(), $data['review_comment'] ?? null);

        return back()->with('success', '申请已驳回。');
    }

    public function approveGroup(Request $request, GroupApplication $groupApplication): RedirectResponse
    {
        abort_unless($this->access->canReviewGroup($request->user(), $groupApplication), 403);

        $data = $request->validate([
            'final_scores' => ['nullable', 'array'],
            'final_scores.*' => ['numeric', 'min:0.5', 'max:1000', 'multiple_of:0.5'],
            'review_comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->scores->approveGroup(
            $groupApplication,
            $request->user(),
            $data['final_scores'] ?? [],
            $data['review_comment'] ?? null
        );

        return back()->with('success', '集体申请已通过，成员德育分已生成。');
    }

    public function rejectGroup(Request $request, GroupApplication $groupApplication): RedirectResponse
    {
        abort_unless($this->access->canReviewGroup($request->user(), $groupApplication), 403);

        $data = $request->validate([
            'review_comment' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'review_comment' => '驳回原因',
        ]);

        $this->scores->rejectGroup($groupApplication, $request->user(), $data['review_comment'] ?? null);

        return back()->with('success', '集体申请已驳回。');
    }

    public function cancelPersonalApproval(Request $request, ScoreApplication $application): RedirectResponse
    {
        abort_unless($this->canReviewPersonal($request, $application), 403, '无权取消该申请的通过状态。');

        $data = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:400'],
        ], [], [
            'cancel_reason' => '取消原因',
        ]);

        $this->scores->cancelApplicationApproval(
            $application,
            $request->user(),
            $data['cancel_reason'] ?? null
        );

        return back()->with('success', '已取消通过，相关分值已扣回，申请退回待审核。');
    }

    public function updateApprovedPersonal(Request $request, ScoreApplication $application): RedirectResponse
    {
        abort_unless($this->canReviewPersonal($request, $application), 403, '无权修改该申请。');

        $user = $request->user();

        if ($this->isOfficerMode($request)) {
            abort_unless($this->access->isClassOfficer($user), 403, '你尚未被任命为班干部。');
        } else {
            abort_unless($user->isManager(), 403, '只有辅导员或管理员可以修改已通过的申请。');
        }

        abort_unless($application->status === 'approved', 403, '只有已通过的申请可以修改。');

        $application->loadMissing('category');
        $isVolunteer = $application->category?->isVolunteer() ?? false;

        $data = $request->validate([
            'final_score' => $isVolunteer
                ? ['nullable', 'numeric']
                : ['required', 'numeric', 'min:0.5', 'max:1000', 'multiple_of:0.5'],
            'review_comment' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'final_score' => '核定分值',
            'review_comment' => '审核意见',
        ]);

        $this->scores->updateApprovedApplication(
            $application,
            $user,
            $isVolunteer ? 0 : (float) $data['final_score'],
            $data['review_comment'] ?? null
        );

        return back()->with('success', '已修改核定分值，学生德育分已同步更新。');
    }

    public function cancelGroupApproval(Request $request, GroupApplication $groupApplication): RedirectResponse
    {
        abort_unless($this->access->canReviewGroup($request->user(), $groupApplication), 403);

        $data = $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:400'],
        ], [], [
            'cancel_reason' => '取消原因',
        ]);

        $this->scores->cancelGroupApproval(
            $groupApplication,
            $request->user(),
            $data['cancel_reason'] ?? null
        );

        return back()->with('success', '已取消集体申请通过，成员分值已扣回，申请退回待审核。');
    }

    public function destroyPersonal(Request $request, ScoreApplication $application): RedirectResponse
    {
        $user = $request->user();

        if ($this->isOfficerMode($request)) {
            abort_unless(
                $this->access->canOfficerReviewStudent($user, $application->user),
                403,
                '只能删除本班同学的申请。'
            );
        } else {
            abort_unless($user->isManager(), 403, '无权删除该申请。');
            abort_unless(
                $this->access->canAccessStudent($user, $application->user),
                403,
                '无权删除该申请。'
            );
        }

        $result = $this->scores->deleteApplication($application, $user);

        return redirect()
            ->route($this->isOfficerMode($request) ? 'officer.review.index' : 'review.index')
            ->with(
                'success',
                $result['revoked_records'] > 0
                    ? '申请记录已删除，相关分值已同步扣回。'
                    : '申请记录已删除。'
            );
    }

    public function destroyGroup(Request $request, GroupApplication $groupApplication): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user->isManager() && $this->access->canAccessGroup($user, $groupApplication),
            403,
            '无权删除该集体申请。'
        );

        $result = $this->scores->deleteGroupApplication($groupApplication, $user);

        return redirect()
            ->route('review.index')
            ->with(
                'success',
                $result['revoked_records'] > 0
                    ? '集体申请记录已删除，成员分值已同步扣回。'
                    : '集体申请记录已删除。'
            );
    }

    private function canReviewPersonal(Request $request, ScoreApplication $application): bool
    {
        $user = $request->user();

        if ($this->isOfficerMode($request)) {
            return $this->access->canOfficerReviewStudent($user, $application->user);
        }

        return $this->access->canAccessStudent($user, $application->user);
    }

    private function counts($user, bool $officerMode = false): array
    {
        $personal = ScoreApplication::query();
        $officerMode
            ? $this->access->scopeOfficerApplications($personal, $user)
            : $this->access->scopePersonalApplications($personal, $user);

        $groups = GroupApplication::query();
        $officerMode
            ? $this->access->scopeOfficerGroups($groups, $user)
            : $this->access->scopeGroupApplications($groups, $user);

        $appeals = Appeal::query();
        $this->access->scopeAppeals($appeals, $user);

        return [
            'personal_pending' => (clone $personal)->where('status', 'pending')->count(),
            'group_pending' => (clone $groups)->where('status', 'pending')->count(),
            'approved' => (clone $personal)->where('status', 'approved')->count()
                + (clone $groups)->where('status', 'approved')->count(),
            'rejected' => (clone $personal)->where('status', 'rejected')->count()
                + (clone $groups)->where('status', 'rejected')->count(),
            'pending_appeals' => $officerMode ? 0 : (clone $appeals)->where('status', 'pending')->count(),
        ];
    }

    public function bulk(Request $request): RedirectResponse
    {
        $officerMode = $this->isOfficerMode($request);

        if ($officerMode) {
            abort_unless($this->access->isClassOfficer($request->user()), 403, '你尚未被任命为班干部。');
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(['personal', 'group'])],
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'review_comment' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($officerMode && $data['type'] !== 'personal') {
            abort(403);
        }

        if ($data['action'] === 'reject' && empty($data['review_comment'])) {
            $data['review_comment'] = null;
        }

        if ($data['type'] === 'group' && ! $request->user()->isAdmin() && ! $request->user()->isCollegeAdmin()) {
            abort(403);
        }

        $success = 0;
        $failed = 0;
        $reviewer = $request->user();

        foreach ($data['ids'] as $id) {
            try {
                if ($data['type'] === 'personal') {
                    $application = ScoreApplication::find($id);

                    $allowed = $application
                        && ($officerMode
                            ? $this->access->canOfficerReviewStudent($reviewer, $application->user)
                            : $this->access->canAccessStudent($reviewer, $application->user));

                    if (! $application || $application->status !== 'pending' || ! $allowed) {
                        $failed++;
                        continue;
                    }

                    if ($data['action'] === 'approve') {
                        $this->scores->approveApplication(
                            $application,
                            $reviewer,
                            (float) $application->score,
                            $data['review_comment'] ?? null
                        );
                    } else {
                        $this->scores->rejectApplication($application, $reviewer, $data['review_comment']);
                    }
                } else {
                    $application = GroupApplication::find($id);

                    if (! $application || $application->status !== 'pending' || ! $this->access->canReviewGroup($reviewer, $application)) {
                        $failed++;
                        continue;
                    }

                    if ($data['action'] === 'approve') {
                        $this->scores->approveGroup(
                            $application,
                            $reviewer,
                            [],
                            $data['review_comment'] ?? null
                        );
                    } else {
                        $this->scores->rejectGroup($application, $reviewer, $data['review_comment']);
                    }
                }

                $success++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $message = "批量处理完成：成功 {$success} 条";
        if ($failed > 0) {
            $message .= "，失败 {$failed} 条";
        }

        return back()->with($failed > 0 ? 'error' : 'success', $message);
    }
}
