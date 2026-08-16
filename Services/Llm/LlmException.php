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

    /** @var string */
    protected $api_message = '';

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
     * What the endpoint itself said went wrong, short enough to put in front of
     * an agent.
     *
     * Worth the trouble because the alternative is a bare status code: an
     * OpenRouter 400 says exactly which parameter it rejected and why, and
     * without it a misconfiguration is indistinguishable from a broken model.
     *
     * @return string
     */
    public function apiMessage()
    {
        return $this->api_message;
    }

    /**
     * @param string $api_message
     *
     * @return $this
     */
    public function setApiMessage($api_message)
    {
        $this->api_message = (string) $api_message;

        return $this;
    }

    /**
     * Pull the human-readable part out of an error body.
     *
     * Every OpenAI-compatible endpoint nests it differently — OpenRouter and
     * OpenAI use {"error": {"message": ...}}, llama.cpp sometimes answers with a
     * bare {"message": ...}, and a proxy in front of either may return HTML.
     * Anything that is not JSON falls back to the trimmed body.
     *
     * @param string $body
     * @param int    $length
     *
     * @return string
     */
    public static function apiMessageFrom($body, $length = 300)
    {
        $decoded = json_decode((string) $body, true);
        $message = '';

        if (is_array($decoded)) {
            if (isset($decoded['error']['message']) && is_scalar($decoded['error']['message'])) {
                $message = (string) $decoded['error']['message'];
            } elseif (isset($decoded['error']) && is_string($decoded['error'])) {
                $message = $decoded['error'];
            } elseif (isset($decoded['message']) && is_scalar($decoded['message'])) {
                $message = (string) $decoded['message'];
            }
        }

        if ($message === '') {
            // Not JSON, or JSON in a shape nobody documented. The raw body is
            // still more use than nothing, as long as it is not a whole page.
            $message = (string) $body;

            if (stripos($message, '<html') !== false) {
                return '';
            }
        }

        $message = trim(preg_replace('/\s+/u', ' ', $message));

        if (mb_strlen($message) <= $length) {
            return $message;
        }

        return mb_substr($message, 0, $length).'…';
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
                // The endpoint's own wording, when there is one. A bare status
                // code is not something an agent can act on, and it is not
                // something an administrator can debug from a screenshot either.
                if ($this->status_code && $this->api_message !== '') {
                    return __('The AI endpoint returned an error (:code): :message', [
                        'code'    => $this->status_code,
                        'message' => $this->api_message,
                    ]);
                }

                if ($this->status_code) {
                    return __('The AI endpoint returned an error (:code).', ['code' => $this->status_code]);
                }

                if ($this->api_message !== '') {
                    return __('The AI endpoint returned an error: :message', ['message' => $this->api_message]);
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

        // Decoded from the full body, not the excerpt: truncating at 600 bytes
        // usually leaves invalid JSON behind.
        $api_message = self::apiMessageFrom($body);

        if ($status == 401 || $status == 403 || strpos($haystack, 'authentication_error') !== false
            || strpos($haystack, 'invalid api key') !== false) {
            return (new static(self::TYPE_AUTH, 'Endpoint rejected the API key (HTTP '.$status.')', $status, $excerpt))
                ->setApiMessage($api_message);
        }

        if (strpos($haystack, 'context length') !== false
            || strpos($haystack, 'context_length') !== false
            || strpos($haystack, 'maximum context') !== false
            || strpos($haystack, 'too many tokens') !== false
            || strpos($haystack, 'exceeds the available context') !== false) {
            return (new static(self::TYPE_CONTEXT_LENGTH, 'Context length exceeded (HTTP '.$status.')', $status, $excerpt))
                ->setApiMessage($api_message);
        }

        if ($status == 404
            || strpos($haystack, 'model_not_found') !== false
            || strpos($haystack, 'does not exist') !== false
            || strpos($haystack, 'unknown model') !== false) {
            return (new static(self::TYPE_MODEL_NOT_FOUND, 'Model not found (HTTP '.$status.')', $status, $excerpt))
                ->setApiMessage($api_message);
        }

        if (strpos($haystack, 'tool') !== false
            && (strpos($haystack, 'not supported') !== false
                || strpos($haystack, 'unsupported') !== false
                || strpos($haystack, 'does not support') !== false)) {
            return (new static(self::TYPE_TOOLS_UNSUPPORTED, 'Endpoint rejected the tools parameter (HTTP '.$status.')', $status, $excerpt))
                ->setApiMessage($api_message);
        }

        return (new static(self::TYPE_HTTP, 'Endpoint returned HTTP '.$status, $status, $excerpt))
            ->setApiMessage($api_message);
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
