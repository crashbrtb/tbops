<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\StandardChestsController;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * App\Controller\StandardChestsController Test Case
 *
 * @uses \App\Controller\StandardChestsController
 */
class StandardChestsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.StandardChests',
        'app.Users',
        'app.Roles',
        'app.RolesUsers',
    ];

    /**
     * Game chest types these tests write; that table has no fixture.
     */
    private const TYPES = [
        [9001, 'Scarab Chest', 'Level 25 epic Crypt', 2],
        [9002, 'Legendary Ashen Chest', 'Epic Ashen Squad', 3],
        [9003, 'Bone Chest', 'Level 25 Crypt', null], // not linked yet: found by its source
    ];

    /**
     * @inheritDoc
     */
    public function setUp(): void
    {
        parent::setUp();

        // As on the web: a table without its own class is an error, not a generic table.
        $this->getTableLocator()->allowFallbackClass(false);
        $this->session(['Auth' => [
            'id' => 1,
            'username' => 'tester',
            'email' => 'tester@example.com',
            'created' => new DateTime('2026-01-01 00:00:00'),
        ]]);

        $chests = $this->fetchTable('StandardChests');
        foreach ([2 => 'Level 25 epic Crypt', 3 => 'Epic Ashen Squad', 4 => 'Level 25 Crypt'] as $id => $source) {
            $chest = $chests->newEntity(['source' => $source, 'score' => 5]);
            $chest->set('id', $id, ['guard' => false]);
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
    public function tearDown(): void
    {
        $this->fetchTable('GameChestTypes')->deleteAll(['game_type_id IN' => array_column(self::TYPES, 0)]);
        parent::tearDown();
    }

    /**
     * The filter form sends an empty per-page value, which is the default size and
     * not one row per page.
     */
    public function testSearchingFromTheFormListsEveryMatch(): void
    {
        $this->get('/standard-chests?q=epic&type=&score=&alias=&limit=');

        $this->assertResponseOk();
        $this->assertResponseContains('Level 25 epic Crypt');
        $this->assertResponseContains('Epic Ashen Squad');
        $this->assertResponseNotContains('>Level 25 Crypt<');
    }

    /**
     * Every source shows the chests it gives, linked or not yet, and a chest name finds its source.
     */
    public function testChestNamesAreShownAndSearched(): void
    {
        $this->get('/standard-chests');
        $this->assertResponseOk();
        $this->assertResponseContains('Scarab Chest');
        $this->assertResponseContains('Bone Chest');

        $this->get('/standard-chests?q=ashen+chest');
        $this->assertResponseContains('Epic Ashen Squad');
        $this->assertResponseNotContains('Level 25 epic Crypt');

        $this->get('/standard-chests?q=bone');
        $this->assertResponseContains('>Level 25 Crypt<');
        $this->assertResponseNotContains('Epic Ashen Squad');
    }

    /**
     * Test view method
     *
     * @return void
     * @uses \App\Controller\StandardChestsController::view()
     */
    public function testView(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test add method
     *
     * @return void
     * @uses \App\Controller\StandardChestsController::add()
     */
    public function testAdd(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test edit method
     *
     * @return void
     * @uses \App\Controller\StandardChestsController::edit()
     */
    public function testEdit(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }

    /**
     * Test delete method
     *
     * @return void
     * @uses \App\Controller\StandardChestsController::delete()
     */
    public function testDelete(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }
}
