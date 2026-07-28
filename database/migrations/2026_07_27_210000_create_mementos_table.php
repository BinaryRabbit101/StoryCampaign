<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The shelf. A keepsake left behind by a notable resolved moment —
        // mechanically inert forever, which is exactly why it gets its own
        // table instead of a column on characters or a row in `items`. Items
        // enter a world only through evolution because items GRANT things; a
        // memento grants nothing, so play itself may mint one.
        //
        // Append-only like chapters: once minted, a row is never deleted and
        // never edited except by the narrator's clamped rewording of `name`
        // and `line` (and the chapter stamp that completes its provenance the
        // moment the chapter telling it exists).
        Schema::create('mementos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            // Provenance, so the book can cite the moment. Both nullable on
            // purpose: a campaign teardown drops turns and chapters out from
            // under the shelf, and a keepsake outliving its citation is a
            // better failure than a delete that cascades into the shelf.
            $table->foreignId('turn_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained()->nullOnDelete();
            // One of the closed trigger list in App\Services\Mementos.
            $table->string('trigger');
            // Who or what the keepsake is OF. Stored because the narrator's
            // rewording is clamped to "still about the same subject", and
            // that check runs in a later process than the mint did.
            $table->string('subject');
            $table->string('name');
            $table->text('line');
            $table->timestamp('created_at')->nullable();

            $table->index(['campaign_id', 'trigger']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mementos');
    }
};
