<?php

namespace Modules\AiChatPanel\Services\Llm;

/**
 * Scripted client for tests. No network access.
 *
 * Queue up what the endpoint should answer, then assert on what was sent:
 *
 *     $client = new FakeLlmClient();
 *     $client->queueToolCall('conversation_get', ['number' => 7]);
 *     $client->queueText('Conversation 7 is about billing.');
 *     ...
 *     $this->assertCount(2, $client->payloads);
 */
class FakeLlmClient implements LlmClient
{
    /** @var string[] */
    public $models = ['fake-model'];

    /**
     * Queued answers, each a ChatResponse or an LlmException to throw.
     *
     * @var array
     */
    public $queue = [];

    /**
     * Every payload passed to chat()/stream(), in order.
     *
     * @var array
     */
    public $payloads = [];

    /**
     * Set to throw from models().
     *
     * @var LlmException|null
     */
    public $models_exception = null;

    /**
     * Queue a plain text answer.
     *
     * @param string $text
     *
     * @return $this
     */
    public function queueText($text)
    {
        $response = new ChatResponse();
        $response->content = $text;
        $response->finish_reason = 'stop';
        $response->model = $this->models ? $this->models[0] : 'fake-model';

        $this->queue[] = $response;

        return $this;
    }

    /**
     * Queue a turn that asks for one tool.
     *
     * @param string       $name
     * @param array|string $arguments Array is encoded; a string is sent as-is,
     *                                which is how malformed JSON is simulated.
     * @param string       $id
     *
     * @return $this
     */
    public function queueToolCall($name, $arguments = [], $id = null)
    {
        $response = new ChatResponse();
        $response->finish_reason = 'tool_calls';
        $response->model = $this->models ? $this->models[0] : 'fake-model';
        $response->tool_calls = [[
            'id'        => $id ?: 'call_'.(count($this->queue) + 1),
            'name'      => $name,
            'arguments' => is_string($arguments) ? $arguments : \Helper::jsonEncodeSafe($arguments),
        ]];

        $this->queue[] = $response;

        return $this;
    }

    /**
     * Queue several tool calls in a single turn.
     *
     * @param array $calls [['name' => .., 'arguments' => ..], ...]
     *
     * @return $this
     */
    public function queueToolCalls(array $calls)
    {
        $response = new ChatResponse();
        $response->finish_reason = 'tool_calls';
        $response->model = $this->models ? $this->models[0] : 'fake-model';

        foreach ($calls as $i => $call) {
            $arguments = isset($call['arguments']) ? $call['arguments'] : [];

            $response->tool_calls[] = [
                'id'        => isset($call['id']) ? $call['id'] : 'call_'.count($this->queue).'_'.$i,
                'name'      => $call['name'],
                'arguments' => is_string($arguments) ? $arguments : \Helper::jsonEncodeSafe($arguments),
            ];
        }

        $this->queue[] = $response;

        return $this;
    }

    /**
     * Queue a failure.
     *
     * @param LlmException $exception
     *
     * @return $this
     */
    public function queueException(LlmException $exception)
    {
        $this->queue[] = $exception;

        return $this;
    }

    /**
     * Queue a ChatResponse built by the caller.
     *
     * @param ChatResponse $response
     *
     * @return $this
     */
    public function queueResponse(ChatResponse $response)
    {
        $this->queue[] = $response;

        return $this;
    }

    /**
     * The payload of the nth call, or the last one.
     *
     * @param int|null $index
     *
     * @return array|null
     */
    public function payload($index = null)
    {
        if ($index === null) {
            return $this->payloads ? end($this->payloads) : null;
        }

        return isset($this->payloads[$index]) ? $this->payloads[$index] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function models()
    {
        if ($this->models_exception) {
            throw $this->models_exception;
        }

        return $this->models;
    }

    /**
     * {@inheritdoc}
     */
    public function chat(array $payload)
    {
        $this->payloads[] = $payload;

        if (!$this->queue) {
            throw new LlmException(
                LlmException::TYPE_INVALID_RESPONSE,
                'FakeLlmClient ran out of queued responses after '.count($this->payloads).' call(s)'
            );
        }

        $next = array_shift($this->queue);

        if ($next instanceof LlmException) {
            throw $next;
        }

        return $next;
    }

    /**
     * {@inheritdoc}
     */
    public function stream(array $payload, callable $on_delta)
    {
        $response = $this->chat($payload);

        // Emit the answer in a few pieces so streaming consumers are exercised.
        if ($response->content !== '') {
            foreach (str_split($response->content, 16) as $piece) {
                $on_delta(['content' => $piece]);
            }
        }

        return $response;
    }
}
