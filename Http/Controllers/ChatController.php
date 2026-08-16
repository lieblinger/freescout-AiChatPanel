<?php

namespace Modules\AiChatPanel\Http\Controllers;

use App\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\AiChatPanel\Entities\Chat;
use Modules\AiChatPanel\Entities\Message;
use Modules\AiChatPanel\Entities\ToolCall;
use Modules\AiChatPanel\Entities\UserPref;
use Modules\AiChatPanel\Services\Agent\AgentLoop;
use Modules\AiChatPanel\Services\Agent\AgentOutcome;
use Modules\AiChatPanel\Services\ChangeCollector;
use Modules\AiChatPanel\Services\Context\ContextBuilder;
use Modules\AiChatPanel\Services\Context\HistoryWindow;
use Modules\AiChatPanel\Services\Context\TokenBudget;
use Modules\AiChatPanel\Services\Llm\CurlLlmClient;
use Modules\AiChatPanel\Services\Llm\LlmException;
use Modules\AiChatPanel\Services\Markdown\HtmlToMarkdown;
use Modules\AiChatPanel\Services\Markdown\MarkdownToHtml;
use Modules\AiChatPanel\Services\MarkdownRenderer;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Settings;
use Modules\AiChatPanel\Services\Tools\ToolRegistry;

/**
 * Everything the panel talks to.
 *
 * Every action resolves the conversation from the request and authorises
 * against it. Nothing is trusted from the client: not the conversation id, not
 * the model name, not the tool arguments, and least of all the claim that a
 * write was confirmed.
 */
class ChatController extends Controller
{
    /**
     * Whether the SSE response is going to a real client and should be flushed
     * frame by frame. False under CLI, where the test suite reads the body.
     *
     * @var bool
     */
    protected $sse_realtime = true;

