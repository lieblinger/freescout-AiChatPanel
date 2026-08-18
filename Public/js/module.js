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

    var lang_cache = null;

    /**
     * The strings Blade rendered into data-aicp-lang, parsed once.
     *
     * Both views that run this file carry the attribute — the panel and the
     * admin settings form — so which one is on the page does not matter.
     */
    function langMap() {
        if (lang_cache === null) {
            lang_cache = {};

            var raw = $('[data-aicp-lang]').first().attr('data-aicp-lang');

            if (raw) {
                try {
                    lang_cache = JSON.parse(raw) || {};
                } catch (e) {
                    lang_cache = {};
                }
            }
        }

        return lang_cache;
    }

    /**
     * Translate, falling back to the English source string in the call.
     *
     * The strings arrive as a data- attribute rendered by Blade, the same way
     * the panel receives its URLs and preferences. They used to come from a
     * generated vars.js read through core's Lang object, which no install path
     * ever built — see Services/JsStrings.php.
     */
    function t(key, fallback, params) {
        var text = fallback;
        var map = langMap();

        if (typeof map[key] === 'string' && map[key] !== '') {
            text = map[key];
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

    /**
     * escapeHtml for a value going into a double-quoted attribute.
     *
     * Text-node escaping leaves the quote characters alone — correct between
     * tags, wrong inside an attribute, where a single " in model output ends
     * the value early and everything after it is parsed as further attributes.
     */
    function escapeAttr(text) {
        return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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
        // Olson id of the viewer's FreeScout profile timezone. Only used to
        // work out which calendar day "now" falls in; stored timestamps are
        // already formatted server-side.
        timezone: '',
        // Day of the last message put on screen, so appendMessage knows when to
        // draw a separator. Reset whenever the list is replaced.
        lastDayKey: null,
        todayKey: null,
        urls: {},
        // The stored panel_open preference. Only the wide layout reads or
        // writes it — see applyLayoutMode().
        pref_open: false,
        // The stored panel_width preference. What the panel actually gets is
        // capped to the window — see applyWidth().
        pref_width: 380,
        loaded: false,
        busy: false,
        request: null,
        source: null,
        pending: null,
        streaming: true,
        buffer: '',
        $streamBubble: null,
        // Thread ids the assistant edited, awaiting fresh HTML from the poll.
        updatedThreads: {}
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
        panel.timezone = $el.attr('data-timezone') || '';
        panel.urls = {
            history: $el.attr('data-url-history'),
            send: $el.attr('data-url-send'),
            confirm: $el.attr('data-url-confirm'),
            editorHtml: $el.attr('data-url-editor-html'),
            reset: $el.attr('data-url-reset'),
            prefs: $el.attr('data-url-prefs')
        };

        setWidth(parseInt($el.attr('data-width'), 10) || 380, false);
        updatePanelBounds();

        bindPanel();
        bindConversationThreads();
        bindRealtimeUpdates();

        // The stored preference belongs to the wide layout. Narrower viewports
        // start closed and are opened deliberately, from the toolbar button.
        panel.pref_open = $el.attr('data-open') === '1';

        if (panel.pref_open && !isOverlay()) {
            openPanel(false);
        }

        // Seeds last_overlay, so the first resize does not report a transition
        // that never happened.
        applyLayoutMode();
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

            // Fill the input first, then send. sendMessage() reads the input
            // and refuses while the panel is busy or a write is waiting to be
            // confirmed; going through it rather than around it means the
            // prompt is left sitting in the box in those two cases, so the
            // click is never silently swallowed.
            panel.$input.val($(this).attr('data-prompt')).focus();
            sendMessage();
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

        panel.$el.on('click', '.aicp-open-draft', function (e) {
            e.preventDefault();
            openDraftInEditor($(this).attr('data-thread_id'));
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

        if (isOverlay()) {
            $('.aicp-backdrop').removeClass('hidden');
        }

        // Overlay mode is a per-device, per-visit state: opening the drawer on
        // a phone must not become the state the desktop comes back to.
        if (persist !== false && !isOverlay()) {
            panel.pref_open = true;
            savePrefs({panel_open: 1});
        }

        if (!panel.loaded) {
            loadHistory();
        }

        panel.$input.focus();
    }

    function closePanel(persist) {
        $('body').removeClass('aicp-open');
        $('.aicp-backdrop').addClass('hidden');

        if (persist !== false && !isOverlay()) {
            panel.pref_open = false;
            savePrefs({panel_open: 0});
        }
    }

    /**
     * Is the layout too narrow to give the panel a column of its own?
     *
     * Two things have to hold. First, core has to be laying the conversation
     * out in three columns at all: below its own 1100px breakpoint it stops
     * floating #conv-layout-customer beside the thread, and the panel follows
     * it. That one is read through matchMedia rather than $(window).width(),
     * which reports clientWidth and would flip up to a scrollbar's width
     * before the media query does.
     *
     * Second — and this is what a window-width breakpoint alone cannot see —
     * there has to be room left for the thread once the panel has taken its
     * minimum. A 1145px window is above the first test and still hopeless: the
     * left nav takes 260px and the customer rail another 280px, so even the
     * narrowest panel would leave the thread around 300px and the subject line
     * wraps one word per line.
     */
    function isOverlay() {
        var narrow = window.matchMedia
            ? window.matchMedia('(max-width: 1100px)').matches
            : $(window).width() <= 1100;

        return narrow || maxPanelWidth() < WIDTH_MIN;
    }

    var last_overlay = null;

    /**
     * Keep the open state in step with the layout mode when the window is
     * resized across the breakpoint.
     *
     * Entering overlay mode closes the panel, so the thread the user just made
     * room for is actually readable; leaving it restores whatever the stored
     * preference says. Neither direction writes that preference.
     *
     * The body class is what the stylesheet keys the drawer rules off. It
     * cannot be a media query: whether the panel still fits depends on the
     * panel's own width and on how much of the window core's two sidebars are
     * taking, neither of which CSS can measure.
     */
    function applyLayoutMode() {
        var overlay = isOverlay();

        $('body').toggleClass('aicp-overlay', overlay);

        if (overlay === last_overlay) {
            return;
        }

        last_overlay = overlay;

        if (overlay) {
            if ($('body').hasClass('aicp-open')) {
                closePanel(false);
            }

            return;
        }

        $('.aicp-backdrop').addClass('hidden');

        if (panel.pref_open && !$('body').hasClass('aicp-open')) {
            openPanel(false);
        }
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
     *
     * applyLayoutMode() and applyWidth() ride along on the same throttle
     * instead of adding a second resize listener. The first returns
     * immediately unless the mode actually changed; the second re-caps the
     * panel against the new window width.
     */
    function scheduleBoundsUpdate() {
        if (!window.requestAnimationFrame) {
            updatePanelBounds();
            applyLayoutMode();
            applyWidth();
            return;
        }

        if (bounds_frame) {
            return;
        }

        bounds_frame = window.requestAnimationFrame(function () {
            bounds_frame = null;
            updatePanelBounds();
            applyLayoutMode();
            applyWidth();
        });
    }

    /**
     * The width the message thread has to keep, whatever the panel wants.
     *
     * Measured on the thread itself, not on the window: by the time the
     * conversation reaches #conv-layout-main, core's left nav has taken 260px
     * and the customer rail another 280px. Subtracting a margin from the
     * window instead is what let a 1133px window end up with a 225px thread,
     * one word per line in the subject.
     */
    var MIN_THREAD_WIDTH = 450;

    // The range UserPref will accept. Mirrors UserPref::WIDTH_MIN / WIDTH_MAX.
    var WIDTH_MIN = 300;
    var WIDTH_MAX = 900;

    function setWidth(width, persist) {
        // What the user chose. The window may not be wide enough to honour it
        // right now, but that is applyWidth()'s problem, not something to
        // write back to the preference.
        panel.pref_width = Math.max(WIDTH_MIN, Math.min(WIDTH_MAX, width));

        applyWidth();

        if (persist) {
            savePrefs({panel_width: panel.pref_width});
        }
    }

    /**
     * The widest the panel may be and still leave a readable thread.
     *
     * Everything it subtracts is measured, not assumed: core's own breakpoints
     * drop the left nav at 991px and the customer rail at 1100px, and a module
     * is free to change either. None of it depends on the panel, so there is
     * no feedback loop between this and the width it produces.
     *
     * @return int May come out below WIDTH_MIN, which is isOverlay()'s cue
     *             that the panel cannot be a column here at all.
     */
    function maxPanelWidth() {
        var $sidebar = $('.sidebar-2col');
        var sidebar = $sidebar.length && $sidebar.is(':visible') ? $sidebar.outerWidth() : 0;

        // The customer rail is absolutely positioned; the space it occupies is
        // the padding core reserves for it on the thread column.
        var main = document.getElementById('conv-layout-main');
        var rail = main ? parseFloat($(main).css('padding-right')) || 0 : 0;

        return $(window).width() - sidebar - rail - MIN_THREAD_WIDTH;
    }

    /**
     * Publish the width the panel actually gets.
     *
     * Drives both the panel and the shift applied to the conversation layout,
     * so the two can never disagree.
     *
     * In push mode the stored width is capped to what the thread can spare: a
     * panel dragged out to 900px on a wide monitor has to give some of that
     * back on a smaller one. In overlay mode there is nothing to divide up —
     * the drawer floats over the thread and the stylesheet caps it at the
     * viewport.
     */
    function applyWidth() {
        var width = panel.pref_width;

        if (!isOverlay()) {
            width = Math.max(WIDTH_MIN, Math.min(width, maxPanelWidth()));
        }

        document.documentElement.style.setProperty('--aicp-width', width + 'px');
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
        appendMessage({role: 'user', body: text, echo: true});
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
                editor_body: currentEditorBody(),
                editor_mode: currentEditorMode(),
                stream: panel.streaming ? 1 : 0
            }
        }).done(function (response) {
            // Before the branch: the user turn comes back on every shape of
            // this response, and the bubble on screen is still unstamped.
            if (response) {
                stampEcho(response.messages);
            }

            // Streaming is a two-step handshake: this POST creates the turn and
            // hands back a one-shot URL, because EventSource cannot POST.
            if (response && response.stream_url) {
                openStream(response.stream_url);
                return;
            }

            handleTurnResponse(response);
        }).fail(function (xhr, status) {
            setBusy(false);

            // Nothing will ever stamp this one; leaving the class on would let
            // the next send stamp the wrong bubble.
            panel.$messages.find('.aicp-echo').removeClass('aicp-echo');

            if (status === 'abort') {
                return;
            }

            showPanelError(httpError(xhr));
        });
    }

    /**
     * Give the optimistic user bubble its real timestamp.
     *
     * The bubble is drawn before the server has seen the message, so it has no
     * time. The authoritative user turn comes back on the send response — both
     * the streaming handshake and the plain one — and only the time is missing
     * from what is already on screen, so patch it rather than re-render.
     *
     * Not doable from the SSE 'done' frame: that carries only the turns the
     * assistant produced.
     */
    function stampEcho(messages) {
        var $echo = panel.$messages.find('.aicp-message-user.aicp-echo').last();

        if (!$echo.length) {
            return;
        }

        var stamped = null;

        $.each(messages || [], function (i, message) {
            if (message.role === 'user' && message.time) {
                stamped = message;
            }
        });

        $echo.removeClass('aicp-echo');

        if (stamped) {
            $echo.append($('<div class="aicp-message-meta"></div>').text(stamped.time));
        }
    }

    function handleTurnResponse(response) {
        setBusy(false);

        if (!response) {
            showPanelError(t('load_failed', 'Could not load the chat.'));
            return;
        }

        // Before the error return below: a write that succeeded still changed
        // the conversation, even when the completion after it failed.
        applyConversationChanges(response.changes);

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

        // The assistant changed the conversation itself. Refresh the page
        // behind the panel now rather than making the user reload it.
        source.addEventListener('conversation_changed', function (e) {
            applyConversationChanges(parseEvent(e));
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

            // Cumulative, so a client that missed the mid-turn frame catches up.
            applyConversationChanges(data.changes);
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

    /**
     * 'YYYY-MM-DD' for an instant, in the viewer's FreeScout timezone — the
     * same key the server puts on every message.
     *
     * Worked out here rather than sent along with the messages so a panel left
     * open past midnight relabels itself instead of saying "Today" about
     * yesterday. Returns null when the browser has no Intl or does not know the
     * timezone id, in which case the caller falls back to the full date.
     */
    function dayKey(date) {
        if (!window.Intl || !Intl.DateTimeFormat) {
            return null;
        }

        var options = {year: 'numeric', month: '2-digit', day: '2-digit'};

        if (panel.timezone) {
            options.timeZone = panel.timezone;
        }

        try {
            var formatter = new Intl.DateTimeFormat('en-CA', options);

            if (!formatter.formatToParts) {
                return null;
            }

            var parts = {};

            $.each(formatter.formatToParts(date), function (i, part) {
                parts[part.type] = part.value;
            });

            if (!parts.year || !parts.month || !parts.day) {
                return null;
            }

            return parts.year + '-' + parts.month + '-' + parts.day;
        } catch (e) {
            return null;
        }
    }

    /**
     * Calendar arithmetic on the key, not "now minus 24 hours": a local day is
     * 23 or 25 hours long around a DST change.
     */
    function previousDayKey(key) {
        var date = new Date(key + 'T00:00:00Z');

        if (isNaN(date.getTime())) {
            return null;
        }

        date.setUTCDate(date.getUTCDate() - 1);

        return date.toISOString().slice(0, 10);
    }

    function dayLabel(key, full) {
        var today = dayKey(new Date());

        if (today && key === today) {
            return t('day_today', 'Today');
        }

        if (today && key === previousDayKey(today)) {
            return t('day_yesterday', 'Yesterday');
        }

        return full || key || '';
    }

    /**
     * The full date is carried on the element as well as the label, so the
     * separator can be relabelled in place when the day turns over.
     */
    function renderDaySeparator(key, full) {
        return '<div class="aicp-day-separator" data-key="' + escapeAttr(key) + '"'
            + ' data-full="' + escapeAttr(full || '') + '">'
            + '<span>' + escapeHtml(dayLabel(key, full)) + '</span>'
            + '</div>';
    }

    /**
     * Past midnight every "Today" already on screen means yesterday. Cheap
     * enough to re-check whenever something is appended.
     */
    function refreshDayLabels() {
        var today = dayKey(new Date());

        if (!today || today === panel.todayKey) {
            return;
        }

        panel.todayKey = today;

        panel.$messages.find('.aicp-day-separator').each(function () {
            var $separator = $(this);

            $separator.children('span').text(
                dayLabel($separator.attr('data-key'), $separator.attr('data-full'))
            );
        });
    }

    function renderMessages(messages, replace) {
        if (replace) {
            panel.$messages.empty();
            panel.lastDayKey = null;
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
        var html = '';

        if (message.role === 'user') {
            html = renderUserMessage(message);
        } else if (message.role === 'tool') {
            html = renderToolMessage(message);
        } else {
            html = renderAssistantMessage(message);
        }

        // A turn that only asked for tools renders nothing. It must not consume
        // the day separator, or the next real message of that day loses it.
        if (!html) {
            return;
        }

        panel.$messages.find('.aicp-empty').remove();
        refreshDayLabels();

        // The optimistic echo has no server fields: it is being written now, so
        // now is its day.
        var key = message.date_key || dayKey(new Date());

        if (key && key !== panel.lastDayKey) {
            html = renderDaySeparator(key, message.date_label) + html;
            panel.lastDayKey = key;
        }

        var $typing = panel.$messages.find('.aicp-typing');

        if ($typing.length) {
            $typing.before(html);
        } else {
            panel.$messages.append(html);
        }

        scrollToBottom();
    }

    function renderUserMessage(message) {
        return '<div class="aicp-message aicp-message-user' + (message.echo ? ' aicp-echo' : '') + '">'
            + '<div class="aicp-bubble">' + escapeHtml(message.body).replace(/\n/g, '<br>') + '</div>'
            // The echo has no time yet; stampEcho() fills it in once the server
            // has seen the message.
            + (message.time ? '<div class="aicp-message-meta">' + escapeHtml(message.time) + '</div>' : '')
            + '</div>';
    }

    function renderAssistantMessage(message) {
        var isError = message.status === 1;

        if (isError) {
            return '<div class="aicp-message aicp-message-error">'
                + '<div class="aicp-bubble">'
                + '<i class="glyphicon glyphicon-exclamation-sign"></i> '
                + escapeHtml(message.body)
                + '</div>'
                + renderMeta(message)
                + '</div>';
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

        // An empty answer next to a non-empty chain of thought has two very
        // different causes, and they need different advice, so branch on the
        // finish reason rather than guessing:
        //
        //   "length" — max_tokens covers reasoning AND answer on a reasoning
        //   model, so the budget ran out mid-thought and no answer was ever
        //   written. The fix is a setting.
        //
        //   anything else — the model ended its turn after thinking. The
        //   answer, such as it is, is in the reasoning.
        //
        // Either way the chain of thought is never promoted into the answer,
        // but an empty bubble reads as a failure, so say what happened. The
        // text has to stand on its own: the loop's truncation notice is
        // transient and is gone when the chat is reloaded.
        if (!$.trim(message.body || '')) {
            var finish = (message.meta || {}).finish_reason;

            body = '<em class="text-help">'
                + escapeHtml(finish === 'length'
                    ? t('answer_truncated', 'The model used its whole response budget before writing an answer. Raise “Max response tokens” in the settings, then ask again.')
                    : t('reasoning_only', 'The model put its whole answer into its reasoning and returned nothing. Open “Show reasoning” to read it, or ask again.'))
                + '</em>';
        }

        // data-id is what the insert buttons post back, so the server can
        // render this answer for the editor instead of the panel.
        var html = '<div class="aicp-message aicp-message-assistant"'
            + (message.id ? ' data-id="' + escapeAttr(String(message.id)) + '"' : '')
            + ' data-body="' + escapeAttr(message.body || '') + '">';

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
        html += renderMeta(message);

        if ($.trim(message.body || '')) {
            html += '<div class="aicp-message-actions">'
                + '<button type="button" class="btn btn-link btn-xs aicp-action-reply" title="' + escapeAttr(t('insert_reply', 'Insert into reply')) + '">'
                + '<i class="glyphicon glyphicon-share-alt"></i> ' + escapeHtml(t('insert_reply_short', 'Reply')) + '</button>'
                + '<button type="button" class="btn btn-link btn-xs aicp-action-note" title="' + escapeAttr(t('insert_note', 'Insert as internal note')) + '">'
                + '<i class="glyphicon glyphicon-edit"></i> ' + escapeHtml(t('insert_note_short', 'Note')) + '</button>'
                + '<button type="button" class="btn btn-link btn-xs aicp-action-copy" title="' + escapeAttr(t('copy', 'Copy')) + '">'
                + '<i class="glyphicon glyphicon-duplicate"></i> ' + escapeHtml(t('copy', 'Copy')) + '</button>'
                + '</div>';
        }

        return html + '</div>';
    }

    function renderMeta(message) {
        var meta = message.meta || {};
        var parts = [];

        // Formatted server-side, so it already carries the viewer's timezone
        // and their 12/24-hour preference.
        if (message.time) {
            parts.push(message.time);
        }

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
            + renderDraftAction(message, ok)
            + '</div>';
    }

    /**
     * The "Open in editor" link on a draft the assistant saved.
     *
     * Read out of the tool result rather than held in panel state, so it
     * survives the authoritative re-render on the done frame and comes back
     * when the chat is reopened days later.
     *
     * Never opens the editor on its own: showReplyForm() replaces its contents
     * wholesale (core/public/js/main.js:1647) and the agent may be typing.
     */
    function renderDraftAction(message, ok) {
        // The dotted spelling is what the tool was called before 1.3.0. Rows
        // written then are renamed by migration, but a panel left open across
        // the upgrade still holds them.
        var draftTools = ['conversation_create_draft_reply', 'conversation.create_draft_reply'];

        if (!ok || draftTools.indexOf(message.tool_name) === -1) {
            return '';
        }

        var threadId = null;

        try {
            var body = JSON.parse(message.body);
            threadId = (body && body.data) ? body.data.thread_id : null;
        } catch (err) {
            return '';
        }

        if (!threadId) {
            return '';
        }

        return ' <a href="#" class="aicp-open-draft" data-thread_id="' + escapeAttr(String(threadId)) + '">'
            + escapeHtml(t('open_draft', 'Open in editor'))
            + '</a>';
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
        var groups = {};
        var found = false;

        $select.empty();

        models = $.grep($.map(models || [], normaliseModel), Boolean);

        if (!models.length) {
            if (current) {
                $select.append($('<option>').val(current).text(current));
            }

            $select.addClass('hidden');
            return;
        }

        // Already sorted server-side, vendor first; reordering here would undo
        // that. Grouping is what turns OpenRouter's ~500 entries into a list
        // somebody can actually find a model in.
        $.each(models, function (i, model) {
            var $option = $('<option>')
                .val(model.id)
                .attr('title', model.id)
                .text(model.tools === false
                    ? model.label + ' ' + t('model_no_tools', '(no tools)')
                    : model.label);

            if (model.id === current) {
                found = true;
            }

            if (!model.group) {
                $select.append($option);
                return;
            }

            if (!groups[model.group]) {
                groups[model.group] = $('<optgroup>').attr('label', model.group).appendTo($select);
            }

            $option.appendTo(groups[model.group]);
        });

        if (current) {
            // The catalogue is cached for a few minutes, so a model saved as a
            // preference can be missing from it. Dropping it would silently
            // switch the agent to whatever sorts first.
            if (!found) {
                $select.append($('<option>').val(current).text(current).attr('title', current));
            }

            $select.val(current);
        }

        $select.toggleClass('hidden', models.length < 2);
    }

    /**
     * Accept both the plain id list older panels sent and the described entries
     * the server sends now.
     */
    function normaliseModel(model) {
        if (typeof model === 'string') {
            return {id: model, label: model, group: '', tools: null};
        }

        if (!model || !model.id) {
            return null;
        }

        return {
            id: model.id,
            label: model.label || model.id,
            group: model.group || '',
            tools: typeof model.tools === 'boolean' ? model.tools : null
        };
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
                // Read again rather than remembered: the agent may have kept
                // typing while the confirmation was open.
                editor_body: currentEditorBody(),
                editor_mode: currentEditorMode(),
                stream: panel.streaming ? 1 : 0
            }
        }).done(function (response) {
            // Before the stream_url return: the approved write ran in this
            // request, and the follow-up streaming request cannot report it.
            if (response) {
                applyConversationChanges(response.changes);
            }

            if (response && response.stream_url) {
                // Same reasoning for the messages: the tool turn this confirm
                // produced exists only on this response, because the follow-up
                // stream reports the turns that come after it. Without this the
                // approved tool row — and the "Open in editor" link on a draft —
                // stays missing until the chat is reopened.
                if (response.messages) {
                    renderMessages($.grep(response.messages, function (m) {
                        return m.role !== 'user';
                    }), false);
                }

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
    // Live conversation refresh
    // ---------------------------------------------------------------------

    /**
     * React to what the assistant just changed in the conversation.
     *
     * FreeScout has one mechanism for updating an open conversation view:
     * polycast plus App\Events\RealtimeConvNewThread. The server has already
     * written the event row, and core's own handler
     * (core/public/js/main.js:3811) will render the thread, authorise the
     * recipient, insert the HTML and refresh the status and assignee widgets.
     *
     * All this has to do is stop the browser waiting up to five seconds for the
     * next poll. The change set carries ids only; it is a poke, not the data.
     */
    function applyConversationChanges(changes) {
        if (!changes || !changes.conversation_id) {
            return;
        }

        // module.js loads on every page, so never assume the conversation
        // layout is present.
        if (!$('#conv-layout-main').length) {
            return;
        }

        var openId = (typeof getGlobalAttr === 'function')
            ? getGlobalAttr('conversation_id')
            : panel.conversationId;

        if (String(changes.conversation_id) !== String(openId)) {
            return;
        }

        // Core inserts a thread only when it is not already in the DOM
        // (main.js:3821) and has no path at all for one that changed. Remember
        // which ids were edited so the handler below can replace them when the
        // poll delivers the freshly rendered HTML.
        $.each(changes.updated_thread_ids || [], function (i, id) {
            panel.updatedThreads[id] = true;
        });

        pokeRealtime(changes.since);
    }

    /**
     * Replace threads the assistant edited, when the poll brings them back.
     *
     * This rides the same event and the same channel as core's own handler.
     * poly.subscribe() creates a fresh channel object per call and
     * parseResponse() fires all of them (polycast.js:292), so both run: core's
     * inserts what is new, this replaces what changed. Registered after core's
     * because module.js is appended to the bundle behind main.js, so by the
     * time this runs core has already decided to skip the thread.
     */
    function bindRealtimeUpdates() {
        if (typeof poly === 'undefined' || !poly) {
            return;
        }

        var conversationId = (typeof getGlobalAttr === 'function')
            ? getGlobalAttr('conversation_id')
            : panel.conversationId;

        if (!conversationId) {
            return;
        }

        poly.subscribe('conv.' + conversationId)
            .on('App\\Events\\RealtimeConvNewThread', function (data) {
                if (!data || !data.thread_id || !data.thread_html) {
                    return;
                }

                if (!panel.updatedThreads[data.thread_id]) {
                    return;
                }

                var $old = $('#thread-' + data.thread_id);

                if (!$old.length) {
                    // Core inserted it after all — nothing to replace.
                    return;
                }

                delete panel.updatedThreads[data.thread_id];

                // A draft open in the reply editor is deliberately hidden by
                // core's editDraft(). The replacement must not pop it back into
                // view underneath the form the agent is working in.
                var wasHidden = !$old.is(':visible');
                var $new = $(data.thread_html);

                $old.replaceWith($new);

                if (wasHidden) {
                    $new.hide();
                } else if (typeof flashElement === 'function') {
                    flashElement($new);
                }
            });
    }

    /**
     * Make the next polycast poll happen now instead of on its timer.
     *
     * Two things are needed to actually see the change straight away.
     *
     * clearTimeout first: parseResponse() re-arms the timer unconditionally
     * (core/public/js/polycast/polycast.js:306) and setTimeout() overwrites
     * this.timeout without clearing the old one (:182), so an unguarded
     * fetch() leaves a second timer chain running for the life of the page and
     * doubles the poll rate every time it is called.
     *
     * Then the time cursor. polycast defers each handler by the event's age
     * relative to the cursor the poll was made with (:361), and the cursor is
     * whenever the *last* poll ran — so polling immediately would still leave
     * the change hidden for up to the full five-second interval. Winding the
     * cursor back to just before the write makes that age ~0. The server sends
     * it; guessing it here would race the two clocks.
     *
     * parseResponse() resets the cursor from the response (:283), so this is a
     * one-poll effect. Redelivered events are harmless: core skips threads
     * already in the DOM (main.js:3821).
     */
    function pokeRealtime(since) {
        if (typeof poly === 'undefined' || !poly || !poly.connected) {
            return;
        }

        try {
            if (since) {
                poly.setTime(since);
            }

            clearTimeout(poly.timeout);
            poly.fetch();
        } catch (err) {
            // A missed poke costs the user five seconds, not the change.
        }
    }

    /**
     * Bind the controls on threads that arrive after page load.
     *
     * Core binds .edit-draft-trigger and .discard-draft-trigger directly inside
     * initConversation() (core/public/js/main.js:1201,1207), which ran once at
     * page load, so realtime-inserted drafts get dead buttons. Delegating is
     * also the only thing that survives core's "View new message" trigger,
     * which does $('#conv-layout-main').prepend(container.html()) (:3830) and
     * therefore re-parses the markup into brand new nodes.
     *
     * The marker class is what keeps this from double-firing: threads that were
     * on the page at load already carry core's direct handler.
     */
    function bindConversationThreads() {
        var $main = $('#conv-layout-main');

        if (!$main.length) {
            return;
        }

        $main.find('.thread').addClass('aicp-core-bound');

        $main.on('click', '.thread:not(.aicp-core-bound) .edit-draft-trigger', function (e) {
            e.preventDefault();

            if (typeof editDraft === 'function') {
                editDraft($(this));
            }
        });

        $main.on('click', '.thread:not(.aicp-core-bound) .discard-draft-trigger', function (e) {
            e.preventDefault();

            if (typeof discardDraft === 'function') {
                discardDraft($(this).parents('.thread:first').attr('data-thread_id'));
            }
        });

        // Tooltips on injected threads, and again after core splices the
        // "View new message" container: the timeout lets core's own handler
        // finish re-parsing first.
        $main.on('click', '.view-new-trigger', function () {
            setTimeout(function () {
                if (typeof initTooltip === 'function') {
                    initTooltip($main.find('.thread:not(.aicp-core-bound) [data-toggle="tooltip"]'));
                }
            }, 0);
        });
    }

    /**
     * Load a draft into the reply editor, the way core's editDraft() does
     * (core/public/js/main.js:4601). Goes straight to the ajax action rather
     * than clicking the thread's Edit button, so it works whether or not the
     * polycast poll has landed yet.
     */
    function openDraftInEditor(threadId) {
        if (!threadId || typeof fsAjax !== 'function' || typeof showReplyForm !== 'function') {
            showFloatingAlert('error', t('no_editor', 'The reply editor is not available on this page.'));
            return;
        }

        fsAjax(
            {action: 'load_draft', thread_id: threadId},
            laroute.route('conversations.ajax'),
            function (response) {
                loaderHide();

                if (response && response.status === 'success') {
                    showReplyForm(response.data, -50);

                    if (response.data.is_forward == '1' && typeof showForwardForm === 'function') {
                        showForwardForm(response.data);
                    }

                    // Core hides the draft block while it is being edited.
                    $('#thread-' + threadId).hide();
                } else {
                    showAjaxError(response);
                }
            }
        );
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
        var bubble = $message.find('.aicp-bubble').html();

        if (!bubble) {
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

        // The bubble is rendered for a browser, not for a thread body: it
        // keeps <code>, <hr> and <del>, which core's purifier drops the moment
        // the draft is displayed or sent. Ask the server for the editor render
        // of the same answer instead, and fall back to the bubble only when
        // there is no message id to ask about.
        var id = $message.attr('data-id');

        if (!id || !panel.urls.editorHtml) {
            appendToEditor(bubble);
            return;
        }

        var $buttons = $message.find('.aicp-action-reply, .aicp-action-note').prop('disabled', true);

        $.ajax({
            url: panel.urls.editorHtml,
            method: 'POST',
            dataType: 'json',
            data: {
                _token: csrf(),
                conversation_id: panel.conversationId,
                message_id: id
            }
        }).done(function (response) {
            appendToEditor(response && response.html ? response.html : bubble);
        }).fail(function () {
            appendToEditor(bubble);
        }).always(function () {
            $buttons.prop('disabled', false);
        });
    }

    /**
     * What the agent currently has in the reply editor.
     *
     * Sent with every turn so the assistant can answer "make what I wrote more
     * formal" — the draft lives in the browser and may never have been saved.
     * Empty when the form is closed or holds nothing but Summernote's empty
     * paragraph.
     */
    function currentEditorBody() {
        var $body = $('#body');

        if (!$body.length || !$body.data('summernote')) {
            return '';
        }

        if ($('.conv-reply-block').hasClass('hidden')) {
            return '';
        }

        var html = $body.summernote('code') || '';

        if (html === '<div><br></div>' || $.trim($('<div>').html(html).text()) === '') {
            return '';
        }

        // The server caps this too; the point here is not to post a megabyte.
        return html.slice(0, 100000);
    }

    function currentEditorMode() {
        return (typeof getReplyFormMode === 'function') ? getReplyFormMode() : '';
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
