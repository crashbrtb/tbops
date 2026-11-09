<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\Event;
use App\Model\Entity\GameTournament;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;

/**
 * @uses \App\Controller\GameTournamentsController
 * @uses \App\Controller\EventsController::review()
 */
class GameTournamentsControllerTest extends TestCase
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
        'app.BankTransactions',
        'app.Events',
        'app.EventChests',
        'app.EventRewards',
        'app.EventImports',
        'app.EventImportRows',
        'app.EventStandings',
        'app.EventRewardAllocations',
        'app.GameTournaments',
        'app.ClanTournamentResults',
    ];

    /** A 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

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

    private function upload(string $bytes, string $name = 'icon.png'): UploadedFile
    {
        $path = TMP . 'upload_' . uniqid() . '.png';
        file_put_contents($path, $bytes);

        return new UploadedFile(new Stream($path), strlen($bytes), UPLOAD_ERR_OK, $name, 'image/png');
    }

    public function testTheCatalogueIsForAdministrators(): void
    {
        $this->get('/game-tournaments');
        $this->assertRedirectContains('/users/login');

        $this->session(['Auth' => ['id' => 2, 'email' => 'member@example.com']]);
        $this->get('/game-tournaments');
        $this->assertResponseCode(403);
    }

    public function testTheNameIsEditedButTheDurationAndTheImageAreTheGames(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1024, '1');
        $catalogue->offerName($entry, 'Rise of the Ancients', GameTournament::SOURCE_UPLOADER);

        $this->loginAdmin();
        $this->get('/game-tournaments');
        $this->assertResponseOk();
        $this->assertResponseContains('Rise of the Ancients');
        $this->assertResponseContains('1024');
        $this->assertResponseContains("/game-tournaments/view/{$entry->id}");

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", [
            'name' => 'Rise of the Ancients (event)',
            'duration_days' => '3',
            'image' => $this->upload((string)base64_decode(self::PNG)),
        ]);
        $this->assertRedirect(['controller' => 'GameTournaments', 'action' => 'view', $entry->id]);

        $saved = $catalogue->get($entry->id);
        $this->assertSame('Rise of the Ancients (event)', $saved->name);
        $this->assertSame(GameTournament::SOURCE_MANUAL, $saved->name_source);
        $this->assertNull($saved->duration_days, 'the duration is the game\'s');
        $this->assertNull($saved->image_mime, 'the image is the game\'s');

        // Their sections are not offered.
        $this->loginAdmin();
        $this->get("/game-tournaments/edit/{$entry->id}?section=duration");
        $this->assertRedirect(['controller' => 'GameTournaments', 'action' => 'view', $entry->id]);
        $this->loginAdmin();
        $this->get("/game-tournaments/edit/{$entry->id}?section=image");
        $this->assertRedirect(['controller' => 'GameTournaments', 'action' => 'view', $entry->id]);
    }

    public function testTheReviewPageCorrectsWhenTheTournamentRan(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1024);

        $events = $this->fetchTable('Events');
        $event = $events->newEntity([
            'name' => 'Rise', 'criteria' => Event::CRITERIA_IMPORTED, 'contact_player' => 'x',
            'starts_at' => '2026-09-11T17:00', 'ends_at' => '2026-09-12T17:00',
        ]);
        $event->set('game_tournament_id', $entry->id, ['guard' => false]);
        $events->saveOrFail($event, ['allowNoRewards' => true]);

        $this->loginAdmin();
        $this->get("/events/review/{$event->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('value="2026-09-11T17:00"');
        $this->assertResponseContains('one day assumed');

        $this->loginAdmin();
        $this->post("/events/review/{$event->id}", ['intent' => 'dates', 'starts_at' => '2026-09-08T00:00', 'ends_at' => '2026-09-12T17:00']);
        $this->assertRedirect(['controller' => 'Events', 'action' => 'review', $event->id]);
        $this->assertSame('2026-09-08 00:00', $events->get($event->id)->starts_at->format('Y-m-d H:i'));

        $this->loginAdmin();
        $this->post("/events/review/{$event->id}", ['intent' => 'dates', 'starts_at' => '2026-09-13T00:00', 'ends_at' => '2026-09-12T17:00']);
        $this->assertFlashElement('flash/error');
        $this->assertSame('2026-09-08 00:00', $events->get($event->id)->starts_at->format('Y-m-d H:i'));
    }

    public function testPendingShowsWhatHasNoNameAndNamingItClearsThePicture(): void
    {
        $this->loginAdmin();
        $catalogue = $this->fetchTable('GameTournaments');
        $pending = $catalogue->touchType(1040);
        $catalogue->updateAll(['pending_picture' => base64_decode(self::PNG)], ['id' => $pending->id]);
        $named = $catalogue->touchType(1024);
        $named->set('name', 'Rise of the Ancients');
        $catalogue->saveOrFail($named);

        $this->get('/game-tournaments/pending');
        $this->assertResponseOk();
        $this->assertResponseContains('1040');
        $this->assertResponseContains('/game-tournaments/picture/' . $pending->id);
        $this->assertResponseNotContains('Rise of the Ancients');

        $this->get('/game-tournaments/picture/' . $pending->id);
        $this->assertResponseOk();
        $this->assertContentType('image/png');

        $this->post('/game-tournaments/assign-name/' . $pending->id, ['name' => ' Epic Hunt ']);
        $this->assertRedirect(['action' => 'pending']);

        $saved = $catalogue->get($pending->id);
        $this->assertSame('Epic Hunt', $saved->name);
        $this->assertSame(GameTournament::SOURCE_MANUAL, $saved->name_source);
        $this->assertSame(0, $catalogue->find()->where(['id' => $pending->id, 'pending_picture IS NOT' => null])->count());
        $this->assertSame(0, $catalogue->namelessCount());
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $this->loginAdmin();
        $catalogue = $this->fetchTable('GameTournaments');
        $pending = $catalogue->touchType(1040);

        $this->post('/game-tournaments/assign-name/' . $pending->id, ['name' => '  ']);

        $this->assertFlashMessage('Fill in the name of the tournament.');
        $this->assertNull($catalogue->get($pending->id)->name);
    }

    public function testThePendingPagesAreForAdministrators(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $pending = $catalogue->touchType(1040);
        $catalogue->updateAll(['pending_picture' => base64_decode(self::PNG)], ['id' => $pending->id]);

        foreach (['pending', 'picture/' . $pending->id] as $page) {
            $this->get('/game-tournaments/' . $page);
            $this->assertResponseNotContains('PNG');
            $this->assertResponseCode(302);
        }
    }

    public function testTheTournamentPageShowsEachPartAndWhatIsNotDefined(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1032, '1');
        $catalogue->offerName($entry, 'Trials of Olympus', GameTournament::SOURCE_JOURNAL);
        $results = $this->fetchTable('ClanTournamentResults');
        $result = $results->newEmptyEntity();
        $result->set([
            'entry_uid' => 'c16a5e54-15d4-447c-8e9d-d05486620e29', 'kind' => 'multistage_tournament_results_entry',
            'game_type' => 1032, 'game_tournament_key' => '1032:1', 'ended_at' => new DateTime('2026-11-06 18:00:19'),
            'position' => 2, 'points' => 43043175, 'clans_listed' => 3,
            'rewards' => json_encode([['item_id' => 702059, 'name' => 'Olympus Elite Chest', 'amount' => 1680]]),
        ], ['guard' => false]);
        $results->saveOrFail($result);

        $this->loginAdmin();
        $this->get("/game-tournaments/view/{$entry->id}");
        $this->assertResponseOk();
        $this->assertResponseContains('Trials of Olympus');
        $this->assertResponseContains('Not defined');
        $this->assertResponseContains('Olympus Elite Chest');
        $this->assertResponseContains('autoPublishSwitch');
        $this->assertResponseContains('the description, the goal, the prizes');
        $this->assertResponseNotContains('section=duration');
        $this->assertResponseNotContains('section=image');
        foreach (GameTournament::SECTIONS as $section) {
            $this->assertResponseContains("section={$section}");
        }
    }

    public function testTheGamesNameStaysAndTheDisplayNameIsSetOnItsOwn(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1032, '1');
        $catalogue->offerName($entry, 'Trials of Olympus', GameTournament::SOURCE_JOURNAL);

        $this->loginAdmin();
        $this->get("/game-tournaments/edit/{$entry->id}?section=name");
        $this->assertResponseOk();
        $this->assertResponseContains('readonly');

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", [
            'section' => 'name', 'name' => 'Something else', 'display_name' => 'Olimpo', 'duration_days' => '9',
        ]);
        $this->assertRedirect(['controller' => 'GameTournaments', 'action' => 'view', $entry->id]);
        $saved = $catalogue->get($entry->id);
        $this->assertSame('Trials of Olympus', $saved->name, 'the game names it');
        $this->assertSame('Olimpo', $saved->display_name);
        $this->assertSame('Olimpo', $saved->displayName());
        $this->assertNull($saved->duration_days, 'only the section edited is saved');

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", ['section' => 'description', 'description' => 'Clan stages']);
        $this->assertSame('Clan stages', $catalogue->get($entry->id)->description);
        $this->assertSame('Olimpo', $catalogue->get($entry->id)->display_name);
    }

    public function testThePrizesToSplitAreChosenAmongWhatTheClanReceived(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1032, '1');
        $results = $this->fetchTable('ClanTournamentResults');
        $result = $results->newEmptyEntity();
        $result->set([
            'entry_uid' => 'c16a5e54-15d4-447c-8e9d-d05486620e29', 'kind' => 'multistage_tournament_results_entry',
            'game_type' => 1032, 'game_tournament_key' => '1032:1', 'ended_at' => new DateTime('2026-11-06 18:00:19'),
            'position' => 2, 'points' => 43043175, 'clans_listed' => 3,
            'rewards' => json_encode([
                ['item_id' => 702059, 'name' => 'Olympus Elite Chest', 'amount' => 1680],
                ['item_id' => 20, 'name' => 'Scientific Tractates', 'amount' => 500000000],
            ]),
        ], ['guard' => false]);
        $results->saveOrFail($result);

        $this->loginAdmin();
        $this->get("/game-tournaments/edit/{$entry->id}?section=prizes");
        $this->assertResponseOk();
        $this->assertResponseContains('Scientific Tractates');

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", ['section' => 'prizes', 'prizes' => [
            702059 => ['selected' => '1', 'rule' => 'equal', 'min_points' => '10', 'remainder' => 'keep'],
            20 => ['selected' => '0', 'rule' => 'proportional'],
        ]]);
        $this->assertRedirect(['controller' => 'GameTournaments', 'action' => 'view', $entry->id]);
        $this->assertSame([[
            'item_id' => 702059, 'name' => 'Olympus Elite Chest', 'rule' => 'equal', 'min_points' => 10, 'remainder' => 'keep',
        ]], $catalogue->get($entry->id)->prizeSelection());
    }

    public function testPublishingWithoutReviewIsSwitchedPerTournament(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1032, '1');
        $this->assertFalse((bool)$catalogue->get($entry->id)->auto_publish, 'off by default');

        $this->loginAdmin();
        $this->post("/game-tournaments/auto-publish/{$entry->id}", ['auto_publish' => '1']);
        $this->assertRedirect(['controller' => 'GameTournaments', 'action' => 'view', $entry->id]);
        $this->assertTrue((bool)$catalogue->get($entry->id)->auto_publish);
        $this->assertFlashElement('flash/warning');

        $this->loginAdmin();
        $this->post("/game-tournaments/auto-publish/{$entry->id}", ['auto_publish' => '0']);
        $this->assertFalse((bool)$catalogue->get($entry->id)->auto_publish);

        $this->session(['Auth' => ['id' => 2, 'email' => 'member@example.com']]);
        $this->post("/game-tournaments/auto-publish/{$entry->id}", ['auto_publish' => '1']);
        $this->assertResponseCode(403);
        $this->assertFalse((bool)$catalogue->get($entry->id)->auto_publish);
    }

}
