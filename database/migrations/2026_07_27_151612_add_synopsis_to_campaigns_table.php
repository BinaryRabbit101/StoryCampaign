<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // The campaign's running "story so far": one factual line per
            // chapter, appended at narration time, so the narrator can honor
            // promises and grudges older than its two-chapter window.
            $table->text('synopsis')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('synopsis');
        });
    }
};
