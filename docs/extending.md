# Extending AiChatPanel

AiChatPanel is built to be extended. Another module can give the assistant new
**tools** (things it can do) and new **context providers** (things it always
knows), without AiChatPanel knowing that module exists.

Everything here goes through FreeScout's own Eventy filters. There is nothing to
install, nothing to register in a config file, and no dependency in either
direction: **AiChatPanel contains no knowledge of any other module, and your
module does not need AiChatPanel to be installed to keep working** — if it is
absent, your filter callbacks simply never fire.

Three extension points:

| Filter | Purpose | Cost |
|---|---|---|
| `aichatpanel.tools` | capabilities the model can invoke | one round trip per call |
| `aichatpanel.context_providers` | read-only facts appended to every request | paid on every message |
| `aichatpanel.prompt_shortcuts` | buttons above the chat input | free |

**Rule of thumb:** if the data is small, cheap and relevant to nearly every
conversation, make it a **context provider**. If it is large, expensive, or only
occasionally needed, make it a **tool**.

Two Eventy details that catch people out:

- **The `$arguments` count defaults to `1`.** All three filters below pass two
  values, so you must pass `2`, or `$context` arrives as `null`.
- **The default priority is 20**, not 10. Lower runs earlier.

---

## Contents

- [Tools](#tools)
  - [The interface](#the-interface)
  - [A read tool](#a-read-tool)
  - [A write tool](#a-write-tool)
  - [Registering them](#registering-them)
  - [Rules the registry enforces for you](#rules-the-registry-enforces-for-you)
  - [Writing a thread body from a tool](#writing-a-thread-body-from-a-tool)
  - [Writing a good description](#writing-a-good-description)
- [Context providers](#context-providers)
- [Prompt shortcuts](#prompt-shortcuts)
- [The PanelContext object](#the-panelcontext-object)
- [Testing your extension](#testing-your-extension)
- [Checklist](#checklist)

---

## Tools

### The interface

`Modules\AiChatPanel\Services\Tools\Tool`. Extend
`Modules\AiChatPanel\Services\Tools\AbstractTool` rather than implementing the
interface directly — it supplies safe defaults, so a later addition to the
interface will not break your module.

| Method | Returns | Notes |
|---|---|---|
| `name()` | `string` | Namespaced and stable, e.g. `crm.get_contact`. Must match `^[a-zA-Z0-9_.-]{1,64}$`. Renaming resets the admin's settings. |
| `description()` | `string` | For the model. **English, not translated.** |
| `parameters()` | `array` | JSON Schema, object type. |
| `mode()` | `'read'` or `'write'` | Default `read`. |
| `authorize(PanelContext $c)` | `bool` | Delegate to core. Default: user can view the conversation. |
| `isRelevant(PanelContext $c)` | `bool` | Default `true`. Return `false` to keep it out of the payload. |
| `confirmationLabel(array $args, PanelContext $c)` | `string` | Translated. Write tools only. |
| `handle(array $args, PanelContext $c)` | `ToolResult` | Arguments are already validated. |

### A read tool

```php
<?php

namespace Modules\MyCrm\AiTools;

use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\ToolResult;

class GetContactTool extends AbstractTool
{
    public function name()
    {
        return 'crm.get_contact';
    }

    public function description()
    {
        return 'Look up the CRM record for the customer on the current conversation: '
            .'account tier, renewal date, open invoices and assigned account manager. '
            .'Use this when the answer depends on what the customer has bought or is owed. '
            .'Do not use it for contact details, which are already in the conversation.';
    }

    public function parameters()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'include_invoices' => [
                    'type'        => 'boolean',
                    'description' => 'Also return the list of unpaid invoices. Defaults to false.',
                ],
            ],
        ];
    }

    /**
     * Keep it out of the payload when there is nothing to look up. This saves
     * tokens and stops the model reaching for a tool that cannot help.
     */
    public function isRelevant(PanelContext $context)
    {
        return (bool) $context->customer;
    }

    public function handle(array $arguments, PanelContext $context)
    {
        $contact = \Modules\MyCrm\Entities\Contact::forEmail($context->customer->getMainEmail());

        if (!$contact) {
            // A normal outcome, not an exception. The model reads this and
            // moves on.
            return ToolResult::error('No CRM record exists for this customer.');
        }

        $data = [
            'tier'            => $contact->tier,
            'renewal_date'    => $contact->renewal_date,
            'account_manager' => $contact->manager_name,
        ];

        if (!empty($arguments['include_invoices'])) {
            $data['unpaid_invoices'] = $contact->unpaidInvoices()->limit(10)->get()->toArray();
        }

        // The second argument is a short human line for the audit log and the
        // activity row in the panel. It is not sent to the model.
        return ToolResult::ok($data, __('Read the CRM record'));
    }
}
```

### A write tool

Two extra obligations: `mode()` and a `confirmationLabel()` that a human can
judge in one read.

```php
<?php

namespace Modules\MyCrm\AiTools;

use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\AbstractTool;
use Modules\AiChatPanel\Services\Tools\Tool;
use Modules\AiChatPanel\Services\Tools\ToolException;
use Modules\AiChatPanel\Services\Tools\ToolResult;

class AddCrmNoteTool extends AbstractTool
{
    public function name()
    {
        return 'crm.add_note';
    }

    public function description()
    {
        return 'Add a note to the customer CRM record. Notes are visible to sales '
            .'and support but never to the customer. Use it to record an outcome '
            .'the CRM should know about, such as a promised refund.';
    }

    public function parameters()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'body' => [
                    'type'        => 'string',
                    'description' => 'The note text. One or two sentences.',
                    'minLength'   => 1,
                    'maxLength'   => 2000,
                ],
            ],
            'required' => ['body'],
        ];
    }

    public function mode()
    {
        return Tool::MODE_WRITE;
    }

    /**
     * Never do more than the user could do by hand. Combine the conversation
     * gate with whatever permission your own module already uses.
     */
    public function authorize(PanelContext $context)
    {
        return $context->userCanUpdate()
            && $context->user->hasPermission(\Modules\MyCrm\Entities\Permissions::EDIT_CONTACTS);
    }

    /**
     * Shown in the confirmation dialog. Describe the EFFECT — the exact
     * arguments are displayed separately, so do not repeat them. This one IS
     * translated: a person reads it.
     */
    public function confirmationLabel(array $arguments, PanelContext $context)
    {
        return __('Add a note to the CRM record of :customer. Sales will see it; the customer will not.', [
            'customer' => $context->customer->getFullName(true),
        ]);
    }

    public function handle(array $arguments, PanelContext $context)
    {
        $contact = \Modules\MyCrm\Entities\Contact::forEmail($context->customer->getMainEmail());

        if (!$contact) {
            // ToolException is for an expected failure. Its message goes to the
            // model, so write it for the model — and put nothing sensitive in it.
            throw new ToolException('No CRM record exists for this customer, so no note could be added.');
        }

        $note = $contact->notes()->create([
            'body'    => $arguments['body'],
            // Attribute it to the real person, never to a service account.
            'user_id' => $context->user->id,
        ]);

        return ToolResult::ok(
            ['note_id' => $note->id],
            __('Added a CRM note')
        );
    }
}
```

### Registering them

In your module's `ServiceProvider::boot()`:

```php
\Eventy::addFilter('aichatpanel.tools', function ($tools, $context) {
    $tools[] = new \Modules\MyCrm\AiTools\GetContactTool();
    $tools[] = new \Modules\MyCrm\AiTools\AddCrmNoteTool();

    return $tools;
}, 20, 2);   // <- the 2 is required, see the note at the top
```

> **`$context` is `null` on the admin settings screen.** That page lists every
> registered tool so the administrator can switch each one on or off, and it is
> not about any particular conversation. Return your tools unconditionally and
> do not touch `$context` in the callback itself — only `name()`,
> `description()` and `mode()` are read in that case. `authorize()` and
> `isRelevant()` are never called with a null context.

New tools arrive **disabled**. An administrator has to tick them in
*Manage » Settings » AI Chat Panel*, and write tools additionally need the
master "Allow write tools" switch.

### Rules the registry enforces for you

You cannot opt out of these, and you do not have to implement them:

1. **Tools run as the logged-in user.** There is no service account anywhere in
   the module.
2. **`authorize()` is called twice** — before the tool is offered to the model,
   and again immediately before it executes. The second check matters because
   the model's tool call is untrusted input like anything else.
3. **A disabled or unauthorised tool is reported as "unknown"**, never as
   "forbidden". Saying "that exists but you may not use it" leaks information
   into the prompt.
4. **Every `write` tool is confirmed by a human** before it runs. An
   administrator can exempt individually named write tools, but there is
   deliberately no global "trust all writes" switch.
5. **Arguments are validated against your schema** before `handle()` is called,
   with mild coercion for the mistakes models actually make (`"7"` for an
   integer, `"true"` for a boolean). A schema violation is reported back to the
   model as a structured error; your handler never sees it.
6. **Every execution is audited** — including the ones that were blocked or
   rejected — with the user, conversation, tool, arguments, status and duration.
7. **Exceptions never escape.** A `ToolException` message reaches the model; any
   other exception is logged with a stack trace and the model is told only that
   the tool failed.

### Writing a thread body from a tool

If your tool stores a reply, a note or a draft, take Markdown from the model and
put it through `AbstractTool::renderBody()`:

```php
$thread->body = self::renderBody($arguments['body']);
```

That converts the Markdown to the HTML FreeScout's editor produces and sanitises
it in the same step, so you do not need `htmlspecialchars()`,
`\Helper::stripDangerousTags()` or anything else on top.

The reason it is not just `nl2br(htmlspecialchars(...))`: everything in
`threads.body` is run through core's HTMLPurifier config both when it is
displayed and when it is rendered into outgoing mail, and that whitelist has no
`<code>`, no `<hr>` and no `<del>`. `Services/Markdown/EditorHtmlProfile.php`
is a strict subset of it, which is what keeps the formatting alive all the way
to the customer's mailbox. Say so in your `parameters()` description, or the
model will write plain text.

Going the other way — reading a stored body back for the prompt — use
`Services\Markdown\HtmlToMarkdown::fromThread()`, or
`Context\ThreadFormatter::body()` if you also want the quote chain and signature
removed.

### Writing a good description

The description is prompt text. It is the single biggest influence on whether
the model uses your tool correctly.

- Say **when to use it**, not just what it does.
- Say **when not to use it** if there is an obvious wrong case.
- Describe every parameter in its schema, including the default.
- Keep it to two or three sentences. It is paid for on every request.

```php
// Bad — the model has to guess.
return 'Gets contact data.';

// Good.
return 'Look up the CRM record for the customer on the current conversation: '
    .'account tier, renewal date and open invoices. Use this when the answer '
    .'depends on what the customer has bought. Do not use it for contact '
    .'details, which are already in the conversation.';
```

---

## Context providers

A provider appends a labelled, read-only block to the system message on **every**
request. No round trip, but you pay for the tokens every time — so keep it short
and make it earn its place.

`Modules\AiChatPanel\Services\Context\ContextProvider`:

```php
<?php

namespace Modules\MyCrm\AiContext;

use Modules\AiChatPanel\Services\Context\ContextProvider;
use Modules\AiChatPanel\Services\PanelContext;

class AccountStatusProvider implements ContextProvider
{
    /** Namespaced and stable: this is the admin toggle key. */
    public function key()
    {
        return 'mycrm.account_status';
    }

    /** Translated — an administrator reads this in the settings. */
    public function label()
    {
        return __('CRM account status');
    }

    /**
     * Lower runs earlier and survives longer when the budget is tight. The
     * built-in provider uses 20; use less than 20 to outrank it.
     */
    public function priority()
    {
        return 10;
    }

    /**
     * Checked before render() is called. Over-estimating is the safe way to be
     * wrong: an under-estimate can push the request over the model's limit.
     */
    public function estimatedTokens(PanelContext $context)
    {
        return 80;
    }

    /**
     * Return null to contribute nothing this time. Start with a short heading
     * so the model can tell blocks apart.
     *
     * Anything here that came from a customer is untrusted; the caller wraps
     * the whole block in the data delimiters for you.
     */
    public function render(PanelContext $context)
    {
        if (!$context->customer) {
            return null;
        }

        $contact = \Modules\MyCrm\Entities\Contact::forEmail($context->customer->getMainEmail());

        if (!$contact) {
            return null;
        }

        return "CRM account status:\n"
            .'- Tier: '.$contact->tier."\n"
            .'- Renews: '.$contact->renewal_date."\n"
            .'- Unpaid invoices: '.$contact->unpaidInvoices()->count();
    }
}
```

```php
\Eventy::addFilter('aichatpanel.context_providers', function ($providers, $context) {
    $providers[] = new \Modules\MyCrm\AiContext\AccountStatusProvider();

    return $providers;
}, 20, 2);
```

Same `null`-context rule as tools: on the settings screen only `key()` and
`label()` are read.

A provider that throws is logged and skipped; the chat carries on without it.
When the token budget runs out, the lowest-priority providers are dropped first
and the panel tells the user that context was shortened.

The module ships one provider as a worked reference —
`Services/Context/Providers/PreviousConversationsProvider.php`. It is written to
be read.

---

## Prompt shortcuts

The cheapest extension point. Shortcuts are buttons above the input that
prefill it; the user still presses send.

```php
\Eventy::addFilter('aichatpanel.prompt_shortcuts', function ($shortcuts, $context) {
    if ($context->mailbox && $context->mailbox->name === 'Billing') {
        $shortcuts[] = __('Draft a refund confirmation for this customer.');
    }

    return $shortcuts;
}, 20, 2);
```

Plain strings. Keep them short — they are rendered as buttons.

The panel runs every shortcut through `__()` before it renders it, so a string
that has a translation entry is shown — and sent to the model — in the agent's
language, and one that has not passes through as typed. Calling `__()` in your
filter as well, like the example does, is harmless and keeps the intent visible
at the call site; what you must not do is translate at registration time and
cache the result, because the filter runs once per request but the locale is
per user.

---

## The PanelContext object

`Modules\AiChatPanel\Services\PanelContext` is passed to every filter, every
tool and every provider.

| Property | Type | Notes |
|---|---|---|
| `$conversation` | `App\Conversation` | The open conversation. |
| `$mailbox` | `App\Mailbox` | |
| `$customer` | `App\Customer\|null` | **Often null.** Always check. |
| `$user` | `App\User` | The logged-in user. Never elevated. |

| Method | Purpose |
|---|---|
| `userCanView()` | `ConversationPolicy@view` on the open conversation |
| `userCanUpdate()` | `ConversationPolicy@update` — the gate for write tools |
| `canViewConversation($conversation)` | For any *other* conversation you are about to expose |
| `setting($name)` | A module setting with this mailbox's override applied |

`canViewConversation()` is the one to remember. If your tool returns rows about
conversations other than the open one, filter every row through it — a customer
can have conversations in mailboxes this agent cannot see, and putting those in
the prompt leaks them.

Adding fields to `PanelContext` is a compatible change; removing or renaming one
is not.

---

## Testing your extension

The module ships a fake client so you can test an agent run without a model:

```php
use Modules\AiChatPanel\Services\Agent\AgentLoop;
use Modules\AiChatPanel\Services\Llm\FakeLlmClient;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Tools\ToolRegistry;

$client = (new FakeLlmClient())
    ->queueToolCall('crm.get_contact', ['include_invoices' => true])
    ->queueText('The customer is on the Enterprise tier.');

$context = new PanelContext($conversation, $user);
$outcome = (new AgentLoop($client, new ToolRegistry($context), $context, 'fake-model'))
    ->run([
        ['role' => 'system', 'content' => 'system'],
        ['role' => 'user', 'content' => 'What tier is this customer on?'],
    ]);

// What the endpoint was actually sent, request by request:
$client->payloads;
```

`FakeLlmClient` also has `queueToolCalls()` for several calls in one turn,
`queueException()` for endpoint failures, and `queueResponse()` when you need to
build a `ChatResponse` yourself.

AiChatPanel's own suite under `Tests/` uses exactly this, and its
`Tests/Support/` tools are registered through the public filter — so they double
as working examples of everything above.

---

## Checklist

Before you ship:

- [ ] Tool names are namespaced by your module and stable.
- [ ] `description()` says when to use the tool **and** when not to. English.
- [ ] Every parameter has a `description` in the schema.
- [ ] `authorize()` delegates to a core policy or permission.
- [ ] Write tools return `Tool::MODE_WRITE` and have a `confirmationLabel()`.
- [ ] `isRelevant()` returns false when the tool cannot help.
- [ ] Handlers return `ToolResult`, and throw `ToolException` for expected failures.
- [ ] Nothing sensitive in a `ToolException` message — it goes into the prompt.
- [ ] Other conversations are filtered through `canViewConversation()`.
- [ ] `$context` may be `null` in the filter callback.
- [ ] Both filters registered with `20, 2`.
