<?php

namespace Modules\AiChatPanel\Services\Tools;

use Modules\AiChatPanel\Entities\ToolCall;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Settings;

/**
 * Collects tools from every module and decides which of them the model is
 * allowed to see and run.
 *
 * The safety rules live here rather than in the individual tools, so a
 * third-party tool author cannot accidentally opt out of them:
 *
 *   - a tool is only offered if it is admin-enabled, user-permitted and
 *     relevant;
 *   - authorisation is re-checked at execution time, because the client is not
 *     trusted and the model's requested arguments are not trusted either;
 *   - write tools do not execute without a confirmation token that this class
 *     issued;
 *   - every attempt is audited, including the blocked ones.
 *
 * Discovering zero tools is a normal state.
 */
class ToolRegistry
{
    const FILTER = 'aichatpanel.tools';

    /**
     * Null only when listing the catalogue for the admin settings screen,
     * where there is no conversation in play.
     *
     * @var PanelContext|null
     */
    protected $context;

    /** @var Tool[]|null */
    protected $all = null;

    /**
     * @param PanelContext|null $context
     */
    public function __construct($context = null)
    {
        $this->context = $context;
    }

    /**
     * Every tool any module offers, keyed by name, before any filtering.
     *
     * @return Tool[]
     */
    public function all()
    {
        if ($this->all !== null) {
            return $this->all;
        }

        $tools = $this->builtin();

        try {
            $tools = \Eventy::filter(self::FILTER, $tools, $this->context);
        } catch (\Exception $e) {
            \Helper::logException($e, '[AiChatPanel] A module threw while registering tools: ');
        }

        $this->all = [];

        if (!is_array($tools)) {
            return $this->all;
        }

        foreach ($tools as $tool) {
            if (!$tool instanceof Tool) {
                \Log::warning('[AiChatPanel] Ignoring a tool that does not implement the Tool interface: '
                    .(is_object($tool) ? get_class($tool) : gettype($tool)));
                continue;
            }

            $name = $tool->name();

            // Endpoints reject function names outside this character set, and a
            // bad name would fail the whole request, not just this tool.
            if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $name)) {
                \Log::warning('[AiChatPanel] Ignoring tool with an invalid name: '.$name);
                continue;
            }

            if (isset($this->all[$name])) {
                \Log::warning('[AiChatPanel] Duplicate tool name "'.$name.'"; keeping the first registration.');
                continue;
            }

