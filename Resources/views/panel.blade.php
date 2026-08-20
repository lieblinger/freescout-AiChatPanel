{{--
    Rendered by the conversation.after_customer_sidebar action, which puts it
    inside #conv-layout-customer — a 280px absolutely-positioned column that a
    resizable panel cannot live in. module.js moves this element to <body> on
    init, where it becomes position:fixed.

    On the compose screen it is rendered by new_conversation_form.after instead,
    and there is no conversation id yet: core creates the draft conversation
    from its own autosave and module.js adopts the id when it appears.

    Everything the panel needs travels as data- attributes. No inline script:
    the CSP is script-src 'self'.
--}}
@if (!empty($compose))
    {{--
        The compose screen never calls ConversationActionButtons, so the toolbar
        toggle that view.blade.php gets from conversation.get_action_buttons has
        no equivalent here. Render it alongside the panel and let module.js move
        it into #conv-toolbar: the only toolbar hook on that page sits inside the
        email/phone .btn-group, where a third button reads as a third mode.
    --}}
    <div class="btn-group aicp-toolbar-group aicp-unplaced" id="aicp-compose-toggle">
        <button type="button" class="btn btn-default aicp-toggle" title="{{ __('AI Chat') }}"><i class="glyphicon glyphicon-comment"></i></button>
    </div>
@endif

<div id="aicp-panel"
     class="aicp-panel"
     data-conversation-id="{{ $conversation->id ?: '' }}"
     data-compose="{{ !empty($compose) ? '1' : '0' }}"
     data-mailbox-id="{{ $conversation->mailbox_id }}"
     data-open="{{ $prefs['open'] ? '1' : '0' }}"
     data-width="{{ $prefs['width'] }}"
     data-mode="{{ $prefs['mode'] ?? \Modules\AiChatPanel\Entities\UserPref::MODE_DEFAULT }}"
     data-float-x="{{ $prefs['float_x'] ?? '' }}"
     data-float-y="{{ $prefs['float_y'] ?? '' }}"
     data-float-width="{{ $prefs['float_width'] ?? '' }}"
     data-float-height="{{ $prefs['float_height'] ?? '' }}"
     data-timezone="{{ $timezone }}"
     data-aicp-lang="{{ \Modules\AiChatPanel\Services\JsStrings::json() }}"
     data-url-history="{{ route('aichatpanel.chat.history') }}"
     data-url-send="{{ route('aichatpanel.chat.send') }}"
     data-url-confirm="{{ route('aichatpanel.chat.confirm') }}"
     data-url-editor-html="{{ route('aichatpanel.chat.editor_html') }}"
     data-url-reset="{{ route('aichatpanel.chat.reset') }}"
     data-url-prefs="{{ route('aichatpanel.prefs') }}">

    <div class="aicp-resizer" role="separator" aria-orientation="vertical" title="{{ __('Drag to resize') }}"></div>

    {{--
        Grips for the floating window: four edges and four corners. Hidden by
        the stylesheet unless <body> carries .aicp-floating, so the docked
        column and the drawer are unaffected. The direction lives in the class
        name — bindFloatResize() reads it from there.
    --}}
    <div class="aicp-fresize aicp-fresize-n"></div>
    <div class="aicp-fresize aicp-fresize-s"></div>
    <div class="aicp-fresize aicp-fresize-e"></div>
    <div class="aicp-fresize aicp-fresize-w"></div>
    <div class="aicp-fresize aicp-fresize-ne"></div>
    <div class="aicp-fresize aicp-fresize-nw"></div>
    <div class="aicp-fresize aicp-fresize-se"></div>
    <div class="aicp-fresize aicp-fresize-sw"></div>

    <div class="aicp-header">
        <div class="aicp-header-title">
            <i class="glyphicon glyphicon-comment"></i>
            <span>{{ __('AI Chat') }}</span>
        </div>

        <div class="aicp-header-actions">
            {{--
                Hidden until fillModels() has something worth picking from: it
                starts empty, and a history request that never arrives or comes
                back with a single model must not leave an empty dropdown in the
                header. fillModels() unhides it when there are two or more.
            --}}
            <select class="aicp-model form-control input-sm hidden" title="{{ __('Model') }}"></select>

            {{-- Icon and title are swapped by applyMode() when the shape changes. --}}
            <button type="button" class="btn btn-link btn-sm aicp-pin" title="{{ __('Undock') }}">
                <i class="glyphicon glyphicon-new-window"></i>
            </button>

            <button type="button" class="btn btn-link btn-sm aicp-new-chat" title="{{ __('New chat') }}">
                <i class="glyphicon glyphicon-refresh"></i>
            </button>

            <button type="button" class="btn btn-link btn-sm aicp-close" title="{{ __('Close') }}">
                <i class="glyphicon glyphicon-remove"></i>
            </button>
        </div>
    </div>

    <div class="aicp-notices"></div>

    {{--
        The wrapper exists so the jump button has a containing block that ends
        at the bottom of the list rather than at the bottom of the panel: the
        shortcut strip and the composer below it are both of variable height.
    --}}
    <div class="aicp-messages-wrap">
        <div class="aicp-messages" tabindex="0">
            <div class="aicp-empty">
                <p>{{ __('Ask about this conversation, or pick one of the shortcuts below.') }}</p>
                <p class="aicp-empty-hint">{{ __('Nothing you write here is sent to the customer. Drafts are inserted into the reply editor for you to review.') }}</p>
            </div>
        </div>

        {{--
            Unhidden by updateJumpButton() once the reader has scrolled away
            from the bottom. The badge counts the entries that arrived while
            they were away; its number is the only dynamic part, so nothing here
            needs a JsStrings key.
        --}}
        <button type="button"
                class="btn btn-default aicp-jump hidden"
                title="{{ __('Jump to the newest message') }}"
                aria-label="{{ __('Jump to the newest message') }}">
            <i class="glyphicon glyphicon-chevron-down"></i>
            <span class="badge aicp-jump-count hidden" aria-live="polite"></span>
        </button>
    </div>

    {{--
        Shortcuts are stored settings, so they are English source strings, not
        translation keys we control. __() is still the right call: the five
        shipped defaults have entries in the module's lang files, and a shortcut
        an admin typed themselves passes through unchanged. The prompt is
        translated too, so a German agent sends a German prompt and gets a
        German answer back.
    --}}
    @if (!empty($shortcuts))
        <div class="aicp-shortcuts">
            @foreach ($shortcuts as $shortcut)
                @php $shortcut_text = __($shortcut); @endphp
                <button type="button" class="btn btn-default btn-xs aicp-shortcut" data-prompt="{{ $shortcut_text }}" title="{{ $shortcut_text }}">{{ $shortcut_text }}</button>
            @endforeach
        </div>
    @endif

    <div class="aicp-composer">
        <textarea class="aicp-input form-control"
                  rows="3"
                  placeholder="{{ __('Ask about this conversation…') }}"></textarea>

        <div class="aicp-composer-actions">
            <span class="aicp-hint">{{ __('Ctrl+Enter to send') }}</span>
            <button type="button" class="btn btn-default btn-sm aicp-stop hidden">{{ __('Stop') }}</button>
            <button type="button" class="btn btn-primary btn-sm aicp-send">{{ __('Send') }}</button>
        </div>
    </div>
</div>

<div class="aicp-backdrop hidden"></div>
