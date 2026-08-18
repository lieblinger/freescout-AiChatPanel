# Changelog

Newest first. No release so far has removed a setting or changed an endpoint.
1.3.1 is the first to require a migration; it rewrites stored chat history and
runs with the module's other migrations.

## 1.3.1

* Fix - A chat that was started before 1.3.0 works again. Its stored turns still named the tools the way they were spelled then, that history is replayed to the model on every later message, and the model copied the old spelling back — so a request to save a draft reply came back as `Unknown tool "conversation.create_draft_reply"`. The stored names are renamed on upgrade, and the old spelling is accepted from the model in any case.
* Fix - The tool list in the settings shows the tools that are actually on. An install upgraded from 1.2.x had them stored under their pre-1.3.0 names, so every box appeared unticked — and saving that page would have written the empty list back and turned the tools off. Per-mailbox tool lists are renamed as well.
* Fix - The "Open in editor" link on a draft the assistant saved is back. It was matched against the tool's pre-1.3.0 name, so it never appeared.

## 1.3.0

* Fix - Tool calling works against OpenRouter, OpenAI and Anthropic. The builtin tools were named with a dot (`conversation.get`), which OpenAI and Anthropic reject outright, so every message sent with tools enabled came back as "The AI endpoint returned an error (400)". The tools are now named `conversation_get`, `customer_get` and so on, and any tool name a module registers is sanitised before it goes on the wire. Settings that name the old spelling keep working.
* Fix - An endpoint error says what the endpoint actually complained about instead of only its status code, in the panel and in the log. A 400 was previously impossible to diagnose without turning on prompt logging, which logs customer data.
* New - The model picker shows readable names grouped by vendor and sorted, rather than several hundred raw model ids in whatever order the endpoint listed them. Models that cannot do tool calling are marked.
* Change - "Load from endpoint" writes the allowed-models list in alphabetical order.

## 1.2.3

* Fix - The assistant no longer says it has looked at a photo or a document. Attachments reach it as a filename and a type only, and it was reading the filename as if it had seen the file.
* Fix - The assistant no longer writes that documents are attached to a draft. It cannot attach anything, and now says what the agent should attach instead.
* Fix - The assistant no longer reports an action as done — an order placed, an enquiry passed to a colleague — unless the conversation or the agent says it was, and promises nothing on the agent's behalf.
* Fix - On a conversation with no messages yet, the assistant writes only what the agent gave it and asks for the rest, instead of inventing the background of a first mail.
* Fix - A summary, an explanation or an analysis is answered in the chat panel. It used to be able to end up written into an internal note nobody asked for.
* Change - Text you type that reads as something meant for the customer is offered as a draft reply, rewritten to the mailbox's language and tone. It offers every time and waits for your yes — it never drafts from your text unasked.

## 1.2.2

* Fix - Panel strings rendered by JavaScript are translated on every install. They came from a generated `vars.js` that no FreeScout install path builds and that is not in the release zip, so a German panel showed its JavaScript half in English.
* Fix - Paragraph breaks in an answer survive into the reply editor instead of collapsing into single line breaks.
* Change - Clicking a prompt shortcut sends it instead of only filling the input. Write shortcuts as complete prompts.

## 1.2.1

* Fix - Prompt shortcuts are translated. The five shipped defaults have German entries; a shortcut you typed yourself passes through as typed.
* Fix - The shortcut strip no longer grows a scrollbar that hides the shortcuts past the third one.
* Change - The composer placeholder no longer repeats "Ctrl+Enter to send" above the hint that says it.

## 1.2.0

* New - The panel takes a column of its own only while the message thread would keep 450px beside it, and becomes a drawer over the conversation otherwise.
* New - Chat messages carry a time and a day separator, following the viewer's timezone, 12/24-hour preference and locale.
* Fix - Quotes in attribute values built by the client renderer are escaped.
* Change - Opening and closing the panel on a narrow window no longer writes the stored preference.
* Add - Module icon for the Modules settings page.

## 1.1.5

* Fix - The panel docks beside the conversation instead of covering the customer sidebar and the right edge of the threads. Run `php artisan freescout:clear-cache` after updating.

## 1.1.4

* Fix - `module.css` is no longer minified by core, which deleted every `var()` declaration in it and left the panel unpositioned. Run `php artisan freescout:clear-cache` after updating.

## 1.1.3

* Fix - PHP 8.5 deprecations in the cURL client made every request fail with a generic error that read like an endpoint outage. Installs on PHP 8.3 and below were not affected.

## 1.1.2

* Fix - marked and DOMPurify are no longer minified by core, which corrupted them into a syntax error that took down the whole JavaScript bundle and every menu on the page. Run `php artisan freescout:clear-cache` after updating.

## 1.1.1

* Fix - Corrected the testing section of the README.

## 1.1.0

* Change - Default maximum context tokens raised from 8000 to 16000.
* Change - `conversation.get_drafts` added to the default enabled tools.

## 1.0.0

* New - First release. An AI chat panel in the conversation view backed by any OpenAI-compatible endpoint, with per-mailbox settings, a token-budgeted context builder, an open tool registry, per-action confirmation for writes, streaming replies, markdown that survives into the reply editor, and a tool audit log.