    /**
     * Restore the chat for a conversation.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(Request $request)
    {
        try {
            $context = $this->resolve($request);
        } catch (\Exception $e) {
            return $this->denied($e);
        }

        $chat = Chat::findOrCreateFor($context->conversation->id, $context->user->id);

        return \Response::json([
            'status'   => 'success',
            'messages' => $this->panelMessages($chat),
            'model'    => $this->currentModel($context, $chat),
            'models'   => $this->modelChoices(),
            'tools'    => $this->toolSummary($context),
            'pending'  => $this->pendingWriteFor($chat),
        ]);
    }

    /**
     * One stored answer, as the HTML the reply editor wants.
     *
     * The panel's own bubble HTML cannot be reused for this. It is rendered
     * with the panel profile, which allows <code>, <hr> and <del> — exactly the
     * three things core's purifier drops when the draft is displayed or sent,
     * so inserting a bubble silently loses inline code, rules and
     * strikethrough. For a streaming bubble it is marked + DOMPurify output,
     * which is explicitly never the source of truth.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function editorHtml(Request $request)
    {
        try {
            $context = $this->resolve($request);

            $message = Message::find((int) $request->input('message_id'));

            if (!$message || $message->role != Message::ROLE_ASSISTANT) {
                throw new \Exception('not an assistant message');
            }

            $chat = $message->chat;

            // A chat belongs to one conversation AND one user. Checking only
            // the conversation would let one agent read another's chat.
            if (!$chat
                || $chat->conversation_id != $context->conversation->id
                || $chat->user_id != $context->user->id) {
                throw new \Exception('not this user\'s message');
            }

            if ($message->status == Message::STATUS_ERROR) {
                throw new \Exception('failed message');
            }
        } catch (\Exception $e) {
            return $this->denied($e);
        }

        return \Response::json([
            'status' => 'success',
            'html'   => MarkdownToHtml::toEditorHtml($message->body),
        ]);
    }

    /**
     * Send a message and get the answer.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function send(Request $request)
    {
        try {
            $context = $this->resolve($request);
        } catch (\Exception $e) {
            return $this->denied($e);
        }

        if ($limit = $this->rateLimited($context, 'completions')) {
            return $limit;
        }

        $body = trim((string) $request->input('message', ''));

        if ($body === '') {
            return \Response::json(['status' => 'error', 'msg' => __('Write a message first.')]);
        }

        if (mb_strlen($body) > 20000) {
            return \Response::json(['status' => 'error', 'msg' => __('That message is too long.')]);
        }

        $chat = Chat::findOrCreateFor($context->conversation->id, $context->user->id);

        $model = $this->resolveModel($request, $context, $chat);

        if (!$model) {
            return \Response::json([
                'status' => 'error',
                'msg'    => __('No model is configured. Ask an administrator to set a default model.'),
            ]);
        }

        // Refuse to start a new turn while one is waiting on the user: the
        // endpoint would reject a history with an unanswered tool call anyway.
        if ($this->pendingWriteFor($chat)) {
            return \Response::json([
                'status' => 'error',
                'msg'    => __('Approve or reject the pending action first.'),
            ]);
        }

        $user_message = Message::create([
            'chat_id' => $chat->id,
            'role'    => Message::ROLE_USER,
            'body'    => $body,
            'status'  => Message::STATUS_OK,
        ]);

        $chat->model = $model;
        $chat->save();

        $draft = $this->editorDraft($request);
        $mode = $this->editorMode($request);

        if ($request->input('stream')) {
            return \Response::json([
                'status'     => 'success',
                'messages'   => [$user_message->toPanelArray()],
                'stream_url' => $this->openStream($context, $chat, $model, $draft, $mode),
            ]);
        }

        return $this->runAndRespond($context, $chat, $model, [$user_message->toPanelArray()], $draft, $mode);
    }

    /**
     * Server-sent events for one turn.
     *
     * EventSource cannot POST, so the turn is created by send()/confirm() and
     * this GET only replays it. The token is single-use, short-lived and bound
     * to the user, so the URL is not a way to run someone else's turn.
     *
     * @param Request $request
     * @param string  $token
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function stream(Request $request, $token)
    {
        $user = auth()->user();
        $state = \Cache::pull('aichatpanel.stream.'.$token);

        // Pulled, not read: a token works exactly once.
        if (!$user || !$state || $state['user_id'] != $user->id) {
            return $this->streamError(__('This chat request has expired. Send the message again.'));
        }

        $conversation = Conversation::find($state['conversation_id']);

        if (!$conversation || !$user->can('view', $conversation)) {
            return $this->streamError(__('You do not have access to this conversation.'));
        }

        $context = new PanelContext($conversation, $user);

        if (!Settings::isUsable($context->mailbox)) {
            return $this->streamError(__('The AI chat panel is not enabled for this mailbox.'));
        }

        $chat = Chat::find($state['chat_id']);

        if (!$chat || $chat->user_id != $user->id) {
            return $this->streamError(__('This chat request has expired. Send the message again.'));
        }

        // stream() does not go through resolve(), so it arms the collector
        // itself. Without this the tools that run inside the stream would
        // change the conversation and tell nobody.
        ChangeCollector::instance()->arm($conversation->id);

        return $this->sse(function () use ($context, $chat, $state) {
            $this->streamTurn(
                $context,
                $chat,
                $state['model'],
                isset($state['editor_draft']) ? $state['editor_draft'] : '',
                isset($state['editor_mode']) ? $state['editor_mode'] : 'reply'
            );
        });
    }

    /**
     * Create a one-shot token for the streaming endpoint.
     *
     * @param PanelContext $context
     * @param Chat         $chat
     * @param string       $model
     * @param string       $draft Markdown of the reply editor's content.
     * @param string       $mode  'reply' | 'note'
     *
     * @return string
     */
    protected function openStream(PanelContext $context, Chat $chat, $model, $draft = '', $mode = 'reply')
    {
        $token = \Str::random(40);

        // The draft has to travel in the cache entry: the system prompt is
        // built in stream(), which is a different request and has no access to
        // the browser's editor. Markdown rather than the editor's HTML, because
        // it is a fraction of the size.
        \Cache::put('aichatpanel.stream.'.$token, [
            'user_id'         => $context->user->id,
            'conversation_id' => $context->conversation->id,
            'chat_id'         => $chat->id,
            'model'           => $model,
            'editor_draft'    => $draft,
            'editor_mode'     => $mode,
        ], 5);

        return route('aichatpanel.chat.stream', ['token' => $token]);
    }

