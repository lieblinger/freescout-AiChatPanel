<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the builtin tools everywhere an install still spells them the old way.
 *
 * 1.3.0 renamed the builtins from "conversation.get" to "conversation_get",
 * because OpenAI and Anthropic reject a dot in a function name. It taught the
 * code to read the old names but left them in the database, in two places:
 *
 *   - stored chat history, which is replayed to the model turn by turn. The
 *     model read its own past calls in the old spelling, asked for it again,
 *     and got "unknown tool" back. ToolRegistry::find() accepts the old
 *     spelling too now, for a chat this migration cannot reach.
 *   - the tool lists in the settings, which the settings screen ticks by
 *     comparing against the tools' current names — so it showed everything
 *     unticked, and saving the page would have turned the tools off.
 *
 * The map is written out here rather than read from
 * ToolRegistry::canonicalName(): a migration records what was true when it ran,
 * and must keep working after that method eventually goes.
 *
 * The audit table is deliberately untouched. It is a log of what happened, it is
 * never looked up by name, and rewriting it would falsify the record.
 */
class CanonicaliseAichatpanelToolNames extends Migration
{
    /**
     * The module's alias, spelled out for the same reason the map below is.
     */
    const ALIAS = 'aichatpanel';

    public function up()
    {
        $this->renameStoredHistory();
        $this->renameStoredSettings();
    }

    /**
     * @return void
     */
    protected function renameStoredHistory()
    {
        if (!Schema::hasTable('aichatpanel_messages')) {
            return;
        }

        $legacy = $this->legacyNames();

        DB::table('aichatpanel_messages')
            ->select('id', 'tool_name', 'tool_calls')
            ->where(function ($query) use ($legacy) {
                // Tool result turns carry the name directly; assistant turns
                // carry it inside the JSON. Everything else cannot hold one.
                $query->whereIn('tool_name', array_keys($legacy))
                    ->orWhereNotNull('tool_calls');
            })
            ->chunkById(200, function ($rows) use ($legacy) {
                foreach ($rows as $row) {
                    $update = [];

                    // An assistant turn has no tool_name of its own, and a null
                    // array offset is deprecated in PHP 8.
                    $tool_name = (string) $row->tool_name;

                    if (isset($legacy[$tool_name])) {
                        $update['tool_name'] = $legacy[$tool_name];
                    }

                    $calls = $this->renameCalls($row->tool_calls, $legacy);

                    if ($calls !== null) {
                        $update['tool_calls'] = $calls;
                    }

                    if ($update) {
                        DB::table('aichatpanel_messages')->where('id', $row->id)->update($update);
                    }
                }
            });
    }

    /**
     * Rename the tools the settings name.
     *
     * ToolRegistry reads these through canonicalName(), so the panel behaves
     * either way — but the settings screen ticks a box by comparing the stored
     * name with the tool's current one, so every tool showed up unticked, and
     * saving that page would have written the empty tick list back and turned
     * the tools off for real.
     *
     * @return void
     */
    protected function renameStoredSettings()
    {
        $legacy = $this->legacyNames();

        foreach (['tools_enabled', 'write_tools_autorun'] as $name) {
            $key = self::ALIAS.'.'.$name;
            $stored = \Option::get($key, []);

            if (!is_array($stored)) {
                continue;
            }

            $renamed = $this->renameList($stored, $legacy);

            if ($renamed !== $stored) {
                \Option::set($key, $renamed);
                unset(\Option::$cache[$key]);
            }
        }

        if (!Schema::hasTable('mailboxes')) {
            return;
        }

        // A mailbox can override the tool list, and that copy lives in the
        // mailbox's meta column rather than in the options table.
        foreach (\App\Mailbox::all() as $mailbox) {
            $meta = $mailbox->meta;

            if (!is_array($meta) || empty($meta[self::ALIAS]['tools_enabled'])
                || !is_array($meta[self::ALIAS]['tools_enabled'])) {
                continue;
            }

            $stored = $meta[self::ALIAS]['tools_enabled'];
            $renamed = $this->renameList($stored, $legacy);

            if ($renamed !== $stored) {
                $block = $meta[self::ALIAS];
                $block['tools_enabled'] = $renamed;

                $mailbox->setMetaParam(self::ALIAS, $block, true);
            }
        }
    }

    /**
     * @param array $names
     * @param array $legacy
     *
     * @return array
     */
    protected function renameList(array $names, array $legacy)
    {
        $renamed = [];

        foreach ($names as $name) {
            $renamed[] = isset($legacy[$name]) ? $legacy[$name] : $name;
        }

        return $renamed;
    }

    /**
     * The re-encoded tool_calls column, or null when nothing changed.
     *
     * @param string|null $raw
     * @param array       $legacy
     *
     * @return string|null
     */
    protected function renameCalls($raw, array $legacy)
    {
        if (!$raw) {
            return null;
        }

        $calls = json_decode($raw, true);

        if (!is_array($calls)) {
            return null;
        }

        $changed = false;

        foreach ($calls as $i => $call) {
            if (is_array($call) && isset($call['name']) && isset($legacy[$call['name']])) {
                $calls[$i]['name'] = $legacy[$call['name']];
                $changed = true;
            }
        }

        // Plain json_encode with no flags, which is what Eloquent's array cast
        // writes: the column must not change shape just because a name did.
        return $changed ? json_encode($calls) : null;
    }

    /**
     * @return array
     */
    protected function legacyNames()
    {
        return [
            'conversation.list_customer_conversations' => 'conversation_list_customer_conversations',
            'conversation.get'                         => 'conversation_get',
            'conversation.get_drafts'                  => 'conversation_get_drafts',
            'conversation.add_note'                    => 'conversation_add_note',
            'conversation.set_status'                  => 'conversation_set_status',
            'conversation.create_draft_reply'          => 'conversation_create_draft_reply',
            'conversation.update_draft'                => 'conversation_update_draft',
            'customer.get'                             => 'customer_get',
        ];
    }

    /**
     * Deliberately empty: putting the dots back would break tool calling against
     * every hosted endpoint again.
     */
    public function down()
    {
    }
}
