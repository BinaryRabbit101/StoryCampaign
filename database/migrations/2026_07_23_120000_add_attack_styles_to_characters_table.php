<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            // Short phrases for how this body attacks ("bite", "tail-whip").
            // Narration vocabulary only — never a mechanical input.
            $table->json('attack_styles')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('attack_styles');
        });
    }
};
