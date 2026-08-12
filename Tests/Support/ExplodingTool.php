<?php

namespace Modules\AiChatPanel\Tests\Support;

use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;

/**
 * Always throws an unexpected exception, to prove one never escapes the
 * registry and that its message never reaches the model.
 */
class ExplodingTool extends AbstractTool
{
    public function name()
    {
        return 'test.explode';
    }

    public function description()
    {
        return 'Always throws.';
    }

    public function parameters()
    {
        return $this->noParameters();
    }

    public function handle(array $arguments, PanelContext $context)
    {
        throw new \RuntimeException('boom: internal detail that must not reach the model');
    }
}
