<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\Event;
use App\Model\Entity\EventFine;
use App\Model\Entity\GameTournament;
use App\Service\BankingService;
use App\Service\TournamentFineService;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The fine of the players who miss a tournament's goal: set on the catalogue,
 * listed when the result is published, waived, charged to the bank, refunded.
 */
class TournamentFinesTest extends TestCase
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
     * A published Ragnarök with a goal of 1,000 points and three players:
     * Ann reached it, Bob made 600, Cid made nothing.
     *
     * @return array{0: \App\Model\Entity\Event, 1: \App\Model\Entity\GameTournament, 2: array<string, int>}
     */
    private function published(string $mode, int $amount): array
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1026, '1');
        $catalogue->offerName($entry, 'Ragnarök', GameTournament::SOURCE_JOURNAL);
        $catalogue->updateAll(['goal_mode' => 'global', 'goal_points' => 1000, 'fine_mode' => $mode, 'fine_amount' => $amount], ['id' => $entry->id]);

        $members = $this->fetchTable('Members');
        $ids = [];
        foreach (['Ann', 'Bob', 'Cid'] as $name) {
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

        $standings = $this->fetchTable('EventStandings');
        foreach ([['Ann', 1200, true], ['Bob', 600, false], ['Cid', 0, false]] as $i => [$name, $points, $met]) {
            $row = $standings->newEmptyEntity();
            $row->set([
                'event_id' => $event->id, 'position' => $i + 1, 'player' => $name, 'points' => $points, 'member_id' => $ids[$name],
                'eligible' => true, 'guard_level' => 9, 'goal' => 1000, 'goal_met' => $met,
                'chest_count' => 0, 'chest_score' => 0, 'participation' => 0,
            ], ['guard' => false]);
            $standings->saveOrFail($row);
        }

        return [$event, $entry, $ids];
    }

    public function testTheAmountIsFixedOrInProportionToWhatWasMissing(): void
    {
        $this->assertSame(500, TournamentFineService::amountFor('fixed', 500, 600, 1000));
        $this->assertSame(200, TournamentFineService::amountFor('proportional', 500, 600, 1000), '40% missing of 500');
        $this->assertSame(500, TournamentFineService::amountFor('proportional', 500, 0, 1000));
        $this->assertSame(0, TournamentFineService::amountFor('fixed', 500, 1000, 1000), 'the goal reached');
        $this->assertSame(0, TournamentFineService::amountFor('none', 500, 0, 1000));
        $this->assertSame(0, TournamentFineService::amountFor('fixed', 500, 0, 0), 'no goal');
        $this->assertSame(1, TournamentFineService::amountFor('proportional', 1, 900, 1000), 'never less than 1 million');
        $this->assertSame(2, TournamentFineService::amountFor('proportional', 3, 600, 1000), '40% of 3 million (1.2) rounded up');
    }

    public function testTheTiersFineByTheShareOfTheGoalReached(): void
    {
        $tiers = [
            ['from' => 0, 'to' => 10, 'amount' => 1000],
            ['from' => 10, 'to' => 50, 'amount' => 500],
            ['from' => 50, 'to' => 100, 'amount' => 100],
        ];
        $this->assertSame(1000, TournamentFineService::amountFor('tiers', 0, 0, 1000, $tiers));
        $this->assertSame(1000, TournamentFineService::amountFor('tiers', 0, 99, 1000, $tiers));
        $this->assertSame(500, TournamentFineService::amountFor('tiers', 0, 100, 1000, $tiers), 'exactly 10% falls in the next range');
        $this->assertSame(500, TournamentFineService::amountFor('tiers', 0, 499, 1000, $tiers));
        $this->assertSame(100, TournamentFineService::amountFor('tiers', 0, 999, 1000, $tiers));
        $this->assertSame(0, TournamentFineService::amountFor('tiers', 0, 1000, 1000, $tiers), 'the goal reached');
        $this->assertSame(0, TournamentFineService::amountFor('tiers', 0, 700, 1000, array_slice($tiers, 0, 2)), 'no range holds 70%');

        $this->assertNull(TournamentFineService::tierProblem($tiers));
        $this->assertNotNull(TournamentFineService::tierProblem([]));
        $this->assertNotNull(TournamentFineService::tierProblem([['from' => 10, 'to' => 10, 'amount' => 1]]), 'empty range');
        $this->assertNotNull(TournamentFineService::tierProblem([['from' => 0, 'to' => 110, 'amount' => 1]]), 'above 100%');
        $this->assertNotNull(TournamentFineService::tierProblem([['from' => 0, 'to' => 10, 'amount' => 0]]), 'no fine');
        $this->assertNotNull(TournamentFineService::tierProblem([
            ['from' => 0, 'to' => 20, 'amount' => 1], ['from' => 10, 'to' => 50, 'amount' => 1],
        ]), 'overlap');

        $this->assertSame(
            [['from' => 0, 'to' => 10, 'amount' => 1000], ['from' => 10, 'to' => 50, 'amount' => 500]],
            TournamentFineService::marshalTiers([
                ['from' => '10', 'to' => '50', 'amount' => '500'],
                ['from' => '', 'to' => '', 'amount' => ''],
                ['from' => '0', 'to' => '10', 'amount' => '1000'],
            ]),
            'blank rows dropped, sorted'
        );
        $this->assertSame(
            [['from' => 0, 'to' => 10, 'amount' => -1]],
            TournamentFineService::marshalTiers([['from' => '0', 'to' => '10', 'amount' => '0.5']]),
            'no fraction of a million'
        );
        $this->assertNotNull(TournamentFineService::tierProblem([['from' => 0, 'to' => 10, 'amount' => -1]]));
    }

    public function testTheTiersAreSetOnTheTournamentPageAndListTheFines(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        [$event, $entry] = $this->published(TournamentFineService::MODE_NONE, 0);

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", ['section' => 'fine', 'fine_mode' => 'tiers', 'fine_tiers' => [
            ['from' => '0', 'to' => '20', 'amount' => '1000'],
            ['from' => '10', 'to' => '100', 'amount' => '100'],
        ]]);
        $this->assertResponseOk();
        $this->assertResponseContains('overlaps the one before it');

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", ['section' => 'fine', 'fine_mode' => 'tiers', 'fine_amount' => '', 'fine_tiers' => [
            ['from' => '50', 'to' => '100', 'amount' => '100'],
            ['from' => '0', 'to' => '10', 'amount' => '1000'],
            ['from' => '10', 'to' => '50', 'amount' => '500'],
        ]]);
        $this->assertRedirect(['controller' => 'GameTournaments', 'action' => 'view', $entry->id]);
        [$mode, , $tiers] = TournamentFineService::ruleOf($catalogue->get($entry->id));
        $this->assertSame('tiers', $mode);
        $this->assertSame([0, 10, 50], array_column($tiers, 'from'));

        $this->loginAdmin();
        $this->get("/game-tournaments/view/{$entry->id}");
        $this->assertResponseContains('10% to 50% of the goal: 500M');

        // Bob made 600 of 1,000 (60%), Cid nothing.
        $this->assertSame(2, (new TournamentFineService())->propose($event));
        $fines = $this->fetchTable('EventFines');
        $this->assertSame(100, (int)$fines->find()->where(['player' => 'Bob'])->firstOrFail()->amount);
        $this->assertSame(1000, (int)$fines->find()->where(['player' => 'Cid'])->firstOrFail()->amount);
    }

    public function testTheFineIsSetOnTheTournamentPage(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1026, '1');

        $this->loginAdmin();
        $this->get("/game-tournaments/edit/{$entry->id}?section=fine");
        $this->assertResponseOk();
        $this->assertResponseContains('name="fine_mode"');

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", ['section' => 'fine', 'fine_mode' => 'proportional', 'fine_amount' => '']);
        $this->assertResponseOk();
        $this->assertResponseContains('Set the fine, or choose');

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", ['section' => 'fine', 'fine_mode' => 'proportional', 'fine_amount' => '500']);
        $this->assertRedirect(['controller' => 'GameTournaments', 'action' => 'view', $entry->id]);
        $saved = $catalogue->get($entry->id);
        $this->assertSame(['proportional', 500, []], TournamentFineService::ruleOf($saved));

        $this->loginAdmin();
        $this->get("/game-tournaments/view/{$entry->id}");
        $this->assertResponseContains('section=fine');
        $this->assertResponseContains('in proportion to what was missing');
    }

    public function testFinesAreListedWaivedChargedAndRefunded(): void
    {
        [$event, , $ids] = $this->published(TournamentFineService::MODE_PROPORTIONAL, 500);
        $banking = new BankingService();
        $banking->addToBalance($ids['Bob'], 1000);
        $banking->addToBalance($ids['Cid'], 100);

        $service = new TournamentFineService();
        $this->assertSame(2, $service->propose($event), 'Ann reached the goal');
        $fines = $this->fetchTable('EventFines');
        $bob = $fines->find()->where(['player' => 'Bob'])->firstOrFail();
        $this->assertSame(200, (int)$bob->amount);
        $this->assertSame(500, (int)$fines->find()->where(['player' => 'Cid'])->firstOrFail()->amount);

        // The page lists them for the administrator only.
        $this->loginAdmin();
        $this->get("/events/view/{$event->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('id="fines"');
        $this->assertResponseContains('Charge 2 fine(s)');
        $this->session(['Auth' => ['id' => 2, 'email' => 'member@example.com', 'created' => new DateTime('2026-01-01')]]);
        $this->get("/events/view/{$event->id}");
        $this->assertResponseNotContains('id="fines"');

        // Cid is waived, then fined again.
        $cid = $fines->find()->where(['player' => 'Cid'])->firstOrFail();
        $this->loginAdmin();
        $this->post("/event-fines/waive/{$cid->id}");
        $this->assertSame(EventFine::STATUS_WAIVED, $fines->get($cid->id)->status);
        $this->assertSame(1, $service->propose($event), 'Bob listed again; Cid keeps the waiver');
        $this->assertSame(EventFine::STATUS_WAIVED, $fines->get($cid->id)->status);
        $bob = $fines->find()->where(['player' => 'Bob'])->firstOrFail();
        $this->loginAdmin();
        $this->post("/event-fines/waive/{$cid->id}?undo=1");
        $this->assertSame(EventFine::STATUS_PENDING, $fines->get($cid->id)->status);

        // Both are charged; Cid had 100 and now owes 400.
        $this->loginAdmin();
        $this->post("/event-fines/charge/{$event->id}");
        $this->assertRedirectContains("/events/view/{$event->id}");
        $bob = $fines->get($bob->id);
        $this->assertSame(EventFine::STATUS_CHARGED, $bob->status);
        $this->assertSame(800, $banking->getBalance($ids['Bob']));
        $this->assertSame(EventFine::STATUS_CHARGED, $fines->get($cid->id)->status);
        $this->assertSame(-400, $banking->getBalance($ids['Cid']), 'a debt the player pays by depositing');

        // The fine is a fee the clan collects: nothing leaves the bank.
        $transactions = $this->fetchTable('BankTransactions');
        $debit = $transactions->get($bob->bank_transaction_id);
        $this->assertSame(['withdrawal', 'approved', 0, 200, 200],
            [$debit->type, $debit->status, (int)$debit->amount, (int)$debit->fee, (int)$debit->final_amount]);
        $fees = fn (): int => (int)$transactions->find()->where(['status' => 'approved'])->all()->sumOf('fee');
        $this->assertSame(700, $fees());

        // Cid deposits what he owes: back to 0, the clan keeps the 500.
        $banking->addToBalance($ids['Cid'], 400);
        $this->assertSame(0, $banking->getBalance($ids['Cid']));

        // Bob's fine is refunded: his withdrawal is canceled and leaves the fees.
        $this->loginAdmin();
        $this->post("/event-fines/refund/{$bob->id}");
        $this->assertSame(EventFine::STATUS_REFUNDED, $fines->get($bob->id)->status);
        $this->assertSame(1000, $banking->getBalance($ids['Bob']));
        $this->assertSame('canceled', $transactions->get($bob->bank_transaction_id)->status);
        $this->assertSame(500, $fees());
    }

    public function testTheHistoryIsFilteredAndADebtOfOneWhoLeftIsGivenUp(): void
    {
        [$event, , $ids] = $this->published(TournamentFineService::MODE_PROPORTIONAL, 500);
        $banking = new BankingService();
        $banking->addToBalance($ids['Bob'], 1000);
        $banking->addToBalance($ids['Cid'], 100);
        $service = new TournamentFineService();
        $service->propose($event);
        $service->charge($event, 1);
        // Cid owes 400, leaves the clan, and pays 100 of it.
        $this->fetchTable('Members')->updateAll(['active' => 0], ['id' => $ids['Cid']]);
        $banking->addToBalance($ids['Cid'], 100);

        $owing = $service->owing();
        $this->assertSame(['Cid', false, 300], [$owing[0]['player'], $owing[0]['in_clan'], $owing[0]['owed']], 'who left first');
        $this->assertCount(1, $owing, 'Bob paid his fine from his balance');

        // The page: totals, who owes, and the history filtered.
        $this->loginAdmin();
        $this->get('/event-fines?player=Bo');
        $this->assertResponseOk();
        $this->assertResponseContains('Fines to pay, by player');
        $this->assertResponseContains('Give up as lost');
        $body = (string)$this->_response->getBody();
        $history = substr($body, (int)strpos($body, 'Fine history'));
        $this->assertStringContainsString('>Bob<', $history);
        $this->assertStringNotContainsString('>Cid<', $history);
        $this->loginAdmin();
        $this->get("/event-fines?event_id={$event->id}&status=charged");
        $this->assertResponseOk();
        $this->session(['Auth' => ['id' => 2, 'email' => 'member@example.com', 'created' => new DateTime('2026-01-01')]]);
        $this->get('/event-fines');
        $this->assertResponseCode(403);

        // Given up: Cid back to 0, the clan keeps only the 200 he paid.
        $this->loginAdmin();
        $this->post('/event-fines/write-off', ['key' => 'm' . $ids['Cid']]);
        $this->assertRedirectContains('/event-fines');
        $this->assertSame(0, $banking->getBalance($ids['Cid']));
        $cid = $this->fetchTable('EventFines')->find()->where(['player' => 'Cid'])->firstOrFail();
        $this->assertSame([EventFine::STATUS_LOST, 300], [$cid->status, (int)$cid->lost_amount]);
        $this->assertSame(200, (int)$this->fetchTable('BankTransactions')->get($cid->bank_transaction_id)->fee);
        $this->assertSame([], $service->owing());

        // The player's goal history shows the fines.
        $this->loginAdmin();
        $this->get('/player-cycle-summaries/player-history/Cid');
        $this->assertResponseOk();
        $this->assertResponseContains('Tournament fines');
        $this->assertResponseContains('300M given up');
        $this->assertResponseContains('Nothing to pay');
    }

    public function testATournamentWithoutAFineFinesNobody(): void
    {
        [$event] = $this->published(TournamentFineService::MODE_NONE, 0);
        $this->assertSame(0, (new TournamentFineService())->propose($event));

        $this->loginAdmin();
        $this->post("/event-fines/charge/{$event->id}");
        $this->assertSame(0, $this->fetchTable('BankTransactions')->find()->where(['description LIKE' => '%fine%'])->count());

        $this->session(['Auth' => ['id' => 2, 'email' => 'member@example.com', 'created' => new DateTime('2026-01-01')]]);
        $this->post("/event-fines/charge/{$event->id}");
        $this->assertResponseCode(403);
    }
}
