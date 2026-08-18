<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The panel's second desktop shape: a floating window.
 *
 * Which shape the user last chose, and where they put the window, belongs with
 * the open state and the width in aichatpanel_user_prefs — same reasoning as
 * the table it extends: localStorage would lose it on every other browser.
 *
 * The geometry columns are nullable on purpose. Null means "has never floated",
 * which is what tells the client to seed a default size and corner instead of
 * restoring one.
 */
class AddAichatpanelFloatState extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('aichatpanel_user_prefs')) {
            return;
        }

        Schema::table('aichatpanel_user_prefs', function (Blueprint $table) {
            if (!Schema::hasColumn('aichatpanel_user_prefs', 'panel_mode')) {
                // UserPref::MODE_DOCKED / MODE_FLOATING. A tiny integer with
                // constants rather than an enum, per core's migration rules.
                $table->unsignedTinyInteger('panel_mode')->default(1);
            }

            if (!Schema::hasColumn('aichatpanel_user_prefs', 'panel_float_x')) {
                $table->unsignedSmallInteger('panel_float_x')->nullable();
            }

            if (!Schema::hasColumn('aichatpanel_user_prefs', 'panel_float_y')) {
                $table->unsignedSmallInteger('panel_float_y')->nullable();
            }

            if (!Schema::hasColumn('aichatpanel_user_prefs', 'panel_float_width')) {
                $table->unsignedSmallInteger('panel_float_width')->nullable();
            }

            if (!Schema::hasColumn('aichatpanel_user_prefs', 'panel_float_height')) {
                $table->unsignedSmallInteger('panel_float_height')->nullable();
            }
        });
    }

    public function down()
    {
        if (!Schema::hasTable('aichatpanel_user_prefs')) {
            return;
        }

        Schema::table('aichatpanel_user_prefs', function (Blueprint $table) {
            $columns = [
                'panel_mode',
                'panel_float_x',
                'panel_float_y',
                'panel_float_width',
                'panel_float_height',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('aichatpanel_user_prefs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
