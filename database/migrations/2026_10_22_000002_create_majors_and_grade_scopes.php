<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('majors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained('colleges')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['college_id', 'name']);
            $table->index('college_id');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('major_id')->nullable()->after('college_id')->constrained('majors')->nullOnDelete();
            $table->index('major_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('major_id')->nullable()->after('college_id')->constrained('majors')->nullOnDelete();
            $table->index('major_id');
        });

        Schema::create('grade_admin_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('major_id')->constrained('majors')->cascadeOnDelete();
            $table->string('grade', 20);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'college_id', 'major_id', 'grade'], 'uniq_user_grade_scope');
            $table->index(['college_id', 'major_id', 'grade'], 'idx_grade_scope_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_admin_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('major_id');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('major_id');
        });

        Schema::dropIfExists('majors');
    }
};
