<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScoreApplication extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'semester_id',
        'activity_name',
        'applied_on',
        'description',
        'score',
        'final_score',
        'status',
        'reviewer_id',
        'review_comment',
        'reviewed_at',
        'evidence_path',
        'evidence_name',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'applied_on' => 'date',
            'score' => 'float',
            'final_score' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(ScoreCategory::class, 'category_id');
    }

    public function semester()
    {
        return $this->belongsTo(Semester::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function evidences()
    {
        return $this->hasMany(ScoreApplicationEvidence::class)->orderBy('sort_order')->orderBy('id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'approved' => '已通过',
            'rejected' => '已驳回',
            'withdrawn' => '已撤回',
            default => '待审核',
        };
    }

    public function canWithdraw(): bool
    {
        return in_array($this->status, ['pending', 'rejected'], true);
    }
}
