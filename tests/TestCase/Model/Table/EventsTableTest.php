<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Entity\Event;
use App\Model\Table\EventsTable;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\EventsTable Test Case
 *
 * @uses \App\Model\Table\EventsTable
 */
class EventsTableTest extends TestCase
{
    /**
     * @var \App\Model\Table\EventsTable
     */
    protected EventsTable $Events;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.StandardChests',
        'app.EventRewards',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->getTableLocator()->exists('Events') ? [] : ['className' => EventsTable::class];
        /** @var \App\Model\Table\EventsTable $events */
        $events = $this->getTableLocator()->get('Events', $config);
        $this->Events = $events;
        $this->Events->deleteAll([]);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->Events);
        parent::tearDown();
    }

    /**
     * Valid form data for a new event, as the add form would post it.
     *
     * @param array<string, mixed> $overrides Fields to change.
     * @return array<string, mixed>
     */
    protected function formData(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Chest Marathon',
            'criteria' => Event::CRITERIA_CHEST_SCORE,
            'starts_at' => DateTime::now()->addDays(1)->format('Y-m-d\TH:i'),
            'ends_at' => DateTime::now()->addDays(8)->format('Y-m-d\TH:i'),
            'prize' => '500 gold',
            'contact_player' => 'Ventura',
        ];
    }

    /**
     * The datetime-local strings the form posts are read as UTC, not as the
     * request's locale, because that is what the field is labelled as.
     *
     * @return void
     */
    public function testDatesAreMarshalledAsUtc(): void
    {
        $event = $this->Events->newEntity($this->formData([
            'starts_at' => '2027-01-15T18:30',
            'ends_at' => '2027-01-20T18:30',
        ]));

        $this->assertSame('2027-01-15 18:30:00', $event->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $event->starts_at->getTimezone()->getName());
    }

    /**
     * Event numbers are handed out by the table, in order, and never taken from
     * the submitted form.
     *
     * @return void
     */
    public function testEventNumberIsAssignedSequentially(): void
    {
        $first = $this->Events->newEntity($this->formData(['event_number' => 99]));
        $this->Events->saveOrFail($first);
        $this->assertSame(1, $first->event_number, 'A number in the form data is ignored.');

        $second = $this->Events->newEntity($this->formData(['name' => 'Second']));
        $this->Events->saveOrFail($second);
        $this->assertSame(2, $second->event_number);
    }

    /**
     * A new event may not be scheduled to have already started.
     *
     * @return void
     */
    public function testStartDateInThePastIsRejected(): void
    {
        $event = $this->Events->newEntity($this->formData([
            'starts_at' => DateTime::now()->subDays(1)->format('Y-m-d\TH:i'),
        ]));

        $this->assertFalse($this->Events->save($event));
        $this->assertArrayHasKey('startNotInPast', $event->getError('starts_at'));
    }

    /**
     * An event that is already running stays editable: its start is in the past
     * by definition, and that must not block a correction to the prize text.
     *
     * @return void
     */
    public function testRunningEventCanStillBeEdited(): void
    {
        $event = $this->Events->newEntity($this->formData());
        $this->Events->saveOrFail($event);

        // Move it into the past the way time itself would.
        $event->set('starts_at', DateTime::now()->subDays(2));
        $event->set('ends_at', DateTime::now()->addDays(2));
        $this->Events->saveOrFail($event, ['checkRules' => false]);

        $event = $this->Events->patchEntity($event, ['prize' => 'A better prize']);

        $this->assertNotFalse($this->Events->save($event));
        $this->assertSame('A better prize', $event->prize);
    }

    /**
     * The window has to run forwards.
     *
     * @return void
     */
    public function testEndBeforeStartIsRejected(): void
    {
        $event = $this->Events->newEntity($this->formData([
            'starts_at' => DateTime::now()->addDays(9)->format('Y-m-d\TH:i'),
            'ends_at' => DateTime::now()->addDays(2)->format('Y-m-d\TH:i'),
        ]));

        $this->assertFalse($this->Events->save($event));
        $this->assertArrayHasKey('endAfterStart', $event->getError('ends_at'));
    }

    /**
     * A custom chest event without any chest picked would count nothing, so it
     * is not a usable event.
     *
     * @return void
     */
    public function testCustomChestsEventNeedsAtLeastOneChest(): void
    {
        $event = $this->Events->newEntity($this->formData([
            'criteria' => Event::CRITERIA_CUSTOM_CHESTS,
        ]));

        $this->assertFalse($this->Events->save($event));
        $this->assertArrayHasKey('customChestsPicked', $event->getError('event_chests'));
    }

    /**
     * Choosing anything but the custom criteria throws away a chest selection,
     * so a list left over from an earlier choice cannot quietly stay attached.
     *
     * @return void
     */
    public function testChestSelectionIsDroppedForOtherCriteria(): void
    {
        $event = $this->Events->newEntity($this->formData([
            'criteria' => Event::CRITERIA_CHEST_COUNT,
            'custom_metric' => Event::METRIC_COUNT,
            'event_chests' => [
                ['standard_chest_id' => 1, 'source' => 'Gold Crypt'],
            ],
        ]), ['associated' => ['EventChests']]);

        $this->assertSame([], $event->event_chests);
        $this->assertSame(Event::METRIC_SCORE, $event->custom_metric);
    }

    /**
     * The finders divide events by where they sit relative to now, and a
     * cancelled event is never offered as current or upcoming.
     *
     * @return void
     */
    public function testFindersSplitEventsByState(): void
    {
        $upcoming = $this->Events->newEntity($this->formData(['name' => 'Upcoming']));
        $this->Events->saveOrFail($upcoming);

        $running = $this->Events->newEntity($this->formData(['name' => 'Running']));
        $this->Events->saveOrFail($running);
        $running->set('starts_at', DateTime::now()->subDays(1));
        $running->set('ends_at', DateTime::now()->addDays(1));
        $this->Events->saveOrFail($running, ['checkRules' => false]);

        $past = $this->Events->newEntity($this->formData(['name' => 'Past']));
        $this->Events->saveOrFail($past);
        $past->set('starts_at', DateTime::now()->subDays(10));
        $past->set('ends_at', DateTime::now()->subDays(3));
        $this->Events->saveOrFail($past, ['checkRules' => false]);

        $this->assertSame('Running', $this->Events->currentEvent()?->name);
        $this->assertSame(['Upcoming'], $this->Events->find('upcoming')->all()->extract('name')->toList());
        $this->assertSame(['Past'], $this->Events->find('past')->all()->extract('name')->toList());

        // Cancelling takes it out of "running" and puts it in the history.
        $running->set('status', Event::STATUS_CANCELLED);
        $this->Events->saveOrFail($running, ['checkRules' => false]);

        $this->assertNull($this->Events->currentEvent());
        $this->assertContains('Running', $this->Events->find('past')->all()->extract('name')->toList());
    }

    /**
     * The banner blob is never dragged into a listing.
     *
     * @return void
     */
    public function testWithoutBannerFinderOmitsTheBlob(): void
    {
        $event = $this->Events->newEntity($this->formData());
        $event->set('banner_image', 'not-really-an-image');
        $event->set('banner_mime', 'image/png');
        $this->Events->saveOrFail($event);

        $listed = $this->Events->find('withoutBanner')->where(['Events.id' => $event->id])->firstOrFail();

        $this->assertFalse($listed->has('banner_image'));
        $this->assertSame('image/png', $listed->banner_mime);
    }

    /**
     * The derived state follows the window without anything having to run.
     *
     * @return void
     */
    public function testStateIsDerivedFromTheWindow(): void
    {
        $event = $this->Events->newEmptyEntity();
        $event->set('status', Event::STATUS_SCHEDULED);

        $event->set('starts_at', DateTime::now()->addDays(1));
        $event->set('ends_at', DateTime::now()->addDays(2));
        $this->assertSame(Event::STATE_SCHEDULED, $event->state);
        $this->assertFalse($event->is_running);

        $event->set('starts_at', DateTime::now()->subDays(1));
        $this->assertSame(Event::STATE_RUNNING, $event->state);
        $this->assertTrue($event->is_running);

        $event->set('ends_at', DateTime::now()->subHours(1));
        $this->assertSame(Event::STATE_FINISHED, $event->state);

        $event->set('status', Event::STATUS_CANCELLED);
        $this->assertSame(Event::STATE_CANCELLED, $event->state);
    }

    /**
     * A game tournament is registered after it was played: past dates are
     * accepted, the prize text is written from the rewards, and rewards are
     * optional: a tournament can have none.
     *
     * @return void
     */
    public function testGameTournamentRules(): void
    {
        $data = $this->formData([
            'criteria' => Event::CRITERIA_IMPORTED,
            'starts_at' => '2026-01-10T00:00',
            'ends_at' => '2026-01-10T23:59',
            'prize' => '',
        ]);

        $withoutRewards = $this->Events->newEntity($data);
        $this->Events->saveOrFail($withoutRewards);
        $this->assertSame([], $withoutRewards->event_rewards);
        $this->assertSame('', $withoutRewards->prize);

        // "No rewards" ticked on the form drops any line typed before.
        $ticked = $this->Events->newEntity($data + ['no_rewards' => '1', 'event_rewards' => [
            ['item_name' => 'Coins', 'quantity' => '5', 'rule' => 'equal'],
        ]]);
        $this->Events->saveOrFail($ticked);
        $this->assertSame([], $ticked->event_rewards);

        $event = $this->Events->newEntity($data + ['event_rewards' => [
            ['item_name' => 'Coins', 'quantity' => '2.000', 'rule' => 'equal'],
        ]]);
        $this->Events->saveOrFail($event);

        $this->assertSame(2000, $event->event_rewards[0]->quantity);
        $this->assertSame(1, $event->event_rewards[0]->min_points);
        $this->assertStringContainsString('Coins', $event->prize);
        $this->assertSame(Event::STATE_AWAITING, $event->state);

        // Switching the event to another criteria drops its rewards.
        $event = $this->Events->patchEntity($event, $this->formData(), ['associated' => ['EventRewards']]);
        $this->assertSame([], $event->event_rewards);
    }

    /**
     * Tournaments never show up as running or upcoming, and appear in the past
     * list only once published.
     *
     * @return void
     */
    public function testFindersOnlyListPublishedTournaments(): void
    {
        $tournament = $this->Events->newEntity($this->formData([
            'criteria' => Event::CRITERIA_IMPORTED,
            'starts_at' => DateTime::now()->subHours(1)->format('Y-m-d\TH:i'),
            'ends_at' => DateTime::now()->addHours(5)->format('Y-m-d\TH:i'),
            'event_rewards' => [['item_name' => 'Coins', 'quantity' => 10, 'rule' => 'equal']],
        ]));
        $this->Events->saveOrFail($tournament);

        $this->assertSame(0, $this->Events->find('running')->count());
        $this->assertSame(0, $this->Events->find('past')->count());
        $this->assertSame([$tournament->id], $this->Events->find('awaitingImport')->all()->extract('id')->toList());

        $this->Events->updateAll(['published_at' => DateTime::now()], ['id' => $tournament->id]);

        $this->assertSame([$tournament->id], $this->Events->find('past')->all()->extract('id')->toList());
        $this->assertSame(0, $this->Events->find('awaitingImport')->count());
        $this->assertSame(Event::STATE_FINISHED, $this->Events->get($tournament->id)->state);
    }
}
