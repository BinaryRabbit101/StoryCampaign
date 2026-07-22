<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scene_id')->nullable()->constrained();
            $table->unsignedInteger('number');
            // awaiting_player -> locked (submitted) -> resolving -> complete | aborted
            $table->string('status')->default('awaiting_player');
            $table->text('situation');            // summary shown above the form
            $table->json('cards');                // offered action cards, keyed by slot
            $table->json('submission')->nullable(); // {"pre":{...},"main":{...},"post":{...},"intent_text":"..."}
            $table->json('resolution')->nullable(); // ordered beat outcomes + trigger, engine-authored
            $table->string('branch_trigger')->nullable();
            $table->json('meters_snapshot')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('narrated_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'number']);
        });

        // Per-turn narration store — first-class from day one; raw material
        // for the end-of-campaign book.
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('turn_id')->nullable()->constrained();
            $table->unsignedInteger('number');
            $table->string('kind')->default('chapter'); // prologue | chapter | chronicle | coda
            $table->string('intent_line')->nullable();  // optional italicized header
            $table->longText('body');
            $table->timestamps();
            $table->unique(['campaign_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapters');
        Schema::dropIfExists('turns');
    }
};
