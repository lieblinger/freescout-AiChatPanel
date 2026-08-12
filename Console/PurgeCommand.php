<?php

namespace Modules\AiChatPanel\Console;

use Illuminate\Console\Command;
use Modules\AiChatPanel\Entities\Chat;
use Modules\AiChatPanel\Entities\Message;
use Modules\AiChatPanel\Entities\ToolCall;
use Modules\AiChatPanel\Services\Settings;

/**
 * Retention. Registered on the schedule filter by the service provider and
 * runnable by hand.
 *
 * Chats and the tool audit log have separate retentions on purpose: the record
 * of what was done to helpdesk data should be able to outlive the chat that
 * triggered it.
 */
class PurgeCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'aichatpanel:purge {--dry-run : Report what would be deleted without deleting it}';

    /**
     * @var string
     */
    protected $description = 'Delete AiChatPanel chat sessions and tool audit records past their retention';

    /**
     * @return int
     */
    public function handle()
    {
        $dry_run = (bool) $this->option('dry-run');

        $this->purgeChats($dry_run);
        $this->purgeAudit($dry_run);
        $this->purgeOrphans($dry_run);

        return 0;
    }

    /**
     * @param bool $dry_run
     *
     * @return void
     */
    protected function purgeChats($dry_run)
    {
        $days = (int) Settings::get('retention_days');

        if ($days <= 0) {
            $this->line('Chat retention is disabled; keeping all chats.');

            return;
        }

        $cutoff = now()->subDays($days);

        $chat_ids = Chat::where('updated_at', '<', $cutoff)->pluck('id')->toArray();

        if (!$chat_ids) {
            $this->line('No chats older than '.$days.' day(s).');

            return;
        }

        if ($dry_run) {
            $this->line('Would delete '.count($chat_ids).' chat(s) older than '.$days.' day(s).');

            return;
        }

        foreach (array_chunk($chat_ids, \Helper::IN_LIMIT) as $chunk) {
            Message::whereIn('chat_id', $chunk)->delete();
            Chat::whereIn('id', $chunk)->delete();
        }

        $this->info('Deleted '.count($chat_ids).' chat(s) older than '.$days.' day(s).');
    }

    /**
     * @param bool $dry_run
     *
     * @return void
     */
    protected function purgeAudit($dry_run)
    {
        $days = (int) Settings::get('audit_retention_days');

        if ($days <= 0) {
            $this->line('Audit retention is disabled; keeping the whole tool log.');

            return;
        }

        $cutoff = now()->subDays($days);

        $count = ToolCall::where('created_at', '<', $cutoff)->count();

        if (!$count) {
            $this->line('No audit records older than '.$days.' day(s).');

            return;
        }

        if ($dry_run) {
            $this->line('Would delete '.$count.' audit record(s) older than '.$days.' day(s).');

            return;
        }

        ToolCall::where('created_at', '<', $cutoff)->delete();

        $this->info('Deleted '.$count.' audit record(s) older than '.$days.' day(s).');
    }

    /**
     * Chats whose conversation has gone.
     *
     * Both deletion hooks should have caught these already; this is the safety
     * net for rows deleted by a direct query, a restore, or an older version of
     * the module that only hooked one of the two paths.
     *
     * @param bool $dry_run
     *
     * @return void
     */
    protected function purgeOrphans($dry_run)
    {
        $orphans = Chat::whereNotExists(function ($query) {
            $query->select(\DB::raw(1))
                ->from('conversations')
                ->whereRaw('conversations.id = aichatpanel_chats.conversation_id');
        })->pluck('id')->toArray();

        if (!$orphans) {
            return;
        }

        if ($dry_run) {
            $this->line('Would delete '.count($orphans).' chat(s) whose conversation no longer exists.');

            return;
        }

        foreach (array_chunk($orphans, \Helper::IN_LIMIT) as $chunk) {
            Message::whereIn('chat_id', $chunk)->delete();
            Chat::whereIn('id', $chunk)->delete();
        }

        $this->info('Deleted '.count($orphans).' orphaned chat(s).');
    }
}
