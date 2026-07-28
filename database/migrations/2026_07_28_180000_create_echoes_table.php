<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The shelf of finished books, speaking to the tale being written.
        //
        // Every campaign used to begin in total amnesia. The player's tales
        // already share a history — the land roll refuses their last three
        // lands, the books pile up per user — but no book ever knew another
        // one existed. This is the one line that crosses: when a moment here
        // RHYMES with a moment a closed book already preserved, the engine may
        // surface that preserved line as a memory.
        //
        // Every column exists for traceability. An echo is QUOTATION, never
        // invention: `source_campaign_id` + `source_type` + `source_id` resolve
        // to the real persisted memento or chapter the words came out of, so
        // the claim "the player actually lived this" is checkable rather than
        // promised. Claude may reword only the frame around the quote.
        //
        // Append-only like the shelf and the queue: after the write, the only
        // mutations are the narrator's clamped rewording of `line` and the
        // chapter stamp that completes provenance.
        Schema::create('echoes', function (Blueprint $table) {
            $table->id();
            // The tale REMEMBERING.
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            // The tale REMEMBERED — always an ended campaign of the same user.
            // Nullable on delete rather than cascading: a deleted source leaves
            // the memory standing in the book that already told it, which is a
            // better failure than a chapter citing a line that vanished.
            $table->foreignId('source_campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            // memento | chapter — which shelf the quoted words came off.
            $table->string('source_type');
            // The mementos or chapters row. Deliberately NOT a foreign key: it
            // points at one of two tables, and the pairing with source_type is
            // what makes it resolvable. Nothing instantiates across campaigns
            // through it — an echo quotes a line and never spawns anything.
            $table->unsignedBigInteger('source_id');
            // One of the closed rhyme list in App\Services\Echoes.
            $table->string('rhyme');
            // The assembled memory: the engine's frame, the verbatim quote, and
            // the name of the tale it came from.
            $table->text('line');
            // Provenance in this tale. Both nullable for the same reason the
            // shelf's are: a teardown must never be blocked by a memory.
            $table->foreignId('turn_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['campaign_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('echoes');
    }
};
