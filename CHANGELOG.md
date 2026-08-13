# Changelog

Newest first. No release so far has required a migration, removed a setting or
changed an endpoint.

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
