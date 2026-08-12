<?php

namespace Modules\AiChatPanel\Services\Tools;

/**
 * What a tool hands back.
 *
 * The payload is serialised to JSON and sent to the model as a role:tool
 * message, so keep it small and factual. An error result is a normal outcome,
 * not an exception: the model is expected to read it and try something else.
 */
class ToolResult
{
    /** @var bool */
    public $ok = true;

    /** @var array|string|null */
    public $data = null;

    /** @var string */
    public $error = '';

    /**
     * Short human-readable line for the audit log and the panel, e.g.
     * "3 conversations found". Not sent to the model.
     *
     * @var string
     */
    public $summary = '';

    /**
     * @param array|string|null $data
     * @param string            $summary
     *
     * @return static
     */
    public static function ok($data = null, $summary = '')
    {
        $result = new static();
        $result->ok = true;
        $result->data = $data;
        $result->summary = $summary;

        return $result;
    }

    /**
     * A failure the model should see and can act on.
     *
     * @param string $message
     * @param array  $data Extra structure, e.g. which fields were wrong.
     *
     * @return static
     */
    public static function error($message, array $data = [])
    {
        $result = new static();
        $result->ok = false;
        $result->error = $message;
        $result->data = $data ?: null;
        $result->summary = $message;

        return $result;
    }

    /**
     * The content of the role:tool message.
     *
     * @return string
     */
    public function toToolMessageContent()
    {
        if ($this->ok) {
            $payload = ['ok' => true];

            if ($this->data !== null) {
                $payload['data'] = $this->data;
            }
        } else {
            $payload = [
                'ok'    => false,
                'error' => $this->error,
            ];

            if ($this->data) {
                $payload['details'] = $this->data;
            }
        }

        return \Helper::jsonEncodeSafe($payload);
    }
}
