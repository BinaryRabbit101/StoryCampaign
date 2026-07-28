<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Someone else's small story.
        //
        // Bystanders had no continuity: they were furniture that spoke, and
        // whatever a scene said about them died with the ground. One person in
        // the world visibly WANTING something — and visibly better or worse off
        // for how the player answered — is worth more than a dozen more props.
        //
        // Structurally this is a clock owned by an NPC, which is why the row
        // looks like one. Two things make it its own table rather than a flag
        // on `clocks`: it belongs to a PERSON rather than to ground, and it is
        // DORMANT until the player talks to them — until `revealed`, no board
        // group, no forecast line, and nothing at all reaches the narrator.
        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            // Whose want it is. The name is denormalized beside it because a
            // thread outlives the actor row's usefulness — an expired want
            // still has to be able to say who it belonged to.
            $table->foreignId('actor_id')->constrained('actors')->cascadeOnDelete();
            $table->string('actor_name');
            // One of the closed kinds in App\Game\Engine\Threads — what the
            // scene afforded when they turned up, and therefore what moves it
            // and what it pays off into.
            $table->string('kind');
            $table->unsignedTinyInteger('segments');
            $table->unsignedTinyInteger('filled')->default(0);
            // Chapters this want has stood open. The walking kind expires on it
            // (`threads.expiry_chapters`); the rooted kinds expire at the
            // border instead, because a want about this ground cannot follow.
            $table->unsignedSmallInteger('age')->default(0);
            // The whole dormancy rule, as a column. False means the player has
            // not discovered it and nothing about it may be visible anywhere.
            $table->boolean('revealed')->default(false);
            // What the want acts on, when the kind names something: {type,id,name}.
            $table->json('subject')->nullable();
            $table->string('status')->default('open'); // open|filled|failed|expired
            // Append-only: what happened to it, in the order it happened.
            $table->json('history')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads');
    }
};
