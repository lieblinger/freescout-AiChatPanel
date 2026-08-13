<?php

namespace Modules\AiChatPanel\Services\Tools;

use Modules\AiChatPanel\Services\Markdown\MarkdownToHtml;
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
     * A model-written body, as the HTML a thread stores.
     *
     * The model answers in Markdown, so a thread body written by a tool has to
     * be converted the same way an inserted answer is — otherwise "**bold**"
     * reaches the customer's mailbox as four asterisks and a word.
     *
     * The conversion is also the sanitising step: MarkdownToHtml ends in
     * HTMLPurifier with a profile narrower than core's.
     *
     * @param string $markdown
     *
     * @return string
     */
    protected static function renderBody($markdown)
    {
        $html = MarkdownToHtml::toEditorHtml($markdown);

        if (trim(strip_tags($html)) !== '') {
            return $html;
        }

        // A body that was nothing but markup would otherwise be stored empty,
        // and an empty thread is worse than an escaped one.
        return '<div>'.nl2br(htmlspecialchars((string) $markdown, ENT_QUOTES, 'UTF-8')).'</div>';
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
