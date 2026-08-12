<?php

namespace Modules\AiChatPanel\Services\Llm;

/**
 * cURL transport for an OpenAI-compatible endpoint.
 *
 * cURL rather than Guzzle on purpose: Guzzle is only a transitive dependency of
 * core (not declared in its composer.json), and CURLOPT_WRITEFUNCTION gives us
 * the incremental read that SSE streaming needs without pulling anything in.
 */
class CurlLlmClient implements LlmClient
{
    /** @var string */
    protected $base_url;

    /** @var string */
    protected $api_key;

    /** @var int */
    protected $timeout;

    /** @var int */
    protected $connect_timeout;

    /**
     * @param string $base_url
     * @param string $api_key
     * @param int    $timeout
     * @param int    $connect_timeout
     */
    public function __construct($base_url, $api_key = '', $timeout = 120, $connect_timeout = 10)
    {
        $this->base_url = rtrim((string) $base_url, '/');
        $this->api_key = (string) $api_key;
        $this->timeout = (int) $timeout;
        $this->connect_timeout = (int) $connect_timeout;
    }

    /**
     * Build from the module settings.
     *
     * @return static
     *
     * @throws LlmException when the endpoint has not been configured
     */
    public static function fromSettings()
    {
        $base_url = \Modules\AiChatPanel\Services\Settings::baseUrl();

        if (!$base_url) {
            throw new LlmException(LlmException::TYPE_NOT_CONFIGURED, 'Base URL is not set');
        }

        return new static(
            $base_url,
            \Modules\AiChatPanel\Services\Settings::apiKey(),
            (int) \Modules\AiChatPanel\Services\Settings::get('request_timeout'),
            (int) \Modules\AiChatPanel\Services\Settings::get('connect_timeout')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function models()
    {
        list($status, $body) = $this->request('GET', '/v1/models');

        // Not every endpoint implements /v1/models. That is not an error — the
        // UI falls back to a manually entered model name.
        if ($status == 404 || $status == 405) {
            return [];
        }

        if ($status >= 400) {
            throw LlmException::fromHttp($status, $body);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new LlmException(
                LlmException::TYPE_INVALID_RESPONSE,
                '/v1/models did not return JSON',
                $status,
                LlmException::excerpt($body)
            );
        }

        // The standard shape is {"data": [{"id": "..."}]}. Some servers add
        // their own keys alongside it (llama.cpp emits a "models" array too);
        // data[].id is the only field to trust.
        $models = [];

        if (!empty($decoded['data']) && is_array($decoded['data'])) {
            foreach ($decoded['data'] as $entry) {
                if (!empty($entry['id']) && is_scalar($entry['id'])) {
                    $models[] = (string) $entry['id'];
                }
            }
        }

        return array_values(array_unique($models));
    }

    /**
     * {@inheritdoc}
     */
    public function chat(array $payload)
    {
        unset($payload['stream'], $payload['stream_options']);

        $started = microtime(true);

        list($status, $body) = $this->request('POST', '/v1/chat/completions', $payload);

        if ($status >= 400) {
            throw LlmException::fromHttp($status, $body);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new LlmException(
                LlmException::TYPE_INVALID_RESPONSE,
                'Completion response was not JSON',
                $status,
                LlmException::excerpt($body)
            );
        }

        $response = ChatResponse::fromArray($decoded);
        $response->duration = microtime(true) - $started;

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function stream(array $payload, callable $on_delta)
    {
        $payload['stream'] = true;
        $payload['stream_options'] = ['include_usage' => true];

        $started = microtime(true);

        $parser = new SseParser();
        $error_body = '';
        $status = 0;

        $ch = $this->curl('POST', '/v1/chat/completions', $payload);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($parser, $on_delta, &$error_body, &$status) {
            if (!$status) {
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            }

            // An error response is a normal JSON body, not a stream. Collect it
            // and let the caller turn it into a typed exception.
            if ($status >= 400) {
                $error_body .= $chunk;

                return strlen($chunk);
            }

            foreach ($parser->push($chunk) as $delta) {
                $on_delta($delta);
            }

            return strlen($chunk);
        });

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);

        if (!$status) {
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }

        curl_close($ch);

        if ($ok === false && $errno) {
            throw $this->transportException($errno, $error);
        }

        if ($status >= 400) {
            throw LlmException::fromHttp($status, $error_body);
        }

        $response = $parser->response();
        $response->duration = microtime(true) - $started;

        return $response;
    }

    // -----------------------------------------------------------------------

    /**
     * @param string     $method
     * @param string     $path
     * @param array|null $payload
     *
     * @return array [int $status, string $body]
     *
     * @throws LlmException
     */
    protected function request($method, $path, array $payload = null)
    {
        $ch = $this->curl($method, $path, $payload);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($body === false && $errno) {
            throw $this->transportException($errno, $error);
        }

        return [$status, (string) $body];
    }

    /**
     * @param string     $method
     * @param string     $path
     * @param array|null $payload
     *
     * @return resource
     */
    protected function curl($method, $path, array $payload = null)
    {
        $ch = curl_init($this->base_url.$path);

        // Core's defaults give us the proxy and SSL settings; its 40 second
        // timeout is far too short for a completion, so ours is set after.
        \Helper::setCurlDefaultOptions($ch);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connect_timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);

        $headers = ['Accept: application/json'];

        if ($this->api_key !== '') {
            $headers[] = 'Authorization: Bearer '.$this->api_key;
        }

        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, \Helper::jsonEncodeSafe($payload ?: []));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        return $ch;
    }

    /**
     * @param int    $errno
     * @param string $error
     *
     * @return LlmException
     */
    protected function transportException($errno, $error)
    {
        $timeouts = [CURLE_OPERATION_TIMEOUTED];

        if (defined('CURLE_OPERATION_TIMEDOUT')) {
            $timeouts[] = CURLE_OPERATION_TIMEDOUT;
        }

        if (in_array($errno, $timeouts)) {
            return new LlmException(LlmException::TYPE_TIMEOUT, 'cURL timeout: '.$error);
        }

        return new LlmException(LlmException::TYPE_CONNECTION, 'cURL error '.$errno.': '.$error);
    }
}
