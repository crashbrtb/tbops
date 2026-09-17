<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use App\Model\Entity\Event;
use App\Model\Entity\EventImport;
use App\Model\Entity\EventReward;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * @uses \App\Controller\Api\UploaderController
 */
class UploaderControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Users',
        'app.Roles',
        // User 1 holds role 1: an administrator.
        'app.RolesUsers',
        'app.Members',
        'app.CollectedChests',
        'app.PlayerNameMappings',
        'app.ApiTokens',
        'app.Events',
        'app.EventChests',
        'app.EventRewards',
        'app.EventImports',
        'app.EventImportRows',
        'app.EventStandings',
        'app.EventRewardAllocations',
        'app.GameTournaments',
    ];

    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $users = $this->fetchTable('Users');
        $users->updateAll(['active' => true], ['id' => 1]);
        $member = $users->newEntity(['name' => 'Member', 'email' => 'member@example.com', 'password' => 'secret']);
        $member->set('id', 2);
        $member->set('active', true);
        $users->saveOrFail($member);

        [, $this->adminToken] = $this->fetchTable('ApiTokens')->issue(1, 'Clan PC');
    }

    /**
     * @param string|null $token Token to send, or null for none.
     * @return void
     */
    private function authorize(?string $token): void
    {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $this->configRequest(['headers' => $headers]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return json_decode((string)$this->_response->getBody(), true) ?? [];
    }

    /**
     * @param array<string, mixed> $overrides Fields.
     * @return \App\Model\Entity\Event
     */
    private function tournament(array $overrides = []): Event
    {
        $events = $this->fetchTable('Events');
        $event = $events->newEntity($overrides + [
            'name' => 'Rise of the Ancients',
            'criteria' => Event::CRITERIA_IMPORTED,
            'starts_at' => '2026-09-10T00:00',
            'ends_at' => '2026-09-10T23:59',
            'contact_player' => 'Naughtius',
            'event_rewards' => [['item_name' => 'Pieces', 'quantity' => 90, 'rule' => EventReward::RULE_PROPORTIONAL]],
        ]);
        $events->saveOrFail($event);

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function ranking(): array
    {
        return [
            'game_event_name' => 'Rise of the Ancients tournament',
            'capture_method' => 'packet',
            'client_version' => '0.2.0',
            'rows' => [
                ['position' => 1, 'name' => 'Naughtius Maximus', 'points' => 1703103642, 'player_id' => 300647954111],
                ['position' => 2, 'name' => 'Lion', 'points' => 873578580, 'player_id' => 438086712193],
                ['position' => 3, 'name' => 'Lion', 'points' => 0, 'player_id' => 4307852203038],
            ],
        ];
    }

    public function testRequestsWithoutAValidTokenAreRefused(): void
    {
        $this->authorize(null);
        $this->get('/api/v1/me');
        $this->assertResponseCode(401);
        $this->assertHeader('WWW-Authenticate', 'Bearer');
        $this->assertArrayHasKey('error', $this->body());

        $this->authorize('cct_not-a-real-token');
        $this->get('/api/v1/me');
        $this->assertResponseCode(401);
    }

    public function testRevokedAndExpiredTokensAreRefused(): void
    {
        $tokens = $this->fetchTable('ApiTokens');
        [$revoked, $plainRevoked] = $tokens->issue(1, 'old');
        $tokens->updateAll(['revoked_at' => DateTime::now()], ['id' => $revoked->id]);
        [$expired, $plainExpired] = $tokens->issue(1, 'expired');
        $tokens->updateAll(['expires_at' => DateTime::now()->subDays(1)], ['id' => $expired->id]);

        foreach ([$plainRevoked, $plainExpired] as $plain) {
            $this->authorize($plain);
            $this->get('/api/v1/me');
            $this->assertResponseCode(401);
        }
    }

    public function testTheTokenCanAlsoComeInXApiToken(): void
    {
        // For hosts that strip the Authorization header before PHP.
        $this->configRequest(['headers' => ['Accept' => 'application/json', 'X-Api-Token' => $this->adminToken]]);
        $this->get('/api/v1/me');

        $this->assertResponseOk();
    }

    public function testNonAdministratorsAreRefused(): void
    {
        [, $plain] = $this->fetchTable('ApiTokens')->issue(2, 'member');

        $this->authorize($plain);
        $this->get('/api/v1/me');

        $this->assertResponseCode(403);
    }

    public function testMeIdentifiesTheTokenAndRecordsItsUse(): void
    {
        $this->authorize($this->adminToken);
        $this->get('/api/v1/me');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $body = $this->body();
        $this->assertSame(1, $body['user']['id']);
        $this->assertSame('Clan PC', $body['token']['name']);
        $this->assertStringStartsWith('cct_', $body['token']['prefix']);
        $this->assertNotNull($this->fetchTable('ApiTokens')->find()->first()->last_used_at);
    }

    public function testAwaitingListsOnlyUnpublishedTournaments(): void
    {
        $waiting = $this->tournament();
        $this->tournament(['name' => 'Cancelled', 'status' => Event::STATUS_CANCELLED]);
        $this->tournament(['name' => 'Chest event', 'criteria' => Event::CRITERIA_CHEST_SCORE, 'prize' => 'Gold',
            'starts_at' => '2031-01-01T00:00', 'ends_at' => '2031-01-02T00:00']);
        $published = $this->tournament(['name' => 'Done']);
        $this->fetchTable('Events')->updateAll(['published_at' => DateTime::now()], ['id' => $published->id]);

        $this->authorize($this->adminToken);
        $this->get('/api/v1/events/awaiting');

        $this->assertResponseOk();
        $events = $this->body()['events'];
        $this->assertSame([$waiting->id], array_column($events, 'id'));
        $this->assertSame('Pieces', $events[0]['rewards'][0]['item_name']);
        $this->assertStringContainsString('/events/review/' . $waiting->id, $events[0]['review_url']);
    }

    public function testUploadCreatesADraftAndARetryDoesNotDuplicateIt(): void
    {
        $event = $this->tournament();
        $this->authorize($this->adminToken);

        $this->post("/api/v1/events/{$event->id}/imports", json_encode($this->ranking()));
        $this->assertResponseCode(201);
        $body = $this->body();
        $this->assertTrue($body['created']);
        $this->assertSame(3, $body['rows']);
        $this->assertSame(3, $body['unlinked']);
        $this->assertNotEmpty($body['warnings']);

        $import = $this->fetchTable('EventImports')->get($body['import_id']);
        $this->assertSame(EventImport::STATUS_DRAFT, $import->status);
        $this->assertSame('packet', $import->capture_method);
        $this->assertNotNull($import->api_token_id);

        // Headers are consumed by each test request, so the token is sent again.
        $this->authorize($this->adminToken);
        $this->post("/api/v1/events/{$event->id}/imports", json_encode($this->ranking()));
        $this->assertResponseCode(200);
        $this->assertFalse($this->body()['created']);
        $this->assertSame(1, $this->fetchTable('EventImports')->find()->count());
    }

    public function testInvalidRankingsAreRejectedWithTheReason(): void
    {
        $event = $this->tournament();
        $ranking = $this->ranking();
        $ranking['rows'][1]['position'] = 1;
        $ranking['rows'][2]['points'] = -1;

        $this->authorize($this->adminToken);
        $this->post("/api/v1/events/{$event->id}/imports", json_encode($ranking));

        $this->assertResponseCode(422);
        $this->assertArrayHasKey('rows.2.position', $this->body()['errors']);
        $this->assertArrayHasKey('rows.3.points', $this->body()['errors']);
        $this->assertSame(0, $this->fetchTable('EventImports')->find()->count());
    }

    public function testEventsThatCannotTakeARankingAreRefused(): void
    {
        $this->authorize($this->adminToken);

        $this->post('/api/v1/events/9999/imports', json_encode($this->ranking()));
        $this->assertResponseCode(404);

        $published = $this->tournament();
        $this->fetchTable('Events')->updateAll(['published_at' => DateTime::now()], ['id' => $published->id]);
        $this->authorize($this->adminToken);
        $this->post("/api/v1/events/{$published->id}/imports", json_encode($this->ranking()));
        $this->assertResponseCode(409);

        $chests = $this->tournament(['criteria' => Event::CRITERIA_CHEST_COUNT, 'prize' => 'Gold',
            'starts_at' => '2031-01-01T00:00', 'ends_at' => '2031-01-02T00:00']);
        $this->authorize($this->adminToken);
        $this->post("/api/v1/events/{$chests->id}/imports", json_encode($this->ranking()));
        $this->assertResponseCode(422);
    }

    /**
     * @param array<string, mixed> $overrides Tournament fields.
     * @return array<string, mixed>
     */
    private function tournamentUpload(array $overrides = []): array
    {
        return $overrides + [
            'result_uid' => 'eede1d1c-3f13-481a-819d-51fdcebb1e54',
            'tournament_key' => '1024:1',
            'name' => 'Rise of the Ancients',
            'ended_at' => '2026-09-12T17:00:26Z',
        ] + $this->ranking();
    }

    public function testTheUploaderRegistersATournamentInThePast(): void
    {
        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($this->tournamentUpload()));

        $this->assertResponseCode(201);
        $body = $this->body();
        $this->assertTrue($body['event_created']);
        $this->assertSame('catalog', $body['name_source'], 'the name sent is recorded in the catalogue first');
        $this->assertSame(0, $body['rewards']);
        $this->assertSame(3, $body['rows']);
        $this->assertStringContainsString('no reward', implode(' ', $body['warnings']));

        $event = $this->fetchTable('Events')->get($body['event_id'], contain: ['EventRewards']);
        $this->assertSame(Event::CRITERIA_IMPORTED, $event->criteria);
        $this->assertSame('Rise of the Ancients', $event->name);
        $this->assertSame('1024:1', $event->game_tournament_key);
        $this->assertSame('2026-09-12 17:00:26', $event->ends_at->format('Y-m-d H:i:s'));
        // No duration in the catalogue yet: one day before the end.
        $this->assertSame('2026-09-11 17:00:26', $event->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame(1024, $this->fetchTable('GameTournaments')->get($event->game_tournament_id)->game_type);
        $this->assertSame(1, $event->created_by);
        $this->assertSame(Event::STATE_AWAITING, $event->state);
        $this->assertSame(EventImport::STATUS_DRAFT, $this->fetchTable('EventImports')->current($event->id)->status);

        // Sending the same result again reaches the same event and the same draft.
        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($this->tournamentUpload()));
        $this->assertResponseCode(200);
        $this->assertFalse($this->body()['event_created']);
        $this->assertFalse($this->body()['created']);
        $this->assertSame(1, $this->fetchTable('Events')->find()->count());
        $this->assertSame(1, $this->fetchTable('EventImports')->find()->count());
    }

    public function testANewResultOfAKnownTypeInheritsNameAndRewards(): void
    {
        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($this->tournamentUpload()));
        $first = $this->fetchTable('Events')->get($this->body()['event_id']);

        // The administrator says once what this tournament pays.
        $rewards = $this->fetchTable('EventRewards');
        $rewards->saveOrFail($rewards->newEntity([
            'item_name' => 'Artifact pieces', 'quantity' => 500, 'rule' => EventReward::RULE_PROPORTIONAL,
            'min_points' => 10, 'remainder' => EventReward::REMAINDER_KEEP,
        ] + ['event_id' => $first->id], ['accessibleFields' => ['event_id' => true]]));

        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($this->tournamentUpload([
            'result_uid' => '8c1d0b6e-0000-4000-8000-000000000002',
            'name' => '',
            'ended_at' => '2026-09-13T17:00:31Z',
        ])));

        $this->assertResponseCode(201);
        $body = $this->body();
        $this->assertTrue($body['event_created']);
        // The first send put the name in the catalogue.
        $this->assertSame('catalog', $body['name_source']);
        $this->assertSame(1, $body['rewards_copied']);
        $this->assertSame('Rise of the Ancients', $body['event_name']);

        $second = $this->fetchTable('Events')->get($body['event_id'], contain: ['EventRewards']);
        $this->assertSame(500, $second->event_rewards[0]->quantity);
        $this->assertSame(10, $second->event_rewards[0]->min_points);
        $this->assertSame(EventReward::REMAINDER_KEEP, $second->event_rewards[0]->remainder);
        $this->assertNotSame($first->event_number, $second->event_number);

        $this->authorize($this->adminToken);
        $this->get('/api/v1/tournaments/known');
        $this->assertResponseOk();
        $known = $this->body()['tournaments'];
        $this->assertCount(1, $known);
        $this->assertSame(['1024:1', 'Rise of the Ancients'], [$known[0]['tournament_key'], $known[0]['name']]);
        $this->assertSame('Artifact pieces', $known[0]['rewards'][0]['item_name']);
    }

    public function testATypeNeverSeenWithoutANameGetsAPlaceholder(): void
    {
        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($this->tournamentUpload(['name' => null, 'tournament_key' => '1007:14'])));

        $this->assertResponseCode(201);
        $this->assertSame('default', $this->body()['name_source']);
        $this->assertStringContainsString('1007:14', $this->body()['event_name']);
    }

    public function testTournamentUploadsAreValidatedBeforeAnythingIsCreated(): void
    {
        $upload = $this->tournamentUpload(['result_uid' => 'x', 'tournament_key' => 'abc', 'ended_at' => '2099-01-01T00:00:00Z']);
        $upload['rows'][0]['points'] = -3;

        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($upload));

        $this->assertResponseCode(422);
        $errors = $this->body()['errors'];
        foreach (['result_uid', 'tournament_key', 'ended_at', 'rows.1.points'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
        $this->assertSame(0, $this->fetchTable('Events')->find()->count());
    }

    public function testAPublishedTournamentRefusesANewRanking(): void
    {
        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($this->tournamentUpload()));
        $eventId = $this->body()['event_id'];
        $this->fetchTable('Events')->updateAll(['published_at' => DateTime::now()], ['id' => $eventId]);

        $changed = $this->tournamentUpload();
        $changed['rows'][0]['points'] = 1;
        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($changed));

        $this->assertResponseCode(409);
        $this->assertSame(1, $this->fetchTable('EventImports')->find()->count());
    }

    /**
     * Twenty players with ids, so the ranking counts as the whole clan.
     *
     * @param list<int> $ids Game ids.
     * @param array<int, string> $names Names by game id.
     * @return list<array<string, mixed>>
     */
    private function rosterRows(array $ids, array $names = []): array
    {
        $rows = [];
        foreach ($ids as $i => $id) {
            $rows[] = [
                'position' => $i + 1,
                'name' => $names[$id] ?? "Player {$id}",
                'points' => 1000 - $i,
                'player_id' => $id,
                'power' => 2_500_000_000 + $id,
            ];
        }

        return $rows;
    }

    public function testTheStartComesFromTheCatalogueDuration(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $entry = $catalogue->touchType(1024);
        $entry->set('duration_days', 3);
        $catalogue->saveOrFail($entry);

        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($this->tournamentUpload()));

        $this->assertResponseCode(201);
        $event = $this->fetchTable('Events')->get($this->body()['event_id']);
        $this->assertSame('2026-09-09 17:00:26', $event->starts_at->format('Y-m-d H:i:s'));
    }

    public function testTheRankingKeepsTheMembersTableInStepWithTheClan(): void
    {
        $members = $this->fetchTable('Members');
        $members->deleteAll([]);
        $make = function (array $data) use ($members) {
            $member = $members->newEntity($data + ['active' => 1, 'power' => 1]);
            $members->saveOrFail($member);

            return $member;
        };
        $byId = $make(['player' => 'Old Name', 'game_player_id' => 101]);
        $byName = $make(['player' => 'Brunilda', 'active' => 0]);
        $left = $make(['player' => 'Gone', 'game_player_id' => 999]);
        $chestOnly = $make(['player' => 'Typo From OCR']);
        $twinA = $make(['player' => 'Lion']);
        $twinB = $make(['player' => 'Lion']);

        $ids = range(101, 120);
        $upload = $this->tournamentUpload();
        $upload['rows'] = $this->rosterRows($ids, [101 => 'New Name', 102 => 'Brunilda', 103 => 'Lion']);

        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($upload));
        $this->assertResponseCode(201);
        $summary = $this->body()['members'];

        $this->assertTrue($summary['applied']);
        $this->assertSame(1, $summary['renamed']);
        $this->assertSame(1, $summary['linked']);
        $this->assertSame(['Lion'], $summary['ambiguous']);
        $this->assertSame(17, $summary['created'], '20 players - 1 by id - 1 by name - 1 ambiguous');
        $this->assertSame(1, $summary['activated']);
        $this->assertSame(4, $summary['deactivated'], 'Gone, the OCR typo and the two unlinked Lions');

        $this->assertSame('New Name', $members->get($byId->id)->player);
        $this->assertSame(2_500_000_101, $members->get($byId->id)->power);
        $this->assertSame(102, $members->get($byName->id)->game_player_id);
        $this->assertSame(1, (int)$members->get($byName->id)->active);
        $this->assertSame(0, (int)$members->get($left->id)->active);
        $this->assertSame(0, (int)$members->get($chestOnly->id)->active);
        $this->assertNull($members->get($twinA->id)->game_player_id);
        $this->assertSame(1, (int)$members->find()->where(['game_player_id' => 120])->firstOrFail()->active);

        // The draft links every player by game id.
        $import = $this->fetchTable('EventImports')->current($this->body()['event_id']);
        $this->assertNotNull($import->roster_applied_at);
        $this->assertSame($byId->id, $import->event_import_rows[0]->member_id);

        // An older tournament sent afterwards does not bring anyone back.
        $older = $this->tournamentUpload([
            'result_uid' => '11111111-2222-4333-8444-555555555555',
            'ended_at' => '2026-09-01T17:00:00Z',
        ]);
        $older['rows'] = $this->rosterRows(array_merge([999], range(101, 119)));
        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($older));
        $this->assertResponseCode(201);
        $this->assertFalse($this->body()['members']['applied']);
        $this->assertSame('older', $this->body()['members']['reason']);
        $this->assertSame(0, (int)$members->get($left->id)->active);
    }

    public function testARankingWithPlayersKnownOnlyByIdDoesNotDeactivateAnyone(): void
    {
        $members = $this->fetchTable('Members');
        $members->deleteAll([]);
        $member = $members->newEntity(['player' => 'Brunilda', 'active' => 1, 'power' => 1]);
        $members->saveOrFail($member);

        // The uploader got no profiles: every name is a placeholder.
        $ids = range(301, 320);
        $upload = $this->tournamentUpload();
        $upload['rows'] = $this->rosterRows($ids, array_combine($ids, array_map(fn (int $id): string => "id:{$id}", $ids)));

        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($upload));
        $this->assertResponseCode(201);
        $summary = $this->body()['members'];

        $this->assertFalse($summary['applied']);
        $this->assertSame('unnamed', $summary['reason']);
        $this->assertSame(20, $summary['unnamed']);
        $this->assertSame(1, (int)$members->get($member->id)->active);
        $this->assertNull($this->fetchTable('EventImports')->current($this->body()['event_id'])->roster_applied_at);
    }

    public function testALaterRankingWithNamesCompletesTheDraftsSentWithoutThem(): void
    {
        $members = $this->fetchTable('Members');
        $members->deleteAll([]);

        $ids = range(401, 420);
        $unnamed = $this->tournamentUpload(['result_uid' => '11111111-2222-4333-8444-000000000001', 'ended_at' => '2026-09-01T17:00:00Z']);
        $unnamed['rows'] = $this->rosterRows($ids, array_combine($ids, array_map(fn (int $id): string => "id:{$id}", $ids)));
        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($unnamed));
        $this->assertResponseCode(201);
        $firstEvent = $this->body()['event_id'];
        $this->assertSame(0, $this->body()['linked']);

        $named = $this->tournamentUpload(['result_uid' => '11111111-2222-4333-8444-000000000002']);
        $named['rows'] = $this->rosterRows($ids);
        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($named));
        $this->assertResponseCode(201);
        $this->assertTrue($this->body()['members']['applied']);
        $this->assertSame(20, $this->body()['drafts_completed']);

        $rows = $this->fetchTable('EventImports')->current($firstEvent)->event_import_rows;
        $this->assertSame('Player 401', $rows[0]->raw_name);
        $this->assertSame(
            $members->find()->where(['game_player_id' => 401])->firstOrFail()->id,
            $rows[0]->member_id
        );
        $this->assertSame('player_id', $rows[0]->match_type);
    }

    public function testChestActivityNoLongerDecidesWhoIsActiveOnceARosterArrived(): void
    {
        $members = $this->fetchTable('Members');
        $members->deleteAll([]);
        $upload = $this->tournamentUpload();
        $upload['rows'] = $this->rosterRows(range(201, 210));
        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($upload));
        $this->assertResponseCode(201);

        $chests = $this->fetchTable('CollectedChests');
        $chests->saveOrFail($chests->newEntity([
            'name' => 'Chest', 'player' => 'Somebody New', 'source' => 'Crypt', 'type' => 0,
            'collected_at' => DateTime::now(),
        ], ['accessibleFields' => ['*' => true]]));
        $chests->saveOrFail($chests->newEntity([
            'name' => 'Chest', 'player' => 'Player 201', 'source' => 'Crypt', 'type' => 0,
            'collected_at' => DateTime::now()->subWeeks(6),
        ], ['accessibleFields' => ['*' => true]]));

        $members->updateFromCollectedChests();

        $this->assertSame(0, (int)$members->find()->where(['player' => 'Somebody New'])->firstOrFail()->active);
        $this->assertSame(1, (int)$members->find()->where(['game_player_id' => 201])->firstOrFail()->active,
            'an old chest does not deactivate a player the roster says is in the clan');
    }

    public function testTheMapperFillsTheCatalogue(): void
    {
        $catalogue = $this->fetchTable('GameTournaments');
        $manual = $catalogue->touchType(1007);
        $catalogue->offerName($manual, 'Chosen By Admin', 'manual');

        $png = base64_encode((string)file_get_contents(WWW_ROOT . 'favicon.ico'));
        $tiny = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournament-catalog', json_encode(['entries' => [
            ['tournament_key' => '1024:1', 'name' => 'Rise of the Ancients', 'ended_at' => '2026-09-12T17:00:26Z', 'image' => $tiny],
            ['tournament_key' => '1007:14', 'name' => 'Something Else'],
        ]]));

        $this->assertResponseOk();
        [$rise, $kept] = $this->body()['results'];
        $this->assertTrue($rise['created']);
        $this->assertTrue($rise['renamed']);
        $this->assertTrue($rise['image_saved']);
        $this->assertTrue($kept['name_kept']);
        $this->assertSame('Chosen By Admin', $catalogue->get($manual->id)->name);

        $entry = $catalogue->find()->where(['game_type' => 1024])->firstOrFail();
        $this->assertSame('journal', $entry->name_source);
        $this->assertSame('image/png', $entry->image_mime);

        // A name typed in the uploader does not replace what the Journal says.
        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($this->tournamentUpload(['name' => 'Typed Name'])));
        $this->assertSame('Rise of the Ancients', $this->body()['event_name']);

        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournament-catalog', json_encode(['entries' => [['tournament_key' => 'x', 'image' => 'bm90IGFuIGltYWdl']]]));
        $this->assertResponseCode(422);
        unset($png);
    }

    public function testEachRankingOfATournamentHasItsOwnNameAndRewards(): void
    {
        $damage = 'clan_members_item_gain_tracking_final_tracker_statistic_entry:omens_damage';
        $essence = 'clan_points_mining_tournament_clan_statistics_entry';

        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournament-catalog', json_encode(['entries' => [
            ['tournament_key' => '1033:1', 'ranking' => $damage, 'name' => 'score of Remnants of Dread in battles with Dark Omens'],
            ['tournament_key' => '1033:1', 'ranking' => $essence, 'name' => 'contributions of Omen Essence in the Dark Omens tournament'],
        ]]));
        $this->assertResponseOk();
        [$first, $second] = $this->body()['results'];
        $this->assertTrue($first['created']);
        $this->assertTrue($second['created'], 'same type, another ranking: another entry');
        $this->assertNotSame($first['id'], $second['id']);

        $send = function (string $uid, string $ranking): array {
            $this->authorize($this->adminToken);
            $this->post('/api/v1/tournaments', json_encode($this->tournamentUpload([
                'result_uid' => $uid, 'tournament_key' => '1033:1', 'ranking' => $ranking, 'name' => '',
            ])));
            $this->assertResponseCode(201);

            return $this->body();
        };
        $damageEvent = $send('dddddddd-0000-4000-8000-000000000001', $damage);
        $this->assertSame('score of Remnants of Dread in battles with Dark Omens', $damageEvent['event_name']);

        // Rewards set on the damage ranking are not copied to the essence ranking.
        $rewards = $this->fetchTable('EventRewards');
        $rewards->saveOrFail($rewards->newEntity([
            'item_name' => 'Dread chests', 'quantity' => 10, 'rule' => EventReward::RULE_PROPORTIONAL,
            'min_points' => 0, 'remainder' => EventReward::REMAINDER_KEEP, 'event_id' => $damageEvent['event_id'],
        ], ['accessibleFields' => ['event_id' => true]]));

        $essenceEvent = $send('dddddddd-0000-4000-8000-000000000002', $essence);
        $this->assertSame('contributions of Omen Essence in the Dark Omens tournament', $essenceEvent['event_name']);
        $this->assertSame(0, $essenceEvent['rewards_copied']);

        $this->authorize($this->adminToken);
        $this->get('/api/v1/tournaments/known');
        $rankings = array_column($this->body()['tournaments'], 'ranking');
        sort($rankings);
        $this->assertSame([$damage, $essence], $rankings);

        // The classic result keeps the empty ranking, whether it is sent or not.
        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($this->tournamentUpload(['ranking' => 'global_tournament_user_result'])));
        $this->assertResponseCode(201);
        $this->assertSame('', $this->fetchTable('GameTournaments')->find()->where(['game_type' => 1024])->firstOrFail()->ranking);

        $this->authorize($this->adminToken);
        $this->post('/api/v1/tournaments', json_encode($this->tournamentUpload([
            'result_uid' => 'dddddddd-0000-4000-8000-000000000003', 'ranking' => 'Not A Ranking!',
        ])));
        $this->assertResponseCode(422);
        $this->assertArrayHasKey('ranking', $this->body()['errors']);
    }

    public function testTheApiIgnoresTheBrowserSessionAndNeedsNoCsrfToken(): void
    {
        $event = $this->tournament();
        // A logged-in administrator's session is not enough: only the token counts.
        $this->session(['Auth' => ['id' => 1, 'email' => 'admin@example.com']]);
        $this->authorize(null);
        $this->post("/api/v1/events/{$event->id}/imports", json_encode($this->ranking()));
        $this->assertResponseCode(401);

        // And with the token, no CSRF token is needed (none was enabled here).
        $this->authorize($this->adminToken);
        $this->post("/api/v1/events/{$event->id}/imports", json_encode($this->ranking()));
        $this->assertResponseCode(201);
    }
}
