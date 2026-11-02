<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('score_categories', function (Blueprint $table) {
            $table->string('code', 30)->nullable()->unique()->after('type');
        });

        $now = now();
        $volunteerId = DB::table('score_categories')->where('name', '志愿服务')->value('id');

        if ($volunteerId) {
            DB::table('score_categories')->where('id', $volunteerId)->update([
                'code' => 'volunteer',
                'updated_at' => $now,
            ]);
        } else {
            DB::table('score_categories')->insert([
                'name' => '志愿服务',
                'code' => 'volunteer',
                'type' => 'add',
                'description' => '志愿服务加分',
                'sort_order' => 30,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('score_categories', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
