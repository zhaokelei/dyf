<?php

namespace App\Support;

use App\Models\GradeAdminScope;
use App\Models\GroupApplication;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AccessScope
{
    private array $collegeMemo = [];

    private array $classMemo = [];

    public function isSuper(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function isCollegeAdmin(User $user): bool
    {
        return $user->role === 'college_admin';
    }

    public function isClassAdmin(User $user): bool
    {
        return $user->role === 'class_admin';
    }

    public function isManager(User $user): bool
    {
        return $user->isManager();
    }

    /**
     * @return array<int>|null null means all colleges
     */
    public function collegeIds(User $user): ?array
    {
        if ($this->isSuper($user)) {
            return null;
        }

        $key = $user->id;

        if (array_key_exists($key, $this->collegeMemo)) {
            return $this->collegeMemo[$key];
        }

        if ($this->isCollegeAdmin($user)) {
            $ids = DB::table('college_admin_user')
                ->where('user_id', $user->id)
                ->pluck('college_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } elseif ($this->isClassAdmin($user)) {
            $classIds = $this->classIds($user) ?? [];
            $ids = $classIds === []
                ? []
                : SchoolClass::whereIn('id', $classIds)->pluck('college_id')->unique()->map(fn ($id) => (int) $id)->all();
        } else {
            $ids = $user->college_id ? [(int) $user->college_id] : [];
        }

        return $this->collegeMemo[$key] = $ids;
    }

    /**
     * @return array<int>|null null means all classes
     */
    public function classIds(User $user): ?array
    {
        if ($this->isSuper($user)) {
            return null;
        }

        $key = $user->id;

        if (array_key_exists($key, $this->classMemo)) {
            return $this->classMemo[$key];
        }

        if ($this->isClassAdmin($user)) {
            $direct = DB::table('class_admin_user')
                ->where('user_id', $user->id)
                ->pluck('class_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $gradeScopes = GradeAdminScope::query()
                ->where('user_id', $user->id)
                ->get(['college_id', 'major_id', 'grade']);

            $gradeClassIds = [];

            if ($gradeScopes->isNotEmpty()) {
                $gradeClassIds = SchoolClass::query()
                    ->where(function ($query) use ($gradeScopes) {
                        foreach ($gradeScopes as $scope) {
                            $query->orWhere(function ($sub) use ($scope) {
                                $sub->where('college_id', $scope->college_id)
                                    ->where('major_id', $scope->major_id)
                                    ->where('grade', $scope->grade);
                            });
                        }
                    })
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }

            $ids = array_values(array_unique([...$direct, ...$gradeClassIds]));
        } elseif ($this->isCollegeAdmin($user)) {
            $collegeIds = $this->collegeIds($user) ?? [];
            $ids = $collegeIds === []
                ? []
                : SchoolClass::whereIn('college_id', $collegeIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        } else {
            $ids = $user->class_id ? [(int) $user->class_id] : [];
        }

        return $this->classMemo[$key] = $ids;
    }

    public function gradeScopes(User $user): array
    {
        if (! $this->isClassAdmin($user)) {
            return [];
        }

        return GradeAdminScope::query()
            ->with('major:id,name')
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (GradeAdminScope $scope) => [
                'id' => $scope->id,
                'college_id' => $scope->college_id,
                'major_id' => $scope->major_id,
                'major' => $scope->major?->name,
                'grade' => $scope->grade,
                'label' => $scope->major?->name.' '.$scope->grade,
            ])
            ->all();
    }

    public function studentIds(User $user): ?array
    {
        if ($this->isSuper($user)) {
            return null;
        }

        $query = User::query()->where('role', 'student');

        if ($this->isClassAdmin($user)) {
            $query->whereIn('class_id', $this->classIds($user) ?? []);
        } elseif ($this->isCollegeAdmin($user)) {
            $query->whereIn('college_id', $this->collegeIds($user) ?? []);
        } else {
            $query->where('id', $user->id);
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function scopeStudents(Builder $query, User $user): Builder
    {
        $query->where('role', 'student');

        $ids = $this->studentIds($user);

        return $ids === null ? $query : $query->whereIn('id', $ids);
    }

    /**
     * Users a manager may see in user management.
     */
    public function scopeManagedUsers(Builder $query, User $user): Builder
    {
        if ($this->isSuper($user)) {
            return $query;
        }

        if ($this->isCollegeAdmin($user)) {
            return $query->whereIn('college_id', $this->collegeIds($user) ?? [])
                ->whereIn('role', ['student', 'teacher', 'class_admin']);
        }

        if ($this->isClassAdmin($user)) {
            return $query->whereIn('class_id', $this->classIds($user) ?? [])
                ->where('role', 'student');
        }

        return $query->where('id', $user->id)->whereRaw('1 = 0');
    }

    public function scopePersonalApplications(Builder $query, User $user): Builder
    {
        $ids = $this->studentIds($user);

        return $ids === null ? $query : $query->whereIn('user_id', $ids);
    }

    public function scopeScoreRecords(Builder $query, User $user): Builder
    {
        $ids = $this->studentIds($user);

        return $ids === null ? $query : $query->whereIn('user_id', $ids);
    }

    /**
     * Score records of classmates an officer may review, never their own.
     */
    public function scopeOfficerScoreRecords(Builder $query, User $user): Builder
    {
        $ids = $this->officerStudentIds($user);

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('user_id', $ids);
    }

    public function scopeGroupApplications(Builder $query, User $user): Builder
    {
        if ($this->isSuper($user)) {
            return $query;
        }

        if ($this->isCollegeAdmin($user)) {
            $collegeIds = $this->collegeIds($user) ?? [];

            if ($collegeIds === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('members')
                ->whereDoesntHave('members.student', fn (Builder $student) => $student
                    ->whereNull('college_id')
                    ->orWhereNotIn('college_id', $collegeIds));
        }

        if ($this->isClassAdmin($user)) {
            $classIds = $this->classIds($user) ?? [];

            if ($classIds === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereHas('members')
                ->whereDoesntHave('members.student', fn (Builder $student) => $student
                    ->whereNull('class_id')
                    ->orWhereNotIn('class_id', $classIds));
        }

        return $query->where('teacher_id', $user->id);
    }

    /**
     * Group applications whose members all belong to the officer's classes.
     */
    public function scopeOfficerGroups(Builder $query, User $user): Builder
    {
        $classIds = $this->officerClassIds($user);

        if ($classIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('members')
            ->whereDoesntHave('members.student', fn (Builder $student) => $student
                ->whereNull('class_id')
                ->orWhereNotIn('class_id', $classIds));
    }

    public function scopeAppeals(Builder $query, User $user): Builder
    {
        $ids = $this->studentIds($user);

        return $ids === null ? $query : $query->whereIn('user_id', $ids);
    }

    public function canAccessCollege(User $user, ?int $collegeId): bool
    {
        $ids = $this->collegeIds($user);

        return $ids === null || ($collegeId !== null && in_array($collegeId, $ids, true));
    }

    public function canAccessClass(User $user, ?int $classId): bool
    {
        if ($classId === null) {
            return false;
        }

        if ($this->isSuper($user)) {
            return true;
        }

        if ($this->isClassAdmin($user)) {
            return in_array($classId, $this->classIds($user) ?? [], true);
        }

        if ($this->isCollegeAdmin($user)) {
            return $this->canAccessCollege($user, SchoolClass::whereKey($classId)->value('college_id'));
        }

        return $user->class_id === $classId;
    }

    public function canAccessStudent(User $user, User $student): bool
    {
        if ($this->isSuper($user)) {
            return true;
        }

        if ($this->isClassAdmin($user)) {
            return $student->role === 'student'
                && in_array($student->class_id, $this->classIds($user) ?? [], true);
        }

        if ($this->isCollegeAdmin($user)) {
            return in_array($student->college_id, $this->collegeIds($user) ?? [], true);
        }

        return $user->id === $student->id;
    }

    public function canCreateRole(User $actor, string $role): bool
    {
        if ($this->isSuper($actor)) {
            return in_array($role, ['student', 'teacher', 'admin'], true);
        }

        if ($this->isCollegeAdmin($actor)) {
            return in_array($role, ['student', 'teacher'], true);
        }

        return false;
    }

    public function canManageUser(User $actor, User $target): bool
    {
        if ($this->isSuper($actor)) {
            return true;
        }

        if (in_array($target->role, ['admin', 'college_admin'], true)) {
            return false;
        }

        if ($this->isCollegeAdmin($actor)) {
            return $target->role !== 'admin'
                && in_array($target->college_id, $this->collegeIds($actor) ?? [], true);
        }

        if ($this->isClassAdmin($actor)) {
            return $target->role === 'student'
                && in_array($target->class_id, $this->classIds($actor) ?? [], true);
        }

        return false;
    }

    public function canAccessGroup(User $user, GroupApplication $application): bool
    {
        if ($this->isSuper($user)) {
            return true;
        }

        if ($this->isCollegeAdmin($user)) {
            $collegeIds = $this->collegeIds($user) ?? [];

            if ($collegeIds === []) {
                return false;
            }

            return $application->members()->exists()
                && ! $application->members()
                    ->whereHas('student', fn ($q) => $q
                        ->whereNull('college_id')
                        ->orWhereNotIn('college_id', $collegeIds))
                    ->exists();
        }

        if ($this->isClassAdmin($user)) {
            $classIds = $this->classIds($user) ?? [];

            if ($classIds === []) {
                return false;
            }

            return $application->members()->exists()
                && ! $application->members()
                    ->whereHas('student', fn ($q) => $q
                        ->whereNull('class_id')
                        ->orWhereNotIn('class_id', $classIds))
                    ->exists();
        }

        return $application->teacher_id === $user->id;
    }

    public function canReviewGroup(User $user, GroupApplication $application): bool
    {
        if ($this->isSuper($user)) {
            return true;
        }

        return $this->isCollegeAdmin($user) && $this->canAccessGroup($user, $application);
    }

    public function canViewGroup(User $user, GroupApplication $application): bool
    {
        if ($this->canAccessGroup($user, $application)) {
            return true;
        }

        if ($this->canViewOfficerGroup($user, $application)) {
            return true;
        }

        if (! $user->isStudent()) {
            return false;
        }

        return $application->initiator_id === $user->id
            || $application->teacher_id === $user->id
            || $application->members()->where('student_id', $user->id)->exists();
    }

    public function canAssignClassAdmin(User $user, SchoolClass $class): bool
    {
        return $this->isSuper($user) || ($this->isCollegeAdmin($user) && $this->canAccessCollege($user, $class->college_id));
    }

    /**
     * Classes where the student is appointed as a class officer.
     *
     * @return array<int>
     */
    public function officerClassIds(User $user): array
    {
        if (! $user->isStudent()) {
            return [];
        }

        $ids = DB::table('class_officer_user')
            ->where('user_id', $user->id)
            ->pluck('class_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $ids;
    }

    public function isClassOfficer(User $user): bool
    {
        return $this->officerClassIds($user) !== [];
    }

    /**
     * Students an officer may review: classmates in appointed classes, never themselves.
     *
     * @return array<int>
     */
    public function officerStudentIds(User $user): array
    {
        $classIds = $this->officerClassIds($user);

        if ($classIds === []) {
            return [];
        }

        return User::query()
            ->where('role', 'student')
            ->whereIn('class_id', $classIds)
            ->whereKeyNot($user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function scopeOfficerApplications(Builder $query, User $user): Builder
    {
        $ids = $this->officerStudentIds($user);

        return $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('user_id', $ids);
    }

    public function canViewOfficerGroup(User $user, GroupApplication $application): bool
    {
        $classIds = $this->officerClassIds($user);

        if ($classIds === []) {
            return false;
        }

        return $application->members()->exists()
            && ! $application->members()
                ->whereHas('student', fn (Builder $q) => $q
                    ->whereNull('class_id')
                    ->orWhereNotIn('class_id', $classIds))
                ->exists();
    }

    public function canOfficerReviewStudent(User $user, User $student): bool
    {
        return $student->role === 'student'
            && $student->id !== $user->id
            && $student->class_id !== null
            && in_array((int) $student->class_id, $this->officerClassIds($user), true);
    }

    public function canAssignClassOfficer(User $user, SchoolClass $class): bool
    {
        return $this->isSuper($user) || ($this->isCollegeAdmin($user) && $this->canAccessCollege($user, $class->college_id));
    }

    public function restriction(User $user): array
    {
        return [
            'restrict_college_ids' => $this->isSuper($user) ? null : $this->collegeIds($user),
            'restrict_class_ids' => $this->isSuper($user) ? null : $this->classIds($user),
        ];
    }
}
