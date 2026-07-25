<?php

use App\Game\WorldFlavor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The land a tale is set in, rolled by the engine at creation and fixed for
 * the campaign's life (see App\Game\WorldFlavor).
 *
 * Campaigns that predate the roll are backfilled to the harbor city rather
 * than rolled: their ground is already standing and was built from the harbor
 * seed lineage, so relocating them would put the narrator in a different
 * country from the scene. Only new tales roll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('world_flavor')->nullable()->after('tone');
        });

        DB::table('campaigns')->whereNull('world_flavor')->update(['world_flavor' => WorldFlavor::DEFAULT]);
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('world_flavor');
        });
    }
};
