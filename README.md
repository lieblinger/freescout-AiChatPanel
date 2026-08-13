# AiChatPanel

An interactive AI chat panel for the FreeScout conversation view, backed by any
**OpenAI-compatible** endpoint — LiteLLM, vLLM, llama.cpp, Ollama, LM Studio, or
anything else that speaks `/v1/chat/completions`.

Built for self-hosted setups, but not restricted to them. There is no default
provider, no bundled SDK and no hardcoded URL.

**The module drafts. A human sends.** Nothing is ever sent to a customer
automatically — there is no auto-reply and no background answering anywhere in
it.

Licence: **AGPL-3.0-or-later**.

---

## What it does

- A resizable chat panel on the right of the conversation, opened from a toolbar
  button. Open/closed state and width persist per user.
- Multi-turn chat about the open conversation, with the full thread history,
  conversation metadata, customer details and attachment filenames as context.
- Streaming responses, rendered as Markdown and sanitised.
- **Insert as reply** or **insert as internal note** — appended below whatever
  is already in the editor, never replacing it, never sending.
- **Tools**: the assistant can look things up and, with confirmation, change
  things. Other modules can add their own without this module knowing they
  exist — see [docs/extending.md](docs/extending.md).
- Chats are stored per conversation and per user, so reopening a conversation
  restores the whole exchange including tool calls.
- English and German UI.

### Built-in tools

| Tool | Mode | What it does |
|---|---|---|
| `conversation.list_customer_conversations` | read | other conversations of this customer |
| `conversation.get` | read | one conversation by number, with its messages |
| `conversation.get_drafts` | read | the unsent drafts on a conversation, with their thread ids |
| `customer.get` | read | the full stored customer profile, including postal address, typed phone numbers and social profiles |
| `conversation.add_note` | write | add an internal note |
| `conversation.set_status` | write | change the conversation status |
| `conversation.create_draft_reply` | write | save a new draft reply (never sends) |
| `conversation.update_draft` | write | replace the text of an existing draft (never sends) |

The four read tools are on by default; the write tools start **disabled** and
are gated behind the "Allow write tools" master switch. Read tools run without
asking; every write tool is confirmed in the panel first.

**Drafts.** Drafts are deliberately absent from the conversation history the
model is given, so `conversation.get_drafts` is the only way it sees one — which
also means it re-reads the current text every time instead of working from a copy
in the system message that would be stale the moment it edited anything.
`conversation.update_draft` replaces the body of an existing draft, whether the
assistant or a human wrote it. Only drafts: a reply that has been sent, or a note
colleagues have already read, can never be changed through this module.

---

## Requirements

- FreeScout **1.8.233** or newer
- PHP **7.1+** with `curl`, `json` and `mbstring`
- An OpenAI-compatible endpoint reachable from the FreeScout server

No Node build step, no bundler, no CDN. The two vendored frontend libraries
(`marked`, `DOMPurify`) ship as files under `Public/js/vendor/` with their
licences.

---

## Install

1. Copy the module to `Modules/AiChatPanel` (or install the ZIP from
   *Manage » Modules*).
2. Activate it in *Manage » Modules*.
3. Run the installer:

   ```bash
   php artisan freescout:module-install aichatpanel
   ```

   > Activate **before** installing. `freescout:module-install` only migrates
   > modules that are active, so running it first reports "Nothing to migrate"
   > and the tables are not created. If that happens, activate and run it again,
   > or run `php artisan migrate --force`.

4. Configure it in *Manage » Settings » AI Chat Panel*: set the endpoint URL and
   API key, press **Test connection**, then tick the tools you want.

---

## Settings

### Global — *Manage » Settings » AI Chat Panel*

**Connection**

| Setting | Notes |
|---|---|
| Enabled | Master switch; turns the panel off without deactivating the module |
| Endpoint URL | Base URL. `/v1/chat/completions` is appended, so a trailing `/v1` is optional |
| API key | Stored **encrypted**, never sent to the browser, never logged |
| Request timeout | Default 120s. Agent runs with tools need headroom |
| Connect timeout | Default 10s |

**Model**

