<?php

namespace Modules\AiChatPanel\Tests;

use App\Customer;
use Modules\AiChatPanel\Services\Context\ContextBuilder;
use Modules\AiChatPanel\Services\Tools\Builtin\GetCustomerTool;

/**
 * Personal data reaching the model: the agent's own details inline, the
 * customer's stored profile through customer.get, and the send_personal_data
 * switch that governs both.
 *
 * The authorisation boundary is core's, not ours — CustomerPolicy for the
 * customer, and the acting user's own record for the agent block, which
 * UserPolicy::view() permits unconditionally. What is asserted here is that we
 * ask core rather than reimplementing it, and that the reduced fallback really
 * is reduced.
 */
class PersonalDataTest extends AiChatPanelTestCase
{
    /**
     * Give the acting agent a full profile.
     *
     * @return void
     */
    protected function fillAgentProfile()
    {
        $this->agent->email = 'agent@example.invalid';
        $this->agent->phone = '+49 30 1234567';
        $this->agent->job_title = 'Support Lead';
        $this->agent->save();
    }

    /**
     * Give the customer every stored field this module reads.
     *
     * @return void
     */
    protected function fillCustomerProfile()
    {
        $this->customer->address = 'Hauptstrasse 5';
        $this->customer->city = 'Berlin';
        $this->customer->state = 'Berlin';
        $this->customer->zip = '10115';
        $this->customer->country = 'DE';
        $this->customer->company = 'Example GmbH';
        $this->customer->job_title = 'Head of Ops';
        $this->customer->notes = 'Prefers to be called in the morning.';

        $this->customer->setPhones([
            ['value' => '+49 170 1111111', 'type' => Customer::PHONE_TYPE_MOBILE],
            ['value' => '+49 30 2222222', 'type' => Customer::PHONE_TYPE_WORK],
        ]);

        $this->customer->setSocialProfiles([
            ['value' => 'example_gmbh', 'type' => Customer::SOCIAL_TYPE_TWITTER],
        ]);

        $this->customer->save();
    }

    // -----------------------------------------------------------------------
    // The agent block
    // -----------------------------------------------------------------------

    public function testTheSystemMessageNamesTheAgentAndTheirContactDetails()
    {
        $this->fillAgentProfile();

        $content = (new ContextBuilder($this->context()))->build(0)['content'];

        $this->assertStringContainsString('You are helping this agent', $content);
        // "my number" must resolve to the agent, not to the customer.
        $this->assertStringContainsString('never the customer', $content);
        $this->assertStringContainsString($this->agent->getFullName(), $content);
        $this->assertStringContainsString('agent@example.invalid', $content);
        $this->assertStringContainsString('+49 30 1234567', $content);
        $this->assertStringContainsString('Support Lead', $content);
    }

    public function testAnAgentWithNoPhoneProducesNoEmptyPhoneRow()
    {
        $this->agent->phone = '';
        $this->agent->job_title = '';
        $this->agent->save();

        $content = (new ContextBuilder($this->context()))->build(0)['content'];

        // An empty "Phone:" row reads to the model as a fact about the phone
        // number, which is worse than the row being absent.
        $this->assertStringNotContainsString('Phone:', $content);
        $this->assertStringNotContainsString('Job title:', $content);
        $this->assertStringContainsString('You are helping this agent', $content);
    }

    public function testTheAgentBlockSurvivesAConversationLongEnoughToTruncate()
    {
        $this->fillAgentProfile();

        // Comfortably past the budget, so the history is dropped.
        for ($i = 0; $i < 40; $i++) {
            $this->addThread('<div>'.str_repeat('Message body number '.$i.'. ', 60).'</div>');
        }

        $this->setSettings(['max_context_tokens' => 1200]);

        $built = (new ContextBuilder($this->context()))->build(0);

        $this->assertTrue($built['truncated'], 'Expected the history to be truncated.');
        $this->assertStringContainsString('+49 30 1234567', $built['content']);
    }

