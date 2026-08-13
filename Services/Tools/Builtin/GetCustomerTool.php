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
            .'all email addresses, all phone numbers with their type, postal address, city, '
            .'state, postal code, country, company, job title, websites, social profiles and '
            .'the internal notes kept about them. '
            .'Call this whenever a reply needs a contact detail — a phone number to quote, an '
            .'address to confirm, the right form of the name. These are the stored values; do '
            .'not guess them from the message text and do not reformat them.';
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

        // The reduced set, for installs that would rather not send a postal
        // address and a free-text notes field to a third-party endpoint. This
        // is a data-processing choice, not an access-control one: everything in
        // the full set below is already on screen in the conversation sidebar
        // (core/resources/views/customers/profile_snippet.blade.php) for anyone
        // who can open the conversation.
        $full = (bool) $context->setting('send_personal_data');

        $data = [
            'name'      => $customer->getFullName(true),
            'emails'    => $customer->getEmailsAsArray(),
            'company'   => $customer->company ?: null,
            'job_title' => $customer->job_title ?: null,
            'city'      => $customer->city ?: null,
            'country'   => $full ? ($customer->getCountryName() ?: $customer->country) : $customer->country,
        ];

        if ($full) {
            $data['address'] = $customer->address ?: null;
            $data['state'] = $customer->state ?: null;
            $data['zip'] = $customer->zip ?: null;
            $data['channel'] = $customer->getChannelName() ?: null;
            $data['customer_since'] = $customer->created_at ? $customer->created_at->toDateString() : null;
        }

        // Match the per-message cap in GetConversationTool so one long notes
        // field cannot dominate the remaining context.
        $data['notes'] = $customer->notes
            ? \Illuminate\Support\Str::limit($customer->notes, $full ? 4000 : 1000)
            : null;

        // phones, websites and social profiles are JSON columns holding
        // [{'value' => ...}, ...]; core exposes accessors for all three.
        try {
            $data['phones'] = $full ? $this->typed($customer->getPhones(), \App\Customer::$phone_types) : $this->values($customer->getPhones());
            $data['websites'] = $this->values($customer->getWebsites());

            if ($full) {
                $data['social_profiles'] = $this->typed($customer->getSocialProfiles(), \App\Customer::$social_types);
            }
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
     * Core's [{'type' => 1, 'value' => ...}] JSON shape, with the numeric type
     * resolved to a name.
     *
     * The maps passed in are core's own $phone_types / $social_types, which
     * hold untranslated lowercase names ('work', 'mobile'). Deliberately not
     * getPhoneTypeName(), which returns the translated UI label — the model
     * should see a stable token, not whatever the agent's locale renders.
     *
     * @param mixed $entries
     * @param array $types
     *
     * @return array
     */
    protected function typed($entries, array $types)
    {
        if (!is_array($entries)) {
            return [];
        }

        $result = [];

        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['value'])) {
                continue;
            }

            $row = ['value' => $entry['value']];

            if (isset($entry['type']) && isset($types[$entry['type']])) {
                $row['type'] = $types[$entry['type']];
            }

            $result[] = $row;
        }

        return $result;
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
