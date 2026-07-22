<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description');
            $table->json('tags')->nullable();
            $table->string('source')->default('seed'); // seed | evolution
            $table->foreignId('evolution_run_id')->nullable();
            $table->timestamps();
        });

        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->constrained();
            $table->string('title');
            $table->text('description');
            $table->string('status')->default('active'); // active | past
            $table->json('state')->nullable();
            $table->timestamps();
        });

        Schema::create('scene_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained();
            $table->string('name');
            $table->string('feature_type'); // building, chasm, alley, wind_current, ...
            $table->json('affordances');    // e.g. {"reachable_via":["climb","swing"],"height":11}
            $table->json('state')->nullable();
            $table->string('source')->default('seed');
            $table->foreignId('evolution_run_id')->nullable();
            $table->timestamps();
        });

        Schema::create('actors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained();
            $table->string('name');
            $table->string('kind')->default('enemy'); // enemy | npc | creature
            $table->string('tier')->default('regular'); // regular | elite | boss
            $table->json('stats');  // {"health":{"current":8,"max":8},"attack":2,"defense":1}
            $table->json('tags')->nullable(); // {"intimidatable":true,"type":"regular"}
            $table->string('status')->default('active'); // active | defeated | fled | dead
            $table->string('source')->default('seed');
            $table->foreignId('evolution_run_id')->nullable();
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description');
            $table->json('grants')->nullable();      // [{"capability":"glide","magnitude":null}]
            $table->json('constraints')->nullable(); // [{"name":"fragile"}]
            $table->unsignedTinyInteger('power')->default(1);
            $table->string('source')->default('seed');
            $table->foreignId('evolution_run_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
        Schema::dropIfExists('actors');
        Schema::dropIfExists('scene_features');
        Schema::dropIfExists('scenes');
        Schema::dropIfExists('zones');
    }
};
