<?php

namespace Modules\AiChatPanel\Services\Tools\Builtin;

use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\ToolResult;

/**
 * Read tool: the properties of the customer on this conversation.
 *
 * Reference implementation. Deliberately limited to the customer of the *open*
 * conversation — there is no "look up any customer" tool, because that would
 * turn the chat panel into an unaudited customer-database search that ignores
 * the mailbox boundary.
 */
class GetCustomerTool extends AbstractTool
{
    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return 'customer.get';
    }

    /**
     * {@inheritdoc}
     */
    public function description()
    {
        return 'Get the stored profile of the customer on the current conversation: name, '
            .'email addresses, company, job title, phone numbers, address and website. '
            .'Use this when you need contact details or need to address the customer correctly. '
            .'Do not guess these values from the message text — read them here.';
    }

    /**
     * {@inheritdoc}
     */
    public function parameters()
    {
        return $this->noParameters();
    }

    /**
     * {@inheritdoc}
     */
    public function isRelevant(PanelContext $context)
    {
        return (bool) $context->customer;
    }

    /**
     * {@inheritdoc}
     */
    public function authorize(PanelContext $context)
    {
        if (!parent::authorize($context)) {
            return false;
        }

        // Core has its own policy for seeing a customer; use it rather than
        // assuming that access to the conversation implies access to the
        // customer record.
        return !$context->customer || $context->user->can('view', $context->customer);
    }

    /**
     * {@inheritdoc}
     */
    public function handle(array $arguments, PanelContext $context)
    {
        $customer = $context->customer;

        if (!$customer) {
            return ToolResult::error('This conversation has no customer linked.');
        }

        $data = [
            'name'      => $customer->getFullName(true),
            'emails'    => $customer->getEmailsAsArray(),
            'company'   => $customer->company ?: null,
            'job_title' => $customer->job_title ?: null,
            'city'      => $customer->city ?: null,
            'country'   => $customer->country ?: null,
            'notes'     => $customer->notes ? \Illuminate\Support\Str::limit($customer->notes, 1000) : null,
        ];

        // phones and websites are JSON columns holding [{'value' => ...}, ...];
        // core exposes accessors for both.
        try {
            $data['phones'] = $this->values($customer->getPhones());
            $data['websites'] = $this->values($customer->getWebsites());
        } catch (\Exception $e) {
            // Not worth failing the whole lookup over an optional field.
        }

        // Drop empties so the model is not handed a wall of nulls to reason
        // about.
        $data = array_filter($data, function ($value) {
            return $value !== null && $value !== '' && $value !== [];
        });

        return ToolResult::ok($data, __('Read the customer profile'));
    }

    /**
     * Flatten core's [{'value' => ...}] JSON field shape.
     *
     * @param mixed $entries
     *
     * @return array
     */
    protected function values($entries)
    {
        if (!is_array($entries)) {
            return [];
        }

        $values = [];

        foreach ($entries as $entry) {
            if (is_string($entry) && $entry !== '') {
                $values[] = $entry;
            } elseif (is_array($entry) && !empty($entry['value'])) {
                $values[] = $entry['value'];
            }
        }

        return $values;
    }
}
