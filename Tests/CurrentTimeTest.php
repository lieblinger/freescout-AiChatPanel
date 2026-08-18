<?php

namespace Modules\AiChatPanel\Tests;

use App\Thread;
use Carbon\Carbon;
use Modules\AiChatPanel\Services\Clock;
use Modules\AiChatPanel\Services\Context\ContextBuilder;
use Modules\AiChatPanel\Services\Tools\Builtin\TimeNowTool;
use Modules\AiChatPanel\Services\Tools\ToolRegistry;

/**
 * The assistant's clock: what day it is, and which timezone every date it is
 * shown is in.
 *
 * The timezone half is the part worth guarding. FreeScout stores UTC and shows
 * the agent their own zone, so an unconverted timestamp in the prompt is a
 * different time from the one on the agent's screen — and the model states it
 * as fact.
 */
class CurrentTimeTest extends AiChatPanelTestCase
{
    /** Nine hours ahead of UTC and no daylight saving, so the arithmetic is fixed. */
    const TZ = 'Asia/Tokyo';

    public function testTheSystemMessageStatesTheCurrentDateAndItsTimezone()
    {
        $this->useTimezone(self::TZ);

        $built = (new ContextBuilder($this->context()))->build(0);

        $expected = Carbon::now()->setTimezone(self::TZ);

        $this->assertStringContainsString('Current date and time: ', $built['content']);
        $this->assertStringContainsString($expected->format('l, Y-m-d'), $built['content']);
        $this->assertStringContainsString(self::TZ, $built['content']);
        $this->assertStringContainsString('UTC+09:00', $built['content']);
    }

    public function testThreadTimestampsAreRenderedInTheAgentsTimezone()
    {
        $this->useTimezone(self::TZ);

        $stored = $this->storedAt('2026-08-17 23:30:00');
        $local = $stored->copy()->setTimezone(self::TZ);

        $thread = $this->addThread('<div>Late one.</div>');
        $this->setCreatedAt($thread, $stored);

        $built = (new ContextBuilder($this->context()))->build(0);

        $this->assertStringContainsString($local->format(Clock::FORMAT_DATE_TIME), $built['content']);
        $this->assertStringNotContainsString($stored->format(Clock::FORMAT_DATE_TIME), $built['content']);

        // Late enough in the evening that Tokyo has already turned the page:
        // the date moves, not only the clock.
        $this->assertNotSame($stored->format('Y-m-d'), $local->format('Y-m-d'));
    }

    public function testTheConversationCreationDateIsRenderedInTheAgentsTimezone()
    {
        $this->useTimezone(self::TZ);

        $stored = $this->storedAt('2026-08-17 23:30:00');
        $this->setCreatedAt($this->conversation, $stored);

        $built = (new ContextBuilder($this->context()))->build(0);

        $this->assertStringContainsString(
            'Created: '.$stored->copy()->setTimezone(self::TZ)->format(Clock::FORMAT_DATE_TIME),
            $built['content']
        );
    }

    public function testAUserWithoutATimezoneFallsBackToTheApplicationTimezone()
    {
        $this->agent->timezone = '';
        $this->agent->save();

        $this->assertSame(config('app.timezone'), Clock::timezone($this->agent));

        // And with no user at all — the prompt builder tolerates one.
        $this->assertSame(config('app.timezone'), Clock::timezone(null));
    }

    public function testFormattingADateDoesNotMutateIt()
    {
        $this->useTimezone(self::TZ);

        $date = Carbon::parse('2026-08-17 23:30:00');

        Clock::dateTime($date, $this->agent);
        Clock::date($date, $this->agent);
        Clock::humanDiff($date, $this->agent);

        // setTimezone() mutates in place, and what gets passed in here is
        // usually a model's own created_at attribute.
        $this->assertSame('2026-08-17 23:30:00', $date->toDateTimeString());
    }

    public function testAMissingDateFormatsToAnEmptyStringRatherThanNow()
    {
        $this->assertSame('', Clock::dateTime(null, $this->agent));
        $this->assertSame('', Clock::date(null, $this->agent));
        $this->assertSame('', Clock::humanDiff(null, $this->agent));
    }