            $this->all[$name] = $tool;
        }

        ksort($this->all);

        return $this->all;
    }

    /**
     * The tools that may be put in front of the model right now.
     *
     * @return Tool[]
     */
    public function available()
    {
        $enabled_names = $this->context->setting('tools_enabled');

        if (!is_array($enabled_names)) {
            $enabled_names = [];
        }

        // Installs configured before the builtins were renamed still hold the
        // old dotted names.
        $enabled_names = array_map([__CLASS__, 'canonicalName'], $enabled_names);

        $writes_allowed = (bool) Settings::get('write_tools_enabled');

        $available = [];

        foreach ($this->all() as $name => $tool) {
            if (!in_array($name, $enabled_names)) {
                continue;
            }

            if (self::isWrite($tool) && !$writes_allowed) {
                continue;
            }

            try {
                if (!$tool->authorize($this->context)) {
                    continue;
                }

                if (!$tool->isRelevant($this->context)) {
                    continue;
                }
            } catch (\Exception $e) {
                \Helper::logException($e, '[AiChatPanel] Tool "'.$name.'" threw while being filtered: ');
                continue;
            }

            $available[$name] = $tool;
        }

        return $available;
    }

    /**
     * The "tools" parameter of the chat completion request.
     *
     * @return array
     */
    public function toApiDefinitions()
    {
        $definitions = [];
        $available = $this->available();

        foreach ($this->wireNames($available) as $wire => $name) {
            $tool = $available[$name];

            try {
                $definitions[] = [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $wire,
                        'description' => $tool->description(),
                        'parameters'  => $this->normaliseSchema($tool->parameters()),
                    ],
                ];
            } catch (\Exception $e) {
                \Helper::logException($e, '[AiChatPanel] Tool "'.$name.'" threw while describing itself: ');
            }
        }

        return $definitions;
    }

    /**
     * The name a tool is offered under on the wire.
     *
     * OpenAI and Anthropic both require ^[a-zA-Z0-9_-]{1,64}$ for a function
     * name and reject the whole request — not just the offending tool — when it
     * does not match. Local endpoints are lenient, which is exactly why this has
     * to be enforced here rather than trusted to the tool author: a module
     * registering "acme.do_thing" through the aichatpanel.tools filter must not
     * be able to break every completion.
     *
     * @param string $name
     *
     * @return string
     */
    public static function apiName($name)
    {
        return substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $name), 0, 64);
    }

    /**
     * Old dotted names of the builtin tools, mapped to what they are called now.
     *
     * tools_enabled and write_tools_autorun are stored per install, so an
     * upgrade would otherwise silently turn every configured tool off.
     *
     * @param string $name
     *
     * @return string
     */
    public static function canonicalName($name)
    {
        $legacy = [
            'conversation.list_customer_conversations' => 'conversation_list_customer_conversations',
            'conversation.get'                         => 'conversation_get',
            'conversation.get_drafts'                  => 'conversation_get_drafts',
            'conversation.add_note'                    => 'conversation_add_note',
            'conversation.set_status'                  => 'conversation_set_status',
            'conversation.create_draft_reply'          => 'conversation_create_draft_reply',
            'conversation.update_draft'                => 'conversation_update_draft',
            'customer.get'                             => 'customer_get',
        ];

        return isset($legacy[$name]) ? $legacy[$name] : $name;
    }

    /**
     * wire name => internal name, for one set of tools.
     *
     * Sanitising can collide two distinct names ("a.b" and "a_b"), which would
     * silently route one tool's calls to the other. The loser gets a numeric
     * suffix instead.
     *
     * @param Tool[] $tools
     *
     * @return array
     */
    protected function wireNames(array $tools)
    {
        $map = [];

        foreach (array_keys($tools) as $name) {
            $wire = self::apiName($name);

            if (isset($map[$wire])) {
                $stem = substr($wire, 0, 62);

                for ($suffix = 2; isset($map[$stem.'_'.$suffix]); $suffix++) {
                    // Find the first free one.
                }

                $wire = $stem.'_'.$suffix;
            }

            $map[$wire] = $name;
        }

        return $map;
    }

    /**
     * Look up a tool the model asked for.
     *
     * Deliberately searches available() and not all(): a tool that is disabled
     * or not permitted must be indistinguishable from one that does not exist.
     *
     * The old dotted names are accepted too. A chat that ran before the builtins
     * were renamed still holds them in its stored tool_calls, and that history is
     * replayed to the model verbatim, so the model reads its own past calls in
     * the old spelling and asks for it again. Refusing that with "unknown tool"
     * broke every pre-rename chat.
     *
     * @param string $name
     *
     * @return Tool|null
     */
    public function find($name)
    {
        $available = $this->available();

        // The model answers with the name it was given, which is the sanitised
        // one whenever a tool's own name is not wire-safe.
        $wire_names = $this->wireNames($available);

        foreach ([$name, self::canonicalName($name)] as $candidate) {
            if (isset($available[$candidate])) {
                return $available[$candidate];
            }

            if (isset($wire_names[$candidate])) {
                return $available[$wire_names[$candidate]];
            }
        }

        return null;
    }

    /**
     * The name a tool is currently offered to the model under.
     *
     * Falls back to the sanitised name for a tool that is not in available(),
     * which is the name it would be offered under if it were.
     *
     * @param Tool $tool
     *
     * @return string
     */
    public function wireNameFor(Tool $tool)
    {
        $name = $tool->name();

        foreach ($this->wireNames($this->available()) as $wire => $internal) {
            if ($internal === $name) {
                return $wire;
            }
        }

        return self::apiName($name);
    }

    /**
     * Decode and validate the arguments the model sent.
     *
     * @param Tool   $tool
     * @param string $raw_arguments
     *
     * @return array [bool $ok, array $arguments, string $error]
     */
    public function validateArguments(Tool $tool, $raw_arguments)
    {
        $raw_arguments = trim((string) $raw_arguments);

        // Absent arguments are legitimate for a tool that takes none.
        if ($raw_arguments === '' || $raw_arguments === 'null') {
            $decoded = [];
        } else {
            $decoded = json_decode($raw_arguments, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [false, [], 'Arguments were not valid JSON: '.json_last_error_msg()
                    .'. Send the arguments again as a single valid JSON object.'];
            }
        }

        if ($decoded === null) {
            $decoded = [];
        }

        if (!is_array($decoded)) {
            return [false, [], 'Arguments must be a JSON object.'];
        }

        $validator = new SchemaValidator();
        list($ok, $coerced, $errors) = $validator->validate($decoded, $this->normaliseSchema($tool->parameters()));

        if (!$ok) {
            return [false, [], 'Invalid arguments: '.implode('; ', $errors).'.'];
        }

        return [true, $coerced, ''];
    }

    /**
     * Run a tool the model asked for, with every check applied.
     *
     * Never throws: every failure comes back as a ToolResult the model can read.
     *
     * @param string $name
     * @param string $raw_arguments
     * @param array  $options       ['confirmed' => bool, 'chat_id' => int|null]
     *
     * @return ToolResult
     */
    public function execute($name, $raw_arguments, array $options = [])
    {
        $confirmed = !empty($options['confirmed']);
        $chat_id = isset($options['chat_id']) ? $options['chat_id'] : null;

        $tool = $this->find($name);

        if (!$tool) {
            // No audit row: nothing was attempted against helpdesk data, and an
            // unknown name is usually the model hallucinating.
            return ToolResult::error(
                'Unknown tool "'.$name.'". Only call tools that were provided to you in this request.'
            );
        }

        list($ok, $arguments, $error) = $this->validateArguments($tool, $raw_arguments);

        if (!$ok) {
            $this->audit($tool, [], ToolCall::STATUS_DENIED, $chat_id, null, $error);

            return ToolResult::error($error);
        }

        // Re-check authorisation at execution time. available() already did,
        // but the request may have been replayed, and a write may be arriving
        // through the confirmation route long after the tool list was built.
        try {
            if (!$tool->authorize($this->context)) {
                $this->audit($tool, $arguments, ToolCall::STATUS_DENIED, $chat_id, null,
                    'User is not permitted to run this tool');

                return ToolResult::error('You are not permitted to use this tool for this conversation.');
            }
        } catch (\Exception $e) {
            \Helper::logException($e, '[AiChatPanel] Tool "'.$name.'" threw while authorising: ');
            $this->audit($tool, $arguments, ToolCall::STATUS_DENIED, $chat_id, null, 'Authorisation check failed');

            return ToolResult::error('This tool could not be authorised.');
        }

        if (self::isWrite($tool) && !$confirmed && !$this->mayAutoRun($tool)) {
            // Should be unreachable: the agent loop pauses for confirmation
            // before getting here. Refusing anyway means a bug in the loop
            // cannot become an unconfirmed write.
            $this->audit($tool, $arguments, ToolCall::STATUS_DENIED, $chat_id, null,
                'Write tool reached execution without confirmation');

            \Log::warning('[AiChatPanel] Blocked an unconfirmed write tool: '.$name);

            return ToolResult::error('This action needs to be confirmed by the user before it can run.');
        }

        $started = microtime(true);

        try {
            $result = $tool->handle($arguments, $this->context);

            if (!$result instanceof ToolResult) {
                throw new \Exception('Tool did not return a ToolResult');
            }
        } catch (ToolException $e) {
            $this->audit($tool, $arguments, ToolCall::STATUS_FAILED, $chat_id,
                $this->elapsed($started), $e->getMessage());

            return ToolResult::error($e->getMessage(), $e->getDetails());
        } catch (\Exception $e) {
            \Helper::logException($e, '[AiChatPanel] Tool "'.$name.'" failed: ');

            $this->audit($tool, $arguments, ToolCall::STATUS_FAILED, $chat_id,
                $this->elapsed($started), $e->getMessage());

            // The model gets a generic message: an exception string can carry
            // internals that do not belong in a prompt.
            return ToolResult::error('The tool failed to run. Do not retry it; tell the user it is unavailable.');
        }

        $this->audit(
            $tool,
            $arguments,
            $result->ok ? ToolCall::STATUS_OK : ToolCall::STATUS_FAILED,
            $chat_id,
            $this->elapsed($started),
            $result->ok ? '' : $result->error,
            $result->summary
        );

        return $result;
    }

    /**
     * Whether the admin has explicitly named this write tool as auto-runnable.
     *
     * There is deliberately no global "trust all writes" switch, and
     * create_draft_reply can never be on this list.
     *
     * @param Tool $tool
     *
     * @return bool
     */
    public function mayAutoRun(Tool $tool)
    {
        if (!self::isWrite($tool)) {
            return true;
        }

        if (in_array($tool->name(), self::neverAutoRun())) {
            return false;
        }

        $autorun = Settings::get('write_tools_autorun');

        if (!is_array($autorun)) {
            return false;
        }

        return in_array($tool->name(), array_map([__CLASS__, 'canonicalName'], $autorun));
    }

    /**
     * Whether a tool changes data.
     *
     * Reads mode() off the interface rather than a helper on AbstractTool, so
     * a tool implementing Tool directly is judged the same way. Anything that
     * is not explicitly MODE_READ counts as a write: a typo in mode() must fail
     * towards confirmation, not away from it.
     *
     * @param Tool $tool
     *
     * @return bool
     */
    public static function isWrite(Tool $tool)
    {
        try {
            return $tool->mode() !== Tool::MODE_READ;
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Write tools that must always be confirmed, whatever the admin configures.
     *
     * Drafting a customer-facing message is the one action where an unattended
     * mistake ends up in front of a customer, so it is not negotiable. Replacing
     * the text of a draft is the same risk arriving from the other direction: it
     * can overwrite what the agent wrote, so it is on the list too.
     *
     * @return array
     */
    public static function neverAutoRun()
    {
        return [
            'conversation_create_draft_reply',
            'conversation_update_draft',
        ];
    }

    /**
     * Every registered tool, for the admin settings screen.
     *
     * The settings page is not about any one conversation, so the filter is
     * called with a NULL context. Tool authors must tolerate that: return your
     * tools unconditionally and do not touch $context. Only name(),
     * description() and mode() are read here — authorize() and isRelevant()
     * are never called with a null context.
     *
     * @return Tool[]
     */
    public static function catalogue()
    {
        $tools = (new static(null))->builtin();

        try {
            $tools = \Eventy::filter(self::FILTER, $tools, null);
        } catch (\Exception $e) {
            \Helper::logException($e, '[AiChatPanel] A module threw while listing tools for the settings page: ');
        }

        $catalogue = [];

        foreach ((array) $tools as $tool) {
            if (!$tool instanceof Tool) {
                continue;
            }

            try {
                $catalogue[$tool->name()] = $tool;
            } catch (\Exception $e) {
                continue;
            }
        }

        ksort($catalogue);

        return $catalogue;
    }

    /**
     * The tools this module ships. Kept in one place so they read as reference
     * implementations rather than as privileged built-ins — they go through
     * exactly the same registry as a third-party tool.
     *
     * @return Tool[]
     */
    protected function builtin()
    {
        return [
            new Builtin\ListCustomerConversationsTool(),
            new Builtin\GetConversationTool(),
            new Builtin\GetCustomerTool(),
            new Builtin\GetDraftsTool(),
            new Builtin\AddNoteTool(),
            new Builtin\SetStatusTool(),
            new Builtin\CreateDraftReplyTool(),
            new Builtin\UpdateDraftTool(),
        ];
    }

    /**
     * Endpoints are strict about the parameters schema being an object schema
     * with a properties map; a tool returning something looser would fail the
     * whole request rather than just itself.
     *
     * @param mixed $schema
     *
     * @return array
     */
    protected function normaliseSchema($schema)
    {
        if (!is_array($schema)) {
            $schema = [];
        }

        if (empty($schema['type'])) {
            $schema['type'] = 'object';
        }

        if (!isset($schema['properties'])) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    /**
     * @param float $started
     *
     * @return int
     */
    protected function elapsed($started)
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * @return void
     */
    protected function audit(Tool $tool, array $arguments, $status, $chat_id, $duration_ms, $error = '', $summary = '')
    {
        ToolCall::record([
            'user_id'         => $this->context->user->id,
            'conversation_id' => $this->context->conversation->id,
            'mailbox_id'      => $this->context->mailbox ? $this->context->mailbox->id : null,
            'chat_id'         => $chat_id,
            'tool'            => $tool->name(),
            'mode'            => self::isWrite($tool) ? ToolCall::MODE_WRITE : ToolCall::MODE_READ,
            'status'          => $status,
            'arguments'       => $arguments,
            'result'          => $summary ?: null,
            'error'           => $error ?: null,
            'duration_ms'     => $duration_ms,
        ]);
    }
}
