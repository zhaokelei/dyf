<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_application_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('score_application_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('name');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('score_applications')
            ->whereNotNull('evidence_path')
            ->orderBy('id')
            ->get(['id', 'evidence_path', 'evidence_name', 'created_at', 'updated_at'])
            ->each(function ($application) {
                DB::table('score_application_evidences')->insert([
                    'score_application_id' => $application->id,
                    'path' => $application->evidence_path,
                    'name' => $application->evidence_name ?: '证明材料',
                    'sort_order' => 0,
                    'created_at' => $application->created_at,
                    'updated_at' => $application->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_application_evidences');
    }
};