    public function testTheInstructionsForbidAHandWrittenSignOff()
    {
        $content = (new ContextBuilder($this->context()))->build(0)['content'];

        // Substring, not the whole sentence: the wording is tuned in testing
        // and the assertion should survive that.
        $this->assertStringContainsString('Do not end drafts with a sign-off', $content);
    }

    public function testTheAgentBlockDropsToNameOnlyWhenPersonalDataIsOff()
    {
        $this->fillAgentProfile();

        $this->setSettings(['send_personal_data' => false]);

        $content = (new ContextBuilder($this->context()))->build(0)['content'];

        $this->assertStringContainsString($this->agent->getFullName(), $content);
        $this->assertStringNotContainsString('agent@example.invalid', $content);
        $this->assertStringNotContainsString('+49 30 1234567', $content);
        $this->assertStringNotContainsString('Support Lead', $content);
    }

    // -----------------------------------------------------------------------
    // customer.get
    // -----------------------------------------------------------------------

    public function testCustomerGetReturnsTheCompleteStoredProfile()
    {
        $this->fillCustomerProfile();

        $result = (new GetCustomerTool())->handle([], $this->context());

        $this->assertTrue($result->ok);

        $data = $result->data;

        $this->assertEquals('Hauptstrasse 5', $data['address']);
        $this->assertEquals('10115', $data['zip']);
        $this->assertEquals('Berlin', $data['state']);
        $this->assertEquals('Example GmbH', $data['company']);
        $this->assertStringContainsString('morning', $data['notes']);

        // The country is resolved to its full name, not left as the ISO code.
        $this->assertEquals('Germany', $data['country']);

        // Phones carry their type, using core's untranslated names.
        $this->assertEquals(
            [
                ['value' => '+49 170 1111111', 'type' => 'mobile'],
                ['value' => '+49 30 2222222', 'type' => 'work'],
            ],
            $data['phones']
        );

        $this->assertEquals(
            [['value' => 'example_gmbh', 'type' => 'twitter']],
            $data['social_profiles']
        );
    }

    public function testCustomerGetFallsBackToTheReducedSetWhenPersonalDataIsOff()
    {
        $this->fillCustomerProfile();

        $this->setSettings(['send_personal_data' => false]);

        $data = (new GetCustomerTool())->handle([], $this->context())->data;

        // Gone entirely.
        $this->assertArrayNotHasKey('address', $data);
        $this->assertArrayNotHasKey('zip', $data);
        $this->assertArrayNotHasKey('state', $data);
        $this->assertArrayNotHasKey('social_profiles', $data);

        // Still there, because it was there before this change too.
        $this->assertEquals('Example GmbH', $data['company']);
        $this->assertEquals('Berlin', $data['city']);

        // Phones degrade to bare values rather than disappearing, and the
        // country stays the raw code.
        $this->assertEquals(['+49 170 1111111', '+49 30 2222222'], $data['phones']);
        $this->assertEquals('DE', $data['country']);
    }

    public function testEmptyProfileFieldsAreNotSentAsNulls()
    {
        // A customer with almost nothing on file: the model should get a short
        // object, not a wall of nulls to reason about.
        $data = (new GetCustomerTool())->handle([], $this->context())->data;

        foreach ($data as $key => $value) {
            $this->assertNotNull($value, 'Field "'.$key.'" was sent as null.');
            $this->assertNotSame('', $value, 'Field "'.$key.'" was sent as an empty string.');
            $this->assertNotSame([], $value, 'Field "'.$key.'" was sent as an empty array.');
        }
    }

    // -----------------------------------------------------------------------
    // Authorisation is still core's
    // -----------------------------------------------------------------------

    public function testAUserWithoutAccessToTheMailboxIsRefused()
    {
        $this->fillCustomerProfile();

        $tool = new GetCustomerTool();

        $this->assertFalse($tool->authorize($this->context($this->outsider)));
        $this->assertTrue($tool->authorize($this->context($this->agent)));
    }
}
