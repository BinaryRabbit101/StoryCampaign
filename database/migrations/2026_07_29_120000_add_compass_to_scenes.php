<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The compass: scenes learn where they came from and where they sit on the
 * zone's map, and every dressed scene gets its ways out as real rows with
 * headings. Scenes that predate this stay legal everywhere — a null
 * from_scene_id is simply a scene the map draws without a road behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenes', function (Blueprint $table) {
            $table->foreignId('from_scene_id')->nullable()->constrained('scenes')->nullOnDelete();
            $table->string('from_direction', 5)->nullable();
            $table->integer('grid_x')->default(0);
            $table->integer('grid_y')->default(0);
        });

        Schema::create('scene_exits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 5);
            $table->string('label');
            $table->json('locale')->nullable();
            $table->foreignId('to_scene_id')->nullable()->constrained('scenes')->nullOnDelete();
            $table->timestamps();
            $table->unique(['scene_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scene_exits');
        Schema::table('scenes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_scene_id');
            $table->dropColumn(['from_direction', 'grid_x', 'grid_y']);
        });
    }
};
