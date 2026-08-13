<?php

namespace Modules\AiChatPanel\Tests;

use App\User;
use Carbon\Carbon;
use Modules\AiChatPanel\Entities\Chat;
use Modules\AiChatPanel\Entities\Message;

/**
 * The clock fields toPanelArray() hands the panel.
 *
 * The panel draws a day separator whenever `date_key` changes and prints `time`
 * beside the tok/s figure. Both are formatted here rather than in JavaScript so
 * they follow the viewer's FreeScout profile — timezone, 12/24-hour format and
 * locale — like every other date in the application.
 */
class MessageTimestampsTest extends AiChatPanelTestCase
{
    /**
     * Store one message at a known instant.
     *
     * created_at has to be written after create(): Eloquent stamps it itself
     * and would overwrite whatever the attribute was set to.
     *
     * @param string $created_at UTC, 'Y-m-d H:i:s'
     *
     * @return Message
     */
    protected function messageAt($created_at)
    {
        $chat = Chat::findOrCreateFor($this->conversation->id, $this->agent->id);

        $message = Message::create([
            'chat_id' => $chat->id,
            'role'    => Message::ROLE_ASSISTANT,
            'body'    => 'Hello',
            'meta'    => ['duration' => 1.5, 'tokens_per_second' => 40.0],
        ]);

        $message->created_at = Carbon::parse($created_at, 'UTC');
        $message->save();

        return $message->fresh();
    }

    /**
     * The assertion the day separator actually rests on: the same instant falls
     * on different calendar days for two agents in different timezones.
     *
     * @return void
     */
    public function testDayKeyUsesTheViewersTimezone()
    {
        $message = $this->messageAt('2026-08-13 22:30:00');

        $this->agent->timezone = 'UTC';
        $this->agent->save();
        $this->actingAs($this->agent);

        $this->assertEquals('2026-08-13', $message->toPanelArray()['date_key']);

        $this->agent->timezone = 'Australia/Sydney';
        $this->agent->save();
        $this->actingAs($this->agent->fresh());

        $this->assertEquals('2026-08-14', $message->toPanelArray()['date_key']);
    }

    /**
     * The key is compared against a YYYY-MM-DD the browser builds, so it has to
     * stay ASCII whatever the locale does to month names and digits. This is
     * why it is not routed through User::dateFormat().
     *
     * @return void
     */
    public function testDayKeyStaysMachineReadableInEveryLocale()
    {
        $message = $this->messageAt('2026-08-13 09:00:00');

        $this->agent->timezone = 'Europe/Berlin';
        $this->agent->save();
        $this->actingAs($this->agent);

        $locale = app()->getLocale();

        foreach (['en', 'de', 'ar'] as $test_locale) {
            app()->setLocale($test_locale);

            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                $message->toPanelArray()['date_key'],
                'date_key stopped being machine-readable under locale '.$test_locale.'.'
            );
        }

        app()->setLocale($locale);
    }

    /**
     * @return void
     */
    public function testTimeFollowsTheProfileTimeFormat()
    {
        $message = $this->messageAt('2026-08-13 12:32:00');

        $this->agent->timezone = 'UTC';
        $this->agent->time_format = User::TIME_FORMAT_24;
        $this->agent->save();
        $this->actingAs($this->agent);

        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $message->toPanelArray()['time']);

        $this->agent->time_format = User::TIME_FORMAT_12;
        $this->agent->save();
        $this->actingAs($this->agent->fresh());

        $this->assertMatchesRegularExpression('/^\d{1,2}:\d{2}\s?(am|pm)$/i', $message->toPanelArray()['time']);
    }

    /**
     * @return void
     */
    public function testDateLabelIsTheFullLocalisedDate()
    {
        $message = $this->messageAt('2026-08-13 09:00:00');

        $this->agent->timezone = 'UTC';
        $this->agent->save();
        $this->actingAs($this->agent);

        $label = $message->toPanelArray()['date_label'];

        $this->assertStringContainsString('2026', $label);
        $this->assertStringContainsString('13', $label);
    }

    /**
     * User::dateFormat() calls setTimezone() on the Carbon it is handed, which
     * without a copy() would reach through to the model's own attribute and
     * quietly change the created_at that ships alongside it.
     *
     * @return void
     */
    public function testFormattingDoesNotMutateCreatedAt()
    {
        $message = $this->messageAt('2026-08-13 22:30:00');

        $this->agent->timezone = 'Australia/Sydney';
        $this->agent->save();
        $this->actingAs($this->agent);

        $first = $message->toPanelArray();
        $second = $message->toPanelArray();

        $this->assertEquals($first['created_at'], $second['created_at']);
        $this->assertEquals($first['date_key'], $second['date_key']);
    }

    /**
     * The SSE writer runs with the session closed, and the purge command runs
     * on the console. Neither has a user, and neither may blow up.
     *
     * @return void
     */
    public function testWorksWithoutAnAuthenticatedUser()
    {
        $message = $this->messageAt('2026-08-13 09:00:00');

        $panel = $message->toPanelArray();

        $this->assertNotEmpty($panel['date_key']);
        $this->assertNotEmpty($panel['time']);
    }

    /**
     * The fields have to survive the controller, not just the model.
     *
     * @return void
     */
    public function testHistoryEndpointCarriesTheClockFields()
    {
        $this->messageAt('2026-08-13 09:00:00');

        $response = $this->actingAs($this->agent)
            ->csrfPost('/aichatpanel/chat/history', ['conversation_id' => $this->conversation->id]);

        $response->assertStatus(200);

        $messages = $response->json()['messages'];

        $this->assertNotEmpty($messages);
        $this->assertNotEmpty($messages[0]['time']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $messages[0]['date_key']);
        $this->assertNotEmpty($messages[0]['date_label']);
    }
}
