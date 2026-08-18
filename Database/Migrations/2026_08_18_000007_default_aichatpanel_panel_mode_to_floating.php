<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AiChatPanel\Entities\UserPref;

/**
 * The panel's default shape is the window, not the column.
 *
 * Rows created before this were seeded docked, and the column that carries the
 * shape was added with a default of 1 — so every user who had ever opened the
 * panel is sitting on a docked preference nobody chose.
 *
 * Only rows that have never floated are moved. Undocking seeds the window's
 * geometry and saves it with the shape (see togglePanelMode() in module.js), so
 * all four geometry columns being null proves the pin has never been clicked —
 * and therefore that the stored "docked" is the old seed rather than a choice.
 * A user who docked a window back keeps their geometry, and keeps the column.
 */
class DefaultAichatpanelPanelModeToFloating extends Migration
{
    public function up()
    {
        if (!$this->columnsPresent()) {
            return;
        }

        $this->neverFloated()
            ->where('panel_mode', UserPref::MODE_DOCKED)
            ->update(['panel_mode' => UserPref::MODE_FLOATING]);
    }

    public function down()
    {
        if (!$this->columnsPresent()) {
            return;
        }

        $this->neverFloated()
            ->where('panel_mode', UserPref::MODE_FLOATING)
            ->update(['panel_mode' => UserPref::MODE_DOCKED]);
    }

    /**
     * @return bool
     */
    protected function columnsPresent()
    {
        if (!Schema::hasTable('aichatpanel_user_prefs')) {
            return false;
        }

        foreach ($this->floatColumns() as $column) {
            if (!Schema::hasColumn('aichatpanel_user_prefs', $column)) {
                return false;
            }
        }

        return Schema::hasColumn('aichatpanel_user_prefs', 'panel_mode');
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    protected function neverFloated()
    {
        $query = DB::table('aichatpanel_user_prefs');

        foreach ($this->floatColumns() as $column) {
            $query->whereNull($column);
        }

        return $query;
    }

    /**
     * @return array
     */
    protected function floatColumns()
    {
        return [
            'panel_float_x',
            'panel_float_y',
            'panel_float_width',
            'panel_float_height',
        ];
    }
}