    /**
     * Run the loop, emitting SSE frames as it goes.
     *
     * @param PanelContext $context
     * @param Chat         $chat
     * @param string       $model
     * @param string       $draft
     * @param string       $mode
     *
     * @return void
     */
    protected function streamTurn(PanelContext $context, Chat $chat, $model, $draft = '', $mode = 'reply')
    {
        try {
            $client = CurlLlmClient::fromSettings();
        } catch (LlmException $e) {
            $this->sseEvent('failure', ['message' => $e->userMessage(), 'type' => $e->getType()]);

            return;
        }

        $registry = new ToolRegistry($context);

        $assembled = $this->buildMessages($context, $chat, $registry, $draft, $mode);

        foreach ($assembled['notices'] as $notice) {
            $this->sseEvent('notice', ['message' => $notice]);
        }

        $controller = $this;

        $loop = new AgentLoop($client, $registry, $context, $model);
        $loop->setChatId($chat->id)
            ->setStreaming(true)
            ->setEmitter(function ($event, $payload) use ($controller) {
                $controller->sseEvent($event, $payload);
            });

        $outcome = $loop->run($assembled['messages']);

        if ($outcome->status === AgentOutcome::STATUS_ERROR) {
            Message::create([
                'chat_id' => $chat->id,
                'role'    => Message::ROLE_ASSISTANT,
                'body'    => $outcome->error,
                'status'  => Message::STATUS_ERROR,
                'meta'    => ['error_type' => $outcome->error_type],
            ]);

            $this->sseEvent('failure', ['message' => $outcome->error, 'type' => $outcome->error_type]);

            return;
        }

        $persisted = $this->persistTurns($chat, $outcome);
        $chat->touch();

        foreach ($outcome->notices as $notice) {
            $this->sseEvent('notice', ['message' => $notice]);
        }

        // The authoritative render replaces whatever the client built from the
        // deltas, so a stale browser-side renderer can never be what is kept.
        $this->sseEvent('done', [
            'messages' => $persisted,
            'pending'  => $outcome->pending ? $outcome->pending->toPanelArray() : null,
            'usage'    => $outcome->usage,
            'duration' => round($outcome->duration, 2),
            // Cumulative, not the delta: a client that missed a mid-turn frame
            // converges here, and re-applying costs nothing because the browser
            // skips threads already in the DOM (core main.js:3821).
            'changes'  => ChangeCollector::instance()->snapshot(),
        ]);
    }

    /**
     * Wrap a callback in a correctly-configured SSE response.
     *
     * Three things matter here and all three have bitten people:
     *   - X-Accel-Buffering: no, or nginx buffers the whole stream and the user
     *     sees nothing until it finishes;
     *   - every output buffer flushed and implicit flush on, or PHP-FPM does
     *     the same thing one layer down;
     *   - session_write_close(), or Laravel's session file lock blocks every
     *     other request from this user for the whole run.
     *
     * @param callable $callback
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function sse(callable $callback)
    {
        // Real-time flushing only makes sense when there is a socket on the
        // other end. Under CLI (the test suite) there is not: tearing down the
        // output buffers and flushing every frame would push the body straight
        // to stdout, where a test cannot capture and assert on it.
        $this->sse_realtime = !app()->runningInConsole();

        $realtime = $this->sse_realtime;

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($callback, $realtime) {
            if ($realtime) {
                @set_time_limit(0);
                @ini_set('zlib.output_compression', '0');
                @ini_set('output_buffering', '0');
                @ini_set('implicit_flush', '1');

                // Stop as soon as the user closes the panel or navigates away.
                ignore_user_abort(false);

                // Unwind every output buffer between us and the socket.
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }

                ob_implicit_flush(true);
            }

            if ($realtime) {
                // Padding: some proxies will not forward anything until they
                // have seen a couple of KB.
                echo ':'.str_repeat(' ', 2048)."\n\n";
            }

            try {
                $callback();
            } catch (\Exception $e) {
                \Helper::logException($e, '[AiChatPanel] Streaming failed: ');
                $this->sseEvent('failure', ['message' => __('The AI endpoint returned an error.')]);
            }

            $this->sseEvent('end', []);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Connection', 'keep-alive');
        // nginx buffers FastCGI responses by default; this disables it per
        // response, so no nginx config change is needed.
        $response->headers->set('X-Accel-Buffering', 'no');

        // Laravel holds the session file lock for the whole request; without
        // this every other request from this user queues behind the stream.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        return $response;
    }

    /**
     * Emit one SSE frame. Public because the agent loop's emitter calls it.
     *
     * @param string $event
     * @param array  $payload
     *
     * @return void
     */
    public function sseEvent($event, array $payload = [])
    {
        echo 'event: '.$event."\n";
        echo 'data: '.\Helper::jsonEncodeSafe($payload)."\n\n";

        if (!$this->sse_realtime) {
            return;
        }

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        @flush();
    }

