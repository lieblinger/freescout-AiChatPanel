<?php

namespace Modules\AiChatPanel\Services\Agent;

use Modules\AiChatPanel\Entities\Message;
use Modules\AiChatPanel\Services\Llm\ChatResponse;
use Modules\AiChatPanel\Services\Llm\LlmClient;
use Modules\AiChatPanel\Services\Llm\LlmException;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Settings;
use Modules\AiChatPanel\Services\Tools\ToolRegistry;

/**
 * Completion → tool calls → completion, until there is an answer.
 *
 * Bounded three ways, because an agent loop against a self-hosted model is
 * otherwise a very effective way to hang a PHP-FPM worker: a hard iteration
 * cap, a wall-clock cap, and the registry's own per-tool checks.
 *
 * Writes stop the loop. When the model asks for a write tool, the loop returns
 * STATUS_AWAITING_CONFIRMATION and the caller shows a dialog; nothing is
 * executed until the user comes back. Read tools in the same turn are executed
 * first so the pause happens with as much already done as possible.
 *
 * The loop never persists chat messages. It returns the turns it produced and
 * the caller writes them, so an interrupted run and the stored history cannot
 * disagree.
 */
class AgentLoop
{
    /** @var LlmClient */
    protected $client;

    /** @var ToolRegistry */
    protected $registry;

    /** @var PanelContext */
    protected $context;

    /** @var string */
    protected $model;

    /** @var bool */
    protected $stream = false;

    /** @var callable|null */
    protected $emit = null;

    /** @var bool Flipped off when the endpoint or the model cannot do tools. */
    protected $tools_enabled = true;

    /** @var int|null */
    protected $chat_id = null;

    /**
     * @param LlmClient    $client
     * @param ToolRegistry $registry
     * @param PanelContext $context
     * @param string       $model
     */
    public function __construct(LlmClient $client, ToolRegistry $registry, PanelContext $context, $model)
    {
        $this->client = $client;
        $this->registry = $registry;
        $this->context = $context;
        $this->model = $model;
    }

    /**
     * Stream deltas instead of waiting for the whole answer.
     *
     * @param bool $stream
     *
     * @return $this
     */
    public function setStreaming($stream)
    {
        $this->stream = (bool) $stream;

        return $this;
    }

    /**
     * Callback for progress events: ('delta'|'reasoning'|'tool_call'|'tool_result'|'notice', array $payload).
     *
     * @param callable|null $emit
     *
     * @return $this
     */
    public function setEmitter($emit)
    {
        $this->emit = $emit;

        return $this;
    }

    /**
     * The chat these tool calls belong to, for the audit log.
     *
     * @param int|null $chat_id
     *
     * @return $this
     */
    public function setChatId($chat_id)
    {
        $this->chat_id = $chat_id;

        return $this;
    }

    /**
     * Run until an answer, a write confirmation, or a cap.
     *
     * @param array $messages Full API message list, system message first.
     *
     * @return AgentOutcome
     */
    public function run(array $messages)
    {
        $outcome = new AgentOutcome();
        $outcome->model = $this->model;

        $started = microtime(true);

        $max_iterations = $this->maxIterations();
        $deadline = $started + $this->maxSeconds();

        $this->tools_enabled = $this->toolsUsable($outcome);

        for ($iteration = 1; $iteration <= $max_iterations; $iteration++) {
            $outcome->iterations = $iteration;

            if (microtime(true) >= $deadline) {
                $outcome->notice(__('The assistant ran out of time while using tools. This is what it had so far.'));
                break;
            }

            try {
                $response = $this->complete($messages, $outcome);
            } catch (LlmException $e) {
                return $this->fail($outcome, $e, $started);
            }

            $this->accumulateUsage($outcome, $response);

            if ($response->wasTruncated()) {
                $outcome->notice(__('The reply was cut off because it hit the response token limit. Raise "Max response tokens" in the settings for longer answers.'));
            }

            if (!$response->hasToolCalls()) {
                $outcome->turns[] = $this->assistantTurn($response);
                $outcome->status = AgentOutcome::STATUS_COMPLETE;
                $outcome->duration = microtime(true) - $started;

                return $outcome;
            }

            // The assistant turn that asked for the tools has to be replayed
            // verbatim, and every one of its calls needs an answer.
            $outcome->turns[] = $this->assistantTurn($response);
            $messages[] = $response->toHistoryMessage();

            $paused = $this->runToolCalls($response, $outcome, $messages);

            if ($paused) {
                $outcome->status = AgentOutcome::STATUS_AWAITING_CONFIRMATION;
                $outcome->duration = microtime(true) - $started;

                return $outcome;
            }
        }

        // Fell out of the loop: the cap was hit while still calling tools.
        if ($outcome->status !== AgentOutcome::STATUS_AWAITING_CONFIRMATION) {
            $outcome->notice(__('The assistant reached the limit of :count tool steps for one message. Ask it to continue if you need more.', [
                'count' => $max_iterations,
            ]));

            $outcome->status = AgentOutcome::STATUS_COMPLETE;
        }

        $outcome->duration = microtime(true) - $started;

        return $outcome;
    }