| Setting | Notes |
|---|---|
| Default model | Used until a user picks one |
| Allowed models | One per line. Users can only pick from this list. Empty = whatever the endpoint reports. **Load from endpoint** fills it in |
| Temperature | Default 0.3 |
| Max response tokens | Default 2048. See the reasoning-model note below |
| Tool support | Per-model flag, filled in by the connection test, correctable by hand |

**Context**

| Setting | Notes |
|---|---|
| Max context tokens | Budget for history plus extra context. Overflow drops the oldest messages and the panel says so |
| Include internal notes | Default on; per-mailbox overridable |
| Send personal data | Default on; per-mailbox overridable. Governs whether postal addresses, phone numbers, social profiles, customer notes and the agent's own contact details may be sent to the endpoint. Not an access control — all of it is already visible in the conversation sidebar to anyone who can open the ticket — but a limit on what leaves your server |
| System prompt | Appended to the built-in instructions |
| Prompt shortcuts | One per line; rendered as buttons that prefill the input |

**Context providers / Tools** — individual toggles for everything registered,
including tools from other modules. Plus:

| Setting | Notes |
|---|---|
| Allow write tools | Master switch. Off = no data-changing tool is offered at all |
| Run without confirmation | Named write tools exempt from the dialog. Empty by default. There is deliberately no "all writes" option, and neither `conversation.create_draft_reply` nor `conversation.update_draft` can ever be listed |
| Max tool steps | Tool-call/think cycles per message. Default 4 |
| Max tool time | Wall-clock cap per message. Default 60s |

**Limits and retention**

| Setting | Notes |
|---|---|
| Messages per minute / Tool runs per minute | Per user |
| Keep chats for | Days; 0 = forever. Chats always die with their conversation |
| Keep the tool audit log for | Separate retention, so the audit outlives the chat |
| Log full prompts | **Off by default** — prompts contain customer data. API keys are never logged either way |

### Per mailbox — *Mailbox settings » AI Chat Panel*

Enable/disable, extra system prompt, reply language, reply tone, include
internal notes, strip signatures, send personal data, and which tools and
providers are available. Every setting can be left on **Inherit**.

A mailbox can narrow the global tool selection, never widen it.

---

## Known-working endpoints

Verified against **llama.cpp** (build b10326, `qwen3.5-9b`), including streaming
and tool calling.

| Server | Tool calling |
|---|---|
| llama.cpp | yes, with `--jinja` and a template that supports tools |
| vLLM | yes, with `--enable-auto-tool-choice` and a tool-call parser |
| Ollama | model-dependent; silently ignored when the template lacks it |
| LM Studio | varies by model and runtime version |
| LiteLLM | passes through to the upstream provider |

The panel degrades rather than failing: if the endpoint rejects the `tools`
parameter, the module remembers that for the model, tells the user, and answers
without tools.

### Reasoning models

Models that emit a separate `reasoning_content` (Qwen3, DeepSeek-R1 and
friends) are supported: the chain of thought is shown collapsed and is **never**
replayed to the model.

One consequence worth knowing: **`max_tokens` covers the reasoning too.** Set it
too low and the model spends its whole budget thinking and returns an empty
answer with `finish_reason: length`. The panel reports that explicitly, but the
fix is to raise "Max response tokens". 2048 is a sensible floor.

---

## Streaming behind nginx and PHP-FPM

Streaming needs nothing added to your nginx config. The response carries
`X-Accel-Buffering: no`, which nginx consumes to disable FastCGI buffering for
that response only.

If you do not see incremental output:

- **`X-Accel-Buffering` is missing from the response headers.** That is normal
  and is the evidence it worked — nginx strips the header once it has acted on
  it.
- **gzip.** `gzip on` for `text/event-stream` will buffer the stream. Either
  exclude that type or leave gzip off for it.
- **Another proxy in front** (Cloudflare, a load balancer, Apache
  `mod_proxy_fcgi`) may buffer independently. For Apache, add
  `SetEnv proxy-sendchunked 1` and disable `mod_deflate` for the route.
- **`output_buffering` in php.ini.** The module unwinds every output buffer and
  turns on implicit flush, so this is usually handled, but an `auto_prepend_file`
  that opens its own buffer can still interfere.
- **PHP-FPM `request_terminate_timeout`** must be at least as long as the
  configured request timeout, or long runs are killed mid-stream.