    /**
     * An SSE response that only carries an error. Answering a failed stream
     * with JSON would leave EventSource retrying forever.
     *
     * @param string $message
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function streamError($message)
    {
        return $this->sse(function () use ($message) {
            $this->sseEvent('failure', ['message' => $message]);
        });
    }

    /**
     * Approve or reject a pending write tool.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirm(Request $request)
    {
        try {
            $context = $this->resolve($request);
        } catch (\Exception $e) {
            return $this->denied($e);
        }

        if ($limit = $this->rateLimited($context, 'tools')) {
            return $limit;
        }

        $chat = Chat::findOrCreateFor($context->conversation->id, $context->user->id);

        $pending = $this->pendingWriteFor($chat);

        if (!$pending) {
            return \Response::json([
                'status' => 'error',
                'msg'    => __('There is nothing waiting for confirmation.'),
            ]);
        }

        // The client says which call it is confirming; if that disagrees with
        // what is actually pending, refuse rather than run the wrong thing.
        $tool_call_id = (string) $request->input('tool_call_id', '');

        if ($tool_call_id !== $pending['tool_call_id']) {
            return \Response::json([
                'status' => 'error',
                'msg'    => __('That action is no longer the one waiting for confirmation. Reload the conversation.'),
            ]);
        }

        $approved = filter_var($request->input('approved'), FILTER_VALIDATE_BOOLEAN);

        $registry = new ToolRegistry($context);
        $new_panel_messages = [];

        if ($approved) {
            // Arguments come from the stored assistant turn, never from the
            // request: the client may not change what it is approving.
            $result = $registry->execute($pending['tool'], \Helper::jsonEncodeSafe($pending['arguments']), [
                'confirmed' => true,
                'chat_id'   => $chat->id,
            ]);
        } else {
            $result = \Modules\AiChatPanel\Services\Tools\ToolResult::error(
                'The user rejected this action. Do not try it again unless they ask for it.'
            );

            ToolCall::record([
                'user_id'         => $context->user->id,
                'conversation_id' => $context->conversation->id,
                'mailbox_id'      => $context->mailbox ? $context->mailbox->id : null,
                'chat_id'         => $chat->id,
                'tool'            => $pending['tool'],
                'mode'            => ToolCall::MODE_WRITE,
                'status'          => ToolCall::STATUS_REJECTED,
                'arguments'       => $pending['arguments'],
            ]);
        }

        $tool_message = Message::create([
            'chat_id'      => $chat->id,
            'role'         => Message::ROLE_TOOL,
            'body'         => $result->toToolMessageContent(),
            'tool_call_id' => $pending['tool_call_id'],
            'tool_name'    => $pending['tool'],
            'status'       => $result->ok ? Message::STATUS_OK : Message::STATUS_ERROR,
            'meta'         => ['summary' => $result->summary, 'confirmed' => $approved],
        ]);

        // The assistant turn is no longer waiting on anything.
        $this->clearPendingFlag($chat);

        $new_panel_messages[] = $tool_message->toPanelArray();

        $model = $chat->model ?: $this->resolveModel($request, $context, $chat);

        // Asked for again rather than remembered from send(): the agent may
        // have carried on typing while the confirmation was open.
        $draft = $this->editorDraft($request);
        $mode = $this->editorMode($request);

        if ($request->input('stream')) {
            return \Response::json([
                'status'     => 'success',
                'messages'   => $new_panel_messages,
                'stream_url' => $this->openStream($context, $chat, $model, $draft, $mode),
                // The approved write ran above, in *this* request. The
                // follow-up turn is a separate request whose collector starts
                // empty, so if the change set does not ride out here it never
                // reaches the browser at all.
                'changes'    => ChangeCollector::instance()->snapshot(),
            ]);
        }

        return $this->runAndRespond($context, $chat, $model, $new_panel_messages, $draft, $mode);
    }

    /**
     * Start a fresh chat for this conversation.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function reset(Request $request)
    {
        try {
            $context = $this->resolve($request);
        } catch (\Exception $e) {
            return $this->denied($e);
        }

        $chat = Chat::findOrCreateFor($context->conversation->id, $context->user->id);
        $chat->reset();

        return \Response::json([
            'status'   => 'success',
            'messages' => [],
        ]);
    }

    /**
     * Remember the panel's open state, width and model choice.
     *
     * No conversation involved, so this only needs an authenticated user.
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function prefs(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return \Response::json(['status' => 'error', 'msg' => __('Not authenticated.')], 403);
        }

        $pref = UserPref::forUser($user->id);

        if ($request->has('panel_open')) {
            $pref->panel_open = filter_var($request->input('panel_open'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($request->has('panel_width')) {
            $pref->panel_width = UserPref::clampWidth($request->input('panel_width'));
        }

        if ($request->has('last_model')) {
            $model = (string) $request->input('last_model');

            // Only remember a model the admin actually allows.
            if (Settings::modelAllowed($model)) {
                $pref->last_model = $model;
            }
        }

        $pref->save();

        return \Response::json(['status' => 'success']);
    }

    // =======================================================================
    // Internals
    // =======================================================================

    /**
     * Run the agent loop, persist what it produced, and answer the panel.
     *
     * @param PanelContext $context
     * @param Chat         $chat
     * @param string       $model
     * @param array        $prefix_messages Already-persisted turns to echo back.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function runAndRespond(
        PanelContext $context,
        Chat $chat,
        $model,
        array $prefix_messages,
        $draft = '',
        $mode = 'reply'
    ) {
        try {
            $client = CurlLlmClient::fromSettings();
        } catch (LlmException $e) {
            return \Response::json(['status' => 'error', 'msg' => $e->userMessage()]);
        }

        $registry = new ToolRegistry($context);

        $assembled = $this->buildMessages($context, $chat, $registry, $draft, $mode);

        $loop = new AgentLoop($client, $registry, $context, $model);
        $loop->setChatId($chat->id);

        $outcome = $loop->run($assembled['messages']);

        foreach ($assembled['notices'] as $notice) {
            $outcome->notice($notice);
        }

        if ($outcome->status === AgentOutcome::STATUS_ERROR) {
            // Persist the failure so reopening the chat shows what happened
            // rather than a silent gap.
            $error_message = Message::create([
                'chat_id' => $chat->id,
                'role'    => Message::ROLE_ASSISTANT,
                'body'    => $outcome->error,
                'status'  => Message::STATUS_ERROR,
                'meta'    => ['error_type' => $outcome->error_type],
            ]);

            return \Response::json([
                'status'     => 'error',
                'msg'        => $outcome->error,
                'error_type' => $outcome->error_type,
                'messages'   => array_merge($prefix_messages, [$error_message->toPanelArray()]),
                // Also on the error path: an approved write may have succeeded
                // before the completion that followed it failed. The panel
                // showing an error is no reason to hide the draft.
                'changes'    => ChangeCollector::instance()->snapshot(),
            ]);
        }

        $persisted = $this->persistTurns($chat, $outcome);

        $chat->touch();

        return \Response::json([
            'status'   => 'success',
            'messages' => array_merge($prefix_messages, $persisted),
            'notices'  => $outcome->notices,
            'usage'    => $outcome->usage,
            'duration' => round($outcome->duration, 2),
            'pending'  => $outcome->pending ? $outcome->pending->toPanelArray() : null,
            'changes'  => ChangeCollector::instance()->snapshot(),
        ]);
    }

    /**
     * The reply editor's current content, as Markdown, for the prompt.
     *
     * Converted here rather than in the browser: the server render is the
     * source of truth everywhere else in this module, and Markdown is a
     * fraction of the size of the editor's HTML when it has to be cached for
     * the streaming request.
     *
     * @param Request $request
     *
     * @return string
     */
    protected function editorDraft(Request $request)
    {
        $html = (string) $request->input('editor_body', '');

        if (mb_strlen($html) > 200000) {
            $html = mb_substr($html, 0, 200000);
        }

        if (trim(strip_tags($html)) === '') {
            return '';
        }

        $markdown = HtmlToMarkdown::fromEditor($html);

        if (mb_strlen($markdown) > 20000) {
            $markdown = mb_substr($markdown, 0, 20000)."\n\n[…truncated]";
        }

        return $markdown;
    }

