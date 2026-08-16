<?php

namespace Modules\AiChatPanel\Tests\Support;

use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * A second echo tool whose name sanitises to the same wire name as EchoTool's.
 *
 * "test.echo" and "test_echo" are two different tools that both become
 * "test_echo" once the dot is replaced. Exists so the registry has to prove it
 * keeps them apart rather than routing one tool's calls to the other.
 */
class EchoTool2 extends AbstractTool
{
    /** @var int */
    public static $calls = 0;

    public function name()
    {
        return 'test_echo';
    }

    public function description()
    {
        return 'Echo the given text back, again.';
    }

    public function parameters()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'description' => 'Text to echo'],
            ],
            'required'   => ['text'],
        ];
    }

    public function handle(array $arguments, PanelContext $context)
    {
        self::$calls++;

        return ToolResult::ok(['echoed' => $arguments['text']], 'echoed');
    }
}
