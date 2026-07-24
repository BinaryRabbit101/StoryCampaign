<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narrator messages may carry suggested answers — short in-voice replies
 * the player can tap when they don't know how to answer the question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_messages', function (Blueprint $table) {
            $table->json('suggestions')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('interview_messages', function (Blueprint $table) {
            $table->dropColumn('suggestions');
        });
    }
};