    // -----------------------------------------------------------------------

    /**
     * Execute the tool calls of one assistant turn.
     *
     * Reads run immediately. The first write stops the run. Any further writes
     * in the same turn are answered with a "deferred" result rather than being
     * silently dropped, because the endpoint requires every tool_call id to get
     * a reply before the next completion.
     *
     * @param ChatResponse $response
     * @param AgentOutcome $outcome
     * @param array        $messages
     *
     * @return bool Whether the run paused for a confirmation.
     */
    protected function runToolCalls(ChatResponse $response, AgentOutcome $outcome, array &$messages)
    {
        $paused = false;

        foreach ($response->tool_calls as $call) {
            $tool = $this->registry->find($call['name']);
            $is_write = $tool && ToolRegistry::isWrite($tool);

            if ($is_write && !$this->registry->mayAutoRun($tool)) {
                if ($paused) {
                    // A second write in the same turn: answer it so the
                    // protocol stays valid, but do not queue a second dialog.
                    $deferred = \Modules\AiChatPanel\Services\Tools\ToolResult::error(
                        'Not executed: the user is being asked about another action first. '
                        .'Request this again afterwards if it is still needed.'
                    );

                    $this->recordToolResult($outcome, $messages, $call, $deferred);
                    continue;
                }

                list($ok, $arguments, $error) = $this->registry->validateArguments($tool, $call['arguments']);

                if (!$ok) {
                    $result = \Modules\AiChatPanel\Services\Tools\ToolResult::error($error);
                    $this->recordToolResult($outcome, $messages, $call, $result);
                    continue;
                }

                $pending = new PendingWrite();
                $pending->tool_call_id = $call['id'];
                $pending->tool = $call['name'];
                $pending->arguments = $arguments;
                $pending->label = $this->confirmationLabel($tool, $arguments);

                $outcome->pending = $pending;
                $paused = true;

                $this->emit('confirm_required', $pending->toPanelArray());

                continue;
            }

            $this->emit('tool_call', [
                'tool'      => $call['name'],
                'arguments' => $call['arguments'],
            ]);

            $result = $this->registry->execute($call['name'], $call['arguments'], [
                'confirmed' => false,
                'chat_id'   => $this->chat_id,
            ]);

            $this->recordToolResult($outcome, $messages, $call, $result);

            $this->emit('tool_result', [
                'tool'    => $call['name'],
                'ok'      => $result->ok,
                'summary' => $result->summary ?: ($result->ok ? '' : $result->error),
            ]);
        }

        return $paused;
    }

    /**
     * @return void
     */
    protected function recordToolResult(AgentOutcome $outcome, array &$messages, array $call, $result)
    {
        $content = $result->toToolMessageContent();

        $outcome->turns[] = [
            'role'         => Message::ROLE_TOOL,
            'body'         => $content,
            'tool_call_id' => $call['id'],
            'tool_name'    => $call['name'],
            'status'       => $result->ok ? Message::STATUS_OK : Message::STATUS_ERROR,
            'meta'         => ['summary' => $result->summary],
        ];

        $messages[] = [
            'role'         => 'tool',
            'tool_call_id' => $call['id'],
            'content'      => $content,
        ];
    }

    /**
     * One completion, with a single retry without tools if the endpoint turns
     * out not to support them.
     *
     * @param array        $messages
     * @param AgentOutcome $outcome
     *
     * @return ChatResponse
     *
     * @throws LlmException
     */
    protected function complete(array $messages, AgentOutcome $outcome)
    {
        $payload = $this->payload($messages);

        try {
            return $this->send($payload);
        } catch (LlmException $e) {
            if ($e->getType() !== LlmException::TYPE_TOOLS_UNSUPPORTED || empty($payload['tools'])) {
                throw $e;
            }

            // The endpoint rejected the tools parameter outright. Remember it,
            // tell the user, and answer without tools rather than failing.
            $this->tools_enabled = false;

            Settings::rememberModelToolSupport($this->model, false);

            $outcome->notice(__('This model does not support tools, so the assistant answered without them.'));
            $this->emit('notice', ['message' => __('This model does not support tools, so the assistant answered without them.')]);

            unset($payload['tools'], $payload['tool_choice']);

            return $this->send($payload);
        }
    }

