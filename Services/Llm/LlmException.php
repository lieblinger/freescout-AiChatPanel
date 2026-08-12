<?php

namespace Modules\AiChatPanel\Services\Llm;

/**
 * A failure talking to the endpoint, carrying enough structure that the panel
 * can show a distinct, actionable message rather than "something went wrong".
 *
 * The message on the exception is for the log. userMessage() is for the panel
 * and is translatable. Neither ever contains the API key.
 */
class LlmException extends \Exception
{
    const TYPE_CONNECTION      = 'connection';
    const TYPE_TIMEOUT         = 'timeout';
    const TYPE_AUTH            = 'auth';
    const TYPE_HTTP            = 'http';
    const TYPE_MODEL_NOT_FOUND = 'model_not_found';
    const TYPE_CONTEXT_LENGTH  = 'context_length';
    const TYPE_TOOLS_UNSUPPORTED = 'tools_unsupported';
    const TYPE_INVALID_RESPONSE  = 'invalid_response';
    const TYPE_NOT_CONFIGURED    = 'not_configured';

    /** @var string */
    protected $type;

    /** @var int|null */
    protected $status_code;

    /** @var string */
    protected $body_excerpt = '';

    /**
     * @param string $type
     * @param string $message
     * @param int    $status_code
     * @param string $body_excerpt
     */
    public function __construct($type, $message, $status_code = 0, $body_excerpt = '')
    {
        parent::__construct($message, (int) $status_code);

        $this->type = $type;
        $this->status_code = $status_code ?: null;
        $this->body_excerpt = $body_excerpt;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return int|null
     */
    public function getStatusCode()
    {
        return $this->status_code;
    }

    /**
     * Raw response body, truncated. The connection test shows this verbatim —
     * a generic "connection failed" is useless when debugging an endpoint.
     *
     * @return string
     */
    public function getBodyExcerpt()
    {
        return $this->body_excerpt;
    }

    /**
     * A distinct, actionable, translated message for the panel.
     *
     * @return string
     */
    public function userMessage()
    {
        switch ($this->type) {
            case self::TYPE_NOT_CONFIGURED:
                return __('The AI chat panel is not configured yet. An administrator has to set the endpoint URL in the settings.');

            case self::TYPE_CONNECTION:
                return __('Could not reach the AI endpoint. Check that the base URL is correct and that the server is running.');

            case self::TYPE_TIMEOUT:
                return __('The AI endpoint did not answer in time. Try again, or raise the request timeout in the settings.');

            case self::TYPE_AUTH:
                return __('The AI endpoint rejected the API key.');

            case self::TYPE_MODEL_NOT_FOUND:
                return __('The selected model is not available on this endpoint. Pick a different model.');

            case self::TYPE_CONTEXT_LENGTH:
                return __('This conversation is too long for the selected model. Lower the maximum context tokens in the settings, or start a new chat.');

            case self::TYPE_TOOLS_UNSUPPORTED:
                return __('This model does not support tools, so tools were disabled for this message.');

            case self::TYPE_INVALID_RESPONSE:
                return __('The AI endpoint returned a response this module could not read.');

            case self::TYPE_HTTP:
            default:
                if ($this->status_code) {
                    return __('The AI endpoint returned an error (:code).', ['code' => $this->status_code]);
                }

                return __('The AI endpoint returned an error.');
        }
    }

    /**
     * Classify an HTTP error response into one of our types.
     *
     * Endpoints are wildly inconsistent about status codes, so the body is
     * consulted too — llama.cpp answers 401 with an authentication_error
     * object, vLLM answers 400 for both an unknown model and an overlong
     * context, and so on.
     *
     * @param int    $status
     * @param string $body
     *
     * @return static
     */
    public static function fromHttp($status, $body)
    {
        $excerpt = self::excerpt($body);
        $haystack = mb_strtolower($body);

        if ($status == 401 || $status == 403 || strpos($haystack, 'authentication_error') !== false
            || strpos($haystack, 'invalid api key') !== false) {
            return new static(self::TYPE_AUTH, 'Endpoint rejected the API key (HTTP '.$status.')', $status, $excerpt);
        }

        if (strpos($haystack, 'context length') !== false
            || strpos($haystack, 'context_length') !== false
            || strpos($haystack, 'maximum context') !== false
            || strpos($haystack, 'too many tokens') !== false
            || strpos($haystack, 'exceeds the available context') !== false) {
            return new static(self::TYPE_CONTEXT_LENGTH, 'Context length exceeded (HTTP '.$status.')', $status, $excerpt);
        }

        if ($status == 404
            || strpos($haystack, 'model_not_found') !== false
            || strpos($haystack, 'does not exist') !== false
            || strpos($haystack, 'unknown model') !== false) {
            return new static(self::TYPE_MODEL_NOT_FOUND, 'Model not found (HTTP '.$status.')', $status, $excerpt);
        }

        if (strpos($haystack, 'tool') !== false
            && (strpos($haystack, 'not supported') !== false
                || strpos($haystack, 'unsupported') !== false
                || strpos($haystack, 'does not support') !== false)) {
            return new static(self::TYPE_TOOLS_UNSUPPORTED, 'Endpoint rejected the tools parameter (HTTP '.$status.')', $status, $excerpt);
        }

        return new static(self::TYPE_HTTP, 'Endpoint returned HTTP '.$status, $status, $excerpt);
    }

    /**
     * @param string $body
     * @param int    $length
     *
     * @return string
     */
    public static function excerpt($body, $length = 600)
    {
        $body = trim((string) $body);

        if (mb_strlen($body) <= $length) {
            return $body;
        }

        return mb_substr($body, 0, $length).'…';
    }
}
