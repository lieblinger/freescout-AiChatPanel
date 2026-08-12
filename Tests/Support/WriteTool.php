<?php

namespace Modules\AiChatPanel\Tests\Support;

use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\Tool;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * A write tool that touches nothing real, for the confirmation tests.
 */
class WriteTool extends AbstractTool
{
    /** @var int */
    public static $calls = 0;

    public function name()
    {
        return 'test.write';
    }

    public function description()
    {
        return 'Pretend to change something.';
    }

    public function parameters()
    {
        return [
            'type'       => 'object',
            'properties' => ['value' => ['type' => 'string']],
            'required'   => ['value'],
        ];
    }

    public function mode()
    {
        return Tool::MODE_WRITE;
    }

    public function authorize(PanelContext $context)
    {
        return $context->userCanUpdate();
    }

    public function confirmationLabel(array $arguments, PanelContext $context)
    {
        return 'Set the value to "'.$arguments['value'].'".';
    }

    public function handle(array $arguments, PanelContext $context)
    {
        self::$calls++;

        return ToolResult::ok(['written' => $arguments['value']], 'written');
    }
}
