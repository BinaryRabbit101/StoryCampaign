<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only log of world-evolution runs: what changed and why, so
        // successive stateless Claude runs stay coherent and don't spiral.
        Schema::create('evolution_runs', function (Blueprint $table) {
            $table->id();
            $table->string('kind')->default('daily'); // daily | weekly | manual
            $table->string('status')->default('running'); // running | complete | failed
            $table->json('budget')->nullable();    // caps this run was given
            $table->json('changes')->nullable();   // applied changes + Claude's rationale
            $table->text('chronicle')->nullable(); // in-world narration of the update
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evolution_runs');
    }
};
