<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One chat per conversation per user.
 *
 * Guarded by hasTable() because freescout:module-install re-runs migrations
 * every time the module is installed or updated.
 */
class CreateAichatpanelChatsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('aichatpanel_chats')) {
            return;
        }

        Schema::create('aichatpanel_chats', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('conversation_id')->unsigned();
            $table->integer('user_id')->unsigned();
            // Remembered so reopening the chat restores the model that was used.
            $table->string('model', 191)->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id'], 'aichatpanel_chats_conv_user');
            $table->index('updated_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('aichatpanel_chats');
    }
}
