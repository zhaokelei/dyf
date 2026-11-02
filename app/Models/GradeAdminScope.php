<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeAdminScope extends Model
{
    protected $table = 'grade_admin_user';

    protected $fillable = [
        'user_id',
        'college_id',
        'major_id',
        'grade',
        'granted_by',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function college()
    {
        return $this->belongsTo(College::class);
    }

    public function major()
    {
        return $this->belongsTo(Major::class);
    }

    public function label(): string
    {
        return $this->major?->name.' '.$this->grade;
    }
}
