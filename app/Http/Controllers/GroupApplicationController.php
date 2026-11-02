<?php

namespace App\Http\Controllers;

use App\Models\GroupApplication;
use App\Models\GroupApplicationEvidence;
use App\Models\GroupApplicationMember;
use App\Models\ScoreCategory;
use App\Models\Semester;
use App\Models\User;
use App\Services\ExportService;
use App\Services\ImportService;
use App\Services\ScoreService;
use App\Support\AccessScope;
use App\Support\PageSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GroupApplicationController extends Controller
{
    public function __construct(
        private readonly ScoreService $scores,
        private readonly ImportService $imports,
        private readonly ExportService $exports,
        private readonly AccessScope $access
    ) {
    }

    public function index(Request $request): Response
    {
        $page = PageSize::resolve($request, 10);

        $query = GroupApplication::query()
            ->with(['category:id,name,type', 'semester:id,name', 'reviewer:id,name'])
            ->withCount('members')
            ->withMin('members as score_min', DB::raw('coalesce(final_score, score)'))
            ->withMax('members as score_max', DB::raw('coalesce(final_score, score)'))
            ->where('teacher_id', $request->user()->id)
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('semester_id'), fn ($q, $semester) => $q->where('semester_id', $semester))
            ->latest();

        return Inertia::render('GroupApplications/Index', [
            'applications' => $query->paginate($page['limit'])->withQueryString(),
            'semesters' => Semester::orderByDesc('academic_year')->orderBy('term')->get(['id', 'name', 'is_current']),
            'perPage' => $page['per_page'],
            'stats' => [
                'total' => GroupApplication::where('teacher_id', $request->user()->id)->count(),
                'pending' => GroupApplication::where('teacher_id', $request->user()->id)->where('status', 'pending')->count(),
                'approved' => GroupApplication::where('teacher_id', $request->user()->id)->where('status', 'approved')->count(),
                'rejected' => GroupApplication::where('teacher_id', $request->user()->id)->where('status', 'rejected')->count(),
            ],
            'filters' => [
                'status' => $request->input('status'),
                'semester_id' => $request->input('semester_id'),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('GroupApplications/Create', [
            'categories' => ScoreCategory::query()
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('code')->orWhere('code', '<>', 'volunteer');
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'type', 'description', 'default_score', 'max_score']),
            'currentSemester' => Semester::current()?->only(['id', 'name', 'academic_year', 'term']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $semester = Semester::current();

        if (! $semester) {
            throw ValidationException::withMessages([
                'title' => '系统尚未设置当前学期，请联系管理员。',
            ]);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:2000'],
            'category_id' => [
                'required',
                Rule::exists('score_categories', 'id')->where('is_active', true),
            ],
            'members_file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
            'evidence' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], [], [
            'title' => '活动名称',
            'description' => '活动说明',
            'category_id' => '类别',
            'members_file' => '成员名单',
            'evidence' => '证明材料',
        ]);

        $category = ScoreCategory::find($data['category_id']);

        if (! $category || $category->isVolunteer()) {
            throw ValidationException::withMessages([
                'category_id' => '志愿服务类别需通过学生个人申请逐次审核，不能用于集体申请。',
            ]);
        }

        $parsed = $this->imports->parseGroupMembers($request->file('members_file'));

        if ($parsed['errors']) {
            throw ValidationException::withMessages([
                'members_file' => array_slice($parsed['errors'], 0, 10),
            ]);
        }

        if (count($parsed['members']) === 0) {
            throw ValidationException::withMessages([
                'members_file' => '名单中没有有效成员。',
            ]);
        }

        $collegeId = $request->user()->teacher_college_id ?: $request->user()->college_id;

        if (! $collegeId) {
            throw ValidationException::withMessages([
                'members_file' => '当前教师账号尚未分配所属学院，请联系管理员。',
            ]);
        }

        $memberColleges = User::whereIn('id', array_column($parsed['members'], 'student_id'))
            ->pluck('college_id', 'id');

        $outside = [];

        foreach ($parsed['members'] as $member) {
            if ((int) ($memberColleges[$member['student_id']] ?? 0) !== (int) $collegeId) {
                $outside[] = "学号 {$member['username']}（{$member['name']}）不属于您所属学院。";
            }
        }

        if ($outside !== []) {
            throw ValidationException::withMessages([
                'members_file' => array_slice($outside, 0, 10),
            ]);
        }

        $path = null;
        $originalName = null;

        if ($request->hasFile('evidence')) {
            $path = $request->file('evidence')->store('evidence', 'local');
            $originalName = $request->file('evidence')->getClientOriginalName();
        }

        try {
            $application = DB::transaction(function () use ($request, $data, $semester, $parsed, $path, $originalName) {
                $application = GroupApplication::create([
                    'teacher_id' => $request->user()->id,
                    'origin' => 'teacher',
                    'category_id' => $data['category_id'],
                    'semester_id' => $semester->id,
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'status' => 'pending',
                    'evidence_path' => $path,
                    'evidence_name' => $originalName,
                ]);

                foreach ($parsed['members'] as $member) {
                    GroupApplicationMember::create([
                        'group_application_id' => $application->id,
                        'student_id' => $member['student_id'],
                        'score' => $member['score'],
                    ]);
                }

                return $application;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }

        $this->scores->audit($request->user(), 'submit_group_application', $application, [
            'member_count' => count($parsed['members']),
        ]);

        return redirect()
            ->route('group-applications.show', $application)
            ->with('success', '集体申请已提交，共 '.count($parsed['members']).' 名成员。');
    }

    public function createStudent(Request $request): Response
    {
        $user = $request->user();
        $semester = Semester::current();

        $classmates = $user->class_id
            ? User::query()
                ->where('role', 'student')
                ->where('is_active', true)
                ->where('class_id', $user->class_id)
                ->whereKeyNot($user->id)
                ->orderBy('student_id')
                ->get(['id', 'name', 'student_id', 'class_name'])
            : collect();

        return Inertia::render('Applications/GroupCreate', [
            'categories' => ScoreCategory::query()
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('code')->orWhere('code', '<>', 'volunteer');
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'type', 'description', 'default_score', 'max_score']),
            'currentSemester' => $semester?->only(['id', 'name', 'academic_year', 'term']),
            'classmates' => $classmates,
            'classInfo' => [
                'class_id' => $user->class_id,
                'class_name' => $user->class_name ?? $user->schoolClass?->name,
            ],
        ]);
    }

    public function storeStudent(Request $request): RedirectResponse
    {
        $user = $request->user();
        $semester = Semester::current();

        if (! $semester) {
            throw ValidationException::withMessages([
                'title' => '系统尚未设置当前学期，请联系管理员。',
            ]);
        }

        if (! $user->class_id) {
            throw ValidationException::withMessages([
                'member_ids' => '当前账号尚未分班，无法发起集体申请，请联系管理员。',
            ]);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:2000'],
            'category_id' => [
                'required',
                Rule::exists('score_categories', 'id')->where('is_active', true),
            ],
            'applied_on' => ['required', 'date', 'before_or_equal:today'],
            'score' => ['required', 'numeric', 'min:0.5', 'max:1000', 'multiple_of:0.5'],
            'member_ids' => ['required', 'array', 'min:1', 'max:99'],
            'member_ids.*' => ['integer', 'distinct'],
            'evidence' => ['required', 'array', 'min:1', 'max:3'],
            'evidence.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], [], [
            'title' => '活动名称',
            'description' => '活动说明',
            'category_id' => '类别',
            'applied_on' => '活动日期',
            'score' => '申请分值',
            'member_ids' => '集体成员',
            'evidence' => '证明材料',
            'evidence.*' => '证明材料',
        ]);

        $category = ScoreCategory::find($data['category_id']);

        if (! $category || $category->isVolunteer()) {
            throw ValidationException::withMessages([
                'category_id' => '志愿服务类别不能用于集体申请，请通过个人申请逐次提交。',
            ]);
        }

        if ($category->max_score && (float) $data['score'] > (float) $category->max_score) {
            throw ValidationException::withMessages([
                'score' => "该类别单次分值不能超过 {$category->max_score} 分。",
            ]);
        }

        $memberIds = collect($data['member_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($memberIds->contains((int) $user->id)) {
            throw ValidationException::withMessages([
                'member_ids' => '发起人已自动加入，无需重复选择本人。',
            ]);
        }

        $members = User::query()
            ->whereIn('id', $memberIds)
            ->where('role', 'student')
            ->where('is_active', true)
            ->where('class_id', $user->class_id)
            ->get(['id', 'name']);

        if ($members->count() !== $memberIds->count()) {
            throw ValidationException::withMessages([
                'member_ids' => '只能选择本班在读同学，不能选择其他班级或已停用的账号。',
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
            $application = DB::transaction(function () use ($user, $category, $semester, $data, $members, $stored, $first) {
                $application = GroupApplication::create([
                    'teacher_id' => $user->id,
                    'origin' => 'student',
                    'initiator_id' => $user->id,
                    'category_id' => $category->id,
                    'semester_id' => $semester->id,
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'applied_on' => $data['applied_on'],
                    'status' => 'pending',
                    'evidence_path' => $first['path'],
                    'evidence_name' => $first['name'],
                ]);

                GroupApplicationMember::create([
                    'group_application_id' => $application->id,
                    'student_id' => $user->id,
                    'score' => $data['score'],
                ]);

                foreach ($members as $member) {
                    GroupApplicationMember::create([
                        'group_application_id' => $application->id,
                        'student_id' => $member->id,
                        'score' => $data['score'],
                    ]);
                }

                $application->evidences()->createMany($stored);

                return $application;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $file) {
                Storage::disk('local')->delete($file['path']);
            }

            throw $e;
        }

        $this->scores->audit($user, 'submit_student_group_application', $application, [
            'member_count' => $members->count() + 1,
            'score' => (float) $data['score'],
        ]);

        return redirect()
            ->route('applications.group.show', $application)
            ->with('success', '集体申请已提交，共 '.($members->count() + 1).' 名成员，等待管理员审核。');
    }

    public function showStudent(Request $request, GroupApplication $groupApplication): Response
    {
        $user = $request->user();
        abort_unless($this->access->canViewGroup($user, $groupApplication), 403, '无权查看该集体申请。');

        $groupApplication->load([
            'category:id,name,type',
            'semester:id,name',
            'teacher:id,name,employee_id',
            'initiator:id,name,student_id,class_name',
            'reviewer:id,name',
            'evidences:id,group_application_id,name,sort_order',
            'members' => fn ($q) => $q->with('student:id,name,student_id,class_name,college,grade'),
        ]);

        return Inertia::render('Applications/GroupShow', [
            'application' => $groupApplication,
            'canWithdraw' => $groupApplication->canWithdraw()
                && $groupApplication->isStudentOrigin()
                && (int) $groupApplication->initiator_id === (int) $user->id,
            'isInitiator' => $groupApplication->isStudentOrigin()
                && (int) $groupApplication->initiator_id === (int) $user->id,
        ]);
    }

    public function editStudent(Request $request, GroupApplication $groupApplication): Response
    {
        $this->ensureStudentCanEditGroup($request, $groupApplication);

        $groupApplication->load([
            'category:id,name,type',
            'semester:id,name',
            'evidences:id,group_application_id,name,sort_order',
            'members' => fn ($q) => $q->with('student:id,name,student_id,class_name'),
        ]);

        return Inertia::render('Applications/GroupEdit', [
            'application' => $groupApplication,
            'today' => now()->toDateString(),
        ]);
    }

    public function updateStudent(Request $request, GroupApplication $groupApplication): RedirectResponse
    {
        $this->ensureStudentCanEditGroup($request, $groupApplication);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:2000'],
            'applied_on' => ['required', 'date', 'before_or_equal:today'],
            'keep_evidence' => ['nullable', 'array', 'max:3'],
            'keep_evidence.*' => ['integer', 'distinct'],
            'evidence' => ['nullable', 'array', 'max:3'],
            'evidence.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], [], [
            'title' => '活动名称',
            'description' => '活动说明',
            'applied_on' => '活动日期',
            'keep_evidence' => '已有证明材料',
            'evidence' => '证明材料',
            'evidence.*' => '证明材料',
        ]);

        $existing = $groupApplication->evidences()->get();
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
            $removedPaths = DB::transaction(function () use ($groupApplication, $request, $data, $kept, $existing, $stored) {
                $fresh = GroupApplication::query()->lockForUpdate()->find($groupApplication->id);

                if (! $fresh || $fresh->status === 'approved') {
                    throw ValidationException::withMessages([
                        'status' => '已通过的集体申请不可修改。',
                    ]);
                }

                $resetStatus = in_array($fresh->status, ['rejected', 'withdrawn'], true);

                $fresh->update([
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'applied_on' => $data['applied_on'],
                    'status' => $resetStatus ? 'pending' : $fresh->status,
                    'reviewer_id' => $resetStatus ? null : $fresh->reviewer_id,
                    'review_comment' => $resetStatus ? null : $fresh->review_comment,
                    'reviewed_at' => $resetStatus ? null : $fresh->reviewed_at,
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

                $this->scores->audit($request->user(), 'update_student_group_application', $fresh, [
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
            ->route('applications.group.show', $groupApplication)
            ->with('success', '集体申请已修改，等待管理员重新审核。');
    }

    private function ensureStudentCanEditGroup(Request $request, GroupApplication $groupApplication): void
    {
        abort_unless(
            $groupApplication->isStudentOrigin()
                && (int) $groupApplication->initiator_id === (int) $request->user()->id,
            403,
            '只有发起人可以修改该集体申请。'
        );

        abort_if($groupApplication->status === 'approved', 403, '已通过的集体申请不可修改。');
    }

    public function withdrawStudent(Request $request, GroupApplication $groupApplication): RedirectResponse
    {
        abort_unless(
            $groupApplication->isStudentOrigin()
                && (int) $groupApplication->initiator_id === (int) $request->user()->id,
            403,
            '只有发起人可以撤回该集体申请。'
        );

        if (! $groupApplication->canWithdraw()) {
            return back()->with('error', '已通过的集体申请不能撤回。');
        }

        $groupApplication->update(['status' => 'withdrawn']);

        $this->scores->audit($request->user(), 'withdraw_student_group_application', $groupApplication);

        return back()->with('success', '集体申请已撤回。');
    }

    public function previewGroupEvidence(
        Request $request,
        GroupApplication $groupApplication,
        GroupApplicationEvidence $evidence
    ): BinaryFileResponse {
        $this->ensureGroupEvidenceAccess($request, $groupApplication, $evidence);
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

    public function downloadGroupEvidence(
        Request $request,
        GroupApplication $groupApplication,
        GroupApplicationEvidence $evidence
    ): StreamedResponse {
        $this->ensureGroupEvidenceAccess($request, $groupApplication, $evidence);
        abort_unless(Storage::disk('local')->exists($evidence->path), 404);

        return Storage::disk('local')->download($evidence->path, $evidence->name ?: '证明材料');
    }

    private function ensureGroupEvidenceAccess(
        Request $request,
        GroupApplication $groupApplication,
        GroupApplicationEvidence $evidence
    ): void {
        abort_unless((int) $evidence->group_application_id === (int) $groupApplication->id, 404);
        abort_unless($this->access->canViewGroup($request->user(), $groupApplication), 403, '无权查看该证明材料。');
    }

    public function show(Request $request, GroupApplication $groupApplication): Response
    {
        $user = $request->user();
        abort_unless($this->access->canViewGroup($user, $groupApplication), 403, '无权查看该集体申请。');

        $groupApplication->load([
            'category:id,name,type',
            'semester:id,name',
            'teacher:id,name,employee_id,teacher_college',
            'initiator:id,name,student_id,class_name',
            'reviewer:id,name',
            'evidences:id,group_application_id,name,sort_order',
            'members' => fn ($q) => $q->with('student:id,name,student_id,class_name,college,grade'),
        ]);

        return Inertia::render('GroupApplications/Show', [
            'application' => $groupApplication,
        ]);
    }

    public function withdraw(Request $request, GroupApplication $groupApplication): RedirectResponse
    {
        abort_unless($groupApplication->teacher_id === $request->user()->id, 403, '无权操作该申请。');

        if (! $groupApplication->canWithdraw()) {
            return back()->with('error', '已通过的申请不能撤回。');
        }

        $groupApplication->update(['status' => 'withdrawn']);

        $this->scores->audit($request->user(), 'withdraw_group_application', $groupApplication);

        return back()->with('success', '集体申请已撤回。');
    }

    public function downloadEvidence(Request $request, GroupApplication $groupApplication): StreamedResponse
    {
        $user = $request->user();
        abort_unless($this->access->canViewGroup($user, $groupApplication), 403, '无权下载该证明材料。');
        abort_unless(
            $groupApplication->evidence_path && Storage::disk('local')->exists($groupApplication->evidence_path),
            404
        );

        return Storage::disk('local')->download(
            $groupApplication->evidence_path,
            $groupApplication->evidence_name ?: '证明材料'
        );
    }

    public function previewLegacyEvidence(Request $request, GroupApplication $groupApplication): BinaryFileResponse
    {
        abort_unless($this->access->canViewGroup($request->user(), $groupApplication), 403, '无权查看该证明材料。');
        abort_unless(
            $groupApplication->evidence_path && Storage::disk('local')->exists($groupApplication->evidence_path),
            404
        );

        $disk = Storage::disk('local');

        return response()->file(
            $disk->path($groupApplication->evidence_path),
            [
                'Content-Type' => $disk->mimeType($groupApplication->evidence_path) ?: 'application/octet-stream',
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=300',
            ]
        );
    }

    public function template(): StreamedResponse
    {
        return $this->exports->memberTemplate();
    }
}
