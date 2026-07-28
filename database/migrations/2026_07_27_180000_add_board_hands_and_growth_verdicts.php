<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turns', function (Blueprint $table) {
            // The situation as GROUPS, not one run-on paragraph. The prose
            // string stays (the narrator prompt reads it); this is what the
            // player reads, and it is stored beside the cards it explains so
            // a re-read of an old turn shows the ground as it stood then.
            $table->json('situation_board')->nullable()->after('situation');
        });

        Schema::table('characters', function (Blueprint $table) {
            // What is physically in their hands right now. Distinct from
            // items (owned, carried indefinitely): this is scene matter
            // picked up and put down again, and it costs hands to hold.
            $table->json('carrying')->nullable()->after('meters');
        });

        Schema::table('interview_messages', function (Blueprint $table) {
            // A growth request that changed nothing and a growth request that
            // rewrote the sheet used to look identical on screen. The verdict
            // is now recorded with the answer.
            $table->boolean('granted')->nullable()->after('body');
            $table->json('changes')->nullable()->after('granted');
        });
    }

    public function down(): void
    {
        Schema::table('turns', fn (Blueprint $table) => $table->dropColumn('situation_board'));
        Schema::table('characters', fn (Blueprint $table) => $table->dropColumn('carrying'));
        Schema::table('interview_messages', fn (Blueprint $table) => $table->dropColumn(['granted', 'changes']));
    }
};
