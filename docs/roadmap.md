# Deliberately left out, and what v2 would add

Everything here was a conscious decision, not an oversight. Where something was
cut, the reason is given.

---

## Left out on purpose

### Attachment contents

Attachments are listed by **filename, MIME type and size** only. Nothing is
parsed or read.

Doing it properly means PDF/DOCX/image extraction, a size and page budget, OCR
for scans, and a decision about what happens when a 200-page PDF meets an 8k
context. Doing it badly means silently truncating a document and letting the
model answer confidently from the first paragraph. The filename alone is often
enough for the assistant to say "the customer attached `invoice-2201.pdf`, open
it", which is honest.

### Anything that contacts a customer

There is no auto-reply, no background answering, no "send" button anywhere in
the panel, and no queue job that could produce one. `conversation.create_draft_reply`
saves a draft thread and stops. This is a product boundary, not a missing
feature.

### A "trust all write tools" switch

Individually named write tools can be exempted from confirmation. There is
deliberately no global switch, and `conversation.create_draft_reply` cannot be
exempted at all. A single checkbox that turns off every confirmation is the kind
of thing that gets ticked once during a demo and never unticked.

### Cross-conversation and cross-mailbox search

`conversation.get` and `conversation.list_customer_conversations` are scoped to
the customer of the open conversation and to mailboxes the user can already see.
There is no "search all conversations" tool. That would turn the panel into an
unaudited full-text search over the helpdesk that happens to ignore how carefully
the model was prompted.

### Tags and custom fields

Not in FreeScout core — they are paid modules. Rather than guess at their schema,
the tool registry is open: whoever owns those modules can add
`tags.add` / `customfields.set` in a few dozen lines. See `extending.md`.

### A client-side Markdown renderer as the source of truth

`marked` + `DOMPurify` render the stream as it arrives, but the version that is
stored and reloaded is always the server's Parsedown + HTMLPurifier output. The
browser-side renderer is a nicety; it is never what is trusted.

### Per-user or per-mailbox cost accounting

Token usage is reported per response. There is no aggregation, budget or quota.
For a self-hosted endpoint the marginal cost is roughly zero, so this would be
ceremony. It becomes real the moment someone points the module at a metered API.

### Automatic tests against a live model

Every test uses `FakeLlmClient`. Model output is non-deterministic; a suite that
depends on it fails for reasons that have nothing to do with the code. Live
verification is a documented manual procedure (`manual-testing.md`).

---

## v2 candidates, roughly in order of value

### 1. Retrieval over a knowledge base

The obvious next step, and the one that would most change how useful the
assistant is. A context provider backed by an embedding store, so the model can
answer from documented policy instead of from the thread alone. The provider
interface already exists and is the right shape for it; what is missing is the
store, the ingestion pipeline and an admin UI for it — which is a module of its
own, and should be, since not everyone wants a vector database.

### 2. Attachment content extraction

See above. Realistically: PDF and plain text first, behind an admin toggle, with
a hard per-attachment token cap and a visible note in the panel saying how much
of the document the model actually saw.

### 3. Conversation summarisation instead of truncation

Today an over-long thread drops its oldest messages and the panel says so.
Better: summarise the dropped span with a cheap model and keep the summary. The
budget code already tracks exactly what was dropped, so this slots in at one
point in `ContextBuilder`.

### 4. A tool-call approval policy per user or role

Currently confirmation is global-plus-per-tool. A supervisor might reasonably
want senior agents to skip confirmation for `conversation.set_status` while
juniors do not. The registry already re-checks authorisation at execution time,
so this is a policy layer on top rather than surgery.

### 5. An admin view of the audit log

`aichatpanel_tool_calls` is complete and correctly indexed, but there is no UI —
it is a SQL query today. A filterable table under *Manage* with tool, user,
conversation, status and arguments.

### 6. Streaming through the tool loop without re-connecting

A write confirmation currently ends the SSE stream and the approval opens a new
one. It works and is easy to reason about, but a long agent run with several
writes reconnects several times. Doing better means a bidirectional channel,
which FreeScout has no precedent for.

### 7. Panel state that follows the conversation, not just the user

Open/closed and width are per user. A case could be made for remembering that
the panel was open on *this* conversation specifically.

### 8. More languages

English and German ship complete. The strings are extracted and the mechanism is
FreeScout's own, so another locale is a single JSON file plus a
`freescout:module-build`.

---

## Known rough edges

Honest list of things that work but could be better.

- **Signature stripping is best-effort.** Signatures are not marked up in any
  standard way. The module removes the rendered mailbox signature and cuts at
  sigdashes; an idiosyncratic signature will survive into the context. There is
  a per-mailbox switch to turn the attempt off.
- **Quote stripping reuses core's marker list**, which is good but not complete —
  `\MailHelper::$alternative_reply_separators` is explicitly a list upstream
  extends when someone reports a client it does not cover.
- **Token counting is an estimate**, deliberately pessimistic (characters ÷ 3.5).
  The endpoint's real tokeniser is model-specific and not exposed, so the module
  errs towards sending less rather than towards a rejected request.
- **A second write tool in one assistant turn is deferred, not queued.** It is
  answered with "not executed, ask again", because the endpoint requires every
  tool call to get a reply and queueing two confirmation dialogs is worse UX than
  asking the model to try again.
- **The model picker lists what the endpoint reports**, cached for five minutes.
  A model added to the endpoint mid-session takes up to five minutes to appear.
  It is hidden entirely when there is only one model to choose from.
