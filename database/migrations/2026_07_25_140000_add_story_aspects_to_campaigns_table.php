<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The story axes a player sets at creation: the genre the world wears, what
 * drives the tale, and how much magic or machinery it runs on (see
 * App\Game\StoryAspects). Each holds either a catalog key or the player's own
 * typed words — narration colour only, never an input to any mechanic.
 *
 * Null throughout for tales that predate them, which simply keeps the prompt
 * blocks those campaigns already had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('genre')->nullable()->after('world_flavor');
            $table->string('drive')->nullable()->after('genre');
            $table->string('tech_level')->nullable()->after('drive');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['genre', 'drive', 'tech_level']);
        });
    }
};
