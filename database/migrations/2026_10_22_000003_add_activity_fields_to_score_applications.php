<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('score_applications', function (Blueprint $table) {
            $table->string('activity_name', 200)->nullable()->after('semester_id');
            $table->date('applied_on')->nullable()->after('activity_name');
        });
    }

    public function down(): void
    {
        Schema::table('score_applications', function (Blueprint $table) {
            $table->dropColumn(['activity_name', 'applied_on']);
        });
    }
};
