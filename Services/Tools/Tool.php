<?php

namespace Modules\AiChatPanel\Services\Tools;

use Modules\AiChatPanel\Services\PanelContext;

/**
 * A capability any module can expose to the model.
 *
 * This interface is the module's public contract. Adding an optional method to
 * it is a breaking change for implementors, so extend AbstractTool instead of
 * implementing this directly unless you have a reason not to.
 *
 * Register tools on the aichatpanel.tools filter:
 *
 *     \Eventy::addFilter('aichatpanel.tools', function ($tools, $context) {
 *         $tools[] = new \Modules\MyCrm\AiTools\GetContact();
 *         return $tools;
 *     }, 20, 2);
 *
 * See docs/extending.md for a complete worked example.
 *
 * Three rules the registry enforces, so tool authors cannot get them wrong:
 *
 *   - Tools run as the logged-in user. authorize() must delegate to core's
 *     policies and permissions; a tool may never do something the user could
 *     not do themselves in the UI.
 *   - Every MODE_WRITE tool is confirmed by a human before it runs.
 *   - Every execution is written to the audit log, including the ones that were
 *     blocked or rejected.
 */
interface Tool
{
    /** Reads data. Runs without confirmation, still permission-checked and logged. */
    const MODE_READ = 'read';

    /** Changes data. Always confirmed in the panel before it runs. */
    const MODE_WRITE = 'write';

    /**
     * Stable unique name, namespaced by module: "crm.get_contact".
     *
     * This is what the model calls and what the admin toggles, so renaming it
     * resets the admin's settings. Must match ^[a-zA-Z0-9_.-]{1,64}$ — that is
     * what OpenAI-compatible endpoints accept for a function name.
     *
     * @return string
     */
    public function name();

    /**
     * Description for the model. English, in the imperative, and specific about
     * when to use it — this is prompt text, and vague descriptions are the main
     * reason models pick the wrong tool.
     *
     * Not translated: it is read by the model, not by a person.
     *
     * @return string
     */
    public function description();

    /**
     * JSON Schema for the arguments, as a PHP array. Must be an object schema.
     * Arguments are validated against it before the handler is called.
     *
     * @return array
     */
    public function parameters();

    /**
     * MODE_READ or MODE_WRITE.
     *
     * @return string
     */
    public function mode();

    /**
     * Whether the acting user may run this at all.
     *
     * Called twice: before the tool is offered to the model, and again before
     * it executes. Delegate to core — $context->userCanUpdate(),
     * $user->hasPermission(...), $mailbox->userHasAccess(...) — rather than
     * reimplementing the rules.
     *
     * @param PanelContext $context
     *
     * @return bool
     */
    public function authorize(PanelContext $context);

    /**
     * Whether this tool has anything to offer in this context. Returning false
     * keeps it out of the request payload entirely, which saves tokens and
     * stops the model reaching for something irrelevant.
     *
     * @param PanelContext $context
     *
     * @return bool
     */
    public function isRelevant(PanelContext $context);

    /**
     * One translated sentence describing what running this would do, shown in
     * the confirmation dialog. Only meaningful for write tools.
     *
     * The exact arguments are shown separately, so this should describe the
     * effect, not repeat the parameters.
     *
     * @param array        $arguments Validated arguments.
     * @param PanelContext $context
     *
     * @return string
     */
    public function confirmationLabel(array $arguments, PanelContext $context);

    /**
     * Do the work.
     *
     * Throw ToolException for an expected failure — the message goes back to
     * the model as a structured error so it can recover. Any other exception is
     * logged and turned into a generic error; it never reaches the user as a
     * stack trace.
     *
     * @param array        $arguments Already validated against parameters().
     * @param PanelContext $context
     *
     * @return ToolResult
     */
    public function handle(array $arguments, PanelContext $context);
}
