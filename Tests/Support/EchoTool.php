<?php

namespace Modules\AiChatPanel\Tests\Support;

use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * A read tool that exists only for the test suite.
 *
 * Registered through the aichatpanel.tools filter exactly the way a third-party
 * module would register its own, so these doubles also check that the
 * documented extension path works.
 */
class EchoTool extends AbstractTool
{
    /** @var int Times handle() ran, so tests can assert on side effects. */
    public static $calls = 0;

    public function name()
    {
        return 'test.echo';
    }

    public function description()
    {
        return 'Echo the given text back.';
    }

    public function parameters()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'text'  => ['type' => 'string', 'description' => 'Text to echo'],
                'times' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            ],
            'required'   => ['text'],
        ];
    }

    public function handle(array $arguments, PanelContext $context)
    {
        self::$calls++;

        $times = isset($arguments['times']) ? (int) $arguments['times'] : 1;

        return ToolResult::ok(['echoed' => str_repeat($arguments['text'], $times)], 'echoed');
    }
}
