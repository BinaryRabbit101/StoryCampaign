<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more optional stage settings, both narration colour only.
 *
 * `setting` is the player's own words for the land, for the tale that has a
 * place in mind the catalog does not hold. It stands BESIDE `world_flavor`,
 * never instead of it: the rolled flavor still supplies the engine's
 * cold-forge kit, so an offline tale can still be built, while the player's
 * words are what every prompt is told the world is.
 *
 * `opening` is where the first scene should find the character — the moment
 * the tale starts on, handed to the stage builder and the prologue.
 *
 * Null throughout for tales that predate them, which leaves those campaigns
 * exactly the prompt blocks they already had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('setting', 200)->nullable()->after('world_flavor');
            $table->text('opening')->nullable()->after('premise');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['setting', 'opening']);
        });
    }
};
