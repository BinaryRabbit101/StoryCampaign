<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The tale's memory of the enemies who got away. Actor rows are
        // scene-scoped copies, so the NAME is the durable identity — one
        // grudge per campaign+name, tended by evolution, brought back by
        // the engine alone.
        Schema::create('grudges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('actor_name');
            $table->json('stats');
            $table->json('tags')->nullable();
            $table->string('tier')->default('regular');
            // Append-only: {turn_id, chapter_id, event, detail, place?}.
            // The details are prose facts — what the narrator quotes.
            $table->json('history');
            $table->unsignedTinyInteger('heat')->default(1);
            $table->string('disposition'); // vengeful | wary | scheming
            $table->string('status')->default('simmering'); // simmering | returning | resolved
            $table->foreignId('last_seen_chapter_id')->nullable()->constrained('chapters')->nullOnDelete();
            $table->timestamps();

            $table->unique(['campaign_id', 'actor_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grudges');
    }
};
