<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appeal extends Model
{
    protected $fillable = [
        'score_record_id',
        'user_id',
        'reason',
        'evidence_path',
        'evidence_name',
        'status',
        'reviewer_id',
        'review_comment',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function scoreRecord()
    {
        return $this->belongsTo(ScoreRecord::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'approved' => '申诉通过',
            'rejected' => '申诉驳回',
            default => '待处理',
        };
    }
}
