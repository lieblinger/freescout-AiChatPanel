<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every turn of a chat, including the tool calls and their results, so
 * reopening a conversation restores exactly what happened.
 */
class CreateAichatpanelMessagesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('aichatpanel_messages')) {
            return;
        }

        Schema::create('aichatpanel_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('chat_id')->unsigned();

            // Role constants live on Modules\AiChatPanel\Entities\Message.
            $table->unsignedTinyInteger('role');

            $table->longText('body')->nullable();
            // Rendered server-side (Parsedown + HTMLPurifier). Cached so the
            // history does not have to be re-rendered, and so a restored chat
            // never depends on the browser-side renderer.
            $table->longText('body_html')->nullable();
            // Chain of thought, kept for display only and never replayed to the
            // model.
            $table->longText('reasoning')->nullable();

            // Assistant turns that requested tools: the raw normalised calls.
            $table->text('tool_calls')->nullable();
            // Tool result turns: which call they answer.
            $table->string('tool_call_id', 191)->nullable();
            $table->string('tool_name', 191)->nullable();

            $table->unsignedTinyInteger('status')->default(0);
            // Token counts, duration, truncation notices.
            $table->text('meta')->nullable();

            $table->timestamps();

            $table->index(['chat_id', 'id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('aichatpanel_messages');
    }
}
