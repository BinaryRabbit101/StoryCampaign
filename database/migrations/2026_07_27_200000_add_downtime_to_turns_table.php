<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turns', function (Blueprint $table) {
            // How the character spends the idle wait before this turn is
            // played. The offer is written when the turn is opened, the pick
            // lands on it afterwards, and the payout is computed and stamped
            // at the top of this turn's own resolution — so everything about
            // one wait lives on the one row that wait belongs to.
            $table->json('downtime')->nullable()->after('meters_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('turns', fn (Blueprint $table) => $table->dropColumn('downtime'));
    }
};
