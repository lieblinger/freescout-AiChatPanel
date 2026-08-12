<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log of every tool execution.
 *
 * Deliberately separate from the chat tables and with its own retention: the
 * record of what was done to the helpdesk data has to outlive the chat that
 * happened to trigger it. chat_id is nullable and never a hard dependency.
 */
class CreateAichatpanelToolCallsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('aichatpanel_tool_calls')) {
            return;
        }

        Schema::create('aichatpanel_tool_calls', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('user_id')->unsigned();
            $table->integer('conversation_id')->unsigned()->nullable();
            $table->integer('mailbox_id')->unsigned()->nullable();
            $table->integer('chat_id')->unsigned()->nullable();

            $table->string('tool', 191);
            // read | write — constants on Entities\ToolCall.
            $table->unsignedTinyInteger('mode');
            // pending | approved | rejected | ok | denied | failed
            $table->unsignedTinyInteger('status');

            $table->text('arguments')->nullable();
            $table->text('result')->nullable();
            $table->text('error')->nullable();

            $table->integer('duration_ms')->unsigned()->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index(['conversation_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('aichatpanel_tool_calls');
    }
}
