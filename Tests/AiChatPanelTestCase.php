<?php

namespace Modules\AiChatPanel\Tests;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\AiChatPanel\Services\PanelContext;
use Modules\AiChatPanel\Services\Settings;
use Tests\TestCase;

/**
 * Shared fixtures.
 *
 * Runs against the `testing` connection configured in core/phpunit.xml. No test
 * in this suite talks to a real model: the LLM client is always FakeLlmClient.
 */
abstract class AiChatPanelTestCase extends TestCase
{
    /**
     * Every test runs in a transaction that is rolled back afterwards.
     *
     * Without it the fixtures accumulate across runs and faker eventually
     * collides on a unique column, which shows up as a spurious failure in
     * whichever test happens to run at the time.
     */
    use DatabaseTransactions;

    /** @var User */
    protected $admin;

    /** @var User A plain user WITH access to $mailbox. */
    protected $agent;

    /** @var User A plain user WITHOUT access to $mailbox. */
    protected $outsider;

    /** @var Mailbox */
    protected $mailbox;

    /** @var Customer */
    protected $customer;

    /** @var Conversation */
    protected $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        // Refuse to run against anything but the test database.
        //
        // phpunit.xml's env block is ignored entirely when
        // bootstrap/cache/config.php exists, and freescout:clear-cache leaves
        // the config cached. Without this guard the fixtures below would
        // silently overwrite the live settings and factory rows would land in
        // the real database. Run `php artisan config:clear` first.
        if (config('database.default') !== 'testing') {
            $this->fail(
                'Refusing to run: database.default is "'.config('database.default').'", not "testing". '
                .'The config cache is overriding phpunit.xml. Run `php artisan config:clear` first, '
                .'then `php artisan freescout:clear-cache` afterwards.'
            );
        }

        \Session::start();

        $this->admin = factory(User::class)->create(['role' => User::ROLE_ADMIN]);
        $this->agent = factory(User::class)->create(['role' => User::ROLE_USER]);
        $this->outsider = factory(User::class)->create(['role' => User::ROLE_USER]);

        $this->mailbox = factory(Mailbox::class)->create();
        $this->mailbox->users()->sync([$this->agent->id]);

        $this->customer = factory(Customer::class)->create();

        $this->conversation = factory(Conversation::class)->create([
            'mailbox_id'  => $this->mailbox->id,
            'customer_id' => $this->customer->id,
            'user_id'     => $this->agent->id,
            'state'       => Conversation::STATE_PUBLISHED,
            'status'      => Conversation::STATUS_ACTIVE,
        ]);

        $this->setSettings([
            'enabled'             => true,
            'base_url'            => 'http://llm.invalid',
            'default_model'       => 'fake-model',
            'allowed_models'      => 'fake-model',
            'max_context_tokens'  => 8000,
            'max_tool_iterations' => 4,
            'max_tool_seconds'    => 60,
            'include_notes'       => true,
            'tools_enabled'       => [],
            'context_providers'   => [],
            'write_tools_enabled' => true,
            'write_tools_autorun' => [],
            // Reset: rememberModelToolSupport() persists, and a run that marks
            // a model as tool-incapable would otherwise leak into later tests.
            'model_tool_support'  => [],
        ]);
    }

    protected function tearDown(): void
    {
        // The options table is not covered by the transaction rollback in a
        // useful way for the next test's expectations, and Option caches in
        // process, so reset the cache explicitly.
        \Option::$cache = [];

        parent::tearDown();
    }

    /**
     * @param array $values
     *
     * @return void
     */
    protected function setSettings(array $values)
    {
        foreach ($values as $name => $value) {
            Settings::put($name, $value);
        }

        // Belt and braces: core's Option::set() does not invalidate its own
        // in-process cache, and tests reuse the process.
        \Option::$cache = [];
    }

    /**
     * @param User|null         $user
     * @param Conversation|null $conversation
     *
     * @return PanelContext
     */
    protected function context($user = null, $conversation = null)
    {
        return new PanelContext(
            $conversation ?: $this->conversation->fresh(),
            $user ?: $this->agent
        );
    }

    /**
     * A JSON POST carrying a valid CSRF token.
     *
     * Every panel route sits behind the `web` middleware group, so a request
     * without a token is rejected before the controller sees it.
     *
     * @param string $uri
     * @param array  $data
     *
     * @return \Illuminate\Foundation\Testing\TestResponse
     */
    protected function csrfPost($uri, array $data = [])
    {
        return $this->json('POST', $uri, array_merge($data, ['_token' => csrf_token()]));
    }

    /**
     * Add a published thread to the conversation.
     *
     * @param string $body
     * @param int    $type
     *
     * @return Thread
     */
    protected function addThread($body, $type = Thread::TYPE_CUSTOMER)
    {
        $thread = new Thread();
        $thread->conversation_id = $this->conversation->id;
        $thread->type = $type;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->status = Thread::STATUS_ACTIVE;
        $thread->body = $body;
        $thread->customer_id = $this->customer->id;

        if ($type === Thread::TYPE_CUSTOMER) {
            $thread->source_via = Thread::PERSON_CUSTOMER;
            $thread->created_by_customer_id = $this->customer->id;
        } else {
            $thread->source_via = Thread::PERSON_USER;
            $thread->created_by_user_id = $this->agent->id;
        }

        $thread->source_type = Thread::SOURCE_TYPE_EMAIL;
        $thread->save();

        return $thread;
    }
}
