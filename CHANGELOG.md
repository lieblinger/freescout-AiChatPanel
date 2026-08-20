# Changelog

Newest first. No release so far has removed a setting or changed an endpoint.
1.3.1 is the first to require a migration; it rewrites stored chat history and
runs with the module's other migrations.

## 1.3.4

* Fix - The assistant sees a mail that arrives while you are chatting about the ticket. The conversation was being read fresh for every message you sent, but it was the last thing the request made room for: the instructions, the tools, the draft open in your editor and the whole chat so far were all counted first, and once they filled the budget the conversation was dropped out of the request in its entirety. Nothing said so. What the assistant had left to go on was the chat, in which its own earlier answers describe the ticket as it stood when they were written — so it would insist nobody had replied, and then find the reply the moment you made it look. The conversation now has a guaranteed share of the budget that the chat cannot take, and the newest message is kept whatever else has to go. The longer the chat, the more this mattered.
* Change - The default context budget is 64000 tokens, up from 16000. The old figure was sized for the small local models the panel was first written against; every endpoint it is realistically pointed at now carries 128k or more, and the instructions alone take about 3000 of it. This is a default, so it applies to installs that have never saved the AI Chat Panel settings page — if yours has, the stored value is left exactly as it is and you can raise it under Settings » AI Chat Panel, or per mailbox.
* Fix - What gets left out of a long conversation is really the oldest part of it. When the budget ran short the assistant kept walking down the thread past anything that did not fit, so a long recent mail was dropped while short older ones were kept — and the note explaining the gap called them "the oldest". It now stops at the first message it cannot fit, and says what it means.
* Fix - A mail with attachments and no text is no longer dropped without trace. Its body was empty once the quoted reply chain was removed, so the whole message vanished from what the assistant was given and it was never told the mail existed. It is now shown with its attachment list and marked as having no readable text. The same goes for a message whose text does not survive quote and signature removal: the panel says so rather than leaving you to wonder what the assistant read.
* Change - Every date the assistant is shown now comes with how long ago it was, worked out here rather than by the model, and the conversation summary names the newest message and when it arrived. That one line survives even when the budget is too tight for anything else, so "has the customer written back" has an answer in every request.
* Change - Greetings in a draft match the time of day it is being written, not the time of day of the mail it answers. Answering an evening mail the next morning was producing "have a nice evening".
* New - The panel can be resized and undocked on a tablet, not only on a desktop. Below the width where a third column fits, the pin button now swaps between the drawer and a floating window instead of being hidden: there was never a shortage of room for a window on an iPad, only for a column, and the two were being decided by the same test. The drawer gained its left edge back as well, so it can be dragged wider or narrower the way the column can.
* New - Dragging works with a finger. The panel's drag handles — the drawer's edge, the window's header, its eight edges and corners — were mouse-only, so on a touch screen dragging any of them scrolled the page instead. They are pointer-driven now, the grips are bigger where the pointer is imprecise, a tap on the window header stays a tap, and a drag the system interrupts leaves the window where it stood rather than storing a position nobody chose.
* Change - A tablet restores a window you placed there; it never conjures one up. Everyone's stored shape says "floating" whether they picked it or not, so on its own it is not evidence of a decision — the saved position is. Until the pin has been pressed once, a screen with no room for a column still shows the drawer, exactly as before.
* Fix - A floating window follows a tablet's rotation and gets out of the way of the software keyboard. It was measured against the layout viewport, which neither of those changes, so a window placed in landscape stayed off screen in portrait, and one sitting low on the screen put its composer under the keys — while you were typing in it.
* Fix - Dragging the panel's edge no longer jumps when the page is scrolled sideways, which one wide quoted table is enough to cause. It followed the pointer's position in the document; the panel is fixed to the viewport.
* Fix - Scrolling back through a chat while the assistant is writing works. The message list was pinned to the bottom on every token that arrived, so trying to re-read an earlier answer mid-answer pulled the view back down a few times a second, and again the moment the turn finished. It now follows new messages only when you are already at the bottom of the list; scroll up and your position is left alone.
* New - A button to get back to the newest message, over the bottom of the list whenever you have scrolled away from it. It carries a count of what arrived while you were reading further up — tool activity, the answer itself, and a pending confirmation, which no longer waits for you off screen unannounced. Pressing it returns to the bottom and resumes following.
* Fix - Reopening the panel lands on the newest message rather than wherever the browser happened to leave it.
* Fix - A draft the assistant writes or rewrites appears without reloading the page. The conversation was left showing the old text — or no draft at all — until the agent reloaded, which is also how they found out that a rewrite they had asked for had happened. The change now travels on the reply the panel is already waiting for, so the draft block updates the moment the assistant finishes, whether or not the browser's live connection is working.
* New - A rewrite reaches the reply editor when the draft is open in it. Until now the editor kept the text from before the rewrite, so the change was invisible and the next save or send quietly put the old wording back. If you have not typed since opening the draft, the new text simply appears; if you have, you are offered it above the editor and choose between it and your own — nothing you wrote is replaced without you saying so.
* New - "Open in editor" is offered after a rewrite, not only after a draft is first written.

