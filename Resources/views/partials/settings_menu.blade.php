{{-- Injected into the mailbox settings sidebar via the mailboxes.settings.menu action. --}}
<li @if (Route::currentRouteName() == 'aichatpanel.mailbox.settings')class="active"@endif>
    <a href="{{ route('aichatpanel.mailbox.settings', ['mailbox_id' => $mailbox->id]) }}">
        <i class="glyphicon glyphicon-comment"></i> {{ __('AI Chat Panel') }}
    </a>
</li>
