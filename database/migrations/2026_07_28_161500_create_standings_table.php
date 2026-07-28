<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a place remembers about the player. Grudges made INDIVIDUALS
        // remember; this is the communal mirror of one — campaign-scoped, so a
        // zone the shared world lends to two tales holds two separate opinions,
        // and one row per zone the tale has ever done anything in.
        Schema::create('standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            // Clamped by config on the way in — the column is only the store.
            $table->integer('score')->default(0);
            // Append-only: {turn_id, event, shift}. The events are the closed
            // table in App\Game\Engine\Standings and nothing else ever.
            $table->json('history');
            $table->timestamps();

            $table->unique(['campaign_id', 'zone_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standings');
    }
};
