<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\Event;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The chests an event can be made of, as the event form lists them.
 *
 * @uses \App\Controller\EventsController
 */
class EventChestPickerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Users',
        'app.Roles',
        'app.RolesUsers',
        'app.Config',
        'app.StandardChests',
        'app.Events',
        'app.EventChests',
        'app.EventRewards',
    ];

    /**
     * Game chest types these tests write; that table has no fixture.
     */
    private const TYPES = [
        [9101, 'Legendary Ashen Chest', 'Epic Ashen Squad', 3],
        [9102, 'Scarab Chest', 'Level 25 epic Crypt', 2],
    ];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->getTableLocator()->allowFallbackClass(false);
        $this->session(['Auth' => [
            'id' => 1,
            'username' => 'tester',
            'email' => 'tester@example.com',
            'created' => new DateTime('2026-01-01 00:00:00'),
        ]]);

        $chests = $this->fetchTable('StandardChests');
        $chests->deleteAll([]);
        // A new event's chest arrives without a score, as the Ashen one did on 03/11/2026.
        foreach ([2 => ['Level 25 epic Crypt', 50, false], 3 => ['Epic Ashen Squad', 0, true]] as $id => [$source, $score, $monster]) {
            $chest = $chests->newEmptyEntity();
            $chest->set(['id' => $id, 'source' => $source, 'score' => $score, 'monster' => $monster], ['guard' => false]);
            $chests->saveOrFail($chest);
        }
        $types = $this->fetchTable('GameChestTypes');
        $types->deleteAll(['game_type_id IN' => array_column(self::TYPES, 0)]);
        foreach (self::TYPES as [$id, $name, $source, $standard]) {
            $type = $types->newEmptyEntity();
            $fields = ['game_type_id' => $id, 'name' => $name, 'source' => $source, 'standard_chest_id' => $standard];
            $type->set($fields, ['guard' => false]);
            $types->saveOrFail($type);
        }
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->fetchTable('GameChestTypes')->deleteAll(['game_type_id IN' => array_column(self::TYPES, 0)]);
        parent::tearDown();
    }

    /**
     * Every chest is listed, the scored ones first; the form hides the unscored
     * ones while the event is ranked by score.
     */
    public function testEveryChestIsListedAndTheUnscoredOnesAreMarked(): void
    {
        $this->get('/events/add');

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        $this->assertMatchesRegularExpression('/data-unscored="1" data-name="[^"]*legendary ashen chest"/', $body);
        $this->assertMatchesRegularExpression('/data-unscored="0" data-name="[^"]*scarab chest"/', $body);
        $this->assertLessThan(
            strpos($body, 'name="chest_ids[]" value="3"'),
            strpos($body, 'name="chest_ids[]" value="2"'),
            'the scored chest comes first',
        );
    }

    /**
     * Ranked by number, a chest without a score is kept; ranked by score, it is dropped.
     */
    public function testAChestWithoutAScoreIsKeptOnlyWhenRankingByNumber(): void
    {
        $this->assertSame([2, 3], $this->savedChests(Event::METRIC_COUNT));
        $this->assertSame([2], $this->savedChests(Event::METRIC_SCORE));
    }

    /**
     * Create a custom chest event with both chests ticked.
     *
     * @param string $metric What ranks the players.
     * @return list<int> The standard chests the event was saved with.
     */
    private function savedChests(string $metric): array
    {
        $this->enableCsrfToken();
        $this->post('/events/add', [
            'name' => 'Ashen week ' . $metric,
            'criteria' => Event::CRITERIA_CUSTOM_CHESTS,
            'custom_metric' => $metric,
            'chest_ids' => ['2', '3'],
            'starts_at' => DateTime::now()->addHours(1)->format('Y-m-d\TH:i'),
            'ends_at' => DateTime::now()->addDays(3)->format('Y-m-d\TH:i'),
            'prize' => '',
            'contact_player' => 'Naughtius',
            'event_rewards' => [
                ['item_name' => 'Gold', 'quantity' => '90', 'rule' => 'proportional', 'min_points' => '1', 'remainder' => 'top_ranked'],
            ],
            'goal' => ['mode' => 'none'],
        ]);
        $this->assertRedirect();

        $event = $this->fetchTable('Events')->find()->where(['name' => 'Ashen week ' . $metric])
            ->contain(['EventChests'])->firstOrFail();
        $ids = array_map(fn ($chest) => (int)$chest->standard_chest_id, $event->event_chests);
        sort($ids);

        return $ids;
    }
}
