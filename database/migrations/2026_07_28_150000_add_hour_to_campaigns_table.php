<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hour: where this tale stands on the day/night wheel, and how far into
 * that phase it has come.
 *
 * Campaign state rather than scene state on purpose. The air is fixed when a
 * scene is dressed and holds for that ground's life; the light keeps moving
 * whether the tale changes rooms or not, so it belongs to the tale.
 *
 * Every existing campaign wakes at `day`, which emits nothing anywhere — so a
 * save from before the wheel existed keeps playing exactly the numbers it
 * played yesterday until the wheel first turns under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('hour_phase')->default('day');
            $table->unsignedInteger('hour_progress')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['hour_phase', 'hour_progress']);
        });
    }
};
