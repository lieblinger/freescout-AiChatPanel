<?php

namespace Modules\AiChatPanel\Services\Context;

use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Settings;

/**
 * Collects context providers from every module and renders the ones that are
 * enabled, permitted and affordable.
 *
 * Discovering zero providers is a normal state, not an error.
 */
class ProviderRegistry
{
    const FILTER = 'aichatpanel.context_providers';

    /**
     * Every provider any module offers, before filtering.
     *
     * @param PanelContext $context
     *
     * @return ContextProvider[]
     */
    public static function all(PanelContext $context)
    {
        $providers = [new Providers\PreviousConversationsProvider()];

        try {
            $providers = \Eventy::filter(self::FILTER, $providers, $context);
        } catch (\Exception $e) {
            \Helper::logException($e, '[AiChatPanel] A module threw while registering context providers: ');
        }

        if (!is_array($providers)) {
            return [];
        }

        $valid = [];

        foreach ($providers as $provider) {
            // A module returning something wrong must not break the chat.
            if (!$provider instanceof ContextProvider) {
                \Log::warning('[AiChatPanel] Ignoring a context provider that does not implement ContextProvider: '
                    .(is_object($provider) ? get_class($provider) : gettype($provider)));
                continue;
            }

            $valid[$provider->key()] = $provider;
        }

        // Stable order: priority first, then key, so the same set always
        // produces the same prompt.
        uasort($valid, function ($a, $b) {
            $diff = $a->priority() - $b->priority();

            return $diff !== 0 ? $diff : strcmp($a->key(), $b->key());
        });

        return $valid;
    }

    /**
     * Every registered provider, for the admin settings screen.
     *
     * Called with a NULL context, because the settings page is not about any
     * one conversation. Provider authors must tolerate that: return your
     * providers unconditionally and do not touch $context. Only key() and
     * label() are read here.
     *
     * @return ContextProvider[]
     */
    public static function catalogue()
    {
        $providers = [new Providers\PreviousConversationsProvider()];

        try {
            $providers = \Eventy::filter(self::FILTER, $providers, null);
        } catch (\Exception $e) {
            \Helper::logException($e, '[AiChatPanel] A module threw while listing context providers for the settings page: ');
        }

        $catalogue = [];

        foreach ((array) $providers as $provider) {
            if (!$provider instanceof ContextProvider) {
                continue;
            }

            try {
                $catalogue[$provider->key()] = $provider;
            } catch (\Exception $e) {
                continue;
            }
        }

        ksort($catalogue);

        return $catalogue;
    }

    /**
     * Providers the admin has enabled for this mailbox.
     *
     * @param PanelContext $context
     *
     * @return ContextProvider[]
     */
    public static function enabled(PanelContext $context)
    {
        $allowed = $context->setting('context_providers');

        if (!is_array($allowed)) {
            $allowed = [];
        }

        $enabled = [];

        foreach (self::all($context) as $key => $provider) {
            if (in_array($key, $allowed)) {
                $enabled[$key] = $provider;
            }
        }

        return $enabled;
    }

    /**
     * Render the enabled providers into text blocks, dropping the ones that no
     * longer fit.
     *
     * Providers are visited best-priority first, so when the budget runs out it
     * is the least important block that goes.
     *
     * @param PanelContext $context
     * @param TokenBudget  $budget
     *
     * @return string[]
     */
    public static function render(PanelContext $context, TokenBudget $budget)
    {
        $blocks = [];

        foreach (self::enabled($context) as $key => $provider) {
            try {
                $estimate = (int) $provider->estimatedTokens($context);
            } catch (\Exception $e) {
                \Helper::logException($e, '[AiChatPanel] Context provider "'.$key.'" threw while estimating: ');
                continue;
            }

            if (!$budget->fits($estimate)) {
                $budget->drop('provider', self::safeLabel($provider, $key));
                continue;
            }

            try {
                $text = $provider->render($context);
            } catch (\Exception $e) {
                \Helper::logException($e, '[AiChatPanel] Context provider "'.$key.'" threw while rendering: ');
                continue;
            }

            if ($text === null || trim((string) $text) === '') {
                continue;
            }

            $text = self::wrap($key, (string) $text);
            $actual = TokenBudget::estimate($text);

            // The estimate is advisory; the real size decides.
            if (!$budget->tryReserve($actual)) {
                $budget->drop('provider', self::safeLabel($provider, $key));
                continue;
            }

            $blocks[] = $text;
        }

        return $blocks;
    }

    /**
     * Provider output can contain customer data, so it goes inside the same
     * untrusted-data delimiters as the thread history.
     *
     * @param string $key
     * @param string $text
     *
     * @return string
     */
    protected static function wrap($key, $text)
    {
        $text = str_ireplace(
            [ContextBuilder::DELIMITER_OPEN, ContextBuilder::DELIMITER_CLOSE],
            ['[removed]', '[removed]'],
            $text
        );

        return 'Additional context from "'.$key."\":\n"
            .ContextBuilder::DELIMITER_OPEN."\n"
            .trim($text)."\n"
            .ContextBuilder::DELIMITER_CLOSE;
    }

    /**
     * @param ContextProvider $provider
     * @param string          $key
     *
     * @return string
     */
    protected static function safeLabel(ContextProvider $provider, $key)
    {
        try {
            return (string) $provider->label();
        } catch (\Exception $e) {
            return $key;
        }
    }
}
