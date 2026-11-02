<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('score_categories', function (Blueprint $table) {
            $table->decimal('default_score', 8, 1)->nullable()->change();
            $table->decimal('max_score', 8, 1)->nullable()->change();
        });

        Schema::table('score_applications', function (Blueprint $table) {
            $table->decimal('score', 8, 1)->change();
            $table->decimal('final_score', 8, 1)->nullable()->change();
        });

        Schema::table('group_application_members', function (Blueprint $table) {
            $table->decimal('score', 8, 1)->change();
            $table->decimal('final_score', 8, 1)->nullable()->change();
        });

        Schema::table('score_records', function (Blueprint $table) {
            $table->decimal('score', 8, 1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('score_categories', function (Blueprint $table) {
            $table->integer('default_score')->nullable()->change();
            $table->integer('max_score')->nullable()->change();
        });

        Schema::table('score_applications', function (Blueprint $table) {
            $table->integer('score')->change();
            $table->integer('final_score')->nullable()->change();
        });

        Schema::table('group_application_members', function (Blueprint $table) {
            $table->integer('score')->change();
            $table->integer('final_score')->nullable()->change();
        });

        Schema::table('score_records', function (Blueprint $table) {
            $table->integer('score')->change();
        });
    }
};
