{{--
    Strings the panel needs in JavaScript.

    Compiled to Public/js/vars.js. After changing this file run:
        php artisan freescout:module-build aichatpanel

    Every key here is looked up by module.js through t('key', 'English fallback'),
    so if vars.js has not been built the panel still shows English rather than
    breaking. The keys must match the first argument of those t() calls.

    Values are interpolated by Blade with {{ }}, i.e. HTML-escaped — never with
    {!! !!}, and never straight into a JS template literal.
--}}

if (typeof(LangMessages) == "undefined") {
	LangMessages = {};
}
@foreach ($locales as $locale)
	@php
		// Without this every locale renders the English strings. The stock
		// SampleModule template omits it; core's own vars.blade.php does it.
		app()->setLocale($locale);
	@endphp
	var locale_messages = {
		{{-- Settings page --}}
		"testing": "{{ __j('Testing…') }}",
		"loading": "{{ __j('Loading…') }}",
		"test_failed": "{{ __j('The connection test failed.') }}",
		"test_http_error": "{{ __j('The connection test request failed (HTTP :code).') }}",
		"probe_models": "{{ __j('Model list') }}",
		"probe_completion": "{{ __j('Completion') }}",
		"probe_tools": "{{ __j('Tool calling') }}",
		"models_failed": "{{ __j('Could not load the model list.') }}",
		"models_empty": "{{ __j('The endpoint did not report any models.') }}",
		"models_loaded": "{{ __j('Loaded :count model(s).') }}",

		{{-- Panel --}}
		"empty_title": "{{ __j('Ask about this conversation, or pick one of the shortcuts below.') }}",
		"empty_hint": "{{ __j('Nothing you write here is sent to the customer. Drafts are inserted into the reply editor for you to review.') }}",
		"load_failed": "{{ __j('Could not load the chat.') }}",
		"reset_failed": "{{ __j('Could not start a new chat.') }}",
		"resolve_pending": "{{ __j('Approve or reject the pending action first.') }}",
		"forbidden": "{{ __j('You do not have access to this conversation.') }}",
		"rate_limited": "{{ __j('You are sending messages too quickly. Wait a moment and try again.') }}",
		"http_error": "{{ __j('The request failed (HTTP :code).') }}",
		"stream_failed": "{{ __j('The connection to the assistant was interrupted.') }}",
		"thinking": "{{ __j('Thinking…') }}",
		"running_tool": "{{ __j('Running :tool…') }}",
		"tools_unsupported": "{{ __j('The selected model cannot use tools, so they are disabled.') }}",
		"show_reasoning": "{{ __j('Show reasoning') }}",
		"reasoning_only": "{{ __j('The model put its whole answer into its reasoning and returned nothing. Open “Show reasoning” to read it, or ask again.') }}",
		"answer_truncated": "{{ __j('The model used its whole response budget before writing an answer. Raise “Max response tokens” in the settings, then ask again.') }}",
		"tool_ran": "{{ __j('Ran :tool') }}",
		"tool_failed": "{{ __j(':tool failed') }}",

		{{-- Day separators. Every other date is formatted server-side; only
		     these two are decided in the browser, so "Today" stays true in a
		     panel that is open past midnight. --}}
		"day_today": "{{ __j('Today') }}",
		"day_yesterday": "{{ __j('Yesterday') }}",

		{{-- Message actions --}}
		"insert_reply": "{{ __j('Insert into reply') }}",
		"insert_reply_short": "{{ __j('Reply') }}",
		"insert_note": "{{ __j('Insert as internal note') }}",
		"insert_note_short": "{{ __j('Note') }}",
		"copy": "{{ __j('Copy') }}",
		"copied": "{{ __j('Copied to clipboard') }}",
		"copy_failed": "{{ __j('Could not copy.') }}",
		"inserted": "{{ __j('Inserted into the editor. Review it before sending.') }}",
		"no_editor": "{{ __j('The reply editor is not available on this page.') }}",
		"open_draft": "{{ __j('Open in editor') }}",
		"close_reply_first": "{{ __j('Close the open reply first.') }}",
		"close_note_first": "{{ __j('Close the open note first, then insert as a reply.') }}",

		{{-- Write confirmation --}}
		"confirm_title": "{{ __j('The assistant wants to make a change') }}",
		"approve": "{{ __j('Approve') }}",
		"reject": "{{ __j('Reject') }}"
	};

	if (typeof(LangMessages["{{ $locale }}.messages"]) == "undefined") {
		LangMessages["{{ $locale }}.messages"] = {};
	}
	LangMessages["{{ $locale }}.messages"] = $.extend(locale_messages, LangMessages["{{ $locale }}.messages"]);
@endforeach

@php
	// Put the configured default locale back.
	app()->setLocale(config('app.locale'));
@endphp

(function () {
	if (typeof(Lang) == "undefined") {
		Lang = new Lang();
	}
	Lang.setMessages(LangMessages);
})();
