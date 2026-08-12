<?php

namespace Modules\AiChatPanel\Tests\Support;

use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * A read tool the current user may never run, for the permission tests.
 */
class ForbiddenTool extends AbstractTool
{
    /** @var int */
    public static $calls = 0;

    public function name()
    {
        return 'test.forbidden';
    }

    public function description()
    {
        return 'A tool the current user may never run.';
    }

    public function parameters()
    {
        return $this->noParameters();
    }

    public function authorize(PanelContext $context)
    {
        return false;
    }

    public function handle(array $arguments, PanelContext $context)
    {
        self::$calls++;

        return ToolResult::ok(['secret' => 'should never be reached']);
    }
}
