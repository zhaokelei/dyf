<?php

namespace App\Services;

use App\Models\Appeal;
use App\Models\ScoreRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppealService
{
    public function __construct(private readonly ScoreService $scores)
    {
    }

    public function submit(
        User $student,
        ScoreRecord $record,
        string $reason,
        ?string $evidencePath,
        ?string $evidenceName
    ): Appeal {
        if ($record->user_id !== $student->id) {
            throw ValidationException::withMessages([
                'score_record_id' => '只能对自己的扣分记录发起申诉。',
            ]);
        }

        if ($record->score >= 0) {
            throw ValidationException::withMessages([
                'score_record_id' => '只有扣分记录可以申诉。',
            ]);
        }

        if ($record->isRevoked()) {
            throw ValidationException::withMessages([
                'score_record_id' => '该扣分记录已撤销，无需重复申诉。',
            ]);
        }

        $exists = $record->appeals()
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'score_record_id' => '该扣分记录已有待处理或已通过的申诉。',
            ]);
        }

        $appeal = Appeal::create([
            'score_record_id' => $record->id,
            'user_id' => $student->id,
            'reason' => $reason,
            'evidence_path' => $evidencePath,
            'evidence_name' => $evidenceName,
            'status' => 'pending',
        ]);

        $this->scores->audit($student, 'submit_appeal', $appeal, [
            'score_record_id' => $record->id,
            'score' => $record->score,
        ]);

        return $appeal;
    }

    public function approve(Appeal $appeal, User $reviewer, ?string $comment = null): void
    {
        DB::transaction(function () use ($appeal, $reviewer, $comment) {
            $appeal->refresh();

            if ($appeal->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => '该申诉已处理，不能重复审核。',
                ]);
            }

            $record = ScoreRecord::lockForUpdate()->find($appeal->score_record_id);

            if (! $record) {
                throw ValidationException::withMessages([
                    'status' => '关联的扣分记录不存在。',
                ]);
            }

            if ($record->isRevoked()) {
                throw ValidationException::withMessages([
                    'status' => '该扣分记录已被撤销。',
                ]);
            }

            $appeal->update([
                'status' => 'approved',
                'reviewer_id' => $reviewer->id,
                'review_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            $record->update([
                'revoked_at' => now(),
                'revoked_by' => $reviewer->id,
                'revoke_reason' => '扣分申诉通过'.($comment ? "：{$comment}" : ''),
            ]);

            $this->scores->audit($reviewer, 'approve_appeal', $appeal, [
                'score_record_id' => $record->id,
                'revoked_score' => $record->score,
            ]);
        });
    }

    public function reject(Appeal $appeal, User $reviewer, string $comment): void
    {
        DB::transaction(function () use ($appeal, $reviewer, $comment) {
            $appeal->refresh();

            if ($appeal->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => '该申诉已处理，不能重复审核。',
                ]);
            }

            $appeal->update([
                'status' => 'rejected',
                'reviewer_id' => $reviewer->id,
                'review_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            $this->scores->audit($reviewer, 'reject_appeal', $appeal, [
                'score_record_id' => $appeal->score_record_id,
            ]);
        });
    }
}