    /**
     * @param Request $request
     *
     * @return string 'reply' | 'note'
     */
    protected function editorMode(Request $request)
    {
        return $request->input('editor_mode') === 'note' ? 'note' : 'reply';
    }

    /**
     * @param Chat         $chat
     * @param AgentOutcome $outcome
     *
     * @return array
     */
    protected function persistTurns(Chat $chat, AgentOutcome $outcome)
    {
        $panel = [];

        foreach ($outcome->turns as $turn) {
            $attributes = array_merge([
                'chat_id'   => $chat->id,
                'status'    => Message::STATUS_OK,
                'body'      => '',
                'reasoning' => '',
            ], $turn);

            // Render assistant text server-side. The client renders streaming
            // deltas for responsiveness, but the stored, authoritative version
            // is this one.
            if ($attributes['role'] == Message::ROLE_ASSISTANT && trim((string) $attributes['body']) !== '') {
                $attributes['body_html'] = MarkdownRenderer::render($attributes['body']);
            }

            $message = Message::create($attributes);

            // Tool turns are shown as compact activity rows, not bubbles, and
            // the raw JSON payload is not useful to the user.
            $panel[] = $message->toPanelArray();
        }

        return $panel;
    }

    /**
     * Assemble the messages for one turn: the system message, then as much of
     * the chat as fits.
     *
     * The order matters. The chat is windowed FIRST, and it is the windowed
     * cost — not the raw one — that the context builder reserves. That is what
     * stops a long chat from crowding the conversation out of the system
     * message: the reservation is now bounded by the history's own share of the
     * budget, so there is always something left for the ticket.
     *
     * Whatever the window dropped comes back as a rollup line appended to the
     * system message rather than as an extra chat message. It is context about
     * the chat, not a turn in it, and putting it here avoids inventing a role
     * for it that a strict endpoint might reject.
     *
     * @param PanelContext $context
     * @param Chat         $chat
     * @param ToolRegistry $registry
     * @param string       $draft
     * @param string       $mode
     *
     * @return array ['messages' => array, 'notices' => array]
     */
    protected function buildMessages(PanelContext $context, Chat $chat, ToolRegistry $registry, $draft, $mode)
    {
        $window = HistoryWindow::forContext($context)->apply($chat->fresh()->toApiMessages());

        $builder = new ContextBuilder($context);
        $builder->setEditorDraft($draft, $mode);

        $system = $builder->build($window['tokens'] + $this->toolSchemaTokens($registry));

        $content = $system['content'];

        if ($window['rollup'] !== '') {
            $content .= "\n\n".$window['rollup'];
        }

        $notices = [];

        foreach ([$window, $system] as $part) {
            if ($part['truncated']) {
                $notices[] = $part['notice'];
            }
        }

        return [
            'messages' => array_merge([['role' => 'system', 'content' => $content]], $window['messages']),
            'notices'  => $notices,
        ];
    }

