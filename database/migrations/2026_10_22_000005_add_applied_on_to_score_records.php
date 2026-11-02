<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('score_records', function (Blueprint $table) {
            $table->date('applied_on')->nullable()->after('score')->index();
        });

        DB::table('score_records')
            ->whereNull('applied_on')
            ->update(['applied_on' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        Schema::table('score_records', function (Blueprint $table) {
            $table->dropIndex(['applied_on']);
            $table->dropColumn('applied_on');
        });
    }
};
