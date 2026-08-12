<?php

namespace Modules\AiChatPanel\Services\Tools;

use Modules\AiChatPanel\Services\PanelContext;

/**
 * Sensible defaults for a tool. Extend this rather than implementing Tool
 * directly, so that a later addition to the interface does not break you.
 *
 * The defaults are the safe ones: read mode, always relevant, and authorised
 * only if the user can view the conversation. A write tool must override both
 * mode() and authorize().
 */
abstract class AbstractTool implements Tool
{
    /**
     * {@inheritdoc}
     */
    public function mode()
    {
        return self::MODE_READ;
    }

    /**
     * {@inheritdoc}
     */
    public function isRelevant(PanelContext $context)
    {
        return true;
    }

    /**
     * Default: the user must be able to see the conversation the panel is open
     * on. Every tool needs at least this, and write tools tighten it.
     *
     * {@inheritdoc}
     */
    public function authorize(PanelContext $context)
    {
        return $context->userCanView();
    }

    /**
     * {@inheritdoc}
     */
    public function confirmationLabel(array $arguments, PanelContext $context)
    {
        return __('Run :tool', ['tool' => $this->name()]);
    }

    /**
     * Whether this tool changes data.
     *
     * @return bool
     */
    public function isWrite()
    {
        return $this->mode() === self::MODE_WRITE;
    }

    /**
     * The tool as an OpenAI-compatible function definition.
     *
     * @return array
     */
    public function toApiDefinition()
    {
        return [
            'type'     => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => $this->description(),
                'parameters'  => $this->parameters(),
            ],
        ];
    }

    /**
     * Helper for schemas that take no arguments.
     *
     * @return array
     */
    protected function noParameters()
    {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
        ];
    }
}
