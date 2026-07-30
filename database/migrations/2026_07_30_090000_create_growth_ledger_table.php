<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the tale has taught, and what the sheet spent of it.
        //
        // Creation is priced to the point; growth was not priced at all — the
        // interview wrote whatever Claude marked granted. This is the missing
        // currency, and it is a LEDGER for the same reason grudges and
        // standings are: the balance is derived by summing it, never stored,
        // so there is no second copy of the number to drift away from the
        // rows that justify it.
        //
        // Append-only. An earn is written by the engine the instant a turn
        // fixes the moment that paid it; a spend is written when the growth
        // interview actually changes the sheet. Nothing is ever edited.
        Schema::create('growth_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            // The sheet the points belong to. A hero carried into a new tale
            // starts a new ledger — a new tale earns its own.
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            // Where in the tale it happened. Nullable like the shelf's, and
            // for the same reason: a campaign teardown drops turns out from
            // under the ledger, and a row outliving its citation is a better
            // failure than a delete that cascades into the accounts. A spend
            // between turns simply has none.
            $table->foreignId('turn_id')->nullable()->constrained()->nullOnDelete();
            // 'earn' or 'spend'. Both are positive point counts; the kind is
            // the sign, so no row is ever ambiguous about which way it ran.
            $table->string('kind');
            $table->integer('points');
            // The closed earn table in App\Services\GrowthLedger, or the
            // grant slug for a spend.
            $table->string('event');
            // Plain words, engine-written, for the player to read back. No
            // mechanics language ever reaches it.
            $table->string('label');
            $table->timestamps();

            $table->index(['character_id', 'kind']);
            $table->index(['campaign_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_ledger');
    }
};
