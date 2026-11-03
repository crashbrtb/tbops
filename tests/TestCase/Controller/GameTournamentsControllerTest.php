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

    public function testEditingNameDurationAndImage(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1024, '1');
        $catalogue->offerName($entry, 'Rise of the Ancients', GameTournament::SOURCE_JOURNAL);

        $this->loginAdmin();
        $this->get('/game-tournaments');
        $this->assertResponseOk();
        $this->assertResponseContains('Rise of the Ancients');
        $this->assertResponseContains('1024');

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", [
            'name' => 'Rise of the Ancients (event)',
            'duration_days' => '3',
            'image' => $this->upload((string)base64_decode(self::PNG)),
        ]);
        $this->assertRedirect(['controller' => 'GameTournaments', 'action' => 'index']);

        $saved = $catalogue->get($entry->id);
        $this->assertSame('Rise of the Ancients (event)', $saved->name);
        $this->assertSame(GameTournament::SOURCE_MANUAL, $saved->name_source);
        $this->assertSame(3, $saved->duration_days);
        $this->assertSame('image/png', $saved->image_mime);

        // The image is public: tournament pages show it to players.
        $this->session([]);
        $this->get("/game-tournaments/image/{$entry->id}");
        $this->assertResponseOk();
        $this->assertContentType('image/png');

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", ['name' => 'Rise of the Ancients (event)', 'duration_days' => '3', 'remove_image' => '1']);
        $this->assertNull($catalogue->get($entry->id)->image_mime);
    }

    public function testInvalidDurationOrImageIsRefused(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1007);

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", ['name' => '', 'duration_days' => '0']);
        $this->assertResponseOk();
        $this->assertNull($catalogue->get($entry->id)->duration_days);

        $this->loginAdmin();
        $this->post("/game-tournaments/edit/{$entry->id}", ['name' => '', 'duration_days' => '2', 'image' => $this->upload('not an image')]);
        $this->assertResponseOk();
        $this->assertNull($catalogue->get($entry->id)->image_mime);
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
}