    /**
     * What the tool schemas cost.
     *
     * They are sent on every completion in the run, so leaving them out of the
     * budget understates the request by however many tools are switched on.
     *
     * @param ToolRegistry $registry
     *
     * @return int
     */
    protected function toolSchemaTokens(ToolRegistry $registry)
    {
        $definitions = $registry->toApiDefinitions();

        if (!$definitions) {
            return 0;
        }

        return TokenBudget::estimate(\Helper::jsonEncodeSafe($definitions));
    }

    /**
     * The write tool currently waiting on the user, if any.
     *
     * Derived from the stored chat rather than from a session or the client, so
     * it survives a reload and cannot be spoofed.
     *
     * @param Chat $chat
     *
     * @return array|null
     */
    protected function pendingWriteFor(Chat $chat)
    {
        $last_assistant = Message::where('chat_id', $chat->id)
            ->where('role', Message::ROLE_ASSISTANT)
            ->orderBy('id', 'desc')
            ->first();

        if (!$last_assistant || $last_assistant->status != Message::STATUS_PENDING || !$last_assistant->tool_calls) {
            return null;
        }

        // Which of that turn's calls already have an answer?
        $answered = Message::where('chat_id', $chat->id)
            ->where('role', Message::ROLE_TOOL)
            ->where('id', '>', $last_assistant->id)
            ->pluck('tool_call_id')
            ->toArray();

        $context = null;

        foreach ($last_assistant->tool_calls as $call) {
            if (in_array($call['id'], $answered)) {
                continue;
            }

            if ($context === null) {
                $conversation = Conversation::find($chat->conversation_id);

                if (!$conversation) {
                    return null;
                }

                $context = new PanelContext($conversation, auth()->user());
            }

            $registry = new ToolRegistry($context);
            $tool = $registry->find($call['name']);

            if (!$tool || !ToolRegistry::isWrite($tool)) {
                continue;
            }

            list($ok, $arguments) = $registry->validateArguments($tool, $call['arguments']);

            return [
                'tool_call_id' => $call['id'],
                'tool'         => $call['name'],
                'arguments'    => $ok ? $arguments : [],
                'label'        => $tool->confirmationLabel($ok ? $arguments : [], $context),
            ];
        }

        return null;
    }

