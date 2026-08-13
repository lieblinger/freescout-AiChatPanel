@php
    $prompt_shortcuts = $settings['aichatpanel.prompt_shortcuts'];
    if (is_array($prompt_shortcuts)) {
        $prompt_shortcuts = implode("\n", $prompt_shortcuts);
    }
@endphp

<form class="form-horizontal margin-top margin-bottom" method="POST" action="">
    {{ csrf_field() }}

    <h3 class="subheader">{{ __('Connection') }}</h3>

    <div class="form-group">
        <label for="aichatpanel_enabled" class="col-sm-2 control-label">{{ __('Enabled') }}</label>

        <div class="col-sm-6">
            <div class="controls">
                <div class="onoffswitch-wrap">
                    <div class="onoffswitch">
                        <input type="checkbox" name="settings[aichatpanel.enabled]" value="1" id="aichatpanel_enabled" class="onoffswitch-checkbox" @if (old('settings[aichatpanel.enabled]', $settings['aichatpanel.enabled']))checked="checked"@endif>
                        <label class="onoffswitch-label" for="aichatpanel_enabled"></label>
                    </div>
                </div>
            </div>
            <p class="help-block">{{ __('Turns the chat panel off everywhere without deactivating the module.') }}</p>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings[aichatpanel.base_url]') ? ' has-error' : '' }}">
        <label for="aichatpanel_base_url" class="col-sm-2 control-label">{{ __('Endpoint URL') }}</label>

        <div class="col-sm-6">
            <input id="aichatpanel_base_url" type="text" class="form-control" name="settings[aichatpanel.base_url]" value="{{ old('settings[aichatpanel.base_url]', $settings['aichatpanel.base_url']) }}" placeholder="http://localhost:8000" maxlength="255">
            <p class="help-block">{{ __('Base URL of an OpenAI-compatible server. The module appends /v1/chat/completions itself, so a trailing /v1 is optional.') }}</p>
            @include('partials/field_error', ['field'=>'settings.aichatpanel.base_url'])
        </div>
    </div>

    <div class="form-group">
        <label for="aichatpanel_api_key" class="col-sm-2 control-label">{{ __('API Key') }}</label>

        <div class="col-sm-6">
            <input id="aichatpanel_api_key" type="password" class="form-control" name="settings[aichatpanel.api_key]" value="{{ $settings['aichatpanel.api_key'] }}" autocomplete="new-password" maxlength="255">
            <p class="help-block">{{ __('Stored encrypted and never sent to the browser. Leave the masked value untouched to keep the current key. Clear the field to remove it.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Connection') }}</label>

        <div class="col-sm-6">
            {{-- Route URLs travel as data attributes rather than through laroute:
                 one less generated file to keep in step, and it works whether or
                 not freescout:module-laroute has been run. --}}
            <button type="button" class="btn btn-default" id="aichatpanel-test-connection"
                    data-test-url="{{ route('aichatpanel.settings.test_connection') }}"
                    data-models-url="{{ route('aichatpanel.settings.models') }}">{{ __('Test connection') }}</button>
            <div id="aichatpanel-test-result" class="margin-top hidden"></div>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings[aichatpanel.request_timeout]') ? ' has-error' : '' }}">
        <label for="aichatpanel_request_timeout" class="col-sm-2 control-label">{{ __('Request timeout') }}</label>

        <div class="col-sm-6">
            <div class="flexy">
                <input id="aichatpanel_request_timeout" type="number" min="5" max="{{ \Modules\AiChatPanel\Services\Settings::limit('max_request_timeout', 600) }}" class="form-control input-sized" name="settings[aichatpanel.request_timeout]" value="{{ old('settings[aichatpanel.request_timeout]', $settings['aichatpanel.request_timeout']) }}">
                <span class="text-help margin-left-10">{{ __('seconds') }}</span>
            </div>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings[aichatpanel.connect_timeout]') ? ' has-error' : '' }}">
        <label for="aichatpanel_connect_timeout" class="col-sm-2 control-label">{{ __('Connect timeout') }}</label>

        <div class="col-sm-6">
            <div class="flexy">
                <input id="aichatpanel_connect_timeout" type="number" min="1" max="120" class="form-control input-sized" name="settings[aichatpanel.connect_timeout]" value="{{ old('settings[aichatpanel.connect_timeout]', $settings['aichatpanel.connect_timeout']) }}">
                <span class="text-help margin-left-10">{{ __('seconds') }}</span>
            </div>
        </div>
    </div>

    <h3 class="subheader">{{ __('Model') }}</h3>

    <div class="form-group{{ $errors->has('settings[aichatpanel.default_model]') ? ' has-error' : '' }}">
        <label for="aichatpanel_default_model" class="col-sm-2 control-label">{{ __('Default model') }}</label>

        <div class="col-sm-6">
            <input id="aichatpanel_default_model" type="text" class="form-control" name="settings[aichatpanel.default_model]" value="{{ old('settings[aichatpanel.default_model]', $settings['aichatpanel.default_model']) }}" maxlength="191">
            <p class="help-block">{{ __('Used when a user has not picked a model yet.') }}</p>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings[aichatpanel.allowed_models]') ? ' has-error' : '' }}">
        <label for="aichatpanel_allowed_models" class="col-sm-2 control-label">{{ __('Allowed models') }}</label>

        <div class="col-sm-6">
            <textarea id="aichatpanel_allowed_models" class="form-control" rows="4" name="settings[aichatpanel.allowed_models]">{{ old('settings[aichatpanel.allowed_models]', $settings['aichatpanel.allowed_models']) }}</textarea>
            <p class="help-block">
                {{ __('One model name per line. Users can only pick from this list. Leave empty to offer every model the endpoint reports.') }}
                <a href="#" id="aichatpanel-load-models">{{ __('Load from endpoint') }}</a>
            </p>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings[aichatpanel.temperature]') ? ' has-error' : '' }}">
        <label for="aichatpanel_temperature" class="col-sm-2 control-label">{{ __('Temperature') }}</label>

        <div class="col-sm-6">
            <input id="aichatpanel_temperature" type="number" step="0.1" min="0" max="2" class="form-control input-sized" name="settings[aichatpanel.temperature]" value="{{ old('settings[aichatpanel.temperature]', $settings['aichatpanel.temperature']) }}">
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings[aichatpanel.max_response_tokens]') ? ' has-error' : '' }}">
        <label for="aichatpanel_max_response_tokens" class="col-sm-2 control-label">{{ __('Max response tokens') }}</label>

        <div class="col-sm-6">
            <input id="aichatpanel_max_response_tokens" type="number" min="64" class="form-control input-sized" name="settings[aichatpanel.max_response_tokens]" value="{{ old('settings[aichatpanel.max_response_tokens]', $settings['aichatpanel.max_response_tokens']) }}">
            <p class="help-block">{{ __('Reasoning models spend this budget on their chain of thought before they answer. Setting it too low produces empty replies.') }}</p>
        </div>
    </div>

    @if (!empty($settings['aichatpanel.model_tool_support']) && is_array($settings['aichatpanel.model_tool_support']))
        <div class="form-group">
            <label class="col-sm-2 control-label">{{ __('Tool support') }}</label>

            <div class="col-sm-6">
                <div class="controls">
                    @foreach ($settings['aichatpanel.model_tool_support'] as $model_name => $supported)
                        <label class="checkbox" for="aichatpanel_tool_support_{{ md5($model_name) }}">
                            <input type="checkbox" name="settings[aichatpanel.model_tool_support][{{ $model_name }}]" value="1" id="aichatpanel_tool_support_{{ md5($model_name) }}" @if ($supported)checked="checked"@endif>
                            {{ $model_name }}
                        </label>
                    @endforeach
                </div>
                <p class="help-block">{{ __('Whether each model can call tools. Filled in automatically by the connection test; correct it here if the probe got it wrong. Unlisted models are probed on first use.') }}</p>
            </div>
        </div>
    @endif

    <h3 class="subheader">{{ __('Context') }}</h3>

    <div class="form-group{{ $errors->has('settings[aichatpanel.max_context_tokens]') ? ' has-error' : '' }}">
        <label for="aichatpanel_max_context_tokens" class="col-sm-2 control-label">{{ __('Max context tokens') }}</label>

        <div class="col-sm-6">
            <input id="aichatpanel_max_context_tokens" type="number" min="500" class="form-control input-sized" name="settings[aichatpanel.max_context_tokens]" value="{{ old('settings[aichatpanel.max_context_tokens]', $settings['aichatpanel.max_context_tokens']) }}">
            <p class="help-block">{{ __('Budget for the conversation history and extra context. When a thread exceeds it the oldest messages are dropped and the panel says so.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label for="aichatpanel_include_notes" class="col-sm-2 control-label">{{ __('Include internal notes') }}</label>

        <div class="col-sm-6">
            <div class="controls">
                <div class="onoffswitch-wrap">
                    <div class="onoffswitch">
                        <input type="checkbox" name="settings[aichatpanel.include_notes]" value="1" id="aichatpanel_include_notes" class="onoffswitch-checkbox" @if (old('settings[aichatpanel.include_notes]', $settings['aichatpanel.include_notes']))checked="checked"@endif>
                        <label class="onoffswitch-label" for="aichatpanel_include_notes"></label>
                    </div>
                </div>
            </div>
            <p class="help-block">{{ __('Default for all mailboxes. Each mailbox can override it.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label for="aichatpanel_send_personal_data" class="col-sm-2 control-label">{{ __('Send personal data') }}</label>

        <div class="col-sm-6">
            <div class="controls">
                <div class="onoffswitch-wrap">
                    <div class="onoffswitch">
                        <input type="checkbox" name="settings[aichatpanel.send_personal_data]" value="1" id="aichatpanel_send_personal_data" class="onoffswitch-checkbox" @if (old('settings[aichatpanel.send_personal_data]', $settings['aichatpanel.send_personal_data']))checked="checked"@endif>
                        <label class="onoffswitch-label" for="aichatpanel_send_personal_data"></label>
                    </div>
                </div>
            </div>
            <p class="help-block">{{ __('Allows postal addresses, phone numbers, social profiles, customer notes and the agent\'s own contact details to be sent to the endpoint, so the assistant can quote them instead of guessing. Turning this off does not restrict anyone in FreeScout — all of it is already visible in the conversation sidebar. It restricts what leaves your server. Each mailbox can override it.') }}</p>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings[aichatpanel.system_prompt]') ? ' has-error' : '' }}">
        <label for="aichatpanel_system_prompt" class="col-sm-2 control-label">{{ __('System prompt') }}</label>

        <div class="col-sm-6">
            <textarea id="aichatpanel_system_prompt" class="form-control" rows="6" name="settings[aichatpanel.system_prompt]">{{ old('settings[aichatpanel.system_prompt]', $settings['aichatpanel.system_prompt']) }}</textarea>
            <p class="help-block">{{ __('Added to the built-in instructions. Leave empty to use the defaults only. Mailboxes can append their own text.') }}</p>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings[aichatpanel.prompt_shortcuts]') ? ' has-error' : '' }}">
        <label for="aichatpanel_prompt_shortcuts" class="col-sm-2 control-label">{{ __('Prompt shortcuts') }}</label>

        <div class="col-sm-6">
            <textarea id="aichatpanel_prompt_shortcuts" class="form-control" rows="6" name="settings[aichatpanel.prompt_shortcuts]">{{ old('settings[aichatpanel.prompt_shortcuts]', $prompt_shortcuts) }}</textarea>
            <p class="help-block">{{ __('One per line. Shown as buttons above the chat input. They only prefill the input; the user still sends.') }}</p>
        </div>
    </div>

    <h3 class="subheader">{{ __('Context providers') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Enabled providers') }}</label>

        <div class="col-sm-6">
            <div class="controls">
                @php $enabled_providers = is_array($settings['aichatpanel.context_providers']) ? $settings['aichatpanel.context_providers'] : []; @endphp

                @forelse ($provider_catalogue as $provider_key => $provider)
                    <label class="checkbox" for="aichatpanel_provider_{{ md5($provider_key) }}">
                        <input type="checkbox" name="settings[aichatpanel.context_providers][]" value="{{ $provider_key }}" id="aichatpanel_provider_{{ md5($provider_key) }}" @if (in_array($provider_key, $enabled_providers))checked="checked"@endif>
                        {{ $provider->label() }} <code class="aichatpanel-key">{{ $provider_key }}</code>
                    </label>
                @empty
                    <p class="text-help">{{ __('No context providers are registered.') }}</p>
                @endforelse
            </div>
            <p class="help-block">{{ __('Read-only blocks appended to every request. Other modules can register their own; see the module documentation.') }}</p>
        </div>
    </div>

    <h3 class="subheader">{{ __('Tools') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Enabled tools') }}</label>

        <div class="col-sm-6">
            <div class="controls">
                @php $enabled_tools = is_array($settings['aichatpanel.tools_enabled']) ? $settings['aichatpanel.tools_enabled'] : []; @endphp

                @forelse ($tool_catalogue as $tool_name => $tool)
                    <label class="checkbox" for="aichatpanel_tool_{{ md5($tool_name) }}">
                        <input type="checkbox" name="settings[aichatpanel.tools_enabled][]" value="{{ $tool_name }}" id="aichatpanel_tool_{{ md5($tool_name) }}" @if (in_array($tool_name, $enabled_tools))checked="checked"@endif>
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
            <p class="help-block">{{ __('A tool is only offered to the model when it is enabled here, permitted for the user, and relevant to the conversation.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label for="aichatpanel_write_tools_enabled" class="col-sm-2 control-label">{{ __('Allow write tools') }}</label>

        <div class="col-sm-6">
            <div class="controls">
                <div class="onoffswitch-wrap">
                    <div class="onoffswitch">
                        <input type="checkbox" name="settings[aichatpanel.write_tools_enabled]" value="1" id="aichatpanel_write_tools_enabled" class="onoffswitch-checkbox" @if (old('settings[aichatpanel.write_tools_enabled]', $settings['aichatpanel.write_tools_enabled']))checked="checked"@endif>
                        <label class="onoffswitch-label" for="aichatpanel_write_tools_enabled"></label>
                    </div>
                </div>
            </div>
            <p class="help-block">{{ __('Master switch. With this off, no tool that changes data is offered to the model, whatever is ticked above.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Run without confirmation') }}</label>

        <div class="col-sm-6">
            <div class="controls">
                @php $autorun = is_array($settings['aichatpanel.write_tools_autorun']) ? $settings['aichatpanel.write_tools_autorun'] : []; @endphp

                @php $any_autorunnable = false; @endphp
                @foreach ($tool_catalogue as $tool_name => $tool)
                    @if ($tool->mode() === \Modules\AiChatPanel\Services\Tools\Tool::MODE_WRITE && !in_array($tool_name, \Modules\AiChatPanel\Services\Tools\ToolRegistry::neverAutoRun()))
                        @php $any_autorunnable = true; @endphp
                        <label class="checkbox" for="aichatpanel_autorun_{{ md5($tool_name) }}">
                            <input type="checkbox" name="settings[aichatpanel.write_tools_autorun][]" value="{{ $tool_name }}" id="aichatpanel_autorun_{{ md5($tool_name) }}" @if (in_array($tool_name, $autorun))checked="checked"@endif>
                            <code class="aichatpanel-key">{{ $tool_name }}</code>
                        </label>
                    @endif
                @endforeach

                @if (!$any_autorunnable)
                    <p class="text-help">{{ __('No write tools can be exempted from confirmation.') }}</p>
                @endif
            </div>
            <p class="help-block">
                {{ __('Named write tools that may run without asking the user. Leave empty unless you have a reason. There is deliberately no option to exempt all write tools, and writing or changing a draft reply can never be exempted.') }}
            </p>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings[aichatpanel.max_tool_iterations]') ? ' has-error' : '' }}">
        <label for="aichatpanel_max_tool_iterations" class="col-sm-2 control-label">{{ __('Max tool steps') }}</label>

        <div class="col-sm-6">
            <input id="aichatpanel_max_tool_iterations" type="number" min="1" max="{{ \Modules\AiChatPanel\Services\Settings::limit('max_tool_iterations', 10) }}" class="form-control input-sized" name="settings[aichatpanel.max_tool_iterations]" value="{{ old('settings[aichatpanel.max_tool_iterations]', $settings['aichatpanel.max_tool_iterations']) }}">
            <p class="help-block">{{ __('How many times the assistant may call tools and think again within a single message.') }}</p>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings[aichatpanel.max_tool_seconds]') ? ' has-error' : '' }}">
        <label for="aichatpanel_max_tool_seconds" class="col-sm-2 control-label">{{ __('Max tool time') }}</label>

        <div class="col-sm-6">
            <div class="flexy">
                <input id="aichatpanel_max_tool_seconds" type="number" min="5" max="{{ \Modules\AiChatPanel\Services\Settings::limit('max_tool_seconds', 120) }}" class="form-control input-sized" name="settings[aichatpanel.max_tool_seconds]" value="{{ old('settings[aichatpanel.max_tool_seconds]', $settings['aichatpanel.max_tool_seconds']) }}">
                <span class="text-help margin-left-10">{{ __('seconds') }}</span>
            </div>
        </div>
    </div>

    <h3 class="subheader">{{ __('Limits') }}</h3>

    <div class="form-group{{ $errors->has('settings[aichatpanel.rate_limit_completions]') ? ' has-error' : '' }}">
        <label for="aichatpanel_rate_limit_completions" class="col-sm-2 control-label">{{ __('Messages per minute') }}</label>

        <div class="col-sm-6">
            <input id="aichatpanel_rate_limit_completions" type="number" min="1" class="form-control input-sized" name="settings[aichatpanel.rate_limit_completions]" value="{{ old('settings[aichatpanel.rate_limit_completions]', $settings['aichatpanel.rate_limit_completions']) }}">
            <p class="help-block">{{ __('Per user.') }}</p>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings[aichatpanel.rate_limit_tools]') ? ' has-error' : '' }}">
        <label for="aichatpanel_rate_limit_tools" class="col-sm-2 control-label">{{ __('Tool runs per minute') }}</label>

        <div class="col-sm-6">
            <input id="aichatpanel_rate_limit_tools" type="number" min="1" class="form-control input-sized" name="settings[aichatpanel.rate_limit_tools]" value="{{ old('settings[aichatpanel.rate_limit_tools]', $settings['aichatpanel.rate_limit_tools']) }}">
            <p class="help-block">{{ __('Per user.') }}</p>
        </div>
    </div>

    <h3 class="subheader">{{ __('Retention and logging') }}</h3>

    <div class="form-group{{ $errors->has('settings[aichatpanel.retention_days]') ? ' has-error' : '' }}">
        <label for="aichatpanel_retention_days" class="col-sm-2 control-label">{{ __('Keep chats for') }}</label>

        <div class="col-sm-6">
            <div class="flexy">
                <input id="aichatpanel_retention_days" type="number" min="0" class="form-control input-sized" name="settings[aichatpanel.retention_days]" value="{{ old('settings[aichatpanel.retention_days]', $settings['aichatpanel.retention_days']) }}">
                <span class="text-help margin-left-10">{{ __('days') }}</span>
            </div>
            <p class="help-block">{{ __('0 keeps them forever. Chats are always deleted with their conversation.') }}</p>
        </div>
    </div>

    <div class="form-group{{ $errors->has('settings[aichatpanel.audit_retention_days]') ? ' has-error' : '' }}">
        <label for="aichatpanel_audit_retention_days" class="col-sm-2 control-label">{{ __('Keep the tool audit log for') }}</label>

        <div class="col-sm-6">
            <div class="flexy">
                <input id="aichatpanel_audit_retention_days" type="number" min="0" class="form-control input-sized" name="settings[aichatpanel.audit_retention_days]" value="{{ old('settings[aichatpanel.audit_retention_days]', $settings['aichatpanel.audit_retention_days']) }}">
                <span class="text-help margin-left-10">{{ __('days') }}</span>
            </div>
            <p class="help-block">{{ __('Kept independently of chat sessions. 0 keeps it forever.') }}</p>
        </div>
    </div>

    <div class="form-group">
        <label for="aichatpanel_log_prompts" class="col-sm-2 control-label">{{ __('Log full prompts') }}</label>

        <div class="col-sm-6">
            <div class="controls">
                <div class="onoffswitch-wrap">
                    <div class="onoffswitch">
                        <input type="checkbox" name="settings[aichatpanel.log_prompts]" value="1" id="aichatpanel_log_prompts" class="onoffswitch-checkbox" @if (old('settings[aichatpanel.log_prompts]', $settings['aichatpanel.log_prompts']))checked="checked"@endif>
                        <label class="onoffswitch-label" for="aichatpanel_log_prompts"></label>
                    </div>
                </div>
            </div>
            <p class="help-block">{{ __('Writes every prompt body to the application log. Prompts contain customer data, so this is off by default. API keys are never logged.') }}</p>
        </div>
    </div>

    <div class="form-group margin-top">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </div>
</form>
