<?php

namespace App\Http\Controllers;

use App\Models\Appeal;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Services\AppealService;
use App\Support\AccessScope;
use App\Support\PageSize;
use App\Support\PeriodResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppealController extends Controller
{
    public function __construct(
        private readonly AppealService $appeals,
        private readonly AccessScope $access
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $period = PeriodResolver::resolve($request);
        $page = PageSize::resolve($request, 10);

        $records = ScoreRecord::query()
            ->with([
                'category:id,name,type',
                'semester:id,name',
                'latestAppeal:id,score_record_id,status,reason,review_comment,reviewed_at',
            ])
            ->where('user_id', $user->id)
            ->where('score', '<', 0)
            ->when($period['semester_ids'], fn ($q, $ids) => $q->whereIn('semester_id', $ids))
            ->latest()
            ->paginate($page['limit'])
            ->withQueryString();

        $appealCounts = Appeal::query()
            ->where('user_id', $user->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Appeals/Index', [
            'records' => $records,
            'period' => $period,
            'perPage' => $page['per_page'],
            'stats' => [
                'pending' => (int) ($appealCounts['pending'] ?? 0),
                'approved' => (int) ($appealCounts['approved'] ?? 0),
                'rejected' => (int) ($appealCounts['rejected'] ?? 0),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'score_record_id' => ['required', Rule::exists('score_records', 'id')],
            'reason' => ['required', 'string', 'max:1000'],
            'evidence' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], [], [
            'score_record_id' => '扣分记录',
            'reason' => '申诉理由',
            'evidence' => '申诉证明材料',
        ]);

        $record = ScoreRecord::findOrFail($data['score_record_id']);
        $path = $request->file('evidence')->store('appeals', 'local');

        $this->appeals->submit(
            $request->user(),
            $record,
            $data['reason'],
            $path,
            $request->file('evidence')->getClientOriginalName()
        );

        return back()->with('success', '申诉已提交，等待管理员审核。');
    }

    public function adminIndex(Request $request): Response
    {
        $user = $request->user();
        $status = $request->input('status', 'pending');
        $semesterId = $request->input('semester_id');
        $search = $request->input('search');
        $page = PageSize::resolve($request, 20);

        $appeals = Appeal::query()
            ->tap(fn ($q) => $this->access->scopeAppeals($q, $user))
            ->with([
                'user:id,name,student_id,class_name',
                'scoreRecord:id,user_id,category_id,semester_id,score,description,source_type,created_at',
                'scoreRecord.category:id,name,type',
                'scoreRecord.semester:id,name',
                'reviewer:id,name',
            ])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($semesterId, fn ($q) => $q->whereHas('scoreRecord', fn ($sub) => $sub->where('semester_id', $semesterId)))
            ->when($search, function ($q, $value) {
                $q->whereHas('user', function ($sub) use ($value) {
                    $sub->where('name', 'like', "%{$value}%")
                        ->orWhere('student_id', 'like', "%{$value}%");
                });
            })
            ->latest()
            ->paginate($page['limit'])
            ->withQueryString();

        $countQuery = Appeal::query();
        $this->access->scopeAppeals($countQuery, $user);
        $counts = $countQuery
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Appeals/AdminIndex', [
            'appeals' => $appeals,
            'semesters' => Semester::orderByDesc('academic_year')->orderBy('term')->get(['id', 'name', 'is_current']),
            'perPage' => $page['per_page'],
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ],
            'filters' => [
                'status' => $status,
                'semester_id' => $semesterId,
                'search' => $search,
            ],
        ]);
    }

    public function approve(Request $request, Appeal $appeal): RedirectResponse
    {
        abort_unless($this->access->canAccessStudent($request->user(), $appeal->user), 403);

        $data = $request->validate([
            'review_comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->appeals->approve($appeal, $request->user(), $data['review_comment'] ?? null);

        return back()->with('success', '申诉已通过，扣分记录已撤销。');
    }

    public function reject(Request $request, Appeal $appeal): RedirectResponse
    {
        abort_unless($this->access->canAccessStudent($request->user(), $appeal->user), 403);

        $data = $request->validate([
            'review_comment' => ['required', 'string', 'max:1000'],
        ], [], [
            'review_comment' => '驳回原因',
        ]);

        $this->appeals->reject($appeal, $request->user(), $data['review_comment']);

        return back()->with('success', '申诉已驳回。');
    }

    public function downloadEvidence(Request $request, Appeal $appeal): StreamedResponse
    {
        $user = $request->user();
        abort_unless($appeal->user_id === $user->id || $this->access->canAccessStudent($user, $appeal->user), 403, '无权查看该申诉材料。');
        abort_unless($appeal->evidence_path && Storage::disk('local')->exists($appeal->evidence_path), 404);

        return Storage::disk('local')->download(
            $appeal->evidence_path,
            $appeal->evidence_name ?: '申诉证明'
        );
    }
}
