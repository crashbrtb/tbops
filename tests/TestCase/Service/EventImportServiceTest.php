<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Model\Entity\Event;
use App\Model\Entity\EventImport;
use App\Model\Entity\EventImportRow;
use App\Model\Entity\EventReward;
use App\Service\EventImportService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;
use DomainException;

/**
 * @uses \App\Service\EventImportService
 */
class EventImportServiceTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Users',
        'app.Members',
        'app.PlayerNameMappings',
        'app.Events',
        'app.EventChests',
        'app.EventRewards',
        'app.EventImports',
        'app.EventImportRows',
        'app.EventStandings',
        'app.EventRewardAllocations',
    ];

    protected EventImportService $service;

    /**
     * @var array<string, int>
     */
    protected array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EventImportService();

        $members = $this->fetchTable('Members');
        $members->deleteAll([]);
        $this->fetchTable('PlayerNameMappings')->deleteAll([]);

        // Two members called Lion: only the one whose game id is known can be
        // matched, the other has to be linked by hand.
        foreach ([
            'Naughtius' => ['game_player_id' => 300647954111],
            'WARLOCK' => ['game_player_id' => null],
            'Lion' => ['game_player_id' => 438086712193],
            'lion' => ['game_player_id' => null],
            'Bank KOK' => ['game_player_id' => 999000111, 'administrative_account' => true],
            'Brunilda' => ['game_player_id' => null],
        ] as $player => $extra) {
            $member = $members->newEntity(['player' => $player, 'active' => 1] + $extra);
            $members->saveOrFail($member);
            $this->members[$player] = $member->id;
        }
    }

    /**
     * @param array<string, mixed> $overrides Event fields.
     * @return \App\Model\Entity\Event
     */
    private function event(array $overrides = []): Event
    {
        $events = $this->fetchTable('Events');
        $event = $events->newEntity($overrides + [
            'name' => 'Rise of the Ancients',
            'criteria' => Event::CRITERIA_IMPORTED,
            'starts_at' => '2026-09-10T00:00',
            'ends_at' => '2026-09-10T23:59',
            'prize' => '',
            'contact_player' => 'Naughtius',
            'event_rewards' => [
                ['item_name' => 'Artifact pieces', 'quantity' => '1.000', 'rule' => EventReward::RULE_PROPORTIONAL],
                ['item_name' => 'Coins', 'quantity' => '10', 'rule' => EventReward::RULE_EQUAL],
            ],
        ], ['associated' => ['EventRewards']]);
        $events->saveOrFail($event, ['associated' => ['EventRewards']]);

        return $events->get($event->id, contain: ['EventRewards']);
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(): array
    {
        return [
            'game_event_name' => 'Rise of the Ancients tournament',
            'capture_method' => 'packet',
            'client_version' => '0.2',
            'rows' => [
                ['position' => 1, 'name' => 'Naughtius Maximus', 'points' => 600, 'player_id' => 300647954111],
                ['position' => 2, 'name' => 'Bank KOK', 'points' => 500, 'player_id' => 999000111],
                ['position' => 3, 'name' => 'WARLOCK', 'points' => 300, 'player_id' => 523986160991],
                ['position' => 4, 'name' => 'Lion', 'points' => 100, 'player_id' => 438086712193],
                ['position' => 5, 'name' => 'Lion', 'points' => 0, 'player_id' => 4307852203038],
            ],
        ];
    }

    /**
     * @return \App\Model\Entity\EventImport
     */
    private function current(Event $event): EventImport
    {
        return $this->fetchTable('EventImports')->current($event->id);
    }

    public function testValidateRejectsBrokenRankings(): void
    {
        $result = $this->service->validate(['rows' => [
            ['position' => 1, 'name' => 'A', 'points' => 10],
            ['position' => 1, 'name' => '', 'points' => -5, 'player_id' => 'x'],
        ]]);

        $this->assertArrayHasKey('rows.2.position', $result['errors']);
        $this->assertArrayHasKey('rows.2.name', $result['errors']);
        $this->assertArrayHasKey('rows.2.points', $result['errors']);
        $this->assertArrayHasKey('rows.2.player_id', $result['errors']);

        $this->assertArrayHasKey('rows', $this->service->validate(['rows' => []])['errors']);
        $tooMany = array_map(fn ($i) => ['position' => $i, 'name' => "P{$i}", 'points' => 1], range(1, 101));
        $this->assertArrayHasKey('rows', $this->service->validate(['rows' => $tooMany])['errors']);
    }

    public function testValidateAcceptsDigitStringsAndSortsByPosition(): void
    {
        $result = $this->service->validate(['rows' => [
            ['position' => '2', 'name' => ' B ', 'points' => '1703103642'],
            ['position' => 1, 'name' => 'A', 'points' => 4307852203038, 'player_id' => 4307852203038],
        ]]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('A', $result['payload']['rows'][0]['name']);
        $this->assertSame(4307852203038, $result['payload']['rows'][0]['game_player_id']);
        $this->assertSame(['position' => 2, 'name' => 'B', 'points' => 1703103642, 'game_player_id' => null, 'power' => null], $result['payload']['rows'][1]);
    }

    public function testImportMatchesByIdThenByUnambiguousName(): void
    {
        $event = $this->event();
        $payload = $this->service->validate($this->upload())['payload'];

        $result = $this->service->import($event, $payload, 1);
        $this->assertTrue($result['created']);

        $rows = $this->current($event)->event_import_rows;
        $this->assertCount(5, $rows);

        [$naughtius, $bank, $warlock, $lion, $otherLion] = $rows;
        $this->assertSame(EventImportRow::MATCH_PLAYER_ID, $naughtius->match_type);
        $this->assertSame($this->members['Naughtius'], $naughtius->member_id);

        $this->assertSame($this->members['Bank KOK'], $bank->member_id);
        $this->assertFalse($bank->eligible, 'administrative accounts start excluded');

        $this->assertSame(EventImportRow::MATCH_NAME, $warlock->match_type);
        $this->assertSame($this->members['WARLOCK'], $warlock->member_id);

        $this->assertSame(EventImportRow::MATCH_PLAYER_ID, $lion->match_type);
        // "lion" (no id) is the only member this second Lion can be: the other
        // Lion is tied to a different game id.
        $this->assertSame(EventImportRow::MATCH_NAME, $otherLion->match_type);
        $this->assertSame($this->members['lion'], $otherLion->member_id);
    }

    public function testNameCorrectionsAreUsedWhenNothingElseMatches(): void
    {
        $mappings = $this->fetchTable('PlayerNameMappings');
        $mappings->saveOrFail($mappings->newEntity(['ocr_text' => 'Brunhilda', 'correct_name' => 'Brunilda']));

        $rows = $this->service->matchRows([
            ['position' => 1, 'name' => 'Brunhilda', 'points' => 5, 'game_player_id' => null, 'power' => null],
            ['position' => 2, 'name' => 'Nobody', 'points' => 1, 'game_player_id' => null, 'power' => null],
        ]);

        $this->assertSame(EventImportRow::MATCH_MAPPING, $rows[0]['match_type']);
        $this->assertSame($this->members['Brunilda'], $rows[0]['member_id']);
        $this->assertSame(EventImportRow::MATCH_NONE, $rows[1]['match_type']);
        $this->assertTrue($rows[1]['eligible']);
    }

    public function testSameUploadTwiceIsStoredOnceAndANewOneSupersedesTheDraft(): void
    {
        $event = $this->event();
        $payload = $this->service->validate($this->upload())['payload'];

        $first = $this->service->import($event, $payload, 1);
        $again = $this->service->import($event, $payload, 1);
        $this->assertFalse($again['created']);
        $this->assertSame($first['import']->id, $again['import']->id);

        // A new reading can complete a name; the points must stay the game's.
        $changed = $this->upload();
        $changed['rows'][1]['name'] = 'Bank KOK renamed';
        $second = $this->service->import($event, $this->service->validate($changed)['payload'], 1);

        $this->assertTrue($second['created']);
        $imports = $this->fetchTable('EventImports');
        $this->assertSame(EventImport::STATUS_SUPERSEDED, $imports->get($first['import']->id)->status);
        $this->assertSame($second['import']->id, $this->current($event)->id);
    }

    public function testPointsReceivedFromTheGameCanNeverChange(): void
    {
        $event = $this->event();
        $this->service->import($event, $this->service->validate($this->upload())['payload'], 1);

        $changed = $this->upload();
        $changed['rows'][0]['points'] = 650;
        try {
            $this->service->import($event, $this->service->validate($changed)['payload'], 1);
            $this->fail('Different points were accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('cannot change', $e->getMessage());
        }

        // Nor a player added or left out, nor a file in place of the game's data.
        $fewer = $this->upload();
        array_pop($fewer['rows']);
        $csv = $this->upload();
        $csv['capture_method'] = 'csv';
        foreach ([$fewer, $csv] as $upload) {
            try {
                $this->service->import($event, $this->service->validate($upload)['payload'], 1);
                $this->fail('The ranking was replaced.');
            } catch (DomainException) {
            }
        }

        // Still refused after the result was published and taken down again.
        $this->service->publish($event, $this->current($event));
        $this->service->unpublish($this->fetchTable('Events')->get($event->id, contain: ['EventRewards']));
        $this->expectException(DomainException::class);
        $this->service->import($this->fetchTable('Events')->get($event->id, contain: ['EventRewards']), $this->service->validate($changed)['payload'], 1);
    }

    public function testImportIsRefusedForEventsThatDoNotTakeOne(): void
    {
        $payload = $this->service->validate($this->upload())['payload'];

        $chests = $this->event([
            'criteria' => Event::CRITERIA_CHEST_SCORE,
            'prize' => 'Gold',
            'starts_at' => '2030-01-01T00:00',
            'ends_at' => '2030-01-02T00:00',
        ]);
        $this->expectException(DomainException::class);
        $this->service->import($chests, $payload, 1);
    }

    public function testPreviewSplitsTheRewardsWithoutTheAdministrativeAccount(): void
    {
        $event = $this->event();
        $this->service->import($event, $this->service->validate($this->upload())['payload'], 1);

        $preview = $this->service->preview($event, $this->current($event));
        [$pieces, $coins] = $event->event_rewards;
        $rows = $preview['rows'];

        // Eligible points: 600 + 300 + 100 (+0) = 1000; the bank's 500 is ignored.
        $pieceSplit = $preview['distribution']['rewards'][$pieces->id];
        $this->assertSame(600, $pieceSplit['amounts'][$rows[0]->id]);
        $this->assertSame(0, $pieceSplit['amounts'][$rows[1]->id]);
        $this->assertSame(300, $pieceSplit['amounts'][$rows[2]->id]);
        $this->assertSame(1000, $pieceSplit['distributed']);

        // 10 coins among the three players with at least 1 point.
        $coinSplit = $preview['distribution']['rewards'][$coins->id];
        $this->assertSame([4, 0, 3, 3, 0], array_values($coinSplit['amounts']));

        $this->assertSame(1, $preview['totals']['administrative']);
        $texts = implode(' | ', array_column($preview['warnings'], 'text'));
        $this->assertStringContainsString('share a name', $texts);
        $this->assertStringContainsString('linked by name', $texts);
    }

    public function testReviewCorrectionsAndPublishing(): void
    {
        $event = $this->event();
        $this->service->import($event, $this->service->validate($this->upload())['payload'], 1);
        $import = $this->current($event);
        [$naughtius, $bank, $warlock] = $import->event_import_rows;

        // The points are the game's: a form that changes one is refused whole.
        try {
            $this->service->applyReview($import, [
                $naughtius->id => ['eligible' => '0', 'points' => '700'],
            ]);
            $this->fail('A change of points was accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('cannot be changed', $e->getMessage());
        }
        $import = $this->current($event);
        $this->assertSame(600, $import->event_import_rows[0]->points);
        $this->assertTrue($import->event_import_rows[0]->eligible);
        [$naughtius, $bank, $warlock] = $import->event_import_rows;

        // The same points sent back with the form are fine; nothing else changed here.
        $changed = $this->service->applyReview($import, [
            $naughtius->id => ['eligible' => '1', 'points' => '600'],
            $bank->id => ['eligible' => '0', 'member_id' => (string)$this->members['Bank KOK']],
            $warlock->id => ['eligible' => '1', 'member_id' => (string)$this->members['WARLOCK']],
        ]);
        $this->assertSame(0, $changed);

        $import = $this->current($event);

        $recorded = $this->service->publish($event, $import);
        $this->assertSame(5, $recorded);

        $event = $this->fetchTable('Events')->get($event->id, contain: ['EventRewards']);
        $this->assertNotNull($event->published_at);
        $this->assertSame(Event::STATE_FINISHED, $event->state);

        $result = $this->service->publishedResult($event);
        $this->assertSame(5, $result['players']);
        [$pieces, $coins] = $event->event_rewards;
        $this->assertSame(1000, $result['totals'][$pieces->id]);
        $this->assertSame(10, $result['totals'][$coins->id]);
        $this->assertSame('Naughtius', $result['rows'][0]['standing']->player);
        $this->assertFalse($result['rows'][1]['standing']->eligible);
        $this->assertSame([], $result['rows'][1]['amounts']);

        // The name match taught the member its game id.
        $this->assertSame(523986160991, $this->fetchTable('Members')->get($this->members['WARLOCK'])->game_player_id);

        // A published result cannot take another upload, or be edited, until unpublished.
        try {
            $this->service->import($event, $this->service->validate($this->upload())['payload'], 1);
            $this->fail('published events refuse uploads');
        } catch (DomainException) {
        }

        $this->service->unpublish($event);
        $event = $this->fetchTable('Events')->get($event->id, contain: ['EventRewards']);
        $this->assertNull($event->published_at);
        $this->assertSame(Event::STATE_AWAITING, $event->state);
        $this->assertSame(0, $this->fetchTable('EventStandings')->find()->where(['event_id' => $event->id])->count());
        $this->assertSame(0, $this->fetchTable('EventRewardAllocations')->find()->count());
        $this->assertSame(EventImport::STATUS_DRAFT, $this->current($event)->status);
    }

    public function testChestScoringNeverTouchesAPublishedTournament(): void
    {
        $event = $this->event();
        $this->service->import($event, $this->service->validate($this->upload())['payload'], 1);
        $this->service->publish($event, $this->current($event));

        $recorded = (new \App\Service\EventScoringService())->finalize($this->fetchTable('Events')->get($event->id));

        $this->assertSame(0, $recorded);
        $this->assertSame(5, $this->fetchTable('EventStandings')->find()->where(['event_id' => $event->id])->count());
    }

    public function testPublishingIsRefusedWhenARewardWouldGoToNobody(): void
    {
        $event = $this->event();
        $upload = $this->upload();
        $upload['rows'] = [['position' => 1, 'name' => 'Bank KOK', 'points' => 10, 'player_id' => 999000111]];
        $this->service->import($event, $this->service->validate($upload)['payload'], 1);

        $this->expectException(DomainException::class);
        $this->service->publish($event, $this->current($event));
    }

    public function testParsesTheCsvTheDiscoveryToolExports(): void
    {
        $csv = "\xEF\xBB\xBFposicao,nome,pontos,poder,id_jogador\r\n"
            . "1,Naughtius Maximus,1703103642,,300647954111\r\n"
            . "2,\"Raven, the second\",1639488916,,317828058440\r\n"
            . "98,BENAR,0,,622770520040\r\n";

        $data = $this->service->parseCsv($csv);
        $result = $this->service->validate($data);

        $this->assertSame([], $result['errors']);
        $this->assertSame('csv', $result['payload']['capture_method']);
        $this->assertSame('Raven, the second', $result['payload']['rows'][1]['name']);
        $this->assertSame(622770520040, $result['payload']['rows'][2]['game_player_id']);
        $this->assertSame(0, $result['payload']['rows'][2]['points']);

        $semicolons = $this->service->parseCsv("Posição;Jogador;Pontos\n1;Ana;2.010.420.872\n");
        $this->assertSame(2010420872, $this->service->validate($semicolons)['payload']['rows'][0]['points']);
    }
}