The panel works without streaming — if the `EventSource` connection fails it
reports the interruption rather than hanging.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| "Could not reach the AI endpoint" | Wrong base URL, or the server cannot route to it. Check from the server itself, not your laptop — in Docker, `localhost` is the container |
| "The AI endpoint rejected the API key" | Wrong key. Note some servers leave `/v1/models` open while requiring auth for completions, so a green model list is not proof |
| "did not answer in time" | Raise the request timeout; agent runs with tools take longer |
| Empty answers | Reasoning model with too low a "Max response tokens" |
| "too long for the selected model" | Lower "Max context tokens" or start a new chat |
| Tools never used | Tool support off for the model, or none enabled, or the model is weak at tool calling — check with **Test connection** |
| Write tool never runs | "Allow write tools" is off, or the user lacks conversation-edit rights |
| Panel does not appear | Module inactive, disabled globally or for the mailbox, endpoint URL empty, or the user has no access to that mailbox |
| Assets 404 | `php artisan freescout:module-install aichatpanel` recreates the `public/modules/aichatpanel` symlink |

Errors are logged with the `[AiChatPanel]` prefix in `storage/logs/laravel.log`.
API keys are never written there. Prompt bodies only appear if you switch on
"Log full prompts".

---

## Security notes

- The API key lives encrypted in the options table and is never exposed to the
  browser. All model calls are proxied by the backend.
- Every route is authorised **against the conversation id in the request**, using
  core's `ConversationPolicy`. Nothing is trusted from the client.
- Tools run **as the logged-in user**. There is no service account and no
  privilege escalation; a tool cannot do anything the user could not do by hand.
- Thread content is treated as untrusted: it is wrapped in delimiters and the
  system prompt says everything inside them is data, never instructions. That is
  mitigation, not protection — the real control is that **every write is
  confirmed by a human**.
- Model output is rendered through Parsedown and then HTMLPurifier with a tight
  allowlist. Images are dropped entirely, since an image URL in model output is
  a request from the agent's browser to an arbitrary host.
- Conversation content goes to the configured endpoint and nowhere else. No
  telemetry, no third-party calls, no CDN assets.

---

## Extending

Other modules can register tools, context providers and prompt shortcuts through
Eventy filters. AiChatPanel has no knowledge of any specific module, and
discovering zero registered tools is a normal state.

Full interface reference with a complete worked example:
**[docs/extending.md](docs/extending.md)**.

---

## Development

Manual testing against a local endpoint: **[docs/manual-testing.md](docs/manual-testing.md)**.

Running the test suite — no test talks to a real model:

```bash
# From the FreeScout root.
php artisan config:clear

APP_KEY="base64:$(openssl rand -base64 32)" \
DB_CONNECTION=testing CACHE_DRIVER=array SESSION_DRIVER=array \
  ./vendor/bin/phpunit --stderr Modules/AiChatPanel/Tests

php artisan freescout:clear-cache
```

Four things in that command are load-bearing, and the suite fails in confusing
ways without them:

- **`config:clear` first.** `phpunit.xml`'s env block is ignored entirely while
  `bootstrap/cache/config.php` exists, and `freescout:clear-cache` leaves the
  config cached. Without this the suite runs against the **live database**. The
  base test case refuses to run if `database.default` is not `testing`, so this
  fails loudly rather than quietly.
- **A real `APP_KEY`.** Core's `phpunit.xml` sets `APP_KEY="value_from_phpunit"`,
  which is not a valid cipher key, so every HTTP test dies in the cookie
  encrypter once the config cache is gone.
- **`--stderr`.** Core's global `ResponseHeaders` middleware calls
  `header_remove()`; once PHPUnit has written a progress dot to stdout, PHP
  considers headers sent and that raises. (Upstream sidesteps the same problem
  by skipping its one HTTP test on PHP below 8.4.)
- **`freescout:clear-cache` afterwards**, to put the config cache back.

First run only:

```bash
DB_CONNECTION=testing php artisan migrate --force --database=testing
```

and mark the module active in the test database:

```sql
INSERT INTO modules (alias, active) VALUES ('aichatpanel', 1)
  ON DUPLICATE KEY UPDATE active = 1;
```
