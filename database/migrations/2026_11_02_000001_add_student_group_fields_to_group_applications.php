<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_applications', function (Blueprint $table) {
            $table->string('origin', 20)->default('teacher');
            $table->unsignedBigInteger('initiator_id')->nullable();
            $table->date('applied_on')->nullable();

            $table->index(['origin', 'status']);
            $table->index('initiator_id');
        });
    }

    public function down(): void
    {
        Schema::table('group_applications', function (Blueprint $table) {
            $table->dropIndex(['origin', 'status']);
            $table->dropIndex(['initiator_id']);
            $table->dropColumn(['origin', 'initiator_id', 'applied_on']);
        });
    }
};
