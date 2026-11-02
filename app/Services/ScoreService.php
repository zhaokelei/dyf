<?php

namespace App\Services;

use App\Models\Appeal;
use App\Models\AuditLog;
use App\Models\GroupApplication;
use App\Models\ScoreApplication;
use App\Models\ScoreCategory;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\User;
use App\Support\VolunteerRule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ScoreService
{
    public function baseScore(): int
    {
        return (int) Setting::get('base_score', '40');
    }

    public function studentSummary(User $user, ?array $semesterIds = null): array
    {
        $records = ScoreRecord::query()
            ->with(['category:id,name,type', 'semester:id,name'])
            ->where('user_id', $user->id)
            ->when($semesterIds !== null, fn ($q) => $q->whereIn('semester_id', $semesterIds))
            ->orderByRaw('COALESCE(applied_on, DATE(created_at)) DESC')
            ->orderByDesc('id')
            ->get();

        $active = $records->whereNull('revoked_at');
        $add = (float) $active->where('score', '>', 0)->sum('score');
        $deduct = abs((float) $active->where('score', '<', 0)->sum('score'));
        $total = max(0.0, $this->baseScore() + $add - $deduct);

        $categoryBreakdown = $active
            ->groupBy(fn (ScoreRecord $record) => $record->category?->name ?? '其他')
            ->map(function ($items, $name) {
                return [
                    'name' => $name,
                    'type' => $items->first()->category?->type ?? 'add',
                    'score' => (float) $items->sum('score'),
                    'count' => $items->count(),
                ];
            })
            ->values();

        return [
            'base_score' => $this->baseScore(),
            'add_score' => $add,
            'deduct_score' => $deduct,
            'total_score' => $total,
            'record_count' => $active->count(),
            'revoked_count' => $records->whereNotNull('revoked_at')->count(),
            'category_breakdown' => $categoryBreakdown,
            'records' => $records->map(fn (ScoreRecord $record) => [
                'id' => $record->id,
                'category' => $record->category?->name ?? '其他',
                'category_type' => $record->category?->type ?? 'add',
                'score' => $record->score,
                'description' => $record->description,
                'source_type' => $record->source_type,
                'semester' => $record->semester?->name,
                'is_revoked' => $record->isRevoked(),
                'revoke_reason' => $record->revoke_reason,
                'applied_on' => ($record->applied_on ?? $record->created_at)?->toDateString(),
                'created_at' => $record->created_at?->toIso8601String(),
            ]),
        ];
    }

    public function approveApplication(
        ScoreApplication $application,
        User $reviewer,
        float $finalScore,
        ?string $comment = null
    ): void {
        DB::transaction(function () use ($application, $reviewer, $finalScore, $comment) {
            $application = ScoreApplication::lockForUpdate()->find($application->id);

            if (! $application || $application->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => '该申请已处理，不能重复审核。',
                ]);
            }

            $category = $application->category;
            $isVolunteer = $category->isVolunteer();

            if ($isVolunteer) {
                User::query()->whereKey($application->user_id)->lockForUpdate()->first();

                $alreadyApproved = ScoreApplication::query()
                    ->where('user_id', $application->user_id)
                    ->where('category_id', $category->id)
                    ->where('semester_id', $application->semester_id)
                    ->where('status', 'approved')
                    ->whereKeyNot($application->id)
                    ->count();

                if ($alreadyApproved >= VolunteerRule::REQUIRED_COUNT) {
                    throw ValidationException::withMessages([
                        'status' => '该学生本学期志愿服务已达到 4 次上限，不能再通过。',
                    ]);
                }

                $finalScore = 0;
            }

            $signedScore = $category->isDeduct() ? -abs($finalScore) : abs($finalScore);

            $application->update([
                'status' => 'approved',
                'final_score' => abs($finalScore),
                'reviewer_id' => $reviewer->id,
                'review_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            ScoreRecord::create([
                'user_id' => $application->user_id,
                'category_id' => $application->category_id,
                'semester_id' => $application->semester_id,
                'score' => $signedScore,
                'applied_on' => $application->applied_on?->toDateString() ?? now()->toDateString(),
                'source_type' => 'personal',
                'source_id' => $application->id,
                'description' => $application->description,
                'created_by' => $reviewer->id,
            ]);

            if ($isVolunteer) {
                $this->grantVolunteerBonus($application, $reviewer, $category);
            }

            $this->audit($reviewer, 'approve_application', $application, [
                'score' => $signedScore,
            ]);
        });
    }

    private function grantVolunteerBonus(
        ScoreApplication $application,
        User $reviewer,
        ScoreCategory $category
    ): void {
        $approved = VolunteerRule::approvedCount($application->user_id, $application->semester_id);

        if ($approved < VolunteerRule::REQUIRED_COUNT) {
            return;
        }

        if (VolunteerRule::bonusAwarded($application->user_id, $application->semester_id)) {
            return;
        }

        ScoreRecord::create([
            'user_id' => $application->user_id,
            'category_id' => $category->id,
            'semester_id' => $application->semester_id,
            'score' => VolunteerRule::BONUS_SCORE,
            'applied_on' => now()->toDateString(),
            'source_type' => VolunteerRule::BONUS_SOURCE,
            'source_id' => $application->semester_id,
            'description' => '本学期志愿服务已通过 4 次，自动加 6 分',
            'created_by' => $reviewer->id,
        ]);

        $this->audit($reviewer, 'volunteer_bonus', $application, [
            'score' => VolunteerRule::BONUS_SCORE,
        ]);
    }

    public function rejectApplication(
        ScoreApplication $application,
        User $reviewer,
        ?string $comment = null
    ): void {
        DB::transaction(function () use ($application, $reviewer, $comment) {
            $application = ScoreApplication::lockForUpdate()->find($application->id);

            if (! $application || $application->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => '该申请已处理，不能重复审核。',
                ]);
            }

            $application->update([
                'status' => 'rejected',
                'reviewer_id' => $reviewer->id,
                'review_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            $this->audit($reviewer, 'reject_application', $application);
        });
    }

    public function updateApprovedApplication(
        ScoreApplication $application,
        User $reviewer,
        float $finalScore,
        ?string $comment = null
    ): void {
        DB::transaction(function () use ($application, $reviewer, $finalScore, $comment) {
            $application = ScoreApplication::lockForUpdate()->find($application->id);

            if (! $application || $application->status !== 'approved') {
                throw ValidationException::withMessages([
                    'status' => '只有已通过的申请可以修改核定分值。',
                ]);
            }

            $category = $application->category()->first();

            if (! $category) {
                throw ValidationException::withMessages([
                    'category_id' => '申请类别不存在，无法修改核定分值。',
                ]);
            }

            if ($category->isVolunteer()) {
                $finalScore = 0;
            }

            $signedScore = $category->isDeduct() ? -abs($finalScore) : abs($finalScore);
            $oldScore = $application->final_score;

            User::query()->whereKey($application->user_id)->lockForUpdate()->first();

            $record = ScoreRecord::query()
                ->where('source_type', 'personal')
                ->where('source_id', $application->id)
                ->where('user_id', $application->user_id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($record) {
                $record->update(['score' => $signedScore]);
            } else {
                ScoreRecord::create([
                    'user_id' => $application->user_id,
                    'category_id' => $application->category_id,
                    'semester_id' => $application->semester_id,
                    'score' => $signedScore,
                    'applied_on' => $application->applied_on?->toDateString() ?? now()->toDateString(),
                    'source_type' => 'personal',
                    'source_id' => $application->id,
                    'description' => $application->description,
                    'created_by' => $reviewer->id,
                ]);
            }

            $application->update([
                'final_score' => abs($finalScore),
                'review_comment' => $comment,
            ]);

            $this->audit($reviewer, 'update_application_score', $application, [
                'old_score' => $oldScore,
                'new_score' => abs($finalScore),
                'signed_score' => $signedScore,
            ]);
        });
    }

    /**
     * @param  array<int, int>  $finalScores  memberId => score
     */
    public function approveGroup(
        GroupApplication $application,
        User $reviewer,
        array $finalScores = [],
        ?string $comment = null
    ): void {
        DB::transaction(function () use ($application, $reviewer, $finalScores, $comment) {
            $application = GroupApplication::lockForUpdate()->find($application->id);

            if (! $application || $application->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => '该申请已处理，不能重复审核。',
                ]);
            }

            $category = $application->category()->first();

            if (! $category) {
                throw ValidationException::withMessages([
                    'category_id' => '申请类别不存在，无法审核。',
                ]);
            }

            if ($category->isVolunteer()) {
                throw ValidationException::withMessages([
                    'category_id' => '志愿服务需通过个人申请逐次审核，集体申请不能选择志愿服务类别。',
                ]);
            }

            $members = $application->members()->get();

            if ($members->isEmpty()) {
                throw ValidationException::withMessages([
                    'members' => '该集体申请没有成员，无法通过。',
                ]);
            }

            foreach ($members as $member) {
                $score = abs((float) ($finalScores[$member->id] ?? $member->score));
                $signedScore = $category->isDeduct() ? -$score : $score;

                $member->update(['final_score' => $score]);

                ScoreRecord::create([
                    'user_id' => $member->student_id,
                    'category_id' => $application->category_id,
                    'semester_id' => $application->semester_id,
                    'score' => $signedScore,
                    'applied_on' => $application->applied_on?->toDateString()
                        ?? $application->created_at?->toDateString()
                        ?? now()->toDateString(),
                    'source_type' => 'group',
                    'source_id' => $application->id,
                    'description' => $application->title,
                    'created_by' => $reviewer->id,
                ]);
            }

            $application->update([
                'status' => 'approved',
                'reviewer_id' => $reviewer->id,
                'review_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            $this->audit($reviewer, 'approve_group_application', $application, [
                'member_count' => $members->count(),
            ]);
        });
    }

    public function rejectGroup(
        GroupApplication $application,
        User $reviewer,
        ?string $comment = null
    ): void {
        DB::transaction(function () use ($application, $reviewer, $comment) {
            $application = GroupApplication::lockForUpdate()->find($application->id);

            if (! $application || $application->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => '该申请已处理，不能重复审核。',
                ]);
            }

            $application->update([
                'status' => 'rejected',
                'reviewer_id' => $reviewer->id,
                'review_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            $this->audit($reviewer, 'reject_group_application', $application);
        });
    }

    public function cancelApplicationApproval(
        ScoreApplication $application,
        User $actor,
        ?string $reason = null
    ): void {
        DB::transaction(function () use ($application, $actor, $reason) {
            $application = ScoreApplication::lockForUpdate()->find($application->id);

            if (! $application || $application->status !== 'approved') {
                throw ValidationException::withMessages([
                    'status' => '只有已通过的申请可以取消通过。',
                ]);
            }

            $category = $application->category()->first();

            User::query()->whereKey($application->user_id)->lockForUpdate()->first();

            $revoked = 0;
            $records = ScoreRecord::query()
                ->where('source_type', 'personal')
                ->where('source_id', $application->id)
                ->where('user_id', $application->user_id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                $record->update([
                    'revoked_at' => now(),
                    'revoked_by' => $actor->id,
                    'revoke_reason' => $reason ?: '取消审核通过',
                ]);
                $revoked++;
            }

            $application->update([
                'status' => 'pending',
                'final_score' => null,
                'reviewer_id' => null,
                'review_comment' => null,
                'reviewed_at' => null,
            ]);

            if ($category?->isVolunteer()) {
                $this->revokeVolunteerBonusIfUnqualified(
                    $application->user_id,
                    $application->semester_id,
                    $actor,
                    $application
                );
            }

            $this->audit($actor, 'cancel_application_approval', $application, [
                'revoked_records' => $revoked,
                'reason' => $reason,
            ]);
        });
    }

    public function cancelGroupApproval(
        GroupApplication $application,
        User $actor,
        ?string $reason = null
    ): void {
        DB::transaction(function () use ($application, $actor, $reason) {
            $application = GroupApplication::lockForUpdate()->find($application->id);

            if (! $application || $application->status !== 'approved') {
                throw ValidationException::withMessages([
                    'status' => '只有已通过的集体申请可以取消通过。',
                ]);
            }

            $category = $application->category()->first();
            $members = $application->members()->get();

            $records = ScoreRecord::query()
                ->where('source_type', 'group')
                ->where('source_id', $application->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                $record->update([
                    'revoked_at' => now(),
                    'revoked_by' => $actor->id,
                    'revoke_reason' => $reason ?: '取消集体申请通过',
                ]);
            }

            foreach ($members as $member) {
                $member->update(['final_score' => null]);

                if ($category?->isVolunteer()) {
                    User::query()->whereKey($member->student_id)->lockForUpdate()->first();
                    $this->revokeVolunteerBonusIfUnqualified(
                        $member->student_id,
                        $application->semester_id,
                        $actor,
                        $application
                    );
                }
            }

            $application->update([
                'status' => 'pending',
                'reviewer_id' => null,
                'review_comment' => null,
                'reviewed_at' => null,
            ]);

            $this->audit($actor, 'cancel_group_approval', $application, [
                'revoked_records' => $records->count(),
                'member_count' => $members->count(),
                'reason' => $reason,
            ]);
        });
    }

    /**
     * @return array{revoked_records: int}
     */
    public function deleteApplication(ScoreApplication $application, User $actor): array
    {
        $files = [];

        $result = DB::transaction(function () use ($application, $actor, &$files) {
            $application = ScoreApplication::query()->lockForUpdate()->find($application->id);

            if (! $application) {
                return ['revoked_records' => 0];
            }

            $category = $application->category()->first();
            $wasApproved = $application->status === 'approved';

            User::query()->whereKey($application->user_id)->lockForUpdate()->first();

            $revoked = 0;

            if ($wasApproved) {
                $records = ScoreRecord::query()
                    ->where('source_type', 'personal')
                    ->where('source_id', $application->id)
                    ->where('user_id', $application->user_id)
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->get();

                foreach ($records as $record) {
                    $record->update([
                        'revoked_at' => now(),
                        'revoked_by' => $actor->id,
                        'revoke_reason' => '删除申请记录，扣回分值',
                    ]);
                    $revoked++;
                }

                if ($category?->isVolunteer()) {
                    $this->revokeVolunteerBonusIfUnqualified(
                        $application->user_id,
                        $application->semester_id,
                        $actor,
                        $application,
                        $application->id
                    );
                }
            }

            foreach ($application->evidences()->get() as $evidence) {
                $files[] = $evidence->path;
                $evidence->delete();
            }

            if ($application->evidence_path) {
                $files[] = $application->evidence_path;
            }

            $this->audit($actor, 'delete_application', $application, [
                'status' => $application->status,
                'score' => $application->final_score,
                'revoked_records' => $revoked,
            ]);

            $application->delete();

            return ['revoked_records' => $revoked];
        });

        $this->deleteStoredFiles($files);

        return $result;
    }

    /**
     * @return array{revoked_records: int}
     */
    public function deleteGroupApplication(GroupApplication $application, User $actor): array
    {
        $files = [];

        $result = DB::transaction(function () use ($application, $actor, &$files) {
            $application = GroupApplication::query()->lockForUpdate()->find($application->id);

            if (! $application) {
                return ['revoked_records' => 0];
            }

            $category = $application->category()->first();
            $members = $application->members()->get();
            $revoked = 0;

            if ($application->status === 'approved') {
                $records = ScoreRecord::query()
                    ->where('source_type', 'group')
                    ->where('source_id', $application->id)
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->get();

                foreach ($records as $record) {
                    $record->update([
                        'revoked_at' => now(),
                        'revoked_by' => $actor->id,
                        'revoke_reason' => '删除集体申请记录，扣回分值',
                    ]);
                    $revoked++;
                }

                foreach ($members as $member) {
                    $member->update(['final_score' => null]);

                    if ($category?->isVolunteer()) {
                        User::query()->whereKey($member->student_id)->lockForUpdate()->first();
                        $this->revokeVolunteerBonusIfUnqualified(
                            $member->student_id,
                            $application->semester_id,
                            $actor,
                            $application
                        );
                    }
                }
            }

            foreach ($application->evidences()->get() as $evidence) {
                $files[] = $evidence->path;
                $evidence->delete();
            }

            if ($application->evidence_path) {
                $files[] = $application->evidence_path;
            }

            $this->audit($actor, 'delete_group_application', $application, [
                'status' => $application->status,
                'member_count' => $members->count(),
                'revoked_records' => $revoked,
            ]);

            $application->members()->delete();
            $application->delete();

            return ['revoked_records' => $revoked];
        });

        $this->deleteStoredFiles($files);

        return $result;
    }

    /**
     * @param array<int, string|null> $paths
     */
    private function deleteStoredFiles(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            Storage::disk('local')->delete($path);
        }
    }

    private function revokeVolunteerBonusIfUnqualified(
        int $userId,
        int $semesterId,
        User $actor,
        $target = null,
        ?int $excludeApplicationId = null
    ): void {
        if (VolunteerRule::approvedCount($userId, $semesterId, $excludeApplicationId) >= VolunteerRule::REQUIRED_COUNT) {
            return;
        }

        $records = ScoreRecord::query()
            ->where('user_id', $userId)
            ->where('semester_id', $semesterId)
            ->where('source_type', VolunteerRule::BONUS_SOURCE)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->get();

        foreach ($records as $record) {
            $record->update([
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revoke_reason' => '志愿服务已不足 '.VolunteerRule::REQUIRED_COUNT.' 次，撤销奖励分',
            ]);
        }

        if ($records->isNotEmpty()) {
            $this->audit($actor, 'revoke_volunteer_bonus', $target, [
                'score' => -VolunteerRule::BONUS_SCORE,
                'revoked_records' => $records->count(),
            ]);
        }
    }

    public function leaderboard(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $semesterIds = $filters['semester_ids'] ?? [];
        $rankPeriod = $filters['rank_period'] ?? 'all';
        $start = $filters['start'] ?? null;
        $end = $filters['end'] ?? null;

        $semesterConstraint = function ($query) use ($semesterIds) {
            if ($semesterIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('semester_id', $semesterIds);
            }

            $query->whereNull('revoked_at');
        };

        $periodConstraint = function ($query) use ($semesterConstraint, $start, $end) {
            $semesterConstraint($query);

            if ($start) {
                $query->whereRaw('DATE(COALESCE(applied_on, created_at)) >= ?', [$start->toDateString()]);
            }

            if ($end) {
                $query->whereRaw('DATE(COALESCE(applied_on, created_at)) <= ?', [$end->toDateString()]);
            }
        };

        $query = User::query()
            ->with('major:id,name')
            ->where('role', 'student')
            ->where('is_active', true)
            ->when(array_key_exists('restrict_college_ids', $filters) && $filters['restrict_college_ids'] !== null, fn ($q) => $q->whereIn('college_id', $filters['restrict_college_ids']))
            ->when(array_key_exists('restrict_class_ids', $filters) && $filters['restrict_class_ids'] !== null, fn ($q) => $q->whereIn('class_id', $filters['restrict_class_ids']))
            ->when($filters['college'] ?? null, fn ($q, $v) => $q->where('college', $v))
            ->when($filters['college_id'] ?? null, fn ($q, $v) => $q->where('college_id', $v))
            ->when($filters['major_id'] ?? null, fn ($q, $v) => $q->where('major_id', $v))
            ->when($filters['grade'] ?? null, fn ($q, $v) => $q->where('grade', $v))
            ->when($filters['class_id'] ?? null, fn ($q, $v) => $q->where('class_id', $v))
            ->when($filters['class_name'] ?? null, fn ($q, $v) => $q->where('class_name', $v))
            ->when($filters['search'] ?? null, function ($q, $value) {
                $q->where(function ($sub) use ($value) {
                    $sub->where('name', 'like', "%{$value}%")
                        ->orWhere('student_id', 'like', "%{$value}%");
                });
            })
            ->withSum(['scoreRecords as semester_add_sum' => function ($q) use ($semesterConstraint) {
                $semesterConstraint($q);
                $q->where('score', '>', 0);
            }], 'score')
            ->withSum(['scoreRecords as semester_deduct_sum' => function ($q) use ($semesterConstraint) {
                $semesterConstraint($q);
                $q->where('score', '<', 0);
            }], 'score')
            ->withSum(['scoreRecords as period_add_sum' => function ($q) use ($periodConstraint) {
                $periodConstraint($q);
                $q->where('score', '>', 0);
            }], 'score')
            ->withSum(['scoreRecords as period_deduct_sum' => function ($q) use ($periodConstraint) {
                $periodConstraint($q);
                $q->where('score', '<', 0);
            }], 'score');

        if ($rankPeriod === 'all') {
            $query->orderByRaw('(COALESCE(semester_add_sum, 0) + COALESCE(semester_deduct_sum, 0)) DESC')
                ->orderBy('student_id');
        } else {
            $query->orderByRaw('(COALESCE(period_add_sum, 0) + COALESCE(period_deduct_sum, 0)) DESC')
                ->orderBy('student_id');
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $base = $this->baseScore();

        $paginator->getCollection()->transform(function (User $user) use ($base, $rankPeriod) {
            $semesterAdd = (float) ($user->semester_add_sum ?? 0);
            $semesterDeduct = abs((float) ($user->semester_deduct_sum ?? 0));
            $periodAdd = (float) ($user->period_add_sum ?? 0);
            $periodDeduct = abs((float) ($user->period_deduct_sum ?? 0));

            $semesterNet = $semesterAdd - $semesterDeduct;
            $periodNet = $periodAdd - $periodDeduct;
            $total = max(0, $base + $semesterNet);
            $rankingScore = $rankPeriod === 'all' ? $total : $periodNet;

            return [
                'id' => $user->id,
                'name' => $user->name,
                'student_id' => $user->student_id,
                'class_id' => $user->class_id,
                'class_name' => $user->class_name,
                'college' => $user->college,
                'major' => $user->major?->name,
                'grade' => $user->grade,
                'base_score' => $base,
                'add_score' => $semesterAdd,
                'deduct_score' => $semesterDeduct,
                'total_score' => $total,
                'period_add_score' => $periodAdd,
                'period_deduct_score' => $periodDeduct,
                'period_score' => $periodNet,
                'ranking_score' => $rankingScore,
            ];
        });

        return $paginator;
    }

    public function overview(?array $semesterIds = null, ?array $restrictUserIds = null): array
    {
        $records = ScoreRecord::query()
            ->whereNull('revoked_at')
            ->when($semesterIds !== null, fn ($q) => $q->whereIn('semester_id', $semesterIds))
            ->when($restrictUserIds !== null, fn ($q) => $q->whereIn('user_id', $restrictUserIds));

        $scopeGroups = function ($query) use ($restrictUserIds) {
            if ($restrictUserIds === null) {
                return $query;
            }

            if ($restrictUserIds === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('members')
                ->whereDoesntHave('members.student', fn ($student) => $student->whereNotIn('id', $restrictUserIds));
        };

        $personalPending = ScoreApplication::query()
            ->where('status', 'pending')
            ->when($restrictUserIds !== null, fn ($q) => $q->whereIn('user_id', $restrictUserIds))
            ->count();

        $groupPending = $scopeGroups(GroupApplication::query()->where('status', 'pending'))->count();

        $personalApproved = ScoreApplication::query()
            ->where('status', 'approved')
            ->when($restrictUserIds !== null, fn ($q) => $q->whereIn('user_id', $restrictUserIds))
            ->count();

        $groupApproved = $scopeGroups(GroupApplication::query()->where('status', 'approved'))->count();

        $studentQuery = User::query()->where('role', 'student')->where('is_active', true)
            ->when($restrictUserIds !== null, fn ($q) => $q->whereIn('id', $restrictUserIds));

        return [
            'student_count' => $studentQuery->count(),
            'pending_applications' => $personalPending + $groupPending,
            'pending_appeals' => Appeal::query()
                ->where('status', 'pending')
                ->when($restrictUserIds !== null, fn ($q) => $q->whereIn('user_id', $restrictUserIds))
                ->count(),
            'approved_applications' => $personalApproved + $groupApproved,
            'record_count' => (clone $records)->count(),
            'add_total' => (float) (clone $records)->where('score', '>', 0)->sum('score'),
            'deduct_total' => abs((float) (clone $records)->where('score', '<', 0)->sum('score')),
        ];
    }

    public function audit(User $actor, string $action, $target = null, array $meta = []): void
    {
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->id,
            'meta' => $meta,
            'ip' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}
