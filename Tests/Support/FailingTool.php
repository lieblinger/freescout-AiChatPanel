<?php

namespace Modules\AiChatPanel\Tests\Support;

use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\ToolException;

/**
 * Fails the expected way: a ToolException, whose message is written for the
 * model and is meant to reach it.
 */
class FailingTool extends AbstractTool
{
    public function name()
    {
        return 'test.fail';
    }

    public function description()
    {
        return 'Fails predictably.';
    }

    public function parameters()
    {
        return $this->noParameters();
    }

    public function handle(array $arguments, PanelContext $context)
    {
        throw new ToolException('Nothing matched your query. Try a different one.');
    }
}
