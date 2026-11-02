<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupApplicationMember extends Model
{
    protected $fillable = [
        'group_application_id',
        'student_id',
        'score',
        'final_score',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'final_score' => 'float',
        ];
    }

    public function groupApplication()
    {
        return $this->belongsTo(GroupApplication::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
