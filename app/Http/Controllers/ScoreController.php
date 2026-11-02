<?php

namespace App\Http\Controllers;

use App\Models\College;
use App\Models\GroupApplication;
use App\Models\Major;
use App\Models\SchoolClass;
use App\Models\ScoreApplication;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\User;
use App\Services\ExportService;
use App\Services\LeaderboardExportService;
use App\Services\ScoreService;
use App\Support\AccessScope;
use App\Support\PageSize;
use App\Support\PeriodResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScoreController extends Controller
{
    public function __construct(
        private readonly ScoreService $scores,
        private readonly ExportService $exports,
        private readonly LeaderboardExportService $exportData,
        private readonly AccessScope $access
    ) {
    }

    public function my(Request $request): Response
    {
        $period = PeriodResolver::resolve($request);

        return Inertia::render('Scores/My', [
            'summary' => $this->scores->studentSummary($request->user(), $period['semester_ids']),
            'period' => $period,
        ]);
    }

    public function leaderboard(Request $request): Response
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'scope' => ['nullable', 'in:all,college,grade,class'],
            'college_id' => ['nullable', 'integer'],
            'major_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'in:10,20,50,100,all'],
        ]);

        $period = PeriodResolver::resolve($request);
        $ranking = PeriodResolver::ranking($request);
        $perPageInput = (string) $request->input('per_page', '20');
        $perPage = $perPageInput === 'all' ? 100000 : (int) $perPageInput;
        $filters = array_merge(
            $this->leaderboardFilters($period, $ranking, $request),
            $request->user()->isManager() ? $this->access->restriction($request->user()) : []
        );

        return Inertia::render('Leaderboard/Index', [
            'leaderboard' => $this->scores->leaderboard($filters, $perPage),
            'period' => $period,
            'ranking' => $this->rankingPayload($ranking),
            'filterOptions' => $this->filterOptions($request),
            'filters' => [
                'scope' => $filters['scope'],
                'college_id' => $filters['college_id'],
                'major_id' => $filters['major_id'],
                'grade' => $filters['grade'],
                'class_id' => $filters['class_id'],
                'search' => $filters['search'],
                'per_page' => $perPageInput,
            ],
            'scopeLabel' => $this->scopeLabel($filters),
        ]);
    }

    public function exportLeaderboard(Request $request): StreamedResponse
    {
        $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'scope' => ['nullable', 'in:all,college,grade,class'],
            'college_id' => ['nullable', 'integer'],
            'major_id' => ['nullable', 'integer'],
            'class_id' => ['nullable', 'integer'],
        ]);

        $period = PeriodResolver::resolve($request);
        $ranking = PeriodResolver::ranking($request);
        $filters = array_merge(
            $this->leaderboardFilters($period, $ranking, $request),
            $request->user()->isManager() ? $this->access->restriction($request->user()) : []
        );
        $rows = $this->scores->leaderboard($filters, 100000)->items();

        return $this->exports->leaderboard(
            $rows,
            $period['label'],
            $ranking['label'],
            $ranking['rank_period'],
            $this->scopeLabel($filters),
            $this->exportData->details($rows, $filters),
            $this->exportData->summaryClasses($rows),
            $this->exportData->summaryPeriodTitle($period)
        );
    }

    public function records(Request $request): Response
    {
        $page = PageSize::resolve($request, 20);

        $records = ScoreRecord::query()
            ->tap(fn ($q) => $this->access->scopeScoreRecords($q, $request->user()))
            ->with([
                'user:id,name,student_id,class_name',
                'category:id,name,type',
                'semester:id,name',
                'creator:id,name',
                'revoker:id,name',
            ])
            ->when($request->input('category_id'), fn ($q, $value) => $q->where('category_id', $value))
            ->when($request->input('semester_id'), fn ($q, $value) => $q->where('semester_id', $value))
            ->when($request->input('source_type'), fn ($q, $value) => $q->where('source_type', $value))
            ->when($request->input('search'), function ($q, $value) {
                $q->whereHas('user', function ($sub) use ($value) {
                    $sub->where('name', 'like', "%{$value}%")
                        ->orWhere('student_id', 'like', "%{$value}%");
                });
            })
            ->orderByDesc('applied_on')
            ->orderByDesc('id')
            ->paginate($page['limit'])
            ->withQueryString();

        return Inertia::render('Records/Index', [
            'records' => $records,
            'perPage' => $page['per_page'],
            'canManageManual' => $request->user()->isAdmin(),
            'categories' => ScoreCategory::orderBy('sort_order')->get(['id', 'name', 'type']),
            'semesters' => Semester::orderByDesc('academic_year')->orderBy('term')->get(['id', 'name', 'is_current']),
            'students' => $this->scopeStudents(User::query(), $request->user())
                ->where('is_active', true)
                ->orderBy('student_id')
                ->get(['id', 'name', 'student_id', 'class_name']),
            'filters' => $request->only(['category_id', 'semester_id', 'source_type', 'search']),
        ]);
    }

    public function storeRecord(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403, '只有系统管理员可以手工录入成绩。');

        $data = $this->validatedRecord($request);
        $student = User::findOrFail($data['user_id']);
        abort_unless($this->access->canAccessStudent($request->user(), $student), 403);
        $category = ScoreCategory::findOrFail($data['category_id']);
        $signed = $category->isDeduct() ? -abs($data['score']) : abs($data['score']);

        $record = ScoreRecord::create([
            'user_id' => $data['user_id'],
            'category_id' => $category->id,
            'semester_id' => $data['semester_id'],
            'score' => $signed,
            'applied_on' => $data['applied_on'],
            'source_type' => 'manual',
            'description' => $data['description'],
            'created_by' => $request->user()->id,
        ]);

        $this->scores->audit($request->user(), 'create_score_record', $record, [
            'score' => $signed,
        ]);

        return back()->with('success', '成绩已录入。');
    }

    public function storeDeduction(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $officerMode = $request->routeIs('officer.*');

        if ($officerMode) {
            abort_unless($this->access->isClassOfficer($actor), 403, '你尚未被任命为班干部。');
        } else {
            abort_unless($actor->isManager(), 403, '只有辅导员或管理员可以使用该扣分入口。');
        }

        $semester = Semester::current();

        if (! $semester) {
            return back()->with('error', '系统尚未设置当前学期，暂时无法扣分。');
        }

        $data = $request->validate([
            'student_id' => ['required', 'integer'],
            'category_id' => ['required', 'integer'],
            'score' => ['required', 'numeric', 'min:0.5', 'max:1000', 'multiple_of:0.5'],
            'applied_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:500'],
        ], [], [
            'student_id' => '学生',
            'category_id' => '扣分类别',
            'score' => '扣分分值',
            'applied_on' => '扣分日期',
            'description' => '扣分原因',
        ]);

        $student = User::query()
            ->whereKey($data['student_id'])
            ->where('role', 'student')
            ->where('is_active', true)
            ->first();

        if (! $student) {
            throw ValidationException::withMessages([
                'student_id' => '请选择有效的在读学生。',
            ]);
        }

        $allowed = $officerMode
            ? $this->access->canOfficerReviewStudent($actor, $student)
            : $this->access->canAccessStudent($actor, $student);

        abort_unless($allowed, 403, '无权为该学生扣分。');

        $category = ScoreCategory::query()
            ->whereKey($data['category_id'])
            ->where('type', 'deduct')
            ->where('is_active', true)
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => '请选择有效的扣分类别。',
            ]);
        }

        $score = -abs((float) $data['score']);

        $record = ScoreRecord::create([
            'user_id' => $student->id,
            'category_id' => $category->id,
            'semester_id' => $semester->id,
            'score' => $score,
            'applied_on' => $data['applied_on'],
            'source_type' => 'manual',
            'description' => $data['description'],
            'created_by' => $actor->id,
        ]);

        $this->scores->audit($actor, 'create_deduction', $record, [
            'student_id' => $student->id,
            'student_id_number' => $student->student_id,
            'category' => $category->name,
            'score' => $score,
        ]);

        return back()->with('success', "已为 {$student->name} 扣减 ".abs($score).' 分。');
    }

    public function updateRecord(Request $request, ScoreRecord $record): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403, '只有系统管理员可以修改手工录入成绩。');

        abort_unless($this->access->canAccessStudent($request->user(), $record->user), 403);
        if ($record->isRevoked()) {
            return back()->with('error', '已撤销的成绩记录不能修改。');
        }

        if ($record->source_type !== 'manual') {
            return back()->with('error', '申请审核生成的成绩不能直接修改，请撤销后手工补录。');
        }

        $data = $this->validatedRecord($request);
        $student = User::findOrFail($data['user_id']);
        abort_unless($this->access->canAccessStudent($request->user(), $student), 403);

        $category = ScoreCategory::findOrFail($data['category_id']);
        $signed = $category->isDeduct() ? -abs($data['score']) : abs($data['score']);

        $record->update([
            'user_id' => $data['user_id'],
            'category_id' => $category->id,
            'semester_id' => $data['semester_id'],
            'score' => $signed,
            'applied_on' => $data['applied_on'],
            'description' => $data['description'],
        ]);

        $this->scores->audit($request->user(), 'update_score_record', $record, [
            'score' => $signed,
        ]);

        return back()->with('success', '成绩记录已更新。');
    }

    public function destroyRecord(Request $request, ScoreRecord $record): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($this->access->canAccessStudent($actor, $record->user), 403);

        if ($record->isRevoked()) {
            return back()->with('error', '该成绩记录已撤销。');
        }

        if ($record->source_type === 'personal') {
            $application = ScoreApplication::find($record->source_id);

            if (! $application) {
                return back()->with('error', '未找到对应个人申请，无法撤销。');
            }

            abort_unless($this->access->canAccessStudent($actor, $application->user), 403);

            try {
                $this->scores->cancelApplicationApproval($application, $actor, '成绩管理撤销');
            } catch (ValidationException $exception) {
                return back()->with('error', $exception->validator->errors()->first());
            }

            return back()->with('success', '已取消该申请通过状态并扣回分值，申请退回待审核。');
        }

        if ($record->source_type === 'group') {
            $application = GroupApplication::find($record->source_id);

            if (! $application) {
                return back()->with('error', '未找到对应集体申请，无法撤销。');
            }

            abort_unless($this->access->canAccessGroup($actor, $application), 403, '无权撤销该集体申请的成绩。');

            try {
                $this->scores->cancelGroupApproval($application, $actor, '成绩管理撤销');
            } catch (ValidationException $exception) {
                return back()->with('error', $exception->validator->errors()->first());
            }

            return back()->with('success', '已取消该集体申请通过状态并扣回全部成员分值，申请退回待审核。');
        }

        $revoked = DB::transaction(function () use ($request, $record) {
            $locked = ScoreRecord::lockForUpdate()->find($record->id);

            if (! $locked || $locked->isRevoked()) {
                return false;
            }

            $locked->update([
                'revoked_at' => now(),
                'revoked_by' => $request->user()->id,
                'revoke_reason' => '管理员撤销',
            ]);

            $this->scores->audit($request->user(), 'delete_score_record', $locked, [
                'user_id' => $locked->user_id,
                'score' => $locked->score,
            ]);

            return true;
        });

        if (! $revoked) {
            return back()->with('error', '该成绩记录已撤销。');
        }

        return back()->with('success', '成绩记录已撤销。');
    }

    public function forceDestroyRecord(Request $request, ScoreRecord $record): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403, '只有系统管理员可以删除手工录入记录。');
        abort_unless($record->source_type === 'manual', 403, '只有手工录入记录可以永久删除。');
        abort_unless($this->access->canAccessStudent($request->user(), $record->user), 403);

        $deleted = DB::transaction(function () use ($request, $record) {
            $locked = ScoreRecord::lockForUpdate()->find($record->id);

            if (! $locked || $locked->source_type !== 'manual') {
                return false;
            }

            $this->scores->audit($request->user(), 'force_delete_score_record', $locked, [
                'user_id' => $locked->user_id,
                'category_id' => $locked->category_id,
                'semester_id' => $locked->semester_id,
                'score' => $locked->score,
                'applied_on' => $locked->applied_on?->toDateString(),
                'description' => $locked->description,
                'revoked_at' => $locked->revoked_at?->toDateTimeString(),
            ]);

            $locked->delete();

            return true;
        });

        if (! $deleted) {
            return back()->with('error', '该记录不存在或不能永久删除。');
        }

        return back()->with('success', '手工录入记录已永久删除，所有端将不再显示。');
    }

    public function studentDetail(Request $request, User $student): Response
    {
        abort_unless($student->role === 'student', 404);
        abort_unless($this->access->canAccessStudent($request->user(), $student), 403);
        $period = PeriodResolver::resolve($request);

        return Inertia::render('Students/Show', [
            'student' => $student->only(['id', 'name', 'student_id', 'class_name', 'college', 'grade']),
            'summary' => $this->scores->studentSummary($student, $period['semester_ids']),
            'period' => $period,
        ]);
    }

    private function leaderboardFilters(array $period, array $ranking, Request $request): array
    {
        $scope = (string) $request->input('scope', 'all');
        $scope = in_array($scope, ['all', 'college', 'grade', 'class'], true) ? $scope : 'all';

        return [
            'semester_ids' => $period['semester_ids'],
            'rank_period' => $ranking['rank_period'],
            'start' => $ranking['start'],
            'end' => $ranking['end'],
            'start_date' => $ranking['start_date'],
            'end_date' => $ranking['end_date'],
            'scope' => $scope,
            'college_id' => $scope !== 'all' ? ($request->integer('college_id') ?: null) : null,
            'major_id' => $request->integer('major_id') ?: null,
            'grade' => $scope === 'grade' ? ($request->input('grade') ?: null) : null,
            'class_id' => $scope === 'class' ? ($request->integer('class_id') ?: null) : null,
            'search' => $request->input('search'),
            'per_page' => (string) $request->input('per_page', '20'),
        ];
    }

    private function scopeLabel(array $filters): string
    {
        if (($filters['scope'] ?? 'all') === 'all') {
            return '全校总榜';
        }

        if ($filters['scope'] === 'college') {
            $college = $filters['college_id'] ? College::find($filters['college_id']) : null;

            return $college ? $college->name.' 学院榜' : '全部学院榜';
        }

        if ($filters['scope'] === 'grade') {
            return ($filters['grade'] ?: '全部年级').' 年级榜';
        }

        $class = $filters['class_id'] ? SchoolClass::find($filters['class_id']) : null;

        return $class ? $class->name.' 班级榜' : '全部班级榜';
    }

    private function rankingPayload(array $ranking): array
    {
        return [
            'rank_period' => $ranking['rank_period'],
            'label' => $ranking['label'],
            'start_date' => $ranking['start_date'],
            'end_date' => $ranking['end_date'],
        ];
    }

    private function filterOptions(Request $request): array
    {
        $base = User::query()->where('role', 'student');

        if ($request->user()->isManager()) {
            $base = $this->access->scopeStudents($base, $request->user());
        }

        $collegeIds = (clone $base)->whereNotNull('college_id')->distinct()->pluck('college_id');
        $majorIds = (clone $base)->whereNotNull('major_id')->distinct()->pluck('major_id');
        $classIds = (clone $base)->whereNotNull('class_id')->distinct()->pluck('class_id');

        return [
            'colleges' => College::whereIn('id', $collegeIds)->orderBy('name')->get(['id', 'name']),
            'majors' => Major::whereIn('id', $majorIds)->orderBy('name')->get(['id', 'name', 'college_id']),
            'grades' => (clone $base)->whereNotNull('grade')->distinct()->orderBy('grade')->pluck('grade'),
            'classes' => SchoolClass::whereIn('id', $classIds)
                ->with('major:id,name')
                ->orderBy('grade')
                ->orderBy('name')
                ->get(['id', 'name', 'grade', 'college_id', 'major_id']),
        ];
    }

    private function scopeStudents($query, User $user)
    {
        return $this->access->scopeStudents($query, $user);
    }

    private function validatedRecord(Request $request): array
    {
        return $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('role', 'student')],
            'category_id' => ['required', Rule::exists('score_categories', 'id')],
            'semester_id' => ['required', Rule::exists('semesters', 'id')],
            'score' => ['required', 'numeric', 'min:0.5', 'max:1000', 'multiple_of:0.5'],
            'applied_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:500'],
        ], [], [
            'user_id' => '学生',
            'category_id' => '类别',
            'semester_id' => '学期',
            'score' => '分值',
            'applied_on' => '上报日期',
            'description' => '说明',
        ]);
    }
}