    /**
     * @param Chat $chat
     *
     * @return void
     */
    protected function clearPendingFlag(Chat $chat)
    {
        Message::where('chat_id', $chat->id)
            ->where('role', Message::ROLE_ASSISTANT)
            ->where('status', Message::STATUS_PENDING)
            ->update(['status' => Message::STATUS_OK]);
    }

    /**
     * @param Chat $chat
     *
     * @return array
     */
    protected function panelMessages(Chat $chat)
    {
        $messages = [];

        foreach ($chat->messages as $message) {
            $messages[] = $message->toPanelArray();
        }

        return $messages;
    }

    /**
     * Which model to use, in order: what the request asked for (if allowed),
     * the chat's remembered one, the user's last choice, the admin default.
     *
     * @param Request      $request
     * @param PanelContext $context
     * @param Chat         $chat
     *
     * @return string
     */
    protected function resolveModel(Request $request, PanelContext $context, Chat $chat)
    {
        $requested = trim((string) $request->input('model', ''));

        if ($requested && Settings::modelAllowed($requested)) {
            return $requested;
        }

        return $this->currentModel($context, $chat);
    }

    /**
     * @param PanelContext $context
     * @param Chat         $chat
     *
     * @return string
     */
    protected function currentModel(PanelContext $context, Chat $chat)
    {
        if ($chat->model && Settings::modelAllowed($chat->model)) {
            return $chat->model;
        }

        $pref = UserPref::forUser($context->user->id);

        if ($pref->last_model && Settings::modelAllowed($pref->last_model)) {
            return $pref->last_model;
        }

        return (string) Settings::get('default_model');
    }

    /**
     * Models the picker may offer.
     *
     * The allowlist is authoritative. When it is empty the endpoint is asked,
     * but that answer is cached briefly — the picker is rendered on every
     * conversation and must not turn into a request storm.
     *
     * @return array
     */
    protected function modelChoices()
    {
        $catalogue = $this->modelCatalogue();
        $allowed = Settings::allowedModels();

        if ($allowed) {
            $by_id = [];

            foreach ($catalogue as $entry) {
                $by_id[$entry['id']] = $entry;
            }

            $catalogue = [];

            foreach ($allowed as $id) {
                // An allowlisted model the endpoint does not describe is still
                // offered under its bare id: the admin typed it on purpose, and
                // the endpoint may simply not implement /v1/models.
                $catalogue[] = isset($by_id[$id]) ? $by_id[$id] : CurlLlmClient::describeModel($id);
            }
        }

        return self::sortModels($catalogue);
    }

