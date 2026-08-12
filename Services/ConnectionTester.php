<?php

namespace Modules\AiChatPanel\Services;

use Modules\AiChatPanel\Services\Llm\LlmClient;
use Modules\AiChatPanel\Services\Llm\LlmException;

/**
 * Backs the "Test connection" button.
 *
 * Three separate probes, because they fail independently and an admin needs to
 * know which one broke:
 *
 *   1. /v1/models   — optional; some endpoints do not implement it
 *   2. completion   — the one that actually matters, and the one that catches
 *                     an endpoint serving /v1/models without authentication
 *                     while rejecting everything else
 *   3. tool call    — a trivial probe, so the per-model tool flag can default
 *                     to something real instead of a guess
 *
 * Failures report the HTTP status and a body excerpt verbatim. A generic
 * "connection failed" is useless when pointing at a self-hosted endpoint.
 */
class ConnectionTester
{
    /** @var LlmClient */
    protected $client;

    /**
     * @param LlmClient $client
     */
    public function __construct(LlmClient $client)
    {
        $this->client = $client;
    }

    /**
     * @param string $model Model to probe with; falls back to the first one the
     *                      endpoint lists.
     *
     * @return array
     */
    public function run($model = '')
    {
        $result = [
            'ok'      => false,
            'models'  => [
                'ok'      => false,
                'message' => '',
                'count'   => 0,
                'list'    => [],
            ],
            'completion' => [
                'ok'      => false,
                'message' => '',
                'detail'  => '',
            ],
            'tools' => [
                'ok'        => false,
                'supported' => false,
                'message'   => '',
                'detail'    => '',
            ],
        ];

        // -- 1. Models -----------------------------------------------------
        try {
            $models = $this->client->models();

            $result['models']['ok'] = true;
            $result['models']['count'] = count($models);
            $result['models']['list'] = $models;
            $result['models']['message'] = $models
                ? __(':count model(s) available.', ['count' => count($models)])
                : __('The endpoint does not list models. Enter a model name manually.');

            if (!$model && $models) {
                $model = $models[0];
            }
        } catch (LlmException $e) {
            $result['models']['message'] = $e->userMessage();
            $result['models']['detail'] = $this->detail($e);
        }

        if (!$model) {
            $result['completion']['message'] = __('No model to test with. Enter a model name first.');

            return $result;
        }

        // -- 2. Completion -------------------------------------------------
        try {
            $response = $this->client->chat([
                'model'      => $model,
                'messages'   => [
                    ['role' => 'user', 'content' => 'Reply with the single word: ready'],
                ],
                // Generous on purpose: a reasoning model spends its budget on
                // reasoning_content first and would otherwise return an empty
                // content with finish_reason "length".
                'max_tokens'  => 512,
                'temperature' => 0,
            ]);

            $result['completion']['ok'] = true;
            $result['ok'] = true;

            $answer = trim($response->content);

            if ($answer === '' && $response->wasTruncated()) {
                $result['completion']['message'] = __('Connected, but the model used its whole token budget before answering. Raise "Max response tokens".');
            } elseif ($answer === '' && $response->reasoning) {
                $result['completion']['message'] = __('Connected. The model returned reasoning but no answer text.');
            } else {
                $result['completion']['message'] = __('Connected. The model answered: :answer', [
                    'answer' => \Illuminate\Support\Str::limit($answer, 80),
                ]);
            }

            $result['completion']['detail'] = $this->timing($response);
        } catch (LlmException $e) {
            $result['completion']['message'] = $e->userMessage();
            $result['completion']['detail'] = $this->detail($e);

            // No point probing tools against an endpoint that cannot complete.
            return $result;
        }

        // -- 3. Tool-call probe --------------------------------------------
        try {
            $response = $this->client->chat([
                'model'    => $model,
                'messages' => [
                    ['role' => 'user', 'content' => 'What is the weather in Berlin? Use the tool.'],
                ],
                'tools' => [[
                    'type'     => 'function',
                    'function' => [
                        'name'        => 'get_weather',
                        'description' => 'Get the current weather for a city.',
                        'parameters'  => [
                            'type'       => 'object',
                            'properties' => [
                                'city' => ['type' => 'string', 'description' => 'City name'],
                            ],
                            'required'   => ['city'],
                        ],
                    ],
                ]],
                'tool_choice' => 'auto',
                'max_tokens'  => 512,
                'temperature' => 0,
            ]);

            $result['tools']['ok'] = true;
            $result['tools']['supported'] = $response->hasToolCalls();
            $result['tools']['message'] = $response->hasToolCalls()
                ? __('The endpoint accepted a tool call and the model used it.')
                : __('The endpoint accepted the tools parameter but the model did not call the tool. Tools may be unreliable with this model.');

            Settings::rememberModelToolSupport($model, $response->hasToolCalls());
        } catch (LlmException $e) {
            $result['tools']['message'] = $e->getType() == LlmException::TYPE_TOOLS_UNSUPPORTED
                ? __('This endpoint does not support tool calling. The panel will work without tools.')
                : $e->userMessage();
            $result['tools']['detail'] = $this->detail($e);

            Settings::rememberModelToolSupport($model, false);
        }

        return $result;
    }

    /**
     * Status code plus body excerpt, for the admin to read verbatim.
     *
     * @param LlmException $e
     *
     * @return string
     */
    protected function detail(LlmException $e)
    {
        $parts = [];

        if ($e->getStatusCode()) {
            $parts[] = 'HTTP '.$e->getStatusCode();
        }

        if ($e->getBodyExcerpt()) {
            $parts[] = $e->getBodyExcerpt();
        }

        if (!$parts) {
            $parts[] = $e->getMessage();
        }

        return implode(' — ', $parts);
    }

    /**
     * @param \Modules\AiChatPanel\Services\Llm\ChatResponse $response
     *
     * @return string
     */
    protected function timing($response)
    {
        $parts = [sprintf('%.2f s', $response->duration)];

        if (!empty($response->usage['total_tokens'])) {
            $parts[] = $response->usage['total_tokens'].' tokens';
        }

        if ($response->tokens_per_second) {
            $parts[] = sprintf('%.1f tok/s', $response->tokens_per_second);
        }

        return implode(' · ', $parts);
    }
}
