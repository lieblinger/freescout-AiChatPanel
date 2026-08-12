<?php

namespace Modules\AiChatPanel\Services\Tools;

/**
 * An expected failure inside a tool.
 *
 * The message is sent back to the model as a structured tool error, so write it
 * for the model: say what went wrong and what it could do instead. Do not put
 * anything sensitive in it — it goes into the prompt.
 *
 * Anything else a tool throws is treated as a bug: logged with a stack trace,
 * and reported to the model as a generic failure.
 */
class ToolException extends \Exception
{
    /** @var array */
    protected $details = [];

    /**
     * @param string $message
     * @param array  $details
     */
    public function __construct($message, array $details = [])
    {
        parent::__construct($message);

        $this->details = $details;
    }

    /**
     * @return array
     */
    public function getDetails()
    {
        return $this->details;
    }
}
