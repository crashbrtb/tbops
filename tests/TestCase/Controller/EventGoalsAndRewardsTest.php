<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\Event;
use App\Model\Entity\EventReward;
use App\Model\Entity\GameTournament;
use App\Service\EventGoal;
use App\Service\EventImportService;
use App\Service\EventScoringService;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Event goals (global or by guard level, optionally required for a reward)
 * and rewards for clan events, including the "by position" rule.
 *
 * The clan event ranks four players by chest score, each Gold Crypt worth 100:
 * Bank (administrative, 5000), Top (G9, 3000), Low (G1, 2000) and Stranger
 * (no member record, 1000). Its goal is 4000 for G9, 1000 for G1 and 1500 for
 * every other level, so G0 (Bank, Stranger) gets the highest: 4000.
 *
 * @uses \App\Controller\EventsController
 * @uses \App\Service\EventPrizeService
 */
class EventGoalsAndRewardsTest extends TestCase
{
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Users',
        'app.Roles',
        'app.RolesUsers',
        'app.Config',
        'app.Members',
        'app.PlayerNameMappings',
        'app.CollectedChests',
        'app.StandardChests',
        'app.Events',
        'app.EventChests',
        'app.EventRewards',
        'app.EventImports',
        'app.EventImportRows',
        'app.EventStandings',
        'app.EventRewardAllocations',
        'app.GameTournaments',
        'app.BankTransactions',
    ];

    private const WINDOW_START = '2026-03-01 00:00:00';
    private const WINDOW_END = '2026-03-31 23:59:00';

    private const CSV = "posicao,nome,pontos,poder,id_jogador\n"
        . "1,Naughtius Maximus,1703103642,,300647954111\n"
        . "2,Bank KOK,900000000,,999000111\n"
        . "3,WARLOCK,937708918,,523986160991\n"
        . "4,BENAR,0,,622770520040\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();

        $members = $this->fetchTable('Members');
        $members->deleteAll([]);
        $roster = [
            ['player' => 'Bank', 'guards' => 0, 'administrative_account' => true],
            ['player' => 'Top', 'guards' => 9],
            ['player' => 'Low', 'guards' => 1],
            ['player' => 'Bank KOK', 'game_player_id' => 999000111, 'administrative_account' => true],
            ['player' => 'WARLOCK', 'guards' => 5],
        ];
        foreach ($roster as $member) {
            $members->saveOrFail($members->newEntity($member + ['active' => 1], ['validate' => false]), ['checkRules' => false]);
        }

        $chests = $this->fetchTable('StandardChests');
        $chests->deleteAll([]);
        $chests->saveOrFail($chests->newEntity(['source' => 'Gold Crypt', 'score' => 100, 'monster' => 0], ['validate' => false]));

        $collected = $this->fetchTable('CollectedChests');
        $collected->deleteAll([]);
        foreach (['Bank' => 50, 'Top' => 30, 'Low' => 20, 'Stranger' => 10] as $player => $count) {
            for ($i = 0; $i < $count; $i++) {
                $collected->saveOrFail($collected->newEntity([
                    'name' => 'Gold Crypt', 'player' => $player, 'source' => 'Gold Crypt', 'type' => 1,
                    'collected_at' => new DateTime('2026-03-10 12:00:00', 'UTC'),
                ], ['validate' => false]), ['checkRules' => false]);
            }
        }
    }

    private function loginAdmin(): void
    {
        $this->session(['Auth' => ['id' => 1, 'email' => 'admin@example.com', 'created' => new DateTime('2026-01-01')]]);
    }

    /**
     * What the form posts for the clan event, with the window still ahead (the
     * form refuses an end in the past).
     *
     * @param array<string, mixed> $overrides Fields to change.
     * @return array<string, mixed>
     */
    private function clanEventData(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'March crypts',
            'criteria' => Event::CRITERIA_CHEST_SCORE,
            'starts_at' => DateTime::now()->addHours(1)->format('Y-m-d\TH:i'),
            'ends_at' => DateTime::now()->addDays(3)->format('Y-m-d\TH:i'),
            'prize' => '',
            'contact_player' => 'Naughtius',
            'event_rewards' => [
                ['item_name' => 'Gold', 'rule' => 'position', 'positions' => '500; 250', 'min_points' => '1', 'remainder' => 'top_ranked'],
                ['item_name' => 'Coins', 'quantity' => '90', 'rule' => 'proportional', 'min_points' => '1', 'remainder' => 'top_ranked'],
            ],
            'goal' => [
                'mode' => 'guard',
                'points' => '1.500',
                'by_guard' => ['9' => '4000', '1' => '1000', '5' => ''],
                'required' => '1',
            ],
        ];
    }

    /**
     * Create the clan event through the form, then move its window onto the
     * chest data, as if it had run in March.
     *
     * @return \App\Model\Entity\Event
     */
    private function clanEvent(): Event
    {
        $this->loginAdmin();
        $this->post('/events/add', $this->clanEventData());

        $events = $this->fetchTable('Events');
        $event = $events->find()->contain(['EventRewards'])->orderBy(['Events.id' => 'DESC'])->firstOrFail();
        $event->set('starts_at', new DateTime(self::WINDOW_START, 'UTC'));
        $event->set('ends_at', new DateTime(self::WINDOW_END, 'UTC'));
        $events->saveOrFail($event, ['checkRules' => false]);

        return $events->get($event->id, contain: ['EventRewards']);
    }

    /**
     * @param int $eventId Event id.
     * @return array<string, array<string, int>> Player => reward item => amount.
     */
    private function allocations(int $eventId): array
    {
        $out = [];
        $standings = $this->fetchTable('EventStandings')->find()
            ->where(['EventStandings.event_id' => $eventId])
            ->contain(['EventRewardAllocations' => ['EventRewards']])
            ->all();
        foreach ($standings as $standing) {
            foreach ($standing->event_reward_allocations as $allocation) {
                $out[$standing->player][$allocation->event_reward->item_name] = (int)$allocation->amount;
            }
        }
        ksort($out);

        return $out;
    }

    public function testAClanEventIsSavedWithRewardsByPositionAndAGoal(): void
    {
        $event = $this->clanEvent();

        $this->assertCount(2, $event->event_rewards);
        $gold = $event->event_rewards[0];
        $this->assertSame(EventReward::RULE_POSITION, $gold->rule);
        $this->assertSame([500, 250], $gold->positionList());
        $this->assertSame(750, $gold->quantity, 'a reward by position hands out the sum of its places');
        // The prize text is written from the rewards when left empty.
        $this->assertStringContainsString('Gold: #1 500, #2 250', $event->prize);

        $goal = $event->goal();
        $this->assertSame(EventGoal::MODE_GUARD, $goal->mode);
        $this->assertSame(1500, $goal->points);
        $this->assertSame([1 => 1000, 9 => 4000], $goal->byGuard);
        $this->assertTrue($goal->isRequired());
        $this->assertSame(4000, $goal->goalForLevel(0), 'an unknown level gets the highest goal');
        $this->assertSame(1500, $goal->goalForLevel(5), 'a blank level uses the global goal');
    }

    public function testClosingTheEventRewardsOnlyThoseWhoReachedARequiredGoal(): void
    {
        $event = $this->clanEvent();

        // While it is not recorded yet, the page shows what each one would get.
        $live = (new EventScoringService())->resultsFor($event);
        $this->assertSame('live', $live['source']);
        $low = array_values(array_filter($live['rows'], fn (array $r): bool => $r['player'] === 'Low'))[0];
        $this->assertTrue($low['goal_met']);
        $this->assertSame(500, $low['amounts'][$event->event_rewards[0]->id]);

        $this->loginAdmin();
        $this->post("/events/finalize/{$event->id}");
        $this->assertRedirect(['controller' => 'Events', 'action' => 'view', $event->id]);

        $standings = $this->fetchTable('EventStandings')->find()
            ->where(['event_id' => $event->id])->all()->indexBy('player')->toArray();
        $this->assertSame(4, count($standings));
        // Top (G9) missed 4000 with 3000; Low (G1) reached 1000; Stranger has no
        // member record, so G0 and the highest goal; Bank reached it but is administrative.
        $this->assertSame([9, 4000, false], [$standings['Top']->guard_level, $standings['Top']->goal, $standings['Top']->goal_met]);
        $this->assertSame([1, 1000, true], [$standings['Low']->guard_level, $standings['Low']->goal, $standings['Low']->goal_met]);
        $this->assertSame([0, 4000, false], [$standings['Stranger']->guard_level, $standings['Stranger']->goal, $standings['Stranger']->goal_met]);
        $this->assertTrue($standings['Bank']->goal_met);
        $this->assertFalse($standings['Bank']->eligible);

        // Only Low reached the goal: first place and every coin go to them, the
        // second place stays with the clan.
        $this->assertSame(['Low' => ['Gold' => 500, 'Coins' => 90]], $this->allocations($event->id));

        $this->session([]);
        $this->get("/events/view/{$event->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('Official result');
        $this->assertResponseContains('Reaching the goal is required to receive a reward');
    }

    public function testChangingTheGoalOfARecordedEventSplitsTheRewardsAgain(): void
    {
        $event = $this->clanEvent();
        $this->loginAdmin();
        $this->post("/events/finalize/{$event->id}");
        $pointsBefore = $this->fetchTable('EventStandings')->find()->where(['event_id' => $event->id])
            ->all()->combine('player', 'points')->toArray();

        // The goal stays, but is no longer required.
        $data = $this->clanEventData([
            'starts_at' => '2026-03-01T00:00',
            'ends_at' => '2026-03-31T23:59',
        ]);
        $data['goal']['required'] = '0';
        foreach ($event->event_rewards as $index => $reward) {
            $data['event_rewards'][$index]['id'] = $reward->id;
        }
        $this->loginAdmin();
        $this->post("/events/edit/{$event->id}", $data);
        $this->assertRedirect(['controller' => 'Events', 'action' => 'view', $event->id]);

        $this->assertSame($pointsBefore, $this->fetchTable('EventStandings')->find()->where(['event_id' => $event->id])
            ->all()->combine('player', 'points')->toArray(), 'the points stay as recorded');
        // Everybody but the administrative account takes part now: places by
        // ranking, coins in proportion to 3000 / 2000 / 1000.
        $this->assertSame([
            'Low' => ['Gold' => 250, 'Coins' => 30],
            'Stranger' => ['Coins' => 15],
            'Top' => ['Gold' => 500, 'Coins' => 45],
        ], $this->allocations($event->id));
    }

    public function testAGoalModeWithoutAGoalIsRefused(): void
    {
        $this->loginAdmin();
        $this->post('/events/add', $this->clanEventData([
            'goal' => ['mode' => 'global', 'points' => '', 'required' => '1'],
        ]));

        $this->assertResponseOk();
        $this->assertResponseContains('Set the goal, or choose');
        $this->assertSame(0, $this->fetchTable('Events')->find()->count());
    }

    public function testATournamentGetsTheDefaultGoalOfTheCatalogue(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $catalogue->deleteAll([]);
        $entry = $catalogue->newEmptyEntity();
        $entry->set(['game_type' => 1024, 'ranking' => GameTournament::RANKING_DEFAULT], ['guard' => false]);
        $entry->set(EventGoal::marshal(['mode' => 'global', 'points' => '1000000000', 'required' => '1']));
        $catalogue->saveOrFail($entry);

        $service = new EventImportService();
        $registered = $service->registerTournament([
            'result_uid' => 'uid-1', 'tournament_key' => '1024:1', 'name' => 'Ancients',
            'ranking' => GameTournament::RANKING_DEFAULT, 'ended_at' => new DateTime('2026-09-10 20:00:00', 'UTC'),
        ], 1, 'Naughtius');

        $goal = $registered['event']->goal();
        $this->assertSame(EventGoal::MODE_GLOBAL, $goal->mode);
        $this->assertSame(1000000000, $goal->points);
        $this->assertTrue($goal->isRequired());

        // Publishing gives the reward only to the players who reached it.
        $events = $this->fetchTable('Events');
        $event = $events->get($registered['event']->id, contain: ['EventRewards']);
        $event = $events->patchEntity($event, ['event_rewards' => [
            ['item_name' => 'Coins', 'quantity' => 9, 'rule' => EventReward::RULE_EQUAL],
        ]], ['associated' => ['EventRewards']]);
        $events->saveOrFail($event, ['checkRules' => false]);

        $service->import($event, $service->validate($service->parseCsv(self::CSV))['payload'], 1);
        $this->loginAdmin();
        $this->post("/events/review/{$event->id}", ['intent' => 'publish', 'rows' => []]);
        $this->assertRedirect(['controller' => 'Events', 'action' => 'view', $event->id]);

        // Naughtius (1.7 billion) is the only eligible player above 1 billion.
        $this->assertSame(['Naughtius Maximus' => ['Coins' => 9]], $this->allocations($event->id));
        $warlock = $this->fetchTable('EventStandings')->find()->where(['event_id' => $event->id, 'player' => 'WARLOCK'])->firstOrFail();
        $this->assertFalse($warlock->goal_met);
    }

    public function testWithoutACatalogueGoalATournamentInheritsThePreviousOne(): void
    {
        $service = new EventImportService();
        $first = $service->registerTournament([
            'result_uid' => 'uid-1', 'tournament_key' => '2048:1', 'name' => 'Clash',
            'ranking' => GameTournament::RANKING_DEFAULT, 'ended_at' => new DateTime('2026-09-10 20:00:00', 'UTC'),
        ], 1, 'Naughtius');
        $events = $this->fetchTable('Events');
        $events->updateAll(EventGoal::marshal(['mode' => 'global', 'points' => '500']), ['id' => $first['event']->id]);

        $second = $service->registerTournament([
            'result_uid' => 'uid-2', 'tournament_key' => '2048:1', 'name' => 'Clash',
            'ranking' => GameTournament::RANKING_DEFAULT, 'ended_at' => new DateTime('2026-09-11 20:00:00', 'UTC'),
        ], 1, 'Naughtius');

        $this->assertSame(500, $second['event']->goal()->points);
        $this->assertFalse($second['event']->goal()->required);
    }

    public function testTheCatalogueStoresTheDefaultGoal(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $catalogue->deleteAll([]);
        $entry = $catalogue->newEmptyEntity();
        $entry->set(['game_type' => 4096, 'ranking' => GameTournament::RANKING_DEFAULT], ['guard' => false]);
        $catalogue->saveOrFail($entry);

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", [
            'name' => 'Arena',
            'duration_days' => '',
            'goal' => ['mode' => 'guard', 'points' => '100', 'by_guard' => ['9' => '900'], 'required' => '1'],
        ]);
        $this->assertRedirect(['controller' => 'GameTournaments', 'action' => 'index']);

        $goal = EventGoal::fromEntity($catalogue->get($entry->id));
        $this->assertSame(EventGoal::MODE_GUARD, $goal->mode);
        $this->assertSame([9 => 900], $goal->byGuard);
        $this->assertTrue($goal->required);
    }
}