    public function testAgesAreWordedInEnglishWhateverTheInterfaceLanguage()
    {
        app()->setLocale('de');

        $this->assertSame('3 days', Clock::humanDiff(Carbon::now()->subDays(3), $this->agent));
        $this->assertSame('1 day', Clock::humanDiff(Carbon::now()->subDay(), $this->agent));
        $this->assertSame('2 hours', Clock::humanDiff(Carbon::now()->subHours(2), $this->agent));
        $this->assertSame('1 minute', Clock::humanDiff(Carbon::now()->subMinute(), $this->agent));
        $this->assertSame('just now', Clock::humanDiff(Carbon::now()->subSeconds(5), $this->agent));
    }

    public function testTimeNowReportsTheClockAndTheConversationAge()
    {
        $this->useTimezone(self::TZ);
        $this->setCreatedAt($this->conversation, Carbon::now()->subDays(3));

        $thread = $this->addThread('<div>Any news?</div>');
        $this->setCreatedAt($thread, Carbon::now()->subHours(2));

        $result = (new TimeNowTool())->handle([], $this->context());

        $this->assertTrue($result->ok);
        $this->assertSame(self::TZ, $result->data['timezone']);
        $this->assertSame('+09:00', $result->data['utc_offset']);
        $this->assertSame(Carbon::now()->setTimezone(self::TZ)->format('l'), $result->data['weekday']);
        $this->assertSame('3 days', $result->data['conversation']['age']);
        $this->assertSame('2 hours', $result->data['conversation']['since_last_message']);
    }

    public function testTimeNowIgnoresNotesWhenSayingHowLongSinceTheLastMessage()
    {
        $this->useTimezone(self::TZ);

        $message = $this->addThread('<div>Any news?</div>');
        $this->setCreatedAt($message, Carbon::now()->subDays(2));

        // An internal note nobody outside the help desk saw does not reset the
        // clock on the correspondence.
        $note = $this->addThread('<div>Chasing this internally.</div>', Thread::TYPE_NOTE);
        $this->setCreatedAt($note, Carbon::now()->subMinutes(5));

        $result = (new TimeNowTool())->handle([], $this->context());

        $this->assertSame('2 days', $result->data['conversation']['since_last_message']);
    }

    public function testTimeNowOnAnEmptyConversationReportsNullRatherThanFailing()
    {
        $result = (new TimeNowTool())->handle([], $this->context());

        $this->assertTrue($result->ok);
        $this->assertNull($result->data['conversation']['last_message_at']);
        $this->assertNull($result->data['conversation']['since_last_message']);
        $this->assertNotNull($result->data['now']);
    }

    public function testTimeNowIsOfferedInTheToolCatalogue()
    {
        $this->assertArrayHasKey('time_now', ToolRegistry::catalogue());
    }

    public function testTimeNowHasACheckboxOnTheSettingsPage()
    {
        // The settings view loops over the catalogue, so a new tool needs no
        // view change — which is exactly why it is worth asserting once that
        // the loop really does pick one up.
        $response = $this->actingAs($this->admin)->get('/app-settings/aichatpanel');

        $response->assertStatus(200);

        // Not assertSee(): Laravel 5.5 implements it with PHPUnit's
        // assertContains(), which stopped accepting a string haystack in
        // PHPUnit 8 and throws a TypeError under the 9.x this suite runs on.
        $this->assertStringContainsString('value="time_now"', $response->getContent());
    }

    // -----------------------------------------------------------------------

    /**
     * @param string $timezone
     *
     * @return void
     */
    protected function useTimezone($timezone)
    {
        $this->agent->timezone = $timezone;
        $this->agent->save();
    }

    /**
     * A wall-clock time as the database holds it.
     *
     * Carbon::parse() uses the application timezone, which is what Eloquent
     * hydrates date columns with — so this is the same instant the row would
     * be read back as. Not hard-coded to UTC: config('app.timezone') is an
     * install-level choice and this suite must not assume ours.
     *
     * @param string $date
     *
     * @return Carbon
     */
    protected function storedAt($date)
    {
        return Carbon::parse($date);
    }

    /**
     * created_at is not fillable and Eloquent rewrites it on save, so it is set
     * straight on the row.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param Carbon                              $date
     *
     * @return void
     */
    protected function setCreatedAt($model, Carbon $date)
    {
        $model->newQuery()->where('id', $model->id)->update(['created_at' => $date->toDateTimeString()]);

        $model->created_at = $date->copy();
    }
}
