<?php

namespace Modules\AiChatPanel\Services\Llm;

/**
 * Transport to an OpenAI-compatible endpoint.
 *
 * Kept to three methods so the agent loop can be unit-tested against a fake.
 * Nothing provider-specific belongs behind this interface: it speaks
 * /v1/models and /v1/chat/completions and nothing else.
 */
interface LlmClient
{
    /**
     * Model ids offered by the endpoint.
     *
     * Endpoints are not required to implement /v1/models; when it is missing
     * this returns an empty array rather than throwing, so the UI can fall
     * back to a manually entered model name.
     *
     * @return string[]
     *
     * @throws LlmException on transport or auth failure
     */
    public function models();

    /**
     * One completion.
     *
     * @param array $payload A /v1/chat/completions request body.
     *
     * @return ChatResponse
     *
     * @throws LlmException
     */
    public function chat(array $payload);

    /**
     * One completion, streamed.
     *
     * $on_delta is called with an associative array for each parsed chunk:
     *   ['content' => string]    incremental answer text
     *   ['reasoning' => string]  incremental chain of thought
     * Tool-call fragments are accumulated internally and are only visible on
     * the returned ChatResponse, because a partial tool call is not actionable.
     *
     * @param array    $payload
     * @param callable $on_delta
     *
     * @return ChatResponse
     *
     * @throws LlmException
     */
    public function stream(array $payload, callable $on_delta);
}
