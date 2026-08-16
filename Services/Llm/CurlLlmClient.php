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
        $ids = [];

        foreach ($this->catalogue() as $entry) {
            $ids[] = $entry['id'];
        }

        return $ids;
    }

    /**
     * Everything /v1/models says about each model, normalised.
     *
     * Deliberately not on the LlmClient interface: it is only ever called on a
     * concrete client, and adding it would force every implementation to carry
     * a method that has nothing to do with completing a chat.
     *
     * @return array List of ['id' => .., 'label' => .., 'group' => .., 'tools' => bool|null]
     *
     * @throws LlmException
     */
    public function catalogue()
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
        // data[].id is the only field that is always there.
        $catalogue = [];
        $seen = [];

        if (!empty($decoded['data']) && is_array($decoded['data'])) {
            foreach ($decoded['data'] as $entry) {
                if (empty($entry['id']) || !is_scalar($entry['id'])) {
                    continue;
                }

                $id = (string) $entry['id'];

                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $catalogue[] = self::describeModel($id, is_array($entry) ? $entry : []);
            }
        }

        return $catalogue;
    }

    /**
     * One catalogue entry.
     *
     * A bare id is unreadable at a glance and there can be hundreds of them:
     * OpenRouter alone lists ~500, named "anthropic/claude-sonnet-4.5" while
     * carrying "Anthropic: Claude Sonnet 4.5" in a field nobody was reading.
     * The "Vendor: Model" split is OpenRouter's convention; endpoints that only
     * give an id fall back to the vendor path segment, and endpoints that give
     * neither end up ungrouped, which is correct for a single-model llama.cpp.
     *
     * @param string $id
     * @param array  $entry
     *
     * @return array
     */
    public static function describeModel($id, array $entry = [])
    {
        $name = isset($entry['name']) && is_scalar($entry['name']) ? trim((string) $entry['name']) : '';

        $group = '';
        $label = $name !== '' ? $name : $id;

        if ($name !== '' && strpos($name, ': ') !== false) {
            list($group, $label) = explode(': ', $name, 2);
            $group = trim($group);
            $label = trim($label);
        } elseif (strpos($id, '/') !== false) {
            list($vendor) = explode('/', $id, 2);
            $group = self::vendorLabel($vendor);
        }

        return [
            'id'    => $id,
            'label' => $label !== '' ? $label : $id,
            'group' => $group,
            'tools' => self::supportsTools($entry),
        ];
    }

    /**
     * Whether the model can do tool calling: true, false, or null for "the
     * endpoint did not say".
     *
     * The distinction matters. llama.cpp and vLLM do not report
     * supported_parameters at all, and marking their models as tool-less would
     * turn tools off for the endpoints that started this module.
     *
     * @param array $entry
     *
     * @return bool|null
     */
    protected static function supportsTools(array $entry)
    {
        if (empty($entry['supported_parameters']) || !is_array($entry['supported_parameters'])) {
            return null;
        }

        return in_array('tools', $entry['supported_parameters'], true);
    }

    /**
     * @param string $vendor
     *
     * @return string
     */
    protected static function vendorLabel($vendor)
    {
        $vendor = trim(str_replace(['-', '_'], ' ', (string) $vendor));

        if ($vendor === '') {
            return '';
        }

        return mb_convert_case($vendor, MB_CASE_TITLE, 'UTF-8');
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

        $this->close($ch);

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
    protected function request($method, $path, ?array $payload = null)
    {
        $ch = $this->curl($method, $path, $payload);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $this->close($ch);

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
     * @return resource|\CurlHandle
     */
    protected function curl($method, $path, ?array $payload = null)
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
     * Release a handle.
     *
     * curl_close() has been a no-op since PHP 8.0 — curl_init() returns a
     * CurlHandle object that is freed by refcount — and PHP 8.5 deprecates it.
     * Laravel's error handler turns that deprecation into an ErrorException,
     * which is a \Exception, so on PHP 8.5 every single request through this
     * client blew up. Core still supports PHP 7.1, where the call is real.
     *
     * @param resource|\CurlHandle $ch
     *
     * @return void
     */
    protected function close($ch)
    {
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
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
