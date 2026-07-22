<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('interview'); // interview | active | completed | abandoned
            $table->string('title')->nullable();       // Claude-generated book title
            $table->text('back_cover')->nullable();    // one-line back-cover summary
            $table->boolean('ended_early')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description');   // the player's narrative self-description, distilled
            $table->json('meters');        // {"health":{"current":10,"max":10},"tempo":{"time_slow":{"current":2,"max":3}}}
            $table->string('status')->default('alive'); // alive | downed | dead
            $table->timestamp('meters_regenerated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('character_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('capability');            // vocabulary verb, e.g. "reach"
            $table->integer('magnitude')->nullable();  // for parameterized capabilities
            $table->string('grade')->nullable();       // e.g. quiet_move "partial"|"full", squeeze "large"
            $table->json('scope')->nullable();         // e.g. {"vs":"regular"} for intimidate
            $table->string('source')->default('creation'); // creation | growth | item | evolution
            $table->timestamps();
            $table->unique(['character_id', 'capability']);
        });

        Schema::create('character_constraints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('name');            // e.g. "stealth_penalty", "squeeze_min", "unwieldy"
            $table->json('params')->nullable();  // e.g. {"size":"large"}
            $table->string('coupled_capability')->nullable(); // the power this liability balances
            $table->string('source')->default('creation');
            $table->timestamps();
        });

        Schema::create('character_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->boolean('equipped')->default(false);
            $table->integer('charges')->nullable();
            $table->timestamps();
        });

        Schema::create('interview_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('creation'); // creation | growth
            $table->string('role');                      // player | narrator
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_messages');
        Schema::dropIfExists('character_items');
        Schema::dropIfExists('character_constraints');
        Schema::dropIfExists('character_capabilities');
        Schema::dropIfExists('characters');
        Schema::dropIfExists('campaigns');
    }
};
