<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colleges', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 50)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained('colleges')->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('grade', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['college_id', 'name', 'grade']);
            $table->index('college_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('college_id')->nullable()->after('college')->constrained('colleges')->nullOnDelete();
            $table->foreignId('class_id')->nullable()->after('class_name')->constrained('classes')->nullOnDelete();
            $table->foreignId('teacher_college_id')->nullable()->after('teacher_college')->constrained('colleges')->nullOnDelete();
        });

        Schema::create('college_admin_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'college_id']);
        });

        Schema::create('class_admin_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_admin_user');
        Schema::dropIfExists('college_admin_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_college_id');
            $table->dropConstrainedForeignId('class_id');
            $table->dropConstrainedForeignId('college_id');
        });

        Schema::dropIfExists('classes');
        Schema::dropIfExists('colleges');
    }
};
