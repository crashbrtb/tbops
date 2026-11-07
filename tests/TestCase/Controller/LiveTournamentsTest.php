<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\Event;
use App\Model\Entity\EventReward;
use App\Model\Entity\GameTournament;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * @uses \App\Controller\Api\UploaderController::liveTournaments()
 * @uses \App\Controller\Api\UploaderController::gameImages()
 * @uses \App\Controller\EventsController::tournaments()
 * @uses \App\Controller\GameImagesController
 */
class LiveTournamentsTest extends TestCase
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
        'app.ApiTokens',
        'app.Events',
        'app.EventChests',
        'app.EventRewards',
        'app.EventImports',
        'app.EventImportRows',
        'app.EventStandings',
        'app.EventRewardAllocations',
        'app.GameTournaments',
        'app.ClanTournamentResults',
        'app.LiveTournaments',
        'app.GameImages',
        'app.GameItems',
        'app.JobRuns',
    ];

    /** A 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fetchTable('Users')->updateAll(['active' => true], ['id' => 1]);
        [, $this->token] = $this->fetchTable('ApiTokens')->issue(1, 'Clan PC');
    }

    private function authorize(): void
    {
        $this->configRequest(['headers' => [
            'Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->token,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return json_decode((string)$this->_response->getBody(), true) ?? [];
    }

    /**
     * A reading of the game's Tournaments window (2026-11-07).
     *
     * @return array<string, mixed>
     */
    private function reading(): array
    {
        return [
            'read_at' => '2026-11-07T16:06:00Z',
            'clan_id' => 936302870583,
            'tournaments' => [
                [
                    'key' => '1026:1', 'game_type' => 1026, 'variant' => 1, 'run' => 56, 'state' => 'active',
                    'starts_at' => '2026-11-06T17:00:27Z', 'ends_at' => '2026-11-08T17:00:00Z', 'name' => 'Ragnarök',
                    'banner' => 'banner_ragnarok_clan_event_708x123', 'icon' => 'map_ragnarok_widget_62',
                    'clan_points' => 39669755156, 'clan_place' => null,
                    'top_clans' => [['position' => 1, 'clan_id' => 502511173792, 'tag' => 'DeF', 'name' => 'Darkness Falls', 'points' => 968496815057]],
                    'members' => [['position' => 1, 'player_id' => 661425220559, 'name' => 'Bob', 'points' => 1114715569]],
                    'rewards' => [['from' => 1, 'to' => 1, 'items' => [['item_id' => 31, 'name' => 'Dragon Coins', 'icon' => 'fangs_coin_72', 'amount' => 10500000]]]],
                ],
                [
                    'key' => '1007:1', 'game_type' => 1007, 'variant' => 1, 'run' => 448, 'state' => 'upcoming',
                    'starts_at' => '2026-11-07T17:00:00Z', 'ends_at' => null, 'name' => 'Doomsday',
                    'banner' => 'banner_doomsday_708', 'icon' => 'doomsday_icon_tournir_62',
                ],
            ],
            'items' => [['item_id' => 31, 'name' => 'Dragon Coins', 'icon' => 'fangs_coin_72']],
        ];
    }

    public function testAReadingReplacesTheListAndTheCatalogueTakesTheGamesImages(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1026, '1');
        $catalogue->offerName($entry, 'Ragnarök tournament', GameTournament::SOURCE_JOURNAL);

        $this->authorize();
        $this->post('/api/v1/live-tournaments', json_encode($this->reading()));
        $this->assertResponseOk();
        $this->assertSame(2, $this->body()['kept']);
        $this->assertSame(1, $this->body()['items']);

        $saved = $catalogue->get($entry->id);
        $this->assertSame('map_ragnarok_widget_62', $saved->get('game_icon'));
        $this->assertSame('banner_ragnarok_clan_event_708x123', $saved->get('game_banner'));

        // The next reading no longer has Doomsday: it is gone from the list.
        $next = $this->reading();
        unset($next['tournaments'][1]);
        $this->authorize();
        $this->post('/api/v1/live-tournaments', json_encode($next));
        $this->assertSame(1, $this->body()['removed']);
        $this->assertSame(['1026:1'], $this->fetchTable('LiveTournaments')->find()->all()->extract('game_key')->toList());
    }

    public function testImagesAreKeptByTheirCdnFileAndServedToEveryone(): void
    {
        $this->authorize();
        $this->get('/api/v1/game-images');
        $this->assertSame([], (array)$this->body()['images']);

        $this->authorize();
        $this->post('/api/v1/game-images', json_encode(['images' => [
            ['name' => 'fangs_coin_72', 'file_id' => '2fe1793b2a4de61ece36d1325f1050e9', 'png' => self::PNG],
            ['name' => 'bad name!', 'file_id' => 'abc', 'png' => 'xx'],
        ]]));
        $this->assertSame(1, $this->body()['saved']);
        $this->assertArrayHasKey('images.1', $this->body()['errors']);

        $this->authorize();
        $this->get('/api/v1/game-images');
        $this->assertSame(['fangs_coin_72' => '2fe1793b2a4de61ece36d1325f1050e9'], $this->body()['images']);

        $this->configRequest(['headers' => []]);
        $this->get('/game-image/fangs_coin_72');
        $this->assertResponseOk();
        $this->assertContentType('image/png');
        $this->get('/game-image/nothing_here');
        $this->assertResponseCode(404);
    }

    public function testTheSiteAsksForTheItemsOfThePrizesItDoesNotKnow(): void
    {
        $results = $this->fetchTable('ClanTournamentResults');
        $result = $results->newEmptyEntity();
        $result->set([
            'entry_uid' => 'c16a5e54-15d4-447c-8e9d-d05486620e29', 'kind' => 'multistage_tournament_results_entry',
            'game_type' => 1032, 'game_tournament_key' => '1032:1', 'ended_at' => new DateTime('2026-11-06 18:00:19'),
            'position' => 2, 'points' => 43043175, 'clans_listed' => 3,
            'rewards' => json_encode([['item_id' => 702059, 'name' => 'Olympus Elite Chest', 'amount' => 1680], ['item_id' => 31, 'name' => 'Dragon Coins', 'amount' => 1]]),
        ], ['guard' => false]);
        $results->saveOrFail($result);
        (new \App\Service\LiveTournamentService())->recordItems([['item_id' => 31, 'name' => 'Dragon Coins', 'icon' => 'fangs_coin_72']]);

        $this->authorize();
        $this->get('/api/v1/game-images');
        $this->assertSame([702059], $this->body()['items_needed']);
    }

    public function testThePageListsTheRunningAndTheWaitingTournamentsWithWhatTheSiteKnows(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1026, '1');
        $catalogue->offerName($entry, 'Ragnarök tournament', GameTournament::SOURCE_JOURNAL);
        $catalogue->updateAll([
            'display_name' => 'Ragnarok do clã',
            'goal_mode' => 'global', 'goal_points' => 5000000,
            'prize_items' => json_encode([['item_id' => 31, 'name' => 'Dragon Coins', 'rule' => EventReward::RULE_EQUAL, 'min_points' => 1, 'remainder' => 'keep']]),
        ], ['id' => $entry->id]);
        $events = $this->fetchTable('Events');
        $event = $events->newEntity([
            'name' => 'Ragnarok', 'criteria' => Event::CRITERIA_IMPORTED, 'starts_at' => '2026-10-30T17:00', 'ends_at' => '2026-11-01T17:00',
            'contact_player' => 'x', 'event_rewards' => [['item_name' => 'Dragon Coins', 'quantity' => 900000, 'rule' => EventReward::RULE_EQUAL]],
        ]);
        $event->set(['game_tournament_id' => $entry->id, 'game_tournament_key' => '1026:1', 'published_at' => new DateTime('2026-11-01 18:00')], ['guard' => false]);
        $events->saveOrFail($event);

        $this->authorize();
        $this->post('/api/v1/live-tournaments', json_encode($this->reading()));

        $this->configRequest(['headers' => []]);
        $this->get('/events/tournaments');
        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        $this->assertLessThan(strpos($body, 'Doomsday'), strpos($body, 'Ragnarok do clã'), 'the running ones first');
        $this->assertResponseContains('/game-image/banner_ragnarok_clan_event_708x123');
        $this->assertResponseContains('/game-image/fangs_coin_72', 'not written by this test: served by the action');
        $this->assertResponseContains('Darkness Falls');
        $this->assertResponseContains('Goal: 5,000,000 points');
        $this->assertResponseContains('Dragon Coins x900,000', 'the last prize split');
        $this->assertResponseContains('Clan place: 100+');
        $this->assertResponseContains('events/history?tournament=1026');

        // The history of those events.
        $this->get('/events/history?tournament=1026');
        $this->assertResponseOk();
        $this->assertResponseContains('Ragnarok do clã');
    }
}
