<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ending this tale is walking toward, if it has started walking toward one.
 *
 * Campaign state, because an ending is the tale's and not the ground's: the
 * player may cross three zones between arming it and taking it up, and a
 * scene-scoped record would die at the first border.
 *
 * `{state: armed|underway|complete, signals, grudge_name?, clock_id?}`, and
 * engine-written from end to end — nothing a player submits ever reaches it.
 * Null on every campaign that has not ripened, which is every campaign that
 * exists today: a save from before the finale existed keeps playing exactly as
 * it played yesterday until its own chapters and its own debts arm it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->json('finale')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('finale');
        });
    }
};
