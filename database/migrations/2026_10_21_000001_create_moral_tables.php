<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('type', 10)->default('add');
            $table->text('description')->nullable();
            $table->integer('default_score')->nullable();
            $table->integer('max_score')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });

        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 20);
            $table->unsignedTinyInteger('term');
            $table->string('name', 50)->unique();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['academic_year', 'term']);
            $table->index('is_current');
        });

        Schema::create('score_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('score_categories')->restrictOnDelete();
            $table->foreignId('semester_id')->constrained('semesters')->restrictOnDelete();
            $table->string('description', 500);
            $table->integer('score');
            $table->integer('final_score')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'semester_id']);
        });

        Schema::create('group_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('score_categories')->restrictOnDelete();
            $table->foreignId('semester_id')->constrained('semesters')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['teacher_id', 'semester_id']);
        });

        Schema::create('group_application_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->integer('score');
            $table->integer('final_score')->nullable();
            $table->timestamps();

            $table->unique(['group_application_id', 'student_id'], 'uniq_group_student');
            $table->index('student_id');
        });

        Schema::create('score_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('score_categories')->nullOnDelete();
            $table->foreignId('semester_id')->constrained('semesters')->restrictOnDelete();
            $table->integer('score');
            $table->string('source_type', 20)->default('manual');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'semester_id']);
            $table->index(['category_id', 'semester_id']);
            $table->index(['source_type', 'source_id']);
            $table->index(['revoked_at', 'created_at']);
        });

        Schema::create('appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('score_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('score_record_id');
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->longText('content');
            $table->boolean('is_important')->default(false);
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('published_at');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50);
            $table->string('target_type', 50)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['action', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('appeals');
        Schema::dropIfExists('score_records');
        Schema::dropIfExists('group_application_members');
        Schema::dropIfExists('group_applications');
        Schema::dropIfExists('score_applications');
        Schema::dropIfExists('semesters');
        Schema::dropIfExists('score_categories');
    }
};
