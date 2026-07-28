<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The player-owned mirror of the alarm clock.
        //
        // Every tension the game had was scene-local: telegraphs, angles, the
        // alarm, lurkers, pursuit. Nothing persisted that the player was
        // working TOWARD, so there was nothing to come back through the idle
        // wait for. An endeavor is a goal the engine offered, the player
        // committed a beat to, and ordinary qualifying beats fill.
        //
        // One row per endeavor, campaign-scoped and (normally) scene-scoped:
        // a goal about ground the tale has already left is a goal that can
        // never be finished, so a non-portable clock dies with its scene.
        Schema::create('clocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            // Null only for a portable endeavor that has outlived every scene
            // it has stood in; ordinarily the ground it belongs to.
            $table->foreignId('scene_id')->nullable()->constrained()->nullOnDelete();
            // One of the closed kinds in App\Game\Engine\Clocks — what the
            // scene afforded, and therefore which verbs move it.
            $table->string('kind');
            // Player-facing prose, engine-templated. The narrator may quote
            // it; it never invents one.
            $table->string('name');
            $table->unsignedTinyInteger('segments');
            $table->unsignedTinyInteger('filled')->default(0);
            // The verbs that move it. Stored rather than derived so a clock
            // committed under one version of the table keeps its own terms.
            $table->json('advance_verbs');
            // Closed enum: reveal_hidden | destroy_obstacle | grant_readied.
            // Every one of them routes through machinery that already existed.
            $table->string('payoff');
            // What the payoff acts on, when it needs a thing: {type,id,name}.
            $table->json('subject')->nullable();
            // Engine-set, never player-set: a readiness the body carries
            // travels; a search of this ground does not.
            $table->boolean('portable')->default(false);
            $table->string('status')->default('open'); // open|filled|abandoned|expired
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clocks');
    }
};
