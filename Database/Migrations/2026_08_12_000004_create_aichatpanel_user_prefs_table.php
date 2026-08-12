<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user panel state.
 *
 * localStorage alone would lose the setting on every other browser, and the
 * spec asks for the open/closed and width state to persist per user across
 * page loads and conversations.
 */
class CreateAichatpanelUserPrefsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('aichatpanel_user_prefs')) {
            return;
        }

        Schema::create('aichatpanel_user_prefs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unsigned();
            $table->boolean('panel_open')->default(false);
            $table->integer('panel_width')->unsigned()->default(380);
            $table->string('last_model', 191)->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('aichatpanel_user_prefs');
    }
}
