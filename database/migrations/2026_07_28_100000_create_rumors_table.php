<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The world's news, on its way to the character.
        //
        // The evolver has always tended each tale's world overnight and told
        // the READER about it in a Chronicle chapter. The character never
        // heard a word: no scene, no conversation, no crossing ever referenced
        // what changed. This is the queue that closes that gap — engine
        // templated the moment the source fact is logged, delivered one at a
        // time through channels the turn already produced.
        //
        // Append-only in spirit: the only mutation after the write is the
        // pair of `heard_` stamps, and the narrator's clamped rewording of
        // the line.
        Schema::create('rumors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            // evolution | forge | grudge — where the fact came from, so every
            // line can be traced back to something that really happened.
            $table->string('source');
            // The run that logged the change, when there was one. Provenance:
            // a rumor with no real fact behind it is a fabrication, and this
            // is how that stays checkable.
            $table->foreignId('evolution_run_id')->nullable()->constrained()->nullOnDelete();
            // What the news is ABOUT, in plain words. The narrator's rewording
            // is clamped to stay about it.
            $table->string('subject');
            // Set only when the subject is a place. Two jobs: the crossing
            // channel prefers news about where the road leads, and a rumor
            // about ground the tale has since walked is stale and skipped.
            $table->foreignId('subject_zone_id')->nullable()->constrained('zones')->nullOnDelete();
            $table->text('line');
            // Null until it reaches somebody. Nullable on delete for the same
            // reason the shelf's are: a teardown must never be blocked by news.
            $table->foreignId('heard_turn_id')->nullable()->constrained('turns')->nullOnDelete();
            $table->foreignId('heard_chapter_id')->nullable()->constrained('chapters')->nullOnDelete();
            $table->timestamps();

            $table->index(['campaign_id', 'heard_turn_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rumors');
    }
};
