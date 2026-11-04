<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

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
     * A chest without a score can still be picked, after the scored ones, with the chests it gives.
     */
    public function testAChestWithoutAScoreIsListedAfterTheScoredOnes(): void
    {
        $this->get('/events/add');

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        $this->assertStringContainsString('name="chest_ids[]" value="3"', $body);
        $this->assertStringContainsString('Legendary Ashen Chest', $body);
        $this->assertStringContainsString('epic ashen squad legendary ashen chest', $body, 'searched by its chest name');
        $this->assertLessThan(
            strpos($body, 'name="chest_ids[]" value="3"'),
            strpos($body, 'name="chest_ids[]" value="2"'),
            'the scored chest comes first',
        );
        $this->assertSame(1, substr_count($body, 'class="chest-option-unscored"'));
    }
}
