<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is writing this chapter right now.
 *
 * Narration is dispatched inline after the response is flushed AND swept by
 * `game:resolve-due` every minute, and a Claude call outlives a minute easily.
 * Without a claim both paths read the same `narrated_at IS NULL` and both write
 * a chapter — two retellings of one turn, which is what prod actually did.
 *
 * Nullable and stamped rather than a boolean lock: a narration that dies
 * mid-call must become retryable again once the claim goes stale, or the
 * safety net would have nothing left to catch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turns', function (Blueprint $table) {
            $table->timestamp('narration_claimed_at')->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('turns', function (Blueprint $table) {
            $table->dropColumn('narration_claimed_at');
        });
    }
};
