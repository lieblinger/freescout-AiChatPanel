<?php

namespace Modules\AiChatPanel\Services\Context;

use Modules\AiChatPanel\Services\PanelContext;

/**
 * A read-only block of extra context contributed by any module.
 *
 * This is the cheap extension point: no round trip to the model, the block is
 * simply appended to the system message. Use a tool instead when the data is
 * large, expensive to fetch, or only occasionally relevant — a provider is paid
 * for on every single message.
 *
 * Register providers on the aichatpanel.context_providers filter:
 *
 *     \Eventy::addFilter('aichatpanel.context_providers', function ($providers, $context) {
 *         $providers[] = new \Modules\MyModule\AiContext\OpenInvoices();
 *         return $providers;
 *     }, 20, 2);
 *
 * A provider must not throw. If it does, it is logged, skipped, and the chat
 * continues without it.
 */
interface ContextProvider
{
    /**
     * Stable unique id, namespaced by module, e.g. "mymodule.open_invoices".
     *
     * Used as the admin toggle key, so changing it resets everyone's settings.
     *
     * @return string
     */
    public function key();

    /**
     * Human-readable, translated name for the admin settings screen.
     *
     * @return string
     */
    public function label();

    /**
     * Higher runs first and survives longer when the budget is tight.
     * The built-in provider uses 20; use less than 20 to outrank it.
     *
     * @return int
     */
    public function priority();

    /**
     * Rough token cost, used to decide whether this block fits before render()
     * is called. Over-estimating is safer than under-estimating.
     *
     * @return int
     */
    public function estimatedTokens(PanelContext $context);

    /**
     * The block itself, as plain text, or null to contribute nothing this time.
     *
     * Start with a short heading so the model can tell blocks apart. Content
     * that came from a customer is untrusted and will be wrapped in the data
     * delimiters by the caller.
     *
     * @return string|null
     */
    public function render(PanelContext $context);
}
