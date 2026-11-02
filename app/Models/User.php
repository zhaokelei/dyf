<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'name',
        'email',
        'password',
        'role',
        'student_id',
        'employee_id',
        'class_name',
        'class_id',
        'college',
        'college_id',
        'major_id',
        'grade',
        'teacher_college',
        'teacher_college_id',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function applications()
    {
        return $this->hasMany(ScoreApplication::class);
    }

    public function scoreRecords()
    {
        return $this->hasMany(ScoreRecord::class);
    }

    public function groupApplications()
    {
        return $this->hasMany(GroupApplication::class, 'teacher_id');
    }

    public function appeals()
    {
        return $this->hasMany(Appeal::class);
    }

    public function college()
    {
        return $this->belongsTo(College::class);
    }

    public function major()
    {
        return $this->belongsTo(Major::class);
    }

    public function gradeAdminScopes()
    {
        return $this->hasMany(GradeAdminScope::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function teacherCollege()
    {
        return $this->belongsTo(College::class, 'teacher_college_id');
    }

    public function managedColleges()
    {
        return $this->belongsToMany(College::class, 'college_admin_user')
            ->withPivot('granted_by')
            ->withTimestamps();
    }

    public function managedClasses()
    {
        return $this->belongsToMany(SchoolClass::class, 'class_admin_user', 'user_id', 'class_id')
            ->withPivot('granted_by')
            ->withTimestamps();
    }

    public function officerClasses()
    {
        return $this->belongsToMany(SchoolClass::class, 'class_officer_user', 'user_id', 'class_id')
            ->withPivot(['title', 'granted_by'])
            ->withTimestamps();
    }

    public function isClassOfficer(): bool
    {
        return $this->isStudent() && $this->officerClasses()->exists();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isTeacher(): bool
    {
        return $this->role === 'teacher';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    public function isCollegeAdmin(): bool
    {
        return $this->role === 'college_admin';
    }

    public function isClassAdmin(): bool
    {
        return $this->role === 'class_admin';
    }

    public function isManager(): bool
    {
        return in_array($this->role, ['admin', 'college_admin', 'class_admin'], true);
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            'admin' => '管理员',
            'college_admin' => '学院管理员',
            'class_admin' => '辅导员',
            'teacher' => '教师',
            default => '学生',
        };
    }
}
