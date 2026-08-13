@extends('layouts.app')

@section('title_full', __('AI Chat Panel').' - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')

    <div class="section-heading">
        {{ __('AI Chat Panel') }}
    </div>

    @include('partials/flash_messages')

    <div class="row-container">
        <div class="row">
            <div class="col-xs-12">

                <p class="text-help margin-bottom">
                    {{ __('These settings override the global ones for this mailbox only. Anything left on "Inherit" follows the global setting.') }}
                    <a href="{{ route('settings', ['section' => 'aichatpanel']) }}">{{ __('Global settings') }}</a>
                </p>

                <form class="form-horizontal margin-top" method="POST" action="">
                    {{ csrf_field() }}

                    @php
                        $tri = function ($key) use ($meta) {
                            if (!array_key_exists($key, $meta)) { return 'inherit'; }
                            return $meta[$key] ? '1' : '0';
                        };
                    @endphp

                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{ __('Panel enabled') }}</label>

                        <div class="col-sm-6">
                            <div class="controls">
                                @foreach ([
                                    'inherit' => __('Inherit (:value)', ['value' => $globals['enabled'] ? __('on') : __('off')]),
                                    '1'       => __('On'),
                                    '0'       => __('Off'),
                                ] as $value => $label)
                                    <label class="radio inline plain" for="enabled_{{ $value }}">
                                        <input type="radio" name="enabled" value="{{ $value }}" id="enabled_{{ $value }}" @if ($tri('enabled') === (string) $value)checked="checked"@endif> {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{ __('Include internal notes') }}</label>

                        <div class="col-sm-6">
                            <div class="controls">
                                @foreach ([
                                    'inherit' => __('Inherit (:value)', ['value' => $globals['include_notes'] ? __('on') : __('off')]),
                                    '1'       => __('On'),
                                    '0'       => __('Off'),
                                ] as $value => $label)
                                    <label class="radio inline plain" for="include_notes_{{ $value }}">
                                        <input type="radio" name="include_notes" value="{{ $value }}" id="include_notes_{{ $value }}" @if ($tri('include_notes') === (string) $value)checked="checked"@endif> {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            <p class="help-block">{{ __('Whether internal notes are sent to the model as part of the conversation history.') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{ __('Strip signatures') }}</label>

                        <div class="col-sm-6">
                            <div class="controls">
                                @foreach ([
                                    'inherit' => __('Inherit (:value)', ['value' => __('on')]),
                                    '1'       => __('On'),
                                    '0'       => __('Off'),
                                ] as $value => $label)
                                    <label class="radio inline plain" for="include_signature_{{ $value }}">
                                        <input type="radio" name="include_signature" value="{{ $value }}" id="include_signature_{{ $value }}" @if ($tri('include_signature') === (string) $value)checked="checked"@endif> {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            <p class="help-block">{{ __('Signature detection is best-effort. Turn stripping off if this mailbox uses signatures that carry information the assistant needs.') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{ __('Send personal data') }}</label>

                        <div class="col-sm-6">
                            <div class="controls">
                                @foreach ([
                                    'inherit' => __('Inherit (:value)', ['value' => $globals['send_personal_data'] ? __('on') : __('off')]),
                                    '1'       => __('On'),
                                    '0'       => __('Off'),
                                ] as $value => $label)
                                    <label class="radio inline plain" for="send_personal_data_{{ $value }}">
                                        <input type="radio" name="send_personal_data" value="{{ $value }}" id="send_personal_data_{{ $value }}" @if ($tri('send_personal_data') === (string) $value)checked="checked"@endif> {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                            <p class="help-block">{{ __('Whether postal addresses, phone numbers, social profiles, customer notes and the agent\'s own contact details may be sent to the endpoint. Turn it off for a mailbox whose data must not leave your server.') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="system_prompt_addition" class="col-sm-3 control-label">{{ __('Extra system prompt') }}</label>

                        <div class="col-sm-6">
                            <textarea id="system_prompt_addition" name="system_prompt_addition" class="form-control" rows="6">{{ old('system_prompt_addition', isset($meta['system_prompt_addition']) ? $meta['system_prompt_addition'] : '') }}</textarea>
                            <p class="help-block">{{ __('Appended to the global system prompt for this mailbox. Use it for product names, policies or escalation rules.') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="reply_language" class="col-sm-3 control-label">{{ __('Reply language') }}</label>

                        <div class="col-sm-6">
                            <input id="reply_language" type="text" name="reply_language" class="form-control input-sized" maxlength="60" value="{{ old('reply_language', isset($meta['reply_language']) ? $meta['reply_language'] : '') }}" placeholder="{{ __('e.g. German') }}">
                            <p class="help-block">{{ __('Language for drafts meant for the customer. Leave empty to let the assistant follow the conversation.') }}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="reply_tone" class="col-sm-3 control-label">{{ __('Reply tone') }}</label>

                        <div class="col-sm-6">
                            <input id="reply_tone" type="text" name="reply_tone" class="form-control" maxlength="200" value="{{ old('reply_tone', isset($meta['reply_tone']) ? $meta['reply_tone'] : '') }}" placeholder="{{ __('e.g. friendly but concise, address the customer informally') }}">
                        </div>
                    </div>

                    <h3 class="subheader">{{ __('Tools') }}</h3>

                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{ __('Available tools') }}</label>

                        <div class="col-sm-6">
                            @php $tools_override = array_key_exists('tools_enabled', $meta); @endphp

                            <div class="controls">
                                <label class="radio inline plain" for="tools_mode_inherit">
                                    <input type="radio" name="tools_mode" value="inherit" id="tools_mode_inherit" @if (!$tools_override)checked="checked"@endif>
                                    {{ __('Inherit global selection') }}
                                </label>
                                <label class="radio inline" for="tools_mode_override">
                                    <input type="radio" name="tools_mode" value="override" id="tools_mode_override" @if ($tools_override)checked="checked"@endif>
                                    {{ __('Choose for this mailbox') }}
                                </label>
                            </div>

                            <div class="controls margin-top">
                                @php $selected_tools = $tools_override ? $meta['tools_enabled'] : $globals['tools_enabled']; @endphp

                                @forelse ($tool_catalogue as $tool_name => $tool)
                                    <label class="checkbox" for="mb_tool_{{ md5($tool_name) }}">
                                        <input type="checkbox" name="tools_enabled[]" value="{{ $tool_name }}" id="mb_tool_{{ md5($tool_name) }}" @if (in_array($tool_name, $selected_tools))checked="checked"@endif>
                                        <code class="aichatpanel-key">{{ $tool_name }}</code>
                                        @if ($tool->mode() === \Modules\AiChatPanel\Services\Tools\Tool::MODE_WRITE)
                                            <span class="label label-warning">{{ __('write') }}</span>
                                        @else
                                            <span class="label label-default">{{ __('read') }}</span>
                                        @endif
                                    </label>
                                @empty
                                    <p class="text-help">{{ __('No tools are registered.') }}</p>
                                @endforelse
                            </div>
                            <p class="help-block">{{ __('The global master switch for write tools still applies. A mailbox can narrow the selection, never widen it past what is globally allowed.') }}</p>
                        </div>
                    </div>

                    <h3 class="subheader">{{ __('Context providers') }}</h3>

                    <div class="form-group">
                        <label class="col-sm-3 control-label">{{ __('Available providers') }}</label>

                        <div class="col-sm-6">
                            @php $providers_override = array_key_exists('context_providers', $meta); @endphp

                            <div class="controls">
                                <label class="radio inline plain" for="providers_mode_inherit">
                                    <input type="radio" name="providers_mode" value="inherit" id="providers_mode_inherit" @if (!$providers_override)checked="checked"@endif>
                                    {{ __('Inherit global selection') }}
                                </label>
                                <label class="radio inline" for="providers_mode_override">
                                    <input type="radio" name="providers_mode" value="override" id="providers_mode_override" @if ($providers_override)checked="checked"@endif>
                                    {{ __('Choose for this mailbox') }}
                                </label>
                            </div>

                            <div class="controls margin-top">
                                @php $selected_providers = $providers_override ? $meta['context_providers'] : $globals['context_providers']; @endphp

                                @forelse ($provider_catalogue as $provider_key => $provider)
                                    <label class="checkbox" for="mb_provider_{{ md5($provider_key) }}">
                                        <input type="checkbox" name="context_providers[]" value="{{ $provider_key }}" id="mb_provider_{{ md5($provider_key) }}" @if (in_array($provider_key, $selected_providers))checked="checked"@endif>
                                        {{ $provider->label() }} <code class="aichatpanel-key">{{ $provider_key }}</code>
                                    </label>
                                @empty
                                    <p class="text-help">{{ __('No context providers are registered.') }}</p>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="form-group margin-top">
                        <div class="col-sm-6 col-sm-offset-3">
                            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
