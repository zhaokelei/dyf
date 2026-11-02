<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_application_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_application_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['group_application_id', 'sort_order'], 'gae_application_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_application_evidences');
    }
};
