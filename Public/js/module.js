/**
 * AiChatPanel
 *
 * Loaded on every page, so every entry point checks for its own DOM first and
 * returns quietly when it is not there.
 *
 * FreeScout's CSP is script-src 'self': no inline handlers, everything bound
 * with jQuery. Configuration reaches this file through data- attributes, never
 * through PHP interpolated into JavaScript.
 */
(function ($) {
    'use strict';

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Translate through FreeScout's Lang, falling back to the English source
     * string when the module's vars.js has not been built yet.
     */
    function t(key, fallback, params) {
        var text = fallback;

        if (typeof Lang !== 'undefined' && Lang.get) {
            var translated = Lang.get('messages.' + key);
            if (translated && translated !== 'messages.' + key) {
                text = translated;
            }
        }

        if (params) {
            $.each(params, function (name, value) {
                text = text.replace(':' + name, value);
            });
        }

        return text;
    }

    function escapeHtml(text) {
        return $('<div>').text(text == null ? '' : String(text)).html();
    }

    function alertBox(type, message) {
        return '<div class="alert alert-' + type + '">' + escapeHtml(message) + '</div>';
    }

    function csrf() {
        if (typeof getCsrfToken === 'function') {
            return getCsrfToken();
        }

        return $('meta[name="csrf-token"]').attr('content');
    }

    /**
     * Render Markdown from the model.
     *
     * Only used while a response is streaming in — once the turn completes the
     * server sends back its own Parsedown + HTMLPurifier rendering, which
     * replaces this and is what gets stored. Model output is untrusted, so it
     * is sanitised here too, with a deliberately narrow allowlist: no images
     * (an image URL is a request to an arbitrary host), no iframes, no styles.
     */
    function renderMarkdown(text) {
        if (typeof marked === 'undefined' || typeof DOMPurify === 'undefined') {
            // No renderer available: show escaped text rather than raw HTML.
            return '<p>' + escapeHtml(text).replace(/\n/g, '<br>') + '</p>';
        }

        var html;

        try {
            html = marked.parse(String(text), {breaks: true, gfm: true});
        } catch (e) {
            return '<p>' + escapeHtml(text).replace(/\n/g, '<br>') + '</p>';
        }

        return DOMPurify.sanitize(html, {
            ALLOWED_TAGS: [
                'p', 'br', 'strong', 'em', 'b', 'i', 'del', 'code', 'pre',
                'ul', 'ol', 'li', 'blockquote',
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'a',
                'table', 'thead', 'tbody', 'tr', 'th', 'td'
            ],
            ALLOWED_ATTR: ['href', 'title'],
            ALLOWED_URI_REGEXP: /^(?:https?|mailto):/i
        });
    }

    // =====================================================================
    // Admin settings page
    // =====================================================================

    function initSettings() {
        var $button = $('#aichatpanel-test-connection');

        if (!$button.length) {
            return;
        }

        $button.on('click', function (e) {
            e.preventDefault();
            runConnectionTest($button);
        });

        $('#aichatpanel-load-models').on('click', function (e) {
            e.preventDefault();
            loadModels($button);
        });
    }

    function runConnectionTest($button) {
        var $result = $('#aichatpanel-test-result');

        $button.attr('disabled', true);
        $result.removeClass('hidden').html(
            '<div class="text-help">' + escapeHtml(t('testing', 'Testing…')) + '</div>'
        );

        $.ajax({
            url: $button.attr('data-test-url'),
            method: 'POST',
            dataType: 'json',
            data: {
                _token: csrf(),
                base_url: $('#aichatpanel_base_url').val(),
                model: $('#aichatpanel_default_model').val()
            }
        }).done(function (response) {
            $button.attr('disabled', false);

            if (!response || response.status !== 'success') {
                $result.html(alertBox('danger', (response && response.msg) || t(
                    'test_failed', 'The connection test failed.'
                )));
                return;
            }

            $result.html(renderTestResult(response.result));
        }).fail(function (xhr) {
            $button.attr('disabled', false);
            $result.html(alertBox('danger', t(
                'test_http_error',
                'The connection test request failed (HTTP :code).',
                {code: xhr.status}
            )));
        });
    }

    function renderTestResult(result) {
        if (!result) {
            return alertBox('danger', t('test_failed', 'The connection test failed.'));
        }

        var html = '<ul class="list-unstyled aichatpanel-test-result">';

        html += renderTestRow(
            t('probe_models', 'Model list'),
            result.models.ok, result.models.message, result.models.detail
        );
        html += renderTestRow(
            t('probe_completion', 'Completion'),
            result.completion.ok, result.completion.message, result.completion.detail
        );
        html += renderTestRow(
            t('probe_tools', 'Tool calling'),
            result.tools.ok && result.tools.supported,
            result.tools.message, result.tools.detail,
            result.tools.ok && !result.tools.supported
        );

        return html + '</ul>';
    }

    function renderTestRow(label, ok, message, detail, warn) {
        var icon = ok
            ? '<i class="glyphicon glyphicon-ok text-success"></i>'
            : (warn
                ? '<i class="glyphicon glyphicon-warning-sign text-warning"></i>'
                : '<i class="glyphicon glyphicon-remove text-danger"></i>');

        var html = '<li>' + icon + ' <strong>' + escapeHtml(label) + ':</strong> ' + escapeHtml(message);

        if (detail) {
            html += '<div class="aichatpanel-test-detail"><code>' + escapeHtml(detail) + '</code></div>';
        }

        return html + '</li>';
    }

    function loadModels($button) {
        var $link = $('#aichatpanel-load-models');
        var original = $link.text();

        $link.text(t('loading', 'Loading…'));

        $.ajax({
            url: $button.attr('data-models-url'),
            method: 'POST',
            dataType: 'json',
            data: {_token: csrf(), base_url: $('#aichatpanel_base_url').val()}
        }).done(function (response) {
            $link.text(original);

            if (!response || response.status !== 'success') {
                showFloatingAlert('error', (response && response.msg) || t(
                    'models_failed', 'Could not load the model list.'
                ));
                return;
            }

            if (!response.models || !response.models.length) {
                showFloatingAlert('warning', response.msg_success || t(
                    'models_empty', 'The endpoint did not report any models.'
                ));
                return;
            }

            $('#aichatpanel_allowed_models').val(response.models.join('\n'));
            showFloatingAlert('success', t('models_loaded', 'Loaded :count model(s).', {
                count: response.models.length
            }));
        }).fail(function () {
            $link.text(original);
            showFloatingAlert('error', t('models_failed', 'Could not load the model list.'));
        });
    }

    // =====================================================================
    // The panel
    // =====================================================================

    var panel = {
        $el: null,
        $messages: null,
        $input: null,
        conversationId: null,
        urls: {},
        loaded: false,
        busy: false,
        request: null,
        source: null,
        pending: null,
        streaming: true,
        buffer: '',
        $streamBubble: null
    };

    function initPanel() {
        var $el = $('#aicp-panel');

        if (!$el.length) {
            return;
        }

        // The hook renders this inside #conv-layout-customer, a 280px
        // absolutely-positioned column. Move it to the body so it can be a
        // full-height fixed panel that pushes the layout instead of living
        // inside it.
        $el.appendTo('body');
        $('.aicp-backdrop').appendTo('body');

        panel.$el = $el;
        panel.$messages = $el.find('.aicp-messages');
        panel.$input = $el.find('.aicp-input');
        panel.conversationId = $el.attr('data-conversation-id');
        panel.urls = {
            history: $el.attr('data-url-history'),
            send: $el.attr('data-url-send'),
            confirm: $el.attr('data-url-confirm'),
            reset: $el.attr('data-url-reset'),
            prefs: $el.attr('data-url-prefs')
        };

        setWidth(parseInt($el.attr('data-width'), 10) || 380, false);
        updatePanelBounds();

        bindPanel();

        if ($el.attr('data-open') === '1') {
            openPanel(false);
        }
    }

    function bindPanel() {
        // The toolbar button is rendered by core from our
        // conversation.get_action_buttons entry.
        $(document).on('click', '.aicp-toggle', function (e) {
            e.preventDefault();
            togglePanel();
        });

        $(window).on('scroll resize', scheduleBoundsUpdate);

        panel.$el.find('.aicp-close').on('click', function (e) {
            e.preventDefault();
            closePanel();
        });

        $('.aicp-backdrop').on('click', function () {
            closePanel();
        });

        panel.$el.find('.aicp-send').on('click', function (e) {
            e.preventDefault();
            sendMessage();
        });

        panel.$el.find('.aicp-stop').on('click', function (e) {
            e.preventDefault();
            abortRequest();
        });

        panel.$el.find('.aicp-new-chat').on('click', function (e) {
            e.preventDefault();
            resetChat();
        });

        panel.$el.find('.aicp-model').on('change', function () {
            savePrefs({last_model: $(this).val()});
        });

        panel.$input.on('keydown', function (e) {
            // Ctrl+Enter / Cmd+Enter sends; plain Enter is a newline.
            if (e.keyCode === 13 && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                sendMessage();
            }
        });

        panel.$el.on('click', '.aicp-shortcut', function (e) {
            e.preventDefault();
            panel.$input.val($(this).attr('data-prompt')).focus();
        });

        // Delegated: message action buttons are rendered dynamically.
        panel.$el.on('click', '.aicp-action-copy', function (e) {
            e.preventDefault();
            copyMessage($(this).closest('.aicp-message'));
        });

        panel.$el.on('click', '.aicp-action-reply', function (e) {
            e.preventDefault();
            insertIntoEditor($(this).closest('.aicp-message'), false);
        });

        panel.$el.on('click', '.aicp-action-note', function (e) {
            e.preventDefault();
            insertIntoEditor($(this).closest('.aicp-message'), true);
        });

        panel.$el.on('click', '.aicp-confirm-approve', function (e) {
            e.preventDefault();
            resolveConfirmation(true);
        });

        panel.$el.on('click', '.aicp-confirm-reject', function (e) {
            e.preventDefault();
            resolveConfirmation(false);
        });

        panel.$el.on('click', '.aicp-reasoning-toggle', function (e) {
            e.preventDefault();
            $(this).closest('.aicp-message').find('.aicp-reasoning-body').toggleClass('hidden');
        });

        bindResizer();
    }

    // ---------------------------------------------------------------------
    // Open / close / resize
    // ---------------------------------------------------------------------

    function togglePanel() {
        if ($('body').hasClass('aicp-open')) {
            closePanel();
        } else {
            openPanel(true);
        }
    }

    function openPanel(persist) {
        $('body').addClass('aicp-open');

        if (isNarrow()) {
            $('.aicp-backdrop').removeClass('hidden');
        }

        if (persist !== false) {
            savePrefs({panel_open: 1});
        }

        if (!panel.loaded) {
            loadHistory();
        }

        panel.$input.focus();
    }

    function closePanel() {
        $('body').removeClass('aicp-open');
        $('.aicp-backdrop').addClass('hidden');
        savePrefs({panel_open: 0});
    }

    function isNarrow() {
        return $(window).width() < 768;
    }

    var bounds_frame = null;

    /**
     * Keep the panel between the navbar and the footer.
     *
     * Both of them scroll with the page: the navbar is navbar-static-top and
     * leaves the viewport at the top, the footer enters it at the bottom. Fixed
     * offsets would leave an empty strip above the panel as soon as the thread
     * is scrolled, and would cover the footer at the end of it.
     */
    function updatePanelBounds() {
        var $navbar = $('.navbar-static-top').first();
        var navbar_height = $navbar.length ? $navbar.outerHeight() : 50;
        var scrolled = window.pageYOffset || document.documentElement.scrollTop || 0;

        var viewport = window.innerHeight || document.documentElement.clientHeight;
        var $footer = $('.footer').first();
        var bottom = 0;

        if ($footer.length && $footer.is(':visible')) {
            // Stop at the footer's margin box, not its border box: that is where
            // #conv-layout-customer ends too, so the two columns end level.
            var margin = parseFloat($footer.css('margin-top')) || 0;

            bottom = Math.max(0, viewport - ($footer[0].getBoundingClientRect().top - margin));
        }

        var style = document.documentElement.style;

        style.setProperty('--aicp-top', Math.max(0, navbar_height - scrolled) + 'px');
        style.setProperty('--aicp-bottom', bottom + 'px');
    }

    /**
     * updatePanelBounds() on a scroll listener, at most once per frame.
     */
    function scheduleBoundsUpdate() {
        if (!window.requestAnimationFrame) {
            updatePanelBounds();
            return;
        }

        if (bounds_frame) {
            return;
        }

        bounds_frame = window.requestAnimationFrame(function () {
            bounds_frame = null;
            updatePanelBounds();
        });
    }

    function setWidth(width, persist) {
        width = Math.max(300, Math.min(900, width));

        // Drives both the panel width and the shift applied to the
        // conversation layout, so the two can never disagree.
        document.documentElement.style.setProperty('--aicp-width', width + 'px');

        if (persist) {
            savePrefs({panel_width: width});
        }
    }

    function bindResizer() {
        var dragging = false;

        panel.$el.find('.aicp-resizer').on('mousedown', function (e) {
            e.preventDefault();
            dragging = true;
            $('body').addClass('aicp-resizing');
        });

        $(document).on('mousemove', function (e) {
            if (!dragging) {
                return;
            }

            setWidth($(window).width() - e.pageX, false);
        });

        $(document).on('mouseup', function () {
            if (!dragging) {
                return;
            }

            dragging = false;
            $('body').removeClass('aicp-resizing');

            var current = parseInt(
                getComputedStyle(document.documentElement).getPropertyValue('--aicp-width'), 10
            );

            setWidth(current, true);
        });
    }

    function savePrefs(data) {
        data._token = csrf();

        $.ajax({url: panel.urls.prefs, method: 'POST', data: data, dataType: 'json'});
    }

    // ---------------------------------------------------------------------
    // Conversation with the model
    // ---------------------------------------------------------------------

    function loadHistory() {
        setBusy(true);

        $.ajax({
            url: panel.urls.history,
            method: 'POST',
            dataType: 'json',
            data: {_token: csrf(), conversation_id: panel.conversationId}
        }).done(function (response) {
            setBusy(false);

            if (!response || response.status !== 'success') {
                showPanelError((response && response.msg) || t('load_failed', 'Could not load the chat.'));
                return;
            }

            panel.loaded = true;

            fillModels(response.models, response.model);
            renderMessages(response.messages, true);
            renderToolHint(response.tools);

            if (response.pending) {
                showConfirmation(response.pending);
            }
        }).fail(function (xhr) {
            setBusy(false);
            showPanelError(httpError(xhr));
        });
    }

    function sendMessage() {
        if (panel.busy) {
            return;
        }

        if (panel.pending) {
            showPanelError(t('resolve_pending', 'Approve or reject the pending action first.'));
            return;
        }

        var text = $.trim(panel.$input.val());

        if (!text) {
            return;
        }

        panel.$input.val('');
        appendMessage({role: 'user', body: text});
        clearNotices();
        setBusy(true);

        panel.request = $.ajax({
            url: panel.urls.send,
            method: 'POST',
            dataType: 'json',
            data: {
                _token: csrf(),
                conversation_id: panel.conversationId,
                model: panel.$el.find('.aicp-model').val(),
                message: text,
                stream: panel.streaming ? 1 : 0
            }
        }).done(function (response) {
            // Streaming is a two-step handshake: this POST creates the turn and
            // hands back a one-shot URL, because EventSource cannot POST.
            if (response && response.stream_url) {
                openStream(response.stream_url);
                return;
            }

            handleTurnResponse(response);
        }).fail(function (xhr, status) {
            setBusy(false);

            if (status === 'abort') {
                return;
            }

            showPanelError(httpError(xhr));
        });
    }

    function handleTurnResponse(response) {
        setBusy(false);

        if (!response) {
            showPanelError(t('load_failed', 'Could not load the chat.'));
            return;
        }

        // An error still carries the turns that were produced before it, so the
        // user can see what happened rather than a blank.
        if (response.messages) {
            // The user turn is already on screen; skip it.
            renderMessages($.grep(response.messages, function (m) {
                return m.role !== 'user';
            }), false);
        }

        if (response.notices && response.notices.length) {
            showNotices(response.notices);
        }

        if (response.status !== 'success') {
            if (!response.messages || !response.messages.length) {
                showPanelError(response.msg || t('load_failed', 'Could not load the chat.'));
            }
            return;
        }

        if (response.pending) {
            showConfirmation(response.pending);
        }
    }

    function resetChat() {
        if (panel.busy) {
            return;
        }

        setBusy(true);

        $.ajax({
            url: panel.urls.reset,
            method: 'POST',
            dataType: 'json',
            data: {_token: csrf(), conversation_id: panel.conversationId}
        }).done(function (response) {
            setBusy(false);

            if (!response || response.status !== 'success') {
                showPanelError((response && response.msg) || t('reset_failed', 'Could not start a new chat.'));
                return;
            }

            panel.pending = null;
            clearNotices();
            renderMessages([], true);
        }).fail(function (xhr) {
            setBusy(false);
            showPanelError(httpError(xhr));
        });
    }

    function abortRequest() {
        if (panel.request) {
            panel.request.abort();
            panel.request = null;
        }

        closeStream();
        setBusy(false);
    }

    // ---------------------------------------------------------------------
    // Streaming
    // ---------------------------------------------------------------------

    /**
     * Consume one turn as server-sent events.
     *
     * Deltas are rendered client-side so the answer appears as it is written.
     * The 'done' frame then carries the server's own Parsedown + HTMLPurifier
     * rendering and replaces what was built here, so the stored and reloadable
     * version never depends on the browser-side renderer.
     */
    function openStream(url) {
        closeStream();

        panel.buffer = '';
        panel.$streamBubble = null;

        var source = new EventSource(url);
        panel.source = source;

        source.addEventListener('delta', function (e) {
            var data = parseEvent(e);

            if (!data || !data.content) {
                return;
            }

            panel.buffer += data.content;
            renderStreamingBubble(panel.buffer);
        });

        source.addEventListener('reasoning', function () {
            // Chain of thought is not the answer: show that something is
            // happening, but do not render it into the bubble.
            showStreamStatus(t('thinking', 'Thinking…'));
        });

        source.addEventListener('tool_call', function (e) {
            var data = parseEvent(e) || {};
            showStreamStatus(t('running_tool', 'Running :tool…', {tool: data.tool || ''}));
        });

        source.addEventListener('tool_result', function () {
            showStreamStatus(t('thinking', 'Thinking…'));
        });

        source.addEventListener('notice', function (e) {
            var data = parseEvent(e);

            if (data && data.message) {
                showNotices([data.message]);
            }
        });

        // Application-level failure, reported by the server. Deliberately not
        // called "error": EventSource fires its own native "error" event for
        // transport problems and the two would be indistinguishable.
        source.addEventListener('failure', function (e) {
            var data = parseEvent(e);

            discardStreamingBubble();
            showPanelError((data && data.message) || t('load_failed', 'Could not load the chat.'));
        });

        // Transport failure. EventSource reconnects on its own, which is
        // pointless here because the token is single-use, so close it.
        source.addEventListener('error', function () {
            closeStream();
            setBusy(false);

            if (panel.buffer === '') {
                discardStreamingBubble();
                showPanelError(t('stream_failed', 'The connection to the assistant was interrupted.'));
            }
        });

        source.addEventListener('done', function (e) {
            var data = parseEvent(e) || {};

            discardStreamingBubble();

            if (data.messages) {
                renderMessages($.grep(data.messages, function (m) {
                    return m.role !== 'user';
                }), false);
            }

            if (data.pending) {
                showConfirmation(data.pending);
            }
        });

        source.addEventListener('end', function () {
            closeStream();
            setBusy(false);
        });
    }

    function closeStream() {
        if (panel.source) {
            panel.source.close();
            panel.source = null;
        }

        panel.$el.find('.aicp-stream-status').remove();
    }

    function parseEvent(e) {
        if (!e || !e.data) {
            return null;
        }

        try {
            return JSON.parse(e.data);
        } catch (err) {
            return null;
        }
    }

    /**
     * The provisional bubble that grows as tokens arrive.
     */
    function renderStreamingBubble(text) {
        panel.$el.find('.aicp-stream-status').remove();

        if (!panel.$streamBubble) {
            panel.$messages.find('.aicp-empty').remove();
            panel.$messages.find('.aicp-typing').remove();

            panel.$streamBubble = $(
                '<div class="aicp-message aicp-message-assistant aicp-streaming">'
                + '<div class="aicp-bubble aicp-markdown"></div></div>'
            );

            panel.$messages.append(panel.$streamBubble);
        }

        panel.$streamBubble.find('.aicp-bubble').html(renderMarkdown(text));
        scrollToBottom();
    }

    function discardStreamingBubble() {
        if (panel.$streamBubble) {
            panel.$streamBubble.remove();
            panel.$streamBubble = null;
        }

        panel.buffer = '';
        panel.$el.find('.aicp-stream-status').remove();
    }

    function showStreamStatus(text) {
        var $status = panel.$el.find('.aicp-stream-status');

        if (!$status.length) {
            $status = $('<div class="aicp-stream-status"></div>');
            panel.$messages.append($status);
        }

        $status.text(text);
        scrollToBottom();
    }

    function setBusy(busy) {
        panel.busy = busy;

        panel.$el.find('.aicp-send').prop('disabled', busy);
        panel.$el.find('.aicp-stop').toggleClass('hidden', !busy);
        panel.$el.toggleClass('aicp-busy', busy);

        if (busy) {
            panel.$messages.find('.aicp-typing').remove();
            panel.$messages.append(
                '<div class="aicp-typing"><span></span><span></span><span></span></div>'
            );
            scrollToBottom();
        } else {
            panel.$messages.find('.aicp-typing').remove();
            panel.request = null;
        }
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    function renderMessages(messages, replace) {
        if (replace) {
            panel.$messages.empty();
        }

        if (replace && (!messages || !messages.length)) {
            panel.$messages.html(emptyState());
            return;
        }

        panel.$messages.find('.aicp-empty').remove();

        $.each(messages || [], function (i, message) {
            appendMessage(message);
        });

        scrollToBottom();
    }

    function emptyState() {
        return '<div class="aicp-empty">'
            + '<p>' + escapeHtml(t('empty_title', 'Ask about this conversation, or pick one of the shortcuts below.')) + '</p>'
            + '<p class="aicp-empty-hint">' + escapeHtml(t('empty_hint', 'Nothing you write here is sent to the customer. Drafts are inserted into the reply editor for you to review.')) + '</p>'
            + '</div>';
    }

    function appendMessage(message) {
        panel.$messages.find('.aicp-empty').remove();

        var html = '';

        if (message.role === 'user') {
            html = renderUserMessage(message);
        } else if (message.role === 'tool') {
            html = renderToolMessage(message);
        } else {
            html = renderAssistantMessage(message);
        }

        if (html) {
            panel.$messages.find('.aicp-typing').before(html);

            if (!panel.$messages.find('.aicp-typing').length) {
                panel.$messages.append(html);
            }
        }

        scrollToBottom();
    }

    function renderUserMessage(message) {
        return '<div class="aicp-message aicp-message-user">'
            + '<div class="aicp-bubble">' + escapeHtml(message.body).replace(/\n/g, '<br>') + '</div>'
            + '</div>';
    }

    function renderAssistantMessage(message) {
        var isError = message.status === 1;

        if (isError) {
            return '<div class="aicp-message aicp-message-error">'
                + '<div class="aicp-bubble">'
                + '<i class="glyphicon glyphicon-exclamation-sign"></i> '
                + escapeHtml(message.body)
                + '</div></div>';
        }

        // A turn that only asked for tools has no text of its own.
        if (!$.trim(message.body || '') && (message.tool_calls || []).length) {
            return '';
        }

        if (!$.trim(message.body || '') && !$.trim(message.reasoning || '')) {
            return '';
        }

        // Prefer the server-rendered HTML: it is the authoritative, purified
        // version. The client renderer is only for streaming deltas.
        var body = message.html ? message.html : renderMarkdown(message.body || '');

        // A reasoning model sometimes writes its whole answer into
        // reasoning_content and returns empty content with finish_reason
        // "stop" — most often for a short confirmation after a tool ran. The
        // chain of thought is never promoted into the answer, but an empty
        // bubble reads as a failure, so say what happened instead.
        if (!$.trim(message.body || '')) {
            body = '<em class="text-help">'
                + escapeHtml(t('reasoning_only', 'The model put its whole answer into its reasoning and returned nothing. Open “Show reasoning” to read it, or ask again.'))
                + '</em>';
        }

        var html = '<div class="aicp-message aicp-message-assistant" data-body="' + escapeHtml(message.body || '') + '">';

        if ($.trim(message.reasoning || '')) {
            html += '<div class="aicp-reasoning">'
                + '<a href="#" class="aicp-reasoning-toggle">' + escapeHtml(t('show_reasoning', 'Show reasoning')) + '</a>'
                + '<div class="aicp-reasoning-body hidden">' + escapeHtml(message.reasoning) + '</div>'
                + '</div>';
        }

        html += '<div class="aicp-bubble aicp-markdown">' + body + '</div>';

        // Before the actions, not after them: the action row keeps its height
        // while it is invisible, which would push the meta line away from the
        // answer it belongs to.
        html += renderMeta(message.meta);

        if ($.trim(message.body || '')) {
            html += '<div class="aicp-message-actions">'
                + '<button type="button" class="btn btn-link btn-xs aicp-action-reply" title="' + escapeHtml(t('insert_reply', 'Insert into reply')) + '">'
                + '<i class="glyphicon glyphicon-share-alt"></i> ' + escapeHtml(t('insert_reply_short', 'Reply')) + '</button>'
                + '<button type="button" class="btn btn-link btn-xs aicp-action-note" title="' + escapeHtml(t('insert_note', 'Insert as internal note')) + '">'
                + '<i class="glyphicon glyphicon-edit"></i> ' + escapeHtml(t('insert_note_short', 'Note')) + '</button>'
                + '<button type="button" class="btn btn-link btn-xs aicp-action-copy" title="' + escapeHtml(t('copy', 'Copy')) + '">'
                + '<i class="glyphicon glyphicon-duplicate"></i> ' + escapeHtml(t('copy', 'Copy')) + '</button>'
                + '</div>';
        }

        return html + '</div>';
    }

    function renderMeta(meta) {
        if (!meta) {
            return '';
        }

        var parts = [];

        // Token counts stay in the stored meta for support and debugging, but
        // they are not something the agent needs while working.
        if (meta.duration) {
            parts.push(meta.duration + ' s');
        }

        if (meta.tokens_per_second) {
            parts.push(meta.tokens_per_second + ' tok/s');
        }

        if (!parts.length) {
            return '';
        }

        return '<div class="aicp-message-meta">' + escapeHtml(parts.join(' · ')) + '</div>';
    }

    /**
     * Tool turns render as a compact activity row, not a chat bubble: the raw
     * JSON payload is for the model, not the agent.
     */
    function renderToolMessage(message) {
        var ok = message.status !== 1;
        var summary = (message.meta && message.meta.summary) ? message.meta.summary : '';

        if (!summary) {
            summary = ok
                ? t('tool_ran', 'Ran :tool', {tool: message.tool_name})
                : t('tool_failed', ':tool failed', {tool: message.tool_name});
        }

        var icon = ok ? 'glyphicon-cog' : 'glyphicon-warning-sign';

        return '<div class="aicp-tool-row' + (ok ? '' : ' aicp-tool-row-error') + '">'
            + '<i class="glyphicon ' + icon + '"></i> '
            + '<span class="aicp-tool-name">' + escapeHtml(message.tool_name || '') + '</span> '
            + '<span class="aicp-tool-summary">' + escapeHtml(summary) + '</span>'
            + '</div>';
    }

    function renderToolHint(tools) {
        var $hint = panel.$el.find('.aicp-tool-hint');

        $hint.remove();

        if (!tools || !tools.count) {
            return;
        }

        if (!tools.model_supports) {
            panel.$el.find('.aicp-notices').append(
                '<div class="aicp-notice aicp-notice-warning">'
                + escapeHtml(t('tools_unsupported', 'The selected model cannot use tools, so they are disabled.'))
                + '</div>'
            );
        }
    }

    /**
     * Fill the model picker, and hide it when there is nothing to pick.
     *
     * With a single allowed model the select is still populated — sendMessage()
     * reads its value — but showing a one-entry dropdown only asks the agent to
     * make a choice that does not exist.
     */
    function fillModels(models, current) {
        var $select = panel.$el.find('.aicp-model');

        $select.empty();

        if (!models || !models.length) {
            if (current) {
                $select.append($('<option>').val(current).text(current));
            }

            $select.addClass('hidden');
            return;
        }

        $.each(models, function (i, model) {
            $select.append($('<option>').val(model).text(model));
        });

        if (current) {
            $select.val(current);
        }

        $select.toggleClass('hidden', models.length < 2);
    }

    function showNotices(notices) {
        var $box = panel.$el.find('.aicp-notices');

        $.each(notices, function (i, notice) {
            $box.append('<div class="aicp-notice">' + escapeHtml(notice) + '</div>');
        });
    }

    function clearNotices() {
        panel.$el.find('.aicp-notices').empty();
    }

    function showPanelError(message) {
        panel.$messages.find('.aicp-empty').remove();
        panel.$messages.append(
            '<div class="aicp-message aicp-message-error"><div class="aicp-bubble">'
            + '<i class="glyphicon glyphicon-exclamation-sign"></i> ' + escapeHtml(message)
            + '</div></div>'
        );
        scrollToBottom();
    }

    function httpError(xhr) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.msg) {
            return xhr.responseJSON.msg;
        }

        if (xhr && xhr.status === 403) {
            return t('forbidden', 'You do not have access to this conversation.');
        }

        if (xhr && xhr.status === 429) {
            return t('rate_limited', 'You are sending messages too quickly. Wait a moment and try again.');
        }

        return t('http_error', 'The request failed (HTTP :code).', {code: xhr ? xhr.status : 0});
    }

    function scrollToBottom() {
        panel.$messages.scrollTop(panel.$messages[0].scrollHeight);
    }

    // ---------------------------------------------------------------------
    // Write confirmation
    // ---------------------------------------------------------------------

    function showConfirmation(pending) {
        panel.pending = pending;

        var args = '';

        $.each(pending.arguments || {}, function (name, value) {
            if (typeof value === 'object') {
                value = JSON.stringify(value);
            }

            args += '<div class="aicp-confirm-arg">'
                + '<span class="aicp-confirm-arg-name">' + escapeHtml(name) + '</span>'
                + '<span class="aicp-confirm-arg-value">' + escapeHtml(value) + '</span>'
                + '</div>';
        });

        panel.$messages.append(
            '<div class="aicp-confirm">'
            + '<div class="aicp-confirm-head">'
            + '<i class="glyphicon glyphicon-alert"></i> '
            + escapeHtml(t('confirm_title', 'The assistant wants to make a change'))
            + '</div>'
            + '<div class="aicp-confirm-label">' + escapeHtml(pending.label) + '</div>'
            + '<div class="aicp-confirm-tool"><code>' + escapeHtml(pending.tool) + '</code></div>'
            + (args ? '<div class="aicp-confirm-args">' + args + '</div>' : '')
            + '<div class="aicp-confirm-actions">'
            + '<button type="button" class="btn btn-primary btn-sm aicp-confirm-approve">' + escapeHtml(t('approve', 'Approve')) + '</button> '
            + '<button type="button" class="btn btn-default btn-sm aicp-confirm-reject">' + escapeHtml(t('reject', 'Reject')) + '</button>'
            + '</div>'
            + '</div>'
        );

        scrollToBottom();
    }

    function resolveConfirmation(approved) {
        if (!panel.pending || panel.busy) {
            return;
        }

        var pending = panel.pending;

        panel.$el.find('.aicp-confirm').remove();
        panel.pending = null;

        setBusy(true);

        panel.request = $.ajax({
            url: panel.urls.confirm,
            method: 'POST',
            dataType: 'json',
            data: {
                _token: csrf(),
                conversation_id: panel.conversationId,
                tool_call_id: pending.tool_call_id,
                approved: approved ? 1 : 0,
                stream: panel.streaming ? 1 : 0
            }
        }).done(function (response) {
            if (response && response.stream_url) {
                openStream(response.stream_url);
                return;
            }

            handleTurnResponse(response);
        }).fail(function (xhr, status) {
            setBusy(false);

            if (status !== 'abort') {
                showPanelError(httpError(xhr));
            }
        });
    }

    // ---------------------------------------------------------------------
    // Insert into the reply / note editor
    // ---------------------------------------------------------------------

    /**
     * Put an answer into FreeScout's Summernote editor.
     *
     * Appends below whatever is already there rather than replacing it — the
     * agent may have been drafting — and never sends anything.
     */
    function insertIntoEditor($message, asNote) {
        var html = $message.find('.aicp-bubble').html();

        if (!html) {
            return;
        }

        var $block = $('.conv-reply-block');

        if (!$block.length || typeof showReplyForm !== 'function') {
            showFloatingAlert('error', t('no_editor', 'The reply editor is not available on this page.'));
            return;
        }

        var hidden = $block.hasClass('hidden');
        var mode = (typeof getReplyFormMode === 'function') ? getReplyFormMode() : '';

        if (asNote) {
            if (hidden) {
                // The note button opens the form in note mode.
                $('.conv-add-note:first').click();
            } else if (mode !== 'note') {
                // Core refuses to switch modes on an open form (it would create
                // a second draft); switchToNote() is its own way to do it.
                if (typeof switchToNote === 'function') {
                    switchToNote();
                } else {
                    showFloatingAlert('warning', t('close_reply_first', 'Close the open reply first.'));
                    return;
                }
            }
        } else if (hidden) {
            $('.conv-reply:first').click();
        } else if (mode === 'note') {
            showFloatingAlert('warning', t('close_note_first', 'Close the open note first, then insert as a reply.'));
            return;
        }

        appendToEditor(html);
    }

    function appendToEditor(html) {
        var $body = $('#body');

        if (!$body.length || !$body.data('summernote')) {
            showFloatingAlert('error', t('no_editor', 'The reply editor is not available on this page.'));
            return;
        }

        var current = $body.summernote('code');
        var plain = $.trim($('<div>').html(current || '').text());
        var isEmpty = !current || current === '<div><br></div>' || plain === '';

        var next = isEmpty ? html : current + '<div><br></div>' + html;

        $body.summernote('code', next);
        $body.summernote('commit');

        // setReplyBody() does not mark the form dirty, and the autosaver bails
        // on a form it thinks is unchanged.
        $(".conv-reply-block :input[name='body']:first").val(next);

        if (typeof onReplyChange === 'function') {
            onReplyChange();
        }

        showFloatingAlert('success', t('inserted', 'Inserted into the editor. Review it before sending.'));
    }

    function copyMessage($message) {
        var text = $message.attr('data-body') || $message.find('.aicp-bubble').text();

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showFloatingAlert('success', t('copied', 'Copied to clipboard'));
            });
            return;
        }

        var $temp = $('<textarea>').val(text).css({position: 'fixed', opacity: 0}).appendTo('body');
        $temp[0].select();

        try {
            document.execCommand('copy');
            showFloatingAlert('success', t('copied', 'Copied to clipboard'));
        } catch (e) {
            showFloatingAlert('error', t('copy_failed', 'Could not copy.'));
        }

        $temp.remove();
    }

    // =====================================================================

    $(document).ready(function () {
        initSettings();
        initPanel();
    });
})(jQuery);
