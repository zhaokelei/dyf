<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AppealController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TeacherRegisterController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GroupApplicationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ScoreController;
use App\Http\Controllers\SemesterController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
    Route::get('/register', [RegisterController::class, 'choice'])->name('register');
    Route::get('/register/student', [RegisterController::class, 'create'])->name('register.student');
    Route::post('/register/student', [RegisterController::class, 'store'])->name('register.student.store');
    Route::get('/register/teacher', [TeacherRegisterController::class, 'create'])->name('register.teacher');
    Route::post('/register/teacher', [TeacherRegisterController::class, 'store'])->name('register.teacher.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');

    Route::get('/applications/{application}/evidence', [ApplicationController::class, 'downloadEvidence'])->name('applications.evidence');
    Route::get('/applications/{application}/evidence/preview', [ApplicationController::class, 'previewEvidence'])->name('applications.evidence.preview');
    Route::get('/applications/{application}/evidences/{evidence}/preview', [ApplicationController::class, 'previewEvidenceFile'])->name('applications.evidences.preview');
    Route::get('/applications/{application}/evidences/{evidence}/download', [ApplicationController::class, 'downloadEvidenceFile'])->name('applications.evidences.download');
    Route::get('/group-applications/{groupApplication}/evidences/{evidence}/preview', [GroupApplicationController::class, 'previewGroupEvidence'])->name('group-applications.evidences.preview');
    Route::get('/group-applications/{groupApplication}/evidences/{evidence}/download', [GroupApplicationController::class, 'downloadGroupEvidence'])->name('group-applications.evidences.download');
    Route::get('/group-applications/{groupApplication}/evidence', [GroupApplicationController::class, 'downloadEvidence'])->name('group-applications.evidence.download');
    Route::get('/group-applications/{groupApplication}/evidence/preview', [GroupApplicationController::class, 'previewLegacyEvidence'])->name('group-applications.evidence.preview');
    Route::get('/appeals/{appeal}/evidence', [AppealController::class, 'downloadEvidence'])->name('appeals.evidence');

    Route::get('/profile/password', [ProfileController::class, 'editPassword'])->name('profile.password');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::middleware('role:student')->group(function () {
        Route::get('/applications', [ApplicationController::class, 'index'])->name('applications.index');
        Route::get('/applications/create', [ApplicationController::class, 'create'])->name('applications.create');
        Route::post('/applications', [ApplicationController::class, 'store'])->name('applications.store');
        Route::get('/applications/{application}/edit', [ApplicationController::class, 'edit'])->name('applications.edit');
        Route::put('/applications/{application}', [ApplicationController::class, 'update'])->name('applications.update');
        Route::post('/applications/{application}/withdraw', [ApplicationController::class, 'withdraw'])->name('applications.withdraw');

        Route::get('/applications/group/create', [GroupApplicationController::class, 'createStudent'])->name('applications.group.create');
        Route::post('/applications/group', [GroupApplicationController::class, 'storeStudent'])->name('applications.group.store');
        Route::get('/applications/group/{groupApplication}/edit', [GroupApplicationController::class, 'editStudent'])->name('applications.group.edit');
        Route::put('/applications/group/{groupApplication}', [GroupApplicationController::class, 'updateStudent'])->name('applications.group.update');
        Route::get('/applications/group/{groupApplication}', [GroupApplicationController::class, 'showStudent'])->name('applications.group.show');
        Route::post('/applications/group/{groupApplication}/withdraw', [GroupApplicationController::class, 'withdrawStudent'])->name('applications.group.withdraw');

        Route::get('/my-scores', [ScoreController::class, 'my'])->name('scores.my');
        Route::get('/my-scores/export', [ApplicationController::class, 'export'])->name('scores.my.export');

        Route::get('/appeals', [AppealController::class, 'index'])->name('appeals.index');
        Route::post('/appeals', [AppealController::class, 'store'])->name('appeals.store');

        Route::prefix('officer')->group(function () {
            Route::get('/review', [ReviewController::class, 'index'])->name('officer.review.index');
            Route::get('/review/groups/{groupApplication}', [ReviewController::class, 'showGroup'])->name('officer.review.groups.show');
            Route::put('/applications/{application}/approve', [ReviewController::class, 'approvePersonal'])->name('officer.review.approve');
            Route::put('/applications/{application}/reject', [ReviewController::class, 'rejectPersonal'])->name('officer.review.reject');
            Route::put('/applications/{application}/cancel', [ReviewController::class, 'cancelPersonalApproval'])->name('officer.review.cancel');
            Route::put('/applications/{application}/update', [ReviewController::class, 'updateApprovedPersonal'])->name('officer.review.update');
            Route::get('/review/leaderboard/export', [ReviewController::class, 'exportReviewerLeaderboard'])->name('officer.review.leaderboard.export');
            Route::post('/review/bulk', [ReviewController::class, 'bulk'])->name('officer.review.bulk');
            Route::delete('/applications/{application}', [ReviewController::class, 'destroyPersonal'])->name('officer.review.destroy');
            Route::post('/deductions', [ScoreController::class, 'storeDeduction'])->name('officer.deductions.store');
        });
    });

    Route::middleware('role:teacher')->prefix('teacher')->group(function () {
        Route::get('/group-applications', [GroupApplicationController::class, 'index'])->name('group-applications.index');
        Route::get('/group-applications/create', [GroupApplicationController::class, 'create'])->name('group-applications.create');
        Route::post('/group-applications', [GroupApplicationController::class, 'store'])->name('group-applications.store');
        Route::get('/group-applications/template', [GroupApplicationController::class, 'template'])->name('group-applications.template');
        Route::get('/group-applications/{groupApplication}', [GroupApplicationController::class, 'show'])->name('group-applications.show');
        Route::post('/group-applications/{groupApplication}/withdraw', [GroupApplicationController::class, 'withdraw'])->name('group-applications.withdraw');
        Route::get('/group-applications/{groupApplication}/evidence', [GroupApplicationController::class, 'downloadEvidence'])->name('group-applications.evidence');
    });

    Route::middleware('role:admin,teacher,college_admin,class_admin')->group(function () {
        Route::get('/leaderboard', [ScoreController::class, 'leaderboard'])->name('leaderboard.index');
    });

    Route::middleware('role:admin,college_admin,class_admin')->prefix('admin')->group(function () {
        Route::get('/review', [ReviewController::class, 'index'])->name('review.index');
        Route::get('/review/groups/{groupApplication}', [ReviewController::class, 'showGroup'])->name('review.groups.show');
        Route::put('/applications/{application}/approve', [ReviewController::class, 'approvePersonal'])->name('review.applications.approve');
        Route::put('/applications/{application}/reject', [ReviewController::class, 'rejectPersonal'])->name('review.applications.reject');
        Route::put('/applications/{application}/cancel', [ReviewController::class, 'cancelPersonalApproval'])->name('review.applications.cancel');
        Route::put('/applications/{application}/update', [ReviewController::class, 'updateApprovedPersonal'])->name('review.applications.update');
        Route::get('/review/leaderboard/export', [ReviewController::class, 'exportReviewerLeaderboard'])->name('review.leaderboard.export');
        Route::post('/review/bulk', [ReviewController::class, 'bulk'])->name('review.bulk');
        Route::delete('/applications/{application}', [ReviewController::class, 'destroyPersonal'])->name('review.applications.destroy');
        Route::delete('/group-applications/{groupApplication}', [ReviewController::class, 'destroyGroup'])->name('review.groups.destroy');
        Route::post('/deductions', [ScoreController::class, 'storeDeduction'])->name('admin.deductions.store');

        Route::get('/appeals', [AppealController::class, 'adminIndex'])->name('admin.appeals.index');
        Route::put('/appeals/{appeal}/approve', [AppealController::class, 'approve'])->name('admin.appeals.approve');
        Route::put('/appeals/{appeal}/reject', [AppealController::class, 'reject'])->name('admin.appeals.reject');

        Route::get('/group-applications/{groupApplication}/evidence', [GroupApplicationController::class, 'downloadEvidence'])->name('admin.group-applications.evidence');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

        Route::get('/statistics', [StatisticsController::class, 'index'])->name('statistics.index');
        Route::get('/leaderboard/export', [ScoreController::class, 'exportLeaderboard'])->name('leaderboard.export');

        Route::get('/records', [ScoreController::class, 'records'])->name('records.index');
        Route::post('/records', [ScoreController::class, 'storeRecord'])->name('records.store');
        Route::put('/records/{record}', [ScoreController::class, 'updateRecord'])->name('records.update');
        Route::delete('/records/{record}', [ScoreController::class, 'destroyRecord'])->name('records.destroy');
        Route::delete('/records/{record}/force', [ScoreController::class, 'forceDestroyRecord'])->name('records.force-destroy');
        Route::get('/students/{student}', [ScoreController::class, 'studentDetail'])->name('students.show');
    });

    Route::middleware('role:admin,college_admin')->prefix('admin')->group(function () {
        Route::put('/group-applications/{groupApplication}/approve', [ReviewController::class, 'approveGroup'])->name('review.groups.approve');
        Route::put('/group-applications/{groupApplication}/reject', [ReviewController::class, 'rejectGroup'])->name('review.groups.reject');
        Route::put('/group-applications/{groupApplication}/cancel', [ReviewController::class, 'cancelGroupApproval'])->name('review.groups.cancel');

        Route::post('/users', [UserController::class, 'store'])->name('users.store');

        Route::get('/organization', [OrganizationController::class, 'index'])->name('organization.index');
        Route::post('/organization/majors', [OrganizationController::class, 'storeMajor'])->name('organization.majors.store');
        Route::put('/organization/majors/{major}', [OrganizationController::class, 'updateMajor'])->name('organization.majors.update');
        Route::delete('/organization/majors/{major}', [OrganizationController::class, 'destroyMajor'])->name('organization.majors.destroy');
        Route::post('/organization/classes', [OrganizationController::class, 'storeClass'])->name('organization.classes.store');
        Route::put('/organization/classes/{schoolClass}', [OrganizationController::class, 'updateClass'])->name('organization.classes.update');
        Route::delete('/organization/classes/{schoolClass}', [OrganizationController::class, 'destroyClass'])->name('organization.classes.destroy');
        Route::post('/organization/grade-admins', [OrganizationController::class, 'assignGradeAdmin'])->name('organization.grade-admins.store');
        Route::delete('/organization/grade-admins/{gradeScope}', [OrganizationController::class, 'revokeGradeAdmin'])->name('organization.grade-admins.destroy');
        Route::post('/organization/class-admins', [OrganizationController::class, 'assignClassAdmin'])->name('organization.class-admins.store');
        Route::post('/organization/class-admins/sync', [OrganizationController::class, 'syncClassAdmins'])->name('organization.class-admins.sync');
        Route::delete('/organization/class-admins/{user}/{schoolClass}', [OrganizationController::class, 'revokeClassAdmin'])->name('organization.class-admins.destroy');
        Route::post('/organization/class-officers', [OrganizationController::class, 'assignClassOfficer'])->name('organization.class-officers.store');
        Route::delete('/organization/class-officers/{user}/{schoolClass}', [OrganizationController::class, 'revokeClassOfficer'])->name('organization.class-officers.destroy');
    });

    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/users/import/template', [UserController::class, 'importTemplate'])->name('users.import.template');
        Route::post('/users/import', [UserController::class, 'import'])->name('users.import');

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::get('/semesters', [SemesterController::class, 'index'])->name('semesters.index');
        Route::post('/semesters', [SemesterController::class, 'store'])->name('semesters.store');
        Route::post('/semesters/{semester}/current', [SemesterController::class, 'setCurrent'])->name('semesters.current');
        Route::delete('/semesters/{semester}', [SemesterController::class, 'destroy'])->name('semesters.destroy');

        Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');

        Route::post('/organization/colleges', [OrganizationController::class, 'storeCollege'])->name('organization.colleges.store');
        Route::put('/organization/colleges/{college}', [OrganizationController::class, 'updateCollege'])->name('organization.colleges.update');
        Route::delete('/organization/colleges/{college}', [OrganizationController::class, 'destroyCollege'])->name('organization.colleges.destroy');
        Route::post('/organization/college-admins', [OrganizationController::class, 'assignCollegeAdmin'])->name('organization.college-admins.store');
        Route::delete('/organization/college-admins/{user}/{college}', [OrganizationController::class, 'revokeCollegeAdmin'])->name('organization.college-admins.destroy');
    });
});