    /**
     * What the endpoint says about every model it offers.
     *
     * @return array
     */
    protected function modelCatalogue()
    {
        return \Cache::remember('aichatpanel.model_catalogue', 5, function () {
            try {
                return CurlLlmClient::fromSettings()->catalogue();
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    /**
     * Vendor first, then model, both case-insensitive and natural so that
     * "Llama 4" does not sort before "Llama 31".
     *
     * Ungrouped models go last: they are the hand-entered and single-model
     * cases, and burying them above a vendor heading reads as a mistake.
     *
     * @param array $models
     *
     * @return array
     */
    public static function sortModels(array $models)
    {
        usort($models, function ($a, $b) {
            if (($a['group'] === '') !== ($b['group'] === '')) {
                return $a['group'] === '' ? 1 : -1;
            }

            $group = strnatcasecmp($a['group'], $b['group']);

            return $group ?: strnatcasecmp($a['label'], $b['label']);
        });

        return $models;
    }

    /**
     * What the catalogue says about a model's tool support, or null when it is
     * not listed.
     *
     * @param string $model
     *
     * @return bool|null
     */
    protected function catalogueToolSupport($model)
    {
        foreach ($this->modelCatalogue() as $entry) {
            if ($entry['id'] === $model) {
                return $entry['tools'];
            }
        }

        return null;
    }

    /**
     * What the panel needs to know about tools, for the header hint.
     *
     * @param PanelContext $context
     *
     * @return array
     */
    protected function toolSummary(PanelContext $context)
    {
        $registry = new ToolRegistry($context);
        $available = $registry->available();

        $names = [];

        foreach ($available as $name => $tool) {
            $names[] = [
                'name'  => $name,
                'write' => ToolRegistry::isWrite($tool),
            ];
        }

        $model = $this->currentModel($context, Chat::findOrCreateFor($context->conversation->id, $context->user->id));

        $supports = Settings::modelSupportsTools($model);

        if ($supports === null) {
            // Nothing probed yet. OpenRouter states tool support outright, so
            // use it rather than promising tools until the first failure.
            $supports = $this->catalogueToolSupport($model);
        }

        return [
            'count'           => count($names),
            'tools'           => $names,
            'model_supports'  => $supports !== false,
        ];
    }

    /**
     * Resolve and authorise the conversation this request is about.
     *
     * @param Request $request
     *
     * @return PanelContext
     *
     * @throws \Exception
     */
    protected function resolve(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            throw new \Exception('unauthenticated');
        }

        $conversation_id = (int) $request->input('conversation_id');

        if (!$conversation_id) {
            throw new \Exception('missing conversation');
        }

        $conversation = Conversation::find($conversation_id);

        if (!$conversation) {
            throw new \Exception('conversation not found');
        }

        // Core's policy, not a reimplementation: it covers admin, mailbox
        // membership and the only-assigned-tickets permission.
        if (!$user->can('view', $conversation)) {
            throw new \Exception('forbidden');
        }

        $context = new PanelContext($conversation, $user);

        if (!Settings::isUsable($context->mailbox)) {
            throw new \Exception('disabled');
        }

        // From here on anything this request writes to the conversation is the
        // assistant's doing, and the page behind the panel needs to hear about
        // it. Outside an armed request the collector records nothing, which is
        // what keeps ordinary autosave drafts from broadcasting.
        ChangeCollector::instance()->arm($conversation->id);

        return $context;
    }

    /**
     * One answer for every authorisation failure. Distinguishing "not found"
     * from "not allowed" would leak which conversations exist.
     *
     * @param \Exception $e
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function denied(\Exception $e)
    {
        if ($e->getMessage() === 'disabled') {
            return \Response::json([
                'status' => 'error',
                'msg'    => __('The AI chat panel is not enabled for this mailbox.'),
            ], 403);
        }

        return \Response::json([
            'status' => 'error',
            'msg'    => __('You do not have access to this conversation.'),
        ], 403);
    }

    /**
     * @param PanelContext $context
     * @param string       $bucket
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function rateLimited(PanelContext $context, $bucket)
    {
        $max = (int) Settings::get($bucket === 'tools' ? 'rate_limit_tools' : 'rate_limit_completions');

        if ($max < 1) {
            return null;
        }

        $limiter = app(\Illuminate\Cache\RateLimiter::class);
        $key = 'aichatpanel:'.$bucket.':'.$context->user->id;

        if ($limiter->tooManyAttempts($key, $max)) {
            return \Response::json([
                'status' => 'error',
                'msg'    => __('You are sending messages too quickly. Wait a moment and try again.'),
            ], 429);
        }

        // Laravel 5.5's RateLimiter counts decay in MINUTES, not seconds.
        $limiter->hit($key, 1);

        return null;
    }
}
