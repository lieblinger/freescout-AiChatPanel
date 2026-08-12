<?php

namespace Modules\AiChatPanel\Services\Llm;

/**
 * One assistant turn, normalised across endpoints.
 *
 * Deliberately separates content from reasoning: reasoning models (Qwen3.5 and
 * friends) put their chain of thought in a "reasoning_content" field and leave
 * "content" empty until they are done thinking. Treating an empty content as an
 * empty answer is wrong, and replaying reasoning back into the conversation
 * history is both wasteful and confusing to the model.
 */
class ChatResponse
{
    /** @var string */
    public $content = '';

    /** @var string */
    public $reasoning = '';

    /**
     * Normalised tool calls: [['id' => .., 'name' => .., 'arguments' => raw json string], ...]
     *
     * @var array
     */
    public $tool_calls = [];

    /** @var string 'stop' | 'length' | 'tool_calls' | '' */
    public $finish_reason = '';

    /** @var array */
    public $usage = [];

    /** @var string */
    public $model = '';

    /** @var float Seconds spent on the request. */
    public $duration = 0.0;

    /**
     * Tokens per second, when the endpoint reports it (llama.cpp does, under a
     * non-standard "timings" key).
     *
     * @var float|null
     */
    public $tokens_per_second = null;

    /**
     * Build from a decoded /v1/chat/completions body.
     *
     * @param array $body
     *
     * @return static
     *
     * @throws LlmException
     */
    public static function fromArray(array $body)
    {
        if (!isset($body['choices'][0])) {
            throw new LlmException(
                LlmException::TYPE_INVALID_RESPONSE,
                'Response has no choices[0]',
                0,
                LlmException::excerpt(\Helper::jsonEncodeSafe($body))
            );
        }

        $choice = $body['choices'][0];
        $message = isset($choice['message']) && is_array($choice['message']) ? $choice['message'] : [];

        $response = new static();

        $response->content = self::str($message, 'content');
        $response->reasoning = self::str($message, 'reasoning_content');
        $response->finish_reason = isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : '';
        $response->model = isset($body['model']) ? (string) $body['model'] : '';
        $response->usage = isset($body['usage']) && is_array($body['usage']) ? $body['usage'] : [];

        if (isset($body['timings']['predicted_per_second'])) {
            $response->tokens_per_second = (float) $body['timings']['predicted_per_second'];
        }

        if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $i => $call) {
                $response->tool_calls[] = self::normaliseToolCall($call, $i);
            }
        }

        return $response;
    }

    /**
     * Normalise one tool call. "arguments" stays a raw string here — decoding
     * and schema validation belong to the tool layer, which has to report a
     * structured error back to the model when they fail.
     *
     * @param array $call
     * @param int   $index
     *
     * @return array
     */
    public static function normaliseToolCall($call, $index = 0)
    {
        $function = isset($call['function']) && is_array($call['function']) ? $call['function'] : [];

        return [
            'id'        => !empty($call['id']) ? (string) $call['id'] : 'call_'.$index,
            'name'      => isset($function['name']) ? (string) $function['name'] : '',
            'arguments' => isset($function['arguments']) ? (string) $function['arguments'] : '',
        ];
    }

    /**
     * Whether this turn asked for tools to run.
     *
     * @return bool
     */
    public function hasToolCalls()
    {
        return !empty($this->tool_calls);
    }

    /**
     * True when the model ran out of budget mid-answer. Worth surfacing: with a
     * reasoning model this can produce an entirely empty content.
     *
     * @return bool
     */
    public function wasTruncated()
    {
        return $this->finish_reason === 'length';
    }

    /**
     * The assistant message to replay in the next request. Reasoning is
     * deliberately dropped.
     *
     * @return array
     */
    public function toHistoryMessage()
    {
        $message = [
            'role'    => 'assistant',
            'content' => $this->content,
        ];

        if ($this->tool_calls) {
            $message['tool_calls'] = [];

            foreach ($this->tool_calls as $call) {
                $message['tool_calls'][] = [
                    'id'       => $call['id'],
                    'type'     => 'function',
                    'function' => [
                        'name'      => $call['name'],
                        'arguments' => $call['arguments'],
                    ],
                ];
            }
        }

        return $message;
    }

    /**
     * @param array  $array
     * @param string $key
     *
     * @return string
     */
    protected static function str($array, $key)
    {
        return isset($array[$key]) && is_scalar($array[$key]) ? (string) $array[$key] : '';
    }
}
