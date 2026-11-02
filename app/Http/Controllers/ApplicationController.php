<?php

namespace App\Http\Controllers;

use App\Models\GroupApplicationMember;
use App\Models\ScoreApplication;
use App\Models\ScoreApplicationEvidence;
use App\Models\ScoreCategory;
use App\Models\Semester;
use App\Models\User;
use App\Services\ExportService;
use App\Services\ScoreService;
use App\Support\AccessScope;
use App\Support\PageSize;
use App\Support\PeriodResolver;
use App\Support\VolunteerRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicationController extends Controller
{
    public function __construct(
        private readonly ScoreService $scores,
        private readonly ExportService $exports,
        private readonly AccessScope $access
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $page = PageSize::resolve($request, 10);

        $applications = $user->applications()
            ->with(['category:id,name,type', 'semester:id,name', 'reviewer:id,name', 'evidences:id,score_application_id,name,sort_order'])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('semester_id'), fn ($q, $semester) => $q->where('semester_id', $semester))
            ->latest()
            ->paginate($page['limit'])
            ->withQueryString();

        $groupRecords = GroupApplicationMember::query()
            ->where('student_id', $user->id)
            ->with([
                'groupApplication:id,teacher_id,origin,initiator_id,category_id,title,description,status,semester_id,review_comment,reviewed_at,applied_on,created_at',
                'groupApplication.category:id,name,type',
                'groupApplication.teacher:id,name',
                'groupApplication.initiator:id,name,student_id',
                'groupApplication.semester:id,name',
            ])
            ->latest()
            ->paginate($page['limit'], ['*'], 'group_page')
            ->withQueryString();

        $manualRecords = $user->scoreRecords()
            ->where('source_type', 'manual')
            ->with(['category:id,name,type', 'semester:id,name', 'creator:id,name', 'revoker:id,name'])
            ->when($request->input('semester_id'), fn ($q, $semester) => $q->where('semester_id', $semester))
            ->orderByDesc('applied_on')
            ->orderByDesc('id')
            ->paginate($page['limit'], ['*'], 'manual_page')
            ->withQueryString();

        return Inertia::render('Applications/Index', [
            'applications' => $applications,
            'groupRecords' => $groupRecords,
            'manualRecords' => $manualRecords,
            'perPage' => $page['per_page'],
            'semesters' => Semester::orderByDesc('academic_year')->orderBy('term')->get(['id', 'name', 'is_current']),
            'filters' => [
                'status' => $request->input('status'),
                'semester_id' => $request->input('semester_id'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $semester = Semester::current();

        return Inertia::render('Applications/Create', [
            'categories' => ScoreCategory::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'code', 'type', 'description', 'default_score', 'max_score']),
            'currentSemester' => $semester?->only(['id', 'name', 'academic_year', 'term']),
            'selectedCategory' => $request->integer('category') ?: null,
            'volunteer' => VolunteerRule::progress($request->user()->id, $semester?->id ?? 0),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $semester = Semester::current();

        if (! $semester) {
            throw ValidationException::withMessages([
                'category_id' => '系统尚未设置当前学期，请联系管理员。',
            ]);
        }

        $request->validate([
            'category_id' => ['required', 'integer'],
        ], [], [
            'category_id' => '类别',
        ]);

        $category = ScoreCategory::query()
            ->whereKey($request->integer('category_id'))
            ->where('is_active', true)
            ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => '所选类别不可用，请刷新页面后重试。',
            ]);
        }

        $isVolunteer = $category->isVolunteer();

        if ($isVolunteer) {
            if (VolunteerRule::limitReached($request->user()->id, $semester->id)) {
                throw ValidationException::withMessages([
                    'category_id' => '本学期志愿服务已通过 4 次，已达上限，无法继续申请。',
                ]);
            }

            $request->merge(['score' => 0]);
        }

        $data = $request->validate([
            'activity_name' => ['required', 'string', 'max:200'],
            'applied_on' => ['required', 'date', 'before_or_equal:today'],
            'score' => $isVolunteer
                ? ['numeric']
                : ['required', 'numeric', 'min:0.5', 'max:1000', 'multiple_of:0.5'],
            'description' => ['required', 'string', 'max:500'],
            'evidence' => ['required', 'array', 'min:1', 'max:3'],
            'evidence.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], [], [
            'activity_name' => '活动名称',
            'applied_on' => '申请日期',
            'score' => '分值',
            'description' => '申请说明',
            'evidence' => '证明材料',
            'evidence.*' => '证明材料',
        ]);

        if ($isVolunteer) {
            $data['score'] = 0;
        }

        if ($category->max_score && $data['score'] > $category->max_score) {
            throw ValidationException::withMessages([
                'score' => "该类别单次分值不能超过 {$category->max_score} 分。",
            ]);
        }

        $stored = [];

        foreach ($request->file('evidence') as $index => $file) {
            $stored[] = [
                'path' => $file->store('evidence', 'local'),
                'name' => $file->getClientOriginalName(),
                'sort_order' => $index,
            ];
        }

        $first = $stored[0];

        try {
            $application = DB::transaction(function () use ($request, $category, $isVolunteer, $semester, $data, $stored, $first) {
                if ($isVolunteer) {
                    User::query()->whereKey($request->user()->id)->lockForUpdate()->first();

                    if (VolunteerRule::limitReached($request->user()->id, $semester->id)) {
                        throw ValidationException::withMessages([
                            'category_id' => '本学期志愿服务已通过 4 次，已达上限，无法继续申请。',
                        ]);
                    }
                }

                $application = ScoreApplication::create([
                    'user_id' => $request->user()->id,
                    'category_id' => $category->id,
                    'semester_id' => $semester->id,
                    'activity_name' => $data['activity_name'],
                    'applied_on' => $data['applied_on'],
                    'description' => $data['description'],
                    'score' => $data['score'],
                    'status' => 'pending',
                    'evidence_path' => $first['path'],
                    'evidence_name' => $first['name'],
                ]);

                $application->evidences()->createMany($stored);

                return $application;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $file) {
                Storage::disk('local')->delete($file['path']);
            }

            throw $e;
        }

        $this->scores->audit($request->user(), 'submit_application', $application, [
            'score' => $data['score'],
        ]);

        return redirect()
            ->route('applications.index')
            ->with('success', '申请已提交，等待管理员审核。');
    }

    public function edit(Request $request, ScoreApplication $application): Response
    {
        $this->ensureStudentCanEdit($request, $application);

        $application->load([
            'category:id,name,type,code',
            'semester:id,name',
            'evidences:id,score_application_id,name,sort_order',
        ]);

        return Inertia::render('Applications/Edit', [
            'application' => $application,
            'today' => now()->toDateString(),
        ]);
    }

    public function update(Request $request, ScoreApplication $application): RedirectResponse
    {
        $this->ensureStudentCanEdit($request, $application);

        $data = $request->validate([
            'activity_name' => ['required', 'string', 'max:200'],
            'applied_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:500'],
            'keep_evidence' => ['nullable', 'array', 'max:3'],
            'keep_evidence.*' => ['integer', 'distinct'],
            'evidence' => ['nullable', 'array', 'max:3'],
            'evidence.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], [], [
            'activity_name' => '活动名称',
            'applied_on' => '申请日期',
            'description' => '申请说明',
            'keep_evidence' => '已有证明材料',
            'evidence' => '证明材料',
            'evidence.*' => '证明材料',
        ]);

        $existing = $application->evidences()->get();
        $keepIds = collect($data['keep_evidence'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $kept = $existing->whereIn('id', $keepIds->all())->values();

        if ($kept->count() !== $keepIds->count()) {
            throw ValidationException::withMessages([
                'keep_evidence' => '证明材料信息已失效，请刷新页面后重试。',
            ]);
        }

        $newFiles = $request->file('evidence', []);
        $totalEvidence = $kept->count() + count($newFiles);

        if ($totalEvidence < 1 || $totalEvidence > 3) {
            throw ValidationException::withMessages([
                'evidence' => '修改后需保留 1–3 张证明材料。',
            ]);
        }

        $stored = [];

        foreach ($newFiles as $index => $file) {
            $stored[] = [
                'path' => $file->store('evidence', 'local'),
                'name' => $file->getClientOriginalName(),
                'sort_order' => $index,
            ];
        }

        try {
            $removedPaths = DB::transaction(function () use ($application, $request, $data, $kept, $existing, $stored) {
                $fresh = ScoreApplication::query()->lockForUpdate()->find($application->id);

                if (! $fresh || $fresh->status === 'approved') {
                    throw ValidationException::withMessages([
                        'status' => '已通过的申请不可修改。',
                    ]);
                }

                $resetStatus = in_array($fresh->status, ['rejected', 'withdrawn'], true);

                $fresh->update([
                    'activity_name' => $data['activity_name'],
                    'applied_on' => $data['applied_on'],
                    'description' => $data['description'],
                    'status' => $resetStatus ? 'pending' : $fresh->status,
                    'reviewer_id' => $resetStatus ? null : $fresh->reviewer_id,
                    'review_comment' => $resetStatus ? null : $fresh->review_comment,
                    'reviewed_at' => $resetStatus ? null : $fresh->reviewed_at,
                    'final_score' => $resetStatus ? null : $fresh->final_score,
                ]);

                $removeIds = $existing->pluck('id')->diff($kept->pluck('id'))->values()->all();
                $paths = [];

                foreach ($existing->whereIn('id', $removeIds) as $evidence) {
                    $paths[] = $evidence->path;
                    $evidence->delete();
                }

                $order = 0;

                foreach ($kept as $evidence) {
                    $evidence->update(['sort_order' => $order++]);
                }

                foreach ($stored as $file) {
                    $fresh->evidences()->create([
                        'path' => $file['path'],
                        'name' => $file['name'],
                        'sort_order' => $order++,
                    ]);
                }

                $first = $kept->first();

                $fresh->update([
                    'evidence_path' => $first?->path ?? $stored[0]['path'],
                    'evidence_name' => $first?->name ?? $stored[0]['name'],
                ]);

                $this->scores->audit($request->user(), 'update_application', $fresh, [
                    'status' => $fresh->status,
                    'evidence_count' => $order,
                ]);

                return $paths;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $file) {
                Storage::disk('local')->delete($file['path']);
            }

            throw $e;
        }

        foreach (array_unique(array_filter($removedPaths)) as $path) {
            Storage::disk('local')->delete($path);
        }

        return redirect()
            ->route('applications.index')
            ->with('success', '申请已修改，等待管理员重新审核。');
    }

    private function ensureStudentCanEdit(Request $request, ScoreApplication $application): void
    {
        abort_unless((int) $application->user_id === (int) $request->user()->id, 403, '无权修改该申请。');
        abort_if($application->status === 'approved', 403, '已通过的申请不可修改。');
    }

    public function withdraw(Request $request, ScoreApplication $application): RedirectResponse
    {
        abort_unless($application->user_id === $request->user()->id, 403, '无权操作该申请。');

        if (! $application->canWithdraw()) {
            return back()->with('error', '已通过的申请不能撤回。');
        }

        $application->update(['status' => 'withdrawn']);

        $this->scores->audit($request->user(), 'withdraw_application', $application);

        return back()->with('success', '申请已撤回。');
    }

    public function downloadEvidence(Request $request, ScoreApplication $application): StreamedResponse
    {
        $this->ensureEvidenceAccess($request, $application);
        abort_unless($application->evidence_path && Storage::disk('local')->exists($application->evidence_path), 404);

        return Storage::disk('local')->download(
            $application->evidence_path,
            $application->evidence_name ?: '证明材料'
        );
    }

    public function previewEvidence(Request $request, ScoreApplication $application): BinaryFileResponse
    {
        $this->ensureEvidenceAccess($request, $application);
        abort_unless($application->evidence_path && Storage::disk('local')->exists($application->evidence_path), 404);

        $disk = Storage::disk('local');

        return response()->file(
            $disk->path($application->evidence_path),
            [
                'Content-Type' => $disk->mimeType($application->evidence_path) ?: 'application/octet-stream',
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=300',
            ]
        );
    }

    public function downloadEvidenceFile(
        Request $request,
        ScoreApplication $application,
        ScoreApplicationEvidence $evidence
    ): StreamedResponse {
        $this->ensureEvidenceAccess($request, $application);
        $this->ensureEvidenceBelongs($application, $evidence);
        abort_unless(Storage::disk('local')->exists($evidence->path), 404);

        return Storage::disk('local')->download($evidence->path, $evidence->name ?: '证明材料');
    }

    public function previewEvidenceFile(
        Request $request,
        ScoreApplication $application,
        ScoreApplicationEvidence $evidence
    ): BinaryFileResponse {
        $this->ensureEvidenceAccess($request, $application);
        $this->ensureEvidenceBelongs($application, $evidence);
        abort_unless(Storage::disk('local')->exists($evidence->path), 404);

        $disk = Storage::disk('local');

        return response()->file(
            $disk->path($evidence->path),
            [
                'Content-Type' => $disk->mimeType($evidence->path) ?: 'application/octet-stream',
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=300',
            ]
        );
    }

    private function ensureEvidenceAccess(Request $request, ScoreApplication $application): void
    {
        $user = $request->user();
        abort_unless(
            $application->user_id === $user->id
                || $this->access->canAccessStudent($user, $application->user)
                || $this->access->canOfficerReviewStudent($user, $application->user),
            403,
            '无权查看该证明材料。'
        );
    }

    private function ensureEvidenceBelongs(ScoreApplication $application, ScoreApplicationEvidence $evidence): void
    {
        abort_unless($evidence->score_application_id === $application->id, 404);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $period = PeriodResolver::resolve($request);

        $records = $user->scoreRecords()
            ->with(['category:id,name,type', 'semester:id,name'])
            ->when($period['semester_ids'], fn ($q, $ids) => $q->whereIn('semester_id', $ids))
            ->orderByRaw('COALESCE(applied_on, DATE(created_at)) DESC')
            ->orderByDesc('id')
            ->get();

        return $this->exports->personalRecords($user, $records, $period['label']);
    }
}
