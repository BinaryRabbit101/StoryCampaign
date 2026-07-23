<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Player-set stage: all optional, all narration-side except the
     * starting zone (a legal choice among existing world zones).
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->text('premise')->nullable()->after('name');
            $table->string('tone', 120)->nullable()->after('premise');
            $table->foreignId('starting_zone_id')->nullable()->after('tone')->constrained('zones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('starting_zone_id');
            $table->dropColumn(['premise', 'tone']);
        });
    }
};
