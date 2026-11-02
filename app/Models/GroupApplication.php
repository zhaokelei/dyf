<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupApplication extends Model
{
    protected $fillable = [
        'teacher_id',
        'origin',
        'initiator_id',
        'category_id',
        'semester_id',
        'title',
        'description',
        'applied_on',
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
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function isStudentOrigin(): bool
    {
        return $this->origin === 'student';
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

    public function members()
    {
        return $this->hasMany(GroupApplicationMember::class);
    }

    public function evidences()
    {
        return $this->hasMany(GroupApplicationEvidence::class)->orderBy('sort_order')->orderBy('id');
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