## 1.3.3

* Fix - Turning down an action the assistant suggested no longer ends the chat. Rejecting a suggested draft reply, note or status change left the endpoint with a question it had asked and no answer to it, so that message and every one after it in the same chat came back as "The AI endpoint returned an error (400)". The same thing happened whenever a tool failed for any other reason — a lookup that found nothing, a bad argument — and the chat stayed broken from then on. It is now told what happened, as it always should have been, and any chat already stuck this way repairs itself the next time you write in it.

## 1.3.2

* New - The chat works while a new mail is being written, not only on a conversation that already exists. Open it from the toolbar on the New Conversation screen, ask for the mail you want, and insert the answer into the editor. If the recipient is someone you have written to before, their earlier conversations are available to the assistant the same way they are on an existing ticket.
* New - The chat that produced a mail is still there after it is sent. It is kept with the draft FreeScout creates for the compose form, and sending publishes that same conversation — so reopening the ticket shows the exchange the mail came out of. Discarding the draft takes the chat with it.
* New - The panel appears on a saved draft you reopen. That screen is the compose screen, so it never had one.
* Change - While a mail is unsent the assistant writes its answer in the chat for you to insert, and the tools that save a draft reply, rewrite a draft, add a note or set a status are withheld. There is nothing for them to act on until the mail has been sent, and the mail itself is what you are editing.
* New - The assistant knows what day it is. The current date and time are in every request, so "how long has this been open", "has that date passed" and "reply that we will ship on Monday" no longer get answered from whatever the model's training data thought today was.
* Fix - Every timestamp the assistant is shown is now in the agent's own timezone, the same one the conversation view uses. It was being given the raw stored value with no timezone stated, so on any install not running in UTC it reported message times that disagreed with the screen the agent was reading — and worked out elapsed times from them.
* New - A `time_now` tool, off by default. It reports the clock plus the conversation's age and the time since the last message, worked out in PHP rather than by the model. Tick it in Settings » AI Chat Panel to switch it on; fresh installs have it on already.
* New - The panel can leave its column. The pin button in its header undocks it into a window: smaller, dragged by the header, sized from any edge or corner, and floating over a conversation that gets its full width back. Pin it again and it is the column it was, at the width it had.
* Change - The panel starts as a window rather than a column. It is still shut until you ask for it, and the first time you open it you get the floating window over a conversation at full width instead of a third column. Pin it and the column is back, remembered from then on — and anyone who had already docked one keeps it.
* Fix - The model picker is not shown when there is nothing to pick. An empty dropdown sat in the panel header until the chat had loaded, and stayed there on an install whose endpoint reports no models or whose chat could not be loaded at all.
* New - The shape, the window's position and its size are remembered per user, next to the open state and the width, so the window comes back where it was left — in any browser, not only the one it was moved in. A screen too small for it only caps what is drawn: the window is pulled fully back on screen there and the stored geometry survives, so the larger monitor gets it back unchanged. Below the drawer breakpoint there is no window at all — the panel is a drawer and the pin button is hidden.

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
