# Changelog

All notable changes to AI Chat Panel, newest first.

Versions follow [semantic versioning](https://semver.org/). Every release so
far has been additive or a fix: no release has required a migration, removed a
setting, or changed an endpoint.

---

## 1.2.2 — 2026-08-13

**The JavaScript strings are translated on every install.** A German panel used
to render its server-side half in German and its client-side half in English —
the empty state, day separators, `Denkt nach …`, error notices and the
confirmation dialog, around thirty strings. Nothing was missing from `de.json`:
the strings were compiled into `Public/js/vars.js` by `freescout:module-build`,
which nothing in FreeScout ever runs, and which is not in the release zip. They
now travel as a `data-` attribute rendered per request, so there is no build
step for an install to skip. If you worked around this by running
`freescout:module-build` by hand, you no longer need to.

**Paragraph breaks survive into the reply editor.** A blank line in the model's
answer arrived as a plain line break, stacking a draft's salutation, body and
sign-off on consecutive lines. The editor's empty paragraph now separates them.
The markdown round trip is unchanged.

**Clicking a prompt shortcut sends it.** It no longer only fills the input, so
write shortcuts as complete prompts. The busy and pending-confirmation guards
still apply, and a refused send leaves the text in the box.

## 1.2.1 — 2026-08-13

**Prompt shortcuts are translated.** Their labels come from a setting rather
than from a template, so the view echoed the stored English while the rest of
the panel followed the interface language. The five shipped defaults now have
German entries; a shortcut you typed yourself passes through as typed. The
prompt each button prefills is translated with its label.

**The shortcut strip no longer scrolls.** It was capped at three rows while the
shipped list is five, so a default install put a scrollbar beside the buttons
and hid the last two behind it.

**Smaller.** The composer placeholder no longer repeats "Ctrl+Enter to send"
directly above the hint that says it.

## 1.2.0 — 2026-08-13

**Responsive panel.** The panel takes a column of its own only while the message
thread would keep 450px beside it, and becomes a drawer over the conversation
otherwise — closed on load, opened from the toolbar button, dismissed by tapping
the dimmer. The space available is measured off the live DOM rather than guessed
from a breakpoint: core's left nav and customer rail take 540px of the window
between them, which is why a 1133px window used to leave the thread at 225px
with the subject wrapping one word per line. Where the panel does fit, an
over-wide stored width is capped for display only, so widening the window hands
it straight back.

> **Behaviour change.** `panel_open` used to be applied at every window width,
> so a preference set at a desk opened a full-screen overlay on a phone. Opening
> and closing on a narrow window no longer writes the preference at all.

**Day separators and times.** Messages carry a time and a day separator, both
through `User::dateFormat`, so they follow the viewer's timezone, 12/24-hour
preference and locale. Today/Yesterday is decided client-side and relabelled at
midnight, so a panel left open overnight does not go stale.

**Security fix.** Attribute values built by the client renderer are escaped.

**Also.** A module icon on the Modules settings page.

## 1.1.5 — 2026-08-13

**The panel docks beside the conversation** instead of covering the customer
sidebar and the right edge of the threads. The rule that made room for it put
`margin-right` on `#conv-layout`, which core gives an explicit `width: 100%` —
a margin cannot shrink a box whose width is already stated. It goes on
`.content-2col`, a flex item, where it does.

> Clear the cached build after upgrading: `php artisan freescout:clear-cache`

## 1.1.4 — 2026-08-13

**`module.css` no longer goes through core's `Minify::stylesheet()`**, which was
deleting every `var()` declaration in it and leaving the panel unpositioned. The
panel opened, but as a fixed element with no top, bottom or width it landed at
the foot of the document at full width — far below the viewport on any real
conversation, which looked like it was not opening at all. Installs running
`APP_ENV=local` were never affected, because minification is off there.

> Clear the cached build after upgrading: `php artisan freescout:clear-cache`

## 1.1.3 — 2026-08-13

**PHP 8.5 compatibility.** `curl_close()` is guarded by a version check and the
two implicitly nullable parameters in `CurlLlmClient` carry an explicit
`?array`. Both raised deprecations that Laravel turns into `ErrorException`,
which the module's broad catch blocks swallowed — so every request the panel
made failed with a generic error that read like an endpoint outage. Installs on
PHP 8.3 and below were never affected.

> Clear the cache after upgrading: `php artisan freescout:clear-cache`

## 1.1.2 — 2026-08-13

**marked and DOMPurify no longer go through core's `Minify::javascript()`**,
which JShrink was corrupting into a `SyntaxError` that took down the whole JS
bundle — and with it every menu on every page — on any install not running
`APP_ENV=local`.

> Clear the cached build after upgrading, or the broken bundle keeps being
> served from `public/js/builds`: `php artisan freescout:clear-cache`

## 1.1.1 — 2026-08-13

**Documentation only.** No code, no config, no schema. 1.1.0 shipped a README
whose testing section prescribed two workarounds that core fixes had since made
unnecessary.

## 1.1.0 — 2026-08-13

Everything since 1.0.0 is additive: no migrations, no removed settings, no
changed endpoints.

Two config defaults moved — `max_context_tokens` from 8000 to 16000, and
`conversation.get_drafts` added to `tools_enabled`. Defaults reach existing
installs only through a fresh install, so upgraders keep what they have and tick
the new tools themselves.

## 1.0.0 — 2026-08-13

First public release.

An interactive AI chat panel in the conversation view, backed by any
OpenAI-compatible endpoint: per-mailbox settings, a context builder with a token
budget, an open tool registry with read and write tools, per-action confirmation
for anything that changes data, streaming replies, markdown that survives the
trip into the reply editor, and a tool audit log.
