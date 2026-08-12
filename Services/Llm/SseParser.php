<?php

namespace Modules\AiChatPanel\Services\Llm;

/**
 * Incremental parser for an OpenAI-compatible SSE completion stream.
 *
 * Fed arbitrary byte chunks as cURL delivers them: a chunk can split a frame
 * anywhere, including mid-UTF-8-sequence, so everything after the last frame
 * boundary is held back until more bytes arrive.
 */
class SseParser
{
    /** @var string Bytes not yet forming a complete frame. */
    protected $buffer = '';

    /** @var bool */
    protected $done = false;

    /** @var ChatResponse */
    protected $response;

    /**
     * Tool calls under construction, keyed by the index the endpoint sends.
     * Arguments arrive as string fragments and must be concatenated in order
     * before anyone tries to decode them.
     *
     * @var array
     */
    protected $tool_calls = [];

    public function __construct()
    {
        $this->response = new ChatResponse();
    }

    /**
     * Consume a chunk and return the deltas it produced.
     *
     * @param string $chunk
     *
     * @return array List of ['content' => string] / ['reasoning' => string]
     */
    public function push($chunk)
    {
        $this->buffer .= $chunk;
        $deltas = [];

        // Frames are separated by a blank line. \r\n\r\n is tolerated because
        // some proxies rewrite line endings.
        while (($position = $this->nextBoundary()) !== false) {
            list($offset, $length) = $position;

            $frame = substr($this->buffer, 0, $offset);
            $this->buffer = substr($this->buffer, $offset + $length);

            $delta = $this->handleFrame($frame);

            if ($delta) {
                $deltas[] = $delta;
            }
        }

        return $deltas;
    }

    /**
     * The accumulated turn. Only meaningful once the stream has ended.
     *
     * @return ChatResponse
     */
    public function response()
    {
        // Anything left in the buffer is a frame the endpoint never terminated.
        // Parse it rather than silently dropping a final tool call.
        if (trim($this->buffer) !== '') {
            $this->handleFrame($this->buffer);
            $this->buffer = '';
        }

        $this->response->tool_calls = [];

        ksort($this->tool_calls);

        foreach ($this->tool_calls as $call) {
            if ($call['name'] === '') {
                continue;
            }

            $this->response->tool_calls[] = $call;
        }

        // Some endpoints never send finish_reason on the streamed path.
        if (!$this->response->finish_reason && $this->response->tool_calls) {
            $this->response->finish_reason = 'tool_calls';
        }

        return $this->response;
    }

    /**
     * @return bool Whether the endpoint sent its terminating [DONE].
     */
    public function isDone()
    {
        return $this->done;
    }

    // -----------------------------------------------------------------------

    /**
     * @return array|false [offset, separator length]
     */
    protected function nextBoundary()
    {
        $lf = strpos($this->buffer, "\n\n");
        $crlf = strpos($this->buffer, "\r\n\r\n");

        if ($crlf !== false && ($lf === false || $crlf < $lf)) {
            return [$crlf, 4];
        }

        if ($lf !== false) {
            return [$lf, 2];
        }

        return false;
    }

    /**
     * @param string $frame
     *
     * @return array|null
     */
    protected function handleFrame($frame)
    {
        $data = '';

        foreach (preg_split('/\r\n|\n/', $frame) as $line) {
            // Comment/keepalive frames start with a colon; ignore them.
            if ($line === '' || $line[0] === ':') {
                continue;
            }

            if (strpos($line, 'data:') !== 0) {
                continue;
            }

            // Per the SSE spec multiple data: lines in one frame concatenate
            // with a newline.
            $data .= ($data === '' ? '' : "\n").ltrim(substr($line, 5), ' ');
        }

        if ($data === '') {
            return null;
        }

        if ($data === '[DONE]') {
            $this->done = true;

            return null;
        }

        $decoded = json_decode($data, true);

        if (!is_array($decoded)) {
            return null;
        }

        return $this->handleChunk($decoded);
    }

    /**
     * @param array $chunk
     *
     * @return array|null
     */
    protected function handleChunk(array $chunk)
    {
        if (!empty($chunk['model']) && !$this->response->model) {
            $this->response->model = (string) $chunk['model'];
        }

        // With stream_options.include_usage the last chunk carries usage and
        // has an empty choices array.
        if (!empty($chunk['usage']) && is_array($chunk['usage'])) {
            $this->response->usage = $chunk['usage'];
        }

        if (isset($chunk['timings']['predicted_per_second'])) {
            $this->response->tokens_per_second = (float) $chunk['timings']['predicted_per_second'];
        }

        if (!isset($chunk['choices'][0])) {
            return null;
        }

        $choice = $chunk['choices'][0];

        if (!empty($choice['finish_reason'])) {
            $this->response->finish_reason = (string) $choice['finish_reason'];
        }

        if (!isset($choice['delta']) || !is_array($choice['delta'])) {
            return null;
        }

        $delta = $choice['delta'];

        if (!empty($delta['tool_calls']) && is_array($delta['tool_calls'])) {
            $this->accumulateToolCalls($delta['tool_calls']);
        }

        // Reasoning first: a reasoning model emits it while content is still
        // null, and the two never arrive in the same delta.
        if (isset($delta['reasoning_content']) && is_string($delta['reasoning_content']) && $delta['reasoning_content'] !== '') {
            $this->response->reasoning .= $delta['reasoning_content'];

            return ['reasoning' => $delta['reasoning_content']];
        }

        if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
            $this->response->content .= $delta['content'];

            return ['content' => $delta['content']];
        }

        return null;
    }

    /**
     * @param array $fragments
     *
     * @return void
     */
    protected function accumulateToolCalls(array $fragments)
    {
        foreach ($fragments as $position => $fragment) {
            $index = isset($fragment['index']) ? (int) $fragment['index'] : (int) $position;

            if (!isset($this->tool_calls[$index])) {
                $this->tool_calls[$index] = [
                    'id'        => 'call_'.$index,
                    'name'      => '',
                    'arguments' => '',
                ];
            }

            if (!empty($fragment['id'])) {
                $this->tool_calls[$index]['id'] = (string) $fragment['id'];
            }

            if (!isset($fragment['function']) || !is_array($fragment['function'])) {
                continue;
            }

            if (!empty($fragment['function']['name'])) {
                $this->tool_calls[$index]['name'] = (string) $fragment['function']['name'];
            }

            if (isset($fragment['function']['arguments']) && is_string($fragment['function']['arguments'])) {
                $this->tool_calls[$index]['arguments'] .= $fragment['function']['arguments'];
            }
        }
    }
}