    /**
     * @param array $payload
     *
     * @return ChatResponse
     *
     * @throws LlmException
     */
    protected function send(array $payload)
    {
        if (!$this->stream) {
            return $this->client->chat($payload);
        }

        $emit = $this->emit;

        return $this->client->stream($payload, function ($delta) use ($emit) {
            if (!$emit) {
                return;
            }

            if (isset($delta['content'])) {
                $emit('delta', ['content' => $delta['content']]);
            } elseif (isset($delta['reasoning'])) {
                $emit('reasoning', ['content' => $delta['reasoning']]);
            }
        });
    }

    /**
     * @param array $messages
     *
     * @return array
     */
    protected function payload(array $messages)
    {
        $payload = [
            'model'       => $this->model,
            'messages'    => array_values($messages),
            'temperature' => (float) Settings::get('temperature'),
            'max_tokens'  => (int) Settings::get('max_response_tokens'),
        ];

        if ($this->tools_enabled) {
            $definitions = $this->registry->toApiDefinitions();

            if ($definitions) {
                $payload['tools'] = $definitions;
                $payload['tool_choice'] = 'auto';
            }
        }

        if (Settings::get('log_prompts')) {
            // Opt-in only: this contains customer data. The API key is not part
            // of the payload and is never logged.
            \Log::debug('[AiChatPanel] Request payload: '.\Helper::jsonEncodeSafe($payload));
        }

        return $payload;
    }

    /**
     * Whether tools should be offered at all for this model.
     *
     * @param AgentOutcome $outcome
     *
     * @return bool
     */
    protected function toolsUsable(AgentOutcome $outcome)
    {
        $supported = Settings::modelSupportsTools($this->model);

        // null means "not probed yet": try, and learn from the answer.
        if ($supported === false) {
            if ($this->registry->available()) {
                $outcome->notice(__('Tools are turned off for this model because it does not support them.'));
            }

            return false;
        }

        return true;
    }

    /**
     * @param ChatResponse $response
     *
     * @return array
     */
    protected function assistantTurn(ChatResponse $response)
    {
        $meta = [];

        if ($response->usage) {
            $meta['usage'] = $response->usage;
        }

        if ($response->duration) {
            $meta['duration'] = round($response->duration, 2);
        }

        if ($response->tokens_per_second) {
            $meta['tokens_per_second'] = round($response->tokens_per_second, 1);
        }

        if ($response->finish_reason) {
            $meta['finish_reason'] = $response->finish_reason;
        }

        return [
            'role'       => Message::ROLE_ASSISTANT,
            'body'       => $response->content,
            'reasoning'  => $response->reasoning,
            'tool_calls' => $response->tool_calls ?: null,
            'status'     => $response->hasToolCalls() ? Message::STATUS_PENDING : Message::STATUS_OK,
            'meta'       => $meta,
        ];
    }

    /**
     * @return AgentOutcome
     */
    protected function fail(AgentOutcome $outcome, LlmException $e, $started)
    {
        \Helper::logException($e, '[AiChatPanel] Completion failed ('.$e->getType().'): ');

        $outcome->status = AgentOutcome::STATUS_ERROR;
        $outcome->error = $e->userMessage();
        $outcome->error_type = $e->getType();
        $outcome->duration = microtime(true) - $started;

        return $outcome;
    }

    /**
     * @return void
     */
    protected function accumulateUsage(AgentOutcome $outcome, ChatResponse $response)
    {
        if (!$response->usage) {
            return;
        }

        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            if (isset($response->usage[$key])) {
                $current = isset($outcome->usage[$key]) ? $outcome->usage[$key] : 0;
                $outcome->usage[$key] = $current + (int) $response->usage[$key];
            }
        }
    }

    /**
     * @return string
     */
    protected function confirmationLabel($tool, array $arguments)
    {
        try {
            return (string) $tool->confirmationLabel($arguments, $this->context);
        } catch (\Exception $e) {
            return __('Run :tool', ['tool' => $tool->name()]);
        }
    }

    /**
     * @return int
     */
    protected function maxIterations()
    {
        $configured = (int) Settings::get('max_tool_iterations');
        $ceiling = (int) Settings::limit('max_tool_iterations', 10);

        return max(1, min($ceiling, $configured ?: 1));
    }

    /**
     * @return int
     */
    protected function maxSeconds()
    {
        $configured = (int) Settings::get('max_tool_seconds');
        $ceiling = (int) Settings::limit('max_tool_seconds', 120);

        return max(5, min($ceiling, $configured ?: 60));
    }

    /**
     * @return void
     */
    protected function emit($event, array $payload = [])
    {
        if ($this->emit) {
            call_user_func($this->emit, $event, $payload);
        }
    }
}
