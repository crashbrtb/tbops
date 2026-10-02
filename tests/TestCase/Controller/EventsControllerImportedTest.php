<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\Event;
use App\Model\Entity\EventImport;
use App\Model\Entity\EventReward;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;

/**
 * Game tournaments in App\Controller\EventsController: from creation to the
 * published result players see.
 *
 * @uses \App\Controller\EventsController
 */
class EventsControllerImportedTest extends TestCase
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
        'app.BankTransactions',
    ];

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
        $members->saveOrFail($members->newEntity([
            'player' => 'Bank KOK', 'active' => 1, 'game_player_id' => 999000111, 'administrative_account' => true,
        ]));
        $members->saveOrFail($members->newEntity(['player' => 'WARLOCK', 'active' => 1]));
    }

    private function loginAdmin(): void
    {
        $this->session(['Auth' => ['id' => 1, 'email' => 'admin@example.com', 'created' => new DateTime('2026-01-01')]]);
    }

    private function tournament(): Event
    {
        $events = $this->fetchTable('Events');
        $event = $events->newEntity([
            'name' => 'Rise of the Ancients',
            'criteria' => Event::CRITERIA_IMPORTED,
            'starts_at' => '2026-09-10T00:00',
            'ends_at' => '2026-09-10T23:59',
            'contact_player' => 'Naughtius',
            'event_rewards' => [
                ['item_name' => 'Artifact pieces', 'quantity' => 100, 'rule' => EventReward::RULE_PROPORTIONAL],
                ['item_name' => 'Coins', 'quantity' => 9, 'rule' => EventReward::RULE_EQUAL],
            ],
        ]);
        $events->saveOrFail($event);

        return $event;
    }

    private function uploadCsv(Event $event, string $csv = self::CSV): void
    {
        $path = TMP . 'ranking_' . uniqid() . '.csv';
        file_put_contents($path, $csv);
        $this->loginAdmin();
        $this->post("/events/review/{$event->id}", [
            'intent' => 'upload',
            'ranking_file' => new UploadedFile(new Stream($path), strlen($csv), UPLOAD_ERR_OK, 'ranking.csv', 'text/csv'),
        ]);
        @unlink($path);
    }

    public function testCreatingAGameTournamentWithRewards(): void
    {
        $this->loginAdmin();
        $this->post('/events/add', [
            'name' => 'Daily tournament',
            'criteria' => Event::CRITERIA_IMPORTED,
            // Played yesterday: past dates are fine for a tournament.
            'starts_at' => DateTime::now()->subDays(1)->format('Y-m-d\T00:00'),
            'ends_at' => DateTime::now()->subDays(1)->format('Y-m-d\T23:59'),
            'prize' => '',
            'contact_player' => 'Naughtius',
            'event_rewards' => [
                ['item_name' => 'Artifact pieces', 'quantity' => '1.500', 'rule' => 'proportional', 'min_points' => '1', 'remainder' => 'top_ranked'],
                ['item_name' => '', 'quantity' => '', 'rule' => 'equal', 'min_points' => '1', 'remainder' => 'top_ranked'],
            ],
        ]);

        $event = $this->fetchTable('Events')->find()->contain(['EventRewards'])->firstOrFail();
        $this->assertRedirect(['controller' => 'Events', 'action' => 'review', $event->id]);
        $this->assertCount(1, $event->event_rewards, 'the blank line the form keeps is ignored');
        $this->assertSame(1500, $event->event_rewards[0]->quantity);
        $this->assertStringContainsString('Artifact pieces', $event->prize);
        $this->assertSame(Event::STATE_AWAITING, $event->state);
    }

    public function testATournamentCanBeCreatedWithoutRewards(): void
    {
        $this->loginAdmin();
        $this->post('/events/add', [
            'name' => 'No rewards',
            'criteria' => Event::CRITERIA_IMPORTED,
            'starts_at' => '2026-09-01T00:00',
            'ends_at' => '2026-09-01T23:59',
            'prize' => '',
            'contact_player' => 'Naughtius',
            'no_rewards' => '1',
            // Lines typed before ticking "no rewards" are dropped with it.
            'event_rewards' => [['item_name' => 'Coins', 'quantity' => '10', 'rule' => 'equal', 'min_points' => '1', 'remainder' => 'top_ranked']],
        ]);

        $event = $this->fetchTable('Events')->find()->contain(['EventRewards'])->firstOrFail();
        $this->assertRedirect(['controller' => 'Events', 'action' => 'review', $event->id]);
        $this->assertSame([], $event->event_rewards);

        // Its ranking can be published as it is.
        $this->uploadCsv($event);
        $this->loginAdmin();
        $this->post("/events/review/{$event->id}", ['intent' => 'publish', 'rows' => []]);
        $this->assertRedirect(['controller' => 'Events', 'action' => 'view', $event->id]);
        $this->assertNotNull($this->fetchTable('Events')->get($event->id)->published_at);

        $this->session([]);
        $this->get("/events/view/{$event->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('This tournament has no rewards.');
    }

    public function testRewardsCanBeAddedToAPublishedTournamentWithoutTouchingThePoints(): void
    {
        $events = $this->fetchTable('Events');
        $event = $events->newEntity([
            'name' => 'Published bare',
            'criteria' => Event::CRITERIA_IMPORTED,
            'starts_at' => '2026-09-10T00:00',
            'ends_at' => '2026-09-10T23:59',
            'contact_player' => 'Naughtius',
            'event_rewards' => [],
        ]);
        $events->saveOrFail($event);
        $this->uploadCsv($event);
        $this->loginAdmin();
        $this->post("/events/review/{$event->id}", ['intent' => 'publish', 'rows' => []]);

        $standings = $this->fetchTable('EventStandings');
        $before = $standings->find()->where(['event_id' => $event->id])->orderBy(['position' => 'ASC'])
            ->all()->combine('position', 'points')->toArray();
        $this->assertCount(4, $before);

        $this->loginAdmin();
        $this->get("/events/edit/{$event->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('nobody\'s points change');

        $this->loginAdmin();
        $this->post("/events/edit/{$event->id}", [
            'name' => 'Published bare',
            // Even a forged kind change is ignored: the result stays a tournament.
            'criteria' => Event::CRITERIA_CHEST_SCORE,
            'starts_at' => '2026-09-10T00:00',
            'ends_at' => '2026-09-10T23:59',
            'prize' => '',
            'contact_player' => 'Naughtius',
            // Points are not a field of this form: a forged one changes nothing.
            'points' => 1,
            'event_rewards' => [
                ['item_name' => 'Coins', 'quantity' => '2', 'rule' => 'equal', 'min_points' => '1', 'remainder' => 'top_ranked'],
            ],
        ]);
        $this->assertRedirect(['controller' => 'Events', 'action' => 'review', $event->id]);

        $event = $events->get($event->id, contain: ['EventRewards']);
        $this->assertSame(Event::CRITERIA_IMPORTED, $event->criteria);
        $this->assertNotNull($event->published_at);
        $this->assertCount(1, $event->event_rewards);
        $after = $standings->find()->where(['event_id' => $event->id])->orderBy(['position' => 'ASC'])
            ->all()->combine('position', 'points')->toArray();
        $this->assertSame($before, $after);

        // Naughtius and WARLOCK each take one; Bank KOK is administrative, BENAR scored 0.
        $result = (new \App\Service\EventImportService())->publishedResult($event);
        $this->assertSame([$event->event_rewards[0]->id => 2], $result['totals']);
        $this->assertSame(2, $result['recipients']);

        // A reward nobody can receive is refused and the earlier one is kept.
        $this->loginAdmin();
        $this->post("/events/edit/{$event->id}", [
            'name' => 'Published bare',
            'criteria' => Event::CRITERIA_IMPORTED,
            'starts_at' => '2026-09-10T00:00',
            'ends_at' => '2026-09-10T23:59',
            'prize' => '',
            'contact_player' => 'Naughtius',
            'event_rewards' => [
                ['item_name' => 'Gems', 'quantity' => '5', 'rule' => 'equal', 'min_points' => '999999999999', 'remainder' => 'top_ranked'],
            ],
        ]);
        $this->assertRedirect(['controller' => 'Events', 'action' => 'edit', $event->id]);
        $this->assertFlashElement('flash/error');
        $kept = $events->get($event->id, contain: ['EventRewards']);
        $this->assertSame('Coins', $kept->event_rewards[0]->item_name);
        $this->assertSame(2, array_sum((new \App\Service\EventImportService())->publishedResult($kept)['totals']));
    }

    public function testUploadReviewAndPublish(): void
    {
        $event = $this->tournament();

        $this->loginAdmin();
        $this->get("/events/review/{$event->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('No ranking has been received yet.');

        $this->uploadCsv($event);
        $this->assertRedirect(['controller' => 'Events', 'action' => 'review', $event->id]);
        $this->assertFlashElement('flash/success');

        $this->loginAdmin();
        $this->get("/events/review/{$event->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('Naughtius Maximus');
        $this->assertResponseContains('Publish result');

        $import = $this->fetchTable('EventImports')->current($event->id);
        $rows = collection($import->event_import_rows)->indexBy('raw_name')->toArray();
        $this->assertFalse($rows['Bank KOK']->eligible);

        // Exclude BENAR by hand and publish in the same step.
        $this->loginAdmin();
        $this->post("/events/review/{$event->id}", [
            'intent' => 'publish',
            'rows' => [
                $rows['BENAR']->id => ['eligible' => '0', 'member_id' => '', 'points' => '0'],
            ],
        ]);
        $this->assertRedirect(['controller' => 'Events', 'action' => 'view', $event->id]);

        $event = $this->fetchTable('Events')->get($event->id);
        $this->assertNotNull($event->published_at);
        $this->assertSame(EventImport::STATUS_PUBLISHED, $this->fetchTable('EventImports')->get($import->id)->status);

        // Players see it without logging in.
        $this->session([]);
        $this->get("/events/view/{$event->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('Tournament Result');
        $this->assertResponseContains('Artifact pieces');
        $this->assertResponseContains('No reward');

        $this->get('/events/history');
        $this->assertResponseOk();
        $this->assertResponseContains('Rise of the Ancients');
    }

    public function testUnpublishedTournamentsAreHiddenFromPlayers(): void
    {
        $event = $this->tournament();

        $this->get("/events/view/{$event->id}");
        $this->assertResponseCode(404);

        $this->get('/events/history');
        $this->assertResponseNotContains('Rise of the Ancients');

        // An administrator opening it is taken to the review.
        $this->loginAdmin();
        $this->get("/events/view/{$event->id}");
        $this->assertRedirect(['controller' => 'Events', 'action' => 'review', $event->id]);
    }

    public function testUnpublishDuplicateAndFinalizeGuard(): void
    {
        $event = $this->tournament();
        $this->uploadCsv($event);

        $this->loginAdmin();
        $this->post("/events/review/{$event->id}", ['intent' => 'publish']);
        $this->assertRedirect(['controller' => 'Events', 'action' => 'view', $event->id]);

        $this->loginAdmin();
        $this->post("/events/finalize/{$event->id}");
        $this->assertRedirect(['controller' => 'Events', 'action' => 'review', $event->id]);

        $this->loginAdmin();
        $this->post("/events/unpublish/{$event->id}");
        $this->assertRedirect(['controller' => 'Events', 'action' => 'review', $event->id]);
        $this->assertNull($this->fetchTable('Events')->get($event->id)->published_at);
        $this->assertSame(0, $this->fetchTable('EventStandings')->find()->count());

        $this->loginAdmin();
        $this->post("/events/duplicate/{$event->id}");
        $copy = $this->fetchTable('Events')->find()->where(['Events.id !=' => $event->id])->contain(['EventRewards'])->firstOrFail();
        $this->assertRedirect(['controller' => 'Events', 'action' => 'edit', $copy->id]);
        $this->assertSame(Event::CRITERIA_IMPORTED, $copy->criteria);
        $this->assertCount(2, $copy->event_rewards);
        $this->assertSame(DateTime::now()->format('Y-m-d'), $copy->starts_at->format('Y-m-d'));
    }

    public function testEventsCanBeDeletedWhateverTheirRules(): void
    {
        $events = $this->fetchTable('Events');

        // Registered by the uploader before anyone set its rewards.
        $tournament = $events->newEntity([
            'name' => 'From the uploader', 'criteria' => Event::CRITERIA_IMPORTED, 'contact_player' => 'x',
            'starts_at' => '2026-09-12T00:00', 'ends_at' => '2026-09-12T17:00',
        ]);
        $events->saveOrFail($tournament, ['allowNoRewards' => true]);

        // A custom chest event: the delete action loads it without its chests.
        $chest = $this->fetchTable('StandardChests')->find()->firstOrFail();
        $custom = $events->newEntity([
            'name' => 'Custom', 'criteria' => Event::CRITERIA_CUSTOM_CHESTS, 'prize' => 'Gold', 'contact_player' => 'x',
            'starts_at' => DateTime::now()->addDays(1)->format('Y-m-d\TH:i'),
            'ends_at' => DateTime::now()->addDays(2)->format('Y-m-d\TH:i'),
            'event_chests' => [['standard_chest_id' => $chest->id, 'source' => 'Gold Crypt']],
        ]);
        $events->saveOrFail($custom);

        foreach ([$tournament, $custom] as $event) {
            $this->loginAdmin();
            $this->post("/events/delete/{$event->id}");
            $this->assertRedirect(['controller' => 'Events', 'action' => 'manage']);
            $this->assertFlashElement('flash/success');
        }
        $this->assertSame(0, $events->find()->count());
    }

    public function testManageListsTournamentActions(): void
    {
        $event = $this->tournament();

        $this->loginAdmin();
        $this->get('/events/manage');

        $this->assertResponseOk();
        $this->assertResponseContains('Awaiting result');
        $this->assertResponseContains("/events/review/{$event->id}");
        $this->assertResponseContains('New Game Tournament');
    }

    public function testTheFormOffersTheTournamentType(): void
    {
        $this->loginAdmin();
        $this->get('/events/add?type=imported');

        $this->assertResponseOk();
        $this->assertResponseContains('value="imported"');
        $this->assertResponseContains('rewardLineTemplate');
        $this->assertResponseContains('name="event_kind" value="game" checked');
        // Past dates must not be blocked by the browser either.
        $this->assertDoesNotMatchRegularExpression('/id="starts-at"[^>]*min=/', (string)$this->_response->getBody());

        // A clan event keeps the future-only limit, and the game criteria is not sent.
        $this->loginAdmin();
        $this->get('/events/add');
        $this->assertMatchesRegularExpression('/id="starts-at"[^>]*min=/', (string)$this->_response->getBody());
        $this->assertMatchesRegularExpression('/id="gameCriteria"\s+disabled/', (string)$this->_response->getBody());
    }
}
