<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            // Stable pi side identity (ctx.sessionManager.getSessionId()).
            // One web chat per pi session: resume → same chat, new pi session → new chat.
            $table->string('pi_session_id', 64)->nullable()->unique()->after('key');
            // Project grouping key is no longer 1:1 — many chats can share it.
            $table->index('key');
        });

        // Drop the 1:1 unique constraint on `key` (keep the plain index above).
        // Index name differs per driver; try the Laravel default then fall back.
        try {
            Schema::table('chat_sessions', function (Blueprint $table) {
                $table->dropUnique(['key']);
            });
        } catch (\Throwable) {
            try {
                Schema::table('chat_sessions', function (Blueprint $table) {
                    $table->dropUnique('chat_sessions_key_unique');
                });
            } catch (\Throwable) {
                // sqlite / already dropped → ignore
            }
        }
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropUnique(['pi_session_id']);
            $table->dropColumn('pi_session_id');
        });
    }
};
