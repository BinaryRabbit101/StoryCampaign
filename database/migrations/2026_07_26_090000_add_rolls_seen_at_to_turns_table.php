<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dice table is shown once. A resolved turn whose rolls the player has
 * not yet watched fall gates the chapter behind it; stamping this marks the
 * table cleared, so the same rolls never replay on the next device or the
 * next reload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turns', function (Blueprint $table) {
            $table->timestamp('rolls_seen_at')->nullable()->after('resolved_at');
        });

        // Every turn resolved before the dice table existed has already been
        // read as prose — none of them should ambush the player with a grid
        // of dice for a chapter they finished days ago.
        Schema::getConnection()->table('turns')
            ->whereNotNull('resolved_at')
            ->update(['rolls_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('turns', function (Blueprint $table) {
            $table->dropColumn('rolls_seen_at');
        });
    }
};
