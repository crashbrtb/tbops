<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\Event;
use App\Model\Entity\EventPayout;
use App\Model\Entity\GameTournament;
use App\Model\Table\BankTransactionsTable;
use App\Service\BankingService;
use App\Service\ClanResultService;
use App\Service\TournamentPayoutService;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The prizes a tournament sets itself (Silver and items), the Silver credited
 * to the players in the clan bank, and the CSV of the result.
 */
class TournamentPayoutsTest extends TestCase
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
        'app.Events',
        'app.EventRewards',
        'app.EventImports',
        'app.EventImportRows',
        'app.EventStandings',
        'app.EventRewardAllocations',
        'app.GameTournaments',
        'app.ClanTournamentResults',
        'app.BankAccounts',
        'app.BankTransactions',
        'app.BankApprovalLogs',
        'app.EventFines',
        'app.EventPayouts',
        'app.PlayerCycleSummaries',
        'app.ManualGoalPenalties',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();
    }

    private function loginAdmin(): void
    {
        $this->session(['Auth' => ['id' => 1, 'email' => 'admin@example.com', 'created' => new DateTime('2026-01-01')]]);
    }

    /**
     * A Ragnarök with a goal of 1,000 points, Ann and Bob ranked, and a Silver
     * prize of 300 million split 200 / 100 between them.
     *
     * @return array{0: \App\Model\Entity\Event, 1: \App\Model\Entity\GameTournament, 2: array<string, int>}
     */
    private function published(): array
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1026, '1');
        $catalogue->offerName($entry, 'Ragnarök', GameTournament::SOURCE_JOURNAL);
        $catalogue->updateAll(['goal_mode' => 'global', 'goal_points' => 1000], ['id' => $entry->id]);

        $members = $this->fetchTable('Members');
        $ids = [];
        foreach (['Ann', 'Bob'] as $name) {
            $member = $members->newEmptyEntity();
            $member->set(['player' => $name, 'power' => 1, 'guards' => 9, 'specialists' => 9, 'monsters' => 9, 'engineers' => 9, 'active' => 1], ['guard' => false]);
            $members->saveOrFail($member);
            $ids[$name] = (int)$member->id;
        }

        $events = $this->fetchTable('Events');
        $event = $events->newEntity([
            'name' => 'Ragnarök', 'criteria' => Event::CRITERIA_IMPORTED, 'starts_at' => '2026-11-06T17:00', 'ends_at' => '2026-11-08T17:00',
            'contact_player' => 'x',
        ]);
        $event->set(['game_tournament_id' => $entry->id, 'game_tournament_key' => '1026:1', 'published_at' => new DateTime('2026-11-08 18:00')], ['guard' => false]);
        $events->saveOrFail($event);

        $rewards = $this->fetchTable('EventRewards');
        $silver = $rewards->newEntity(['item_name' => 'Silver', 'quantity' => 300, 'rule' => 'proportional', 'min_points' => 1, 'remainder' => 'top_ranked', 'sort' => 1]);
        $silver->set(['event_id' => $event->id, 'is_silver' => true], ['guard' => false]);
        $rewards->saveOrFail($silver);
        $chest = $rewards->newEntity(['item_name' => 'Chest', 'quantity' => 30, 'rule' => 'proportional', 'min_points' => 1, 'remainder' => 'top_ranked', 'sort' => 2]);
        $chest->set(['event_id' => $event->id], ['guard' => false]);
        $rewards->saveOrFail($chest);

        $standings = $this->fetchTable('EventStandings');
        $allocations = $this->fetchTable('EventRewardAllocations');
        foreach ([['Ann', 1200, true, 200, 20], ['Bob', 600, false, 100, 10]] as $i => [$name, $points, $met, $silverShare, $chestShare]) {
            $row = $standings->newEmptyEntity();
            $row->set([
                'event_id' => $event->id, 'position' => $i + 1, 'player' => $name, 'points' => $points, 'member_id' => $ids[$name],
                'eligible' => true, 'guard_level' => 9, 'goal' => 1000, 'goal_met' => $met,
                'chest_count' => 0, 'chest_score' => 0, 'participation' => 0,
            ], ['guard' => false]);
            $standings->saveOrFail($row);
            $allocations->saveOrFail($allocations->newEntity(['event_reward_id' => $silver->id, 'event_standing_id' => $row->id, 'amount' => $silverShare]));
            $allocations->saveOrFail($allocations->newEntity(['event_reward_id' => $chest->id, 'event_standing_id' => $row->id, 'amount' => $chestShare]));
        }

        return [$event, $entry, $ids];
    }

    public function testExtraPrizesAreSetOnTheTournamentAndFillItsEvents(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1026, '1');

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", ['section' => 'prizes', 'extra_prizes_sent' => 1, 'extra_prizes' => [
            ['kind' => 'item', 'name' => '', 'quantity' => '5', 'rule' => 'equal', 'min_points' => '1', 'remainder' => 'top_ranked'],
        ]]);
        $this->assertResponseOk();
        $this->assertResponseContains('Each extra prize needs');

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", ['section' => 'prizes', 'extra_prizes_sent' => 1, 'extra_prizes' => [
            ['kind' => 'silver', 'quantity' => '1000', 'rule' => 'proportional', 'min_points' => '1', 'remainder' => 'top_ranked'],
            ['kind' => 'item', 'name' => 'Epic chest', 'quantity' => '12', 'rule' => 'equal', 'min_points' => '100', 'remainder' => 'keep'],
            ['kind' => 'item', 'name' => '', 'quantity' => '', 'rule' => 'equal', 'min_points' => '1', 'remainder' => 'keep'],
        ]]);
        $this->assertRedirect(['controller' => 'GameTournaments', 'action' => 'view', $entry->id]);
        $extras = $catalogue->get($entry->id)->extraPrizes();
        $this->assertCount(2, $extras, 'the blank row is dropped');
        $this->assertTrue($extras[0]['silver']);
        $this->assertSame(1000, $extras[0]['quantity']);
        $this->assertSame('Epic chest', $extras[1]['name']);

        $this->loginAdmin();
        $this->get("/game-tournaments/view/{$entry->id}");
        $this->assertResponseContains('1B');
        $this->assertResponseContains('Epic chest x12');

        // A pending event of the tournament gets the lines, with no clan result needed.
        $events = $this->fetchTable('Events');
        $event = $events->newEntity([
            'name' => 'Ragnarök', 'criteria' => Event::CRITERIA_IMPORTED, 'starts_at' => '2026-11-06T17:00', 'ends_at' => '2026-11-08T17:00',
            'contact_player' => 'x',
        ]);
        $event->set(['game_tournament_id' => $entry->id, 'game_tournament_key' => '1026:1'], ['guard' => false]);
        $events->saveOrFail($event);
        $this->assertSame(2, (new ClanResultService())->applyPrizes($event));
        $lines = $this->fetchTable('EventRewards')->find()->where(['event_id' => $event->id])->orderBy(['sort' => 'ASC'])->all()->toList();
        $this->assertTrue((bool)$lines[0]->get('is_silver'));
        $this->assertSame(1000, (int)$lines[0]->quantity);
        $this->assertFalse((bool)$lines[1]->get('is_silver'));
        $this->assertSame(2, (new ClanResultService())->applyPrizes($event), 'written again, not doubled');
        $this->assertSame(2, $this->fetchTable('EventRewards')->find()->where(['event_id' => $event->id])->count());
    }

    public function testTheSilverIsListedCreditedLeftOutAndTakenBack(): void
    {
        [$event, , $ids] = $this->published();
        $service = new TournamentPayoutService();
        $banking = new BankingService();

        $this->assertSame(2, $service->propose($event));
        $payouts = $this->fetchTable('EventPayouts');
        $bob = $payouts->find()->where(['player' => 'Bob'])->firstOrFail();
        $this->assertSame(100, (int)$bob->amount, 'only the Silver line counts');

        $this->loginAdmin();
        $this->get("/events/view/{$event->id}");
        $this->assertResponseContains('id="payouts"');
        $this->assertResponseContains('Credit 2 player(s) (300M)');
        $this->assertResponseContains('200M');

        // Bob is left out, Ann is credited.
        $this->loginAdmin();
        $this->post("/event-payouts/waive/{$bob->id}");
        $this->assertSame(EventPayout::STATUS_WAIVED, $payouts->get($bob->id)->status);
        $this->loginAdmin();
        $this->post("/event-payouts/credit/{$event->id}");
        $this->assertFlashMessage('1 player(s) credited, 200M in all.');
        $this->assertSame(200, $banking->getBalance($ids['Ann']));
        $this->assertSame(0, $banking->getBalance($ids['Bob']));
        $ann = $payouts->find()->where(['player' => 'Ann'])->firstOrFail();
        $this->assertSame(EventPayout::STATUS_CREDITED, $ann->status);
        $deposit = $this->fetchTable('BankTransactions')->get($ann->bank_transaction_id);
        $this->assertSame(BankTransactionsTable::TYPE_DEPOSIT, $deposit->type);
        $this->assertSame(BankTransactionsTable::STATUS_APPROVED, $deposit->status);

        // Listing again keeps the credited and the left out.
        $this->assertSame(0, $service->propose($event));

        // Taking Ann's back cancels the deposit.
        $this->loginAdmin();
        $this->post("/event-payouts/reverse/{$ann->id}");
        $this->assertSame(0, $banking->getBalance($ids['Ann']));
        $this->assertSame(EventPayout::STATUS_REVERSED, $payouts->get($ann->id)->status);
        $this->assertSame(BankTransactionsTable::STATUS_CANCELED, $this->fetchTable('BankTransactions')->get($ann->bank_transaction_id)->status);

        // Withdrawing the result takes the credits not made with it.
        $service->withdraw($event);
        $this->assertSame(1, $payouts->find()->where(['event_id' => $event->id])->count(), 'only the reversed one stays');
    }

    public function testTheResultIsExportedAsCsv(): void
    {
        [$event] = $this->published();

        $this->session(['Auth' => ['id' => 2, 'email' => 'member@example.com', 'created' => new DateTime('2026-01-01')]]);
        $this->get("/events/export/{$event->id}");
        $this->assertResponseCode(403);

        $this->loginAdmin();
        $this->get("/events/export/{$event->id}");
        $this->assertResponseOk();
        $this->assertContentType('text/csv');
        $this->assertHeaderContains('Content-Disposition', 'ragnarok-2026-11-08.csv');
        $body = (string)$this->_response->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = array_map(fn (string $l): array => str_getcsv($l, ';', '"', ''), preg_split('/\r?\n/', trim(substr($body, 3))));
        $this->assertSame('Position', $lines[0][0]);
        $this->assertContains('Silver (millions)', $lines[0]);
        $this->assertContains('Chest', $lines[0]);
        $this->assertSame(['1', 'Ann', '', '1200', '1000', '100', 'Yes', 'Yes', '200', '20', '', ''], $lines[1]);
        $this->assertSame('60', $lines[2][5]);
        $this->assertSame('No', $lines[2][6]);
    }
}
