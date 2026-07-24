<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign-scoped worlds: a zone with a campaign_id belongs to that one
 * tale (forged at creation or at the frontier); campaign_id null remains
 * the shared world — seed archetypes and evolution's garden.
 * campaigns.next_zone_id holds the pre-forged frontier zone waiting past
 * the edge of the current one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('next_zone_id')->nullable()->after('starting_zone_id')
                ->constrained('zones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_zone_id');
        });

        Schema::table('zones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
    }
};
