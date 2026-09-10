<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // user = human/device, assistant = pi reply, tool = tool output, status = events
            $table->string('role', 16)->default('user');
            $table->text('text');
            $table->string('tool_name', 64)->nullable();
            $table->string('via', 16)->nullable(); // web | local | pi
            $table->boolean('is_error')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['chat_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
