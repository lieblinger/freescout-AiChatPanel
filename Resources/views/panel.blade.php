{{--
    Rendered by the conversation.after_customer_sidebar action, which puts it
    inside #conv-layout-customer — a 280px absolutely-positioned column that a
    resizable panel cannot live in. module.js moves this element to <body> on
    init, where it becomes position:fixed.

    Everything the panel needs travels as data- attributes. No inline script:
    the CSP is script-src 'self'.
--}}
<div id="aicp-panel"
     class="aicp-panel"
     data-conversation-id="{{ $conversation->id }}"
     data-mailbox-id="{{ $conversation->mailbox_id }}"
     data-open="{{ $prefs['open'] ? '1' : '0' }}"
     data-width="{{ $prefs['width'] }}"
     data-url-history="{{ route('aichatpanel.chat.history') }}"
     data-url-send="{{ route('aichatpanel.chat.send') }}"
     data-url-confirm="{{ route('aichatpanel.chat.confirm') }}"
     data-url-reset="{{ route('aichatpanel.chat.reset') }}"
     data-url-prefs="{{ route('aichatpanel.prefs') }}">

    <div class="aicp-resizer" role="separator" aria-orientation="vertical" title="{{ __('Drag to resize') }}"></div>

    <div class="aicp-header">
        <div class="aicp-header-title">
            <i class="glyphicon glyphicon-comment"></i>
            <span>{{ __('AI Chat') }}</span>
        </div>

        <div class="aicp-header-actions">
            <select class="aicp-model form-control input-sm" title="{{ __('Model') }}"></select>

            <button type="button" class="btn btn-link btn-sm aicp-new-chat" title="{{ __('New chat') }}">
                <i class="glyphicon glyphicon-refresh"></i>
            </button>

            <button type="button" class="btn btn-link btn-sm aicp-close" title="{{ __('Close') }}">
                <i class="glyphicon glyphicon-remove"></i>
            </button>
        </div>
    </div>

    <div class="aicp-notices"></div>

    <div class="aicp-messages" tabindex="0">
        <div class="aicp-empty">
            <p>{{ __('Ask about this conversation, or pick one of the shortcuts below.') }}</p>
            <p class="aicp-empty-hint">{{ __('Nothing you write here is sent to the customer. Drafts are inserted into the reply editor for you to review.') }}</p>
        </div>
    </div>

    @if (!empty($shortcuts))
        <div class="aicp-shortcuts">
            @foreach ($shortcuts as $shortcut)
                <button type="button" class="btn btn-default btn-xs aicp-shortcut" data-prompt="{{ $shortcut }}">{{ $shortcut }}</button>
            @endforeach
        </div>
    @endif

    <div class="aicp-composer">
        <textarea class="aicp-input form-control"
                  rows="3"
                  placeholder="{{ __('Ask about this conversation… (Ctrl+Enter to send)') }}"></textarea>

        <div class="aicp-composer-actions">
            <span class="aicp-hint">{{ __('Ctrl+Enter to send') }}</span>
            <button type="button" class="btn btn-default btn-sm aicp-stop hidden">{{ __('Stop') }}</button>
            <button type="button" class="btn btn-primary btn-sm aicp-send">{{ __('Send') }}</button>
        </div>
    </div>
</div>

<div class="aicp-backdrop hidden"></div>
