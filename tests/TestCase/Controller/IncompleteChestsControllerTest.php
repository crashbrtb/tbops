<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * App\Controller\IncompleteChestsController Test Case
 *
 * @uses \App\Controller\IncompleteChestsController
 */
class IncompleteChestsControllerTest extends TestCase
{
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.IncompleteChests',
        'app.CollectedChests',
        'app.StandardChests',
        'app.Users',
        'app.Roles',
        'app.RolesUsers',
    ];

    /**
     * @inheritDoc
     */
    public function setUp(): void
    {
        parent::setUp();

        // As on the web: a table without its own class is an error, not a generic table.
        $this->getTableLocator()->allowFallbackClass(false);
        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();
    }

    /**
     * Sign in helper. User 1 holds role 1 (admin).
     */
    protected function signIn(int $id = 1): void
    {
        $this->session([
            'Auth' => [
                'id' => $id,
                'username' => 'tester',
                'email' => 'tester@example.com',
                'created' => new DateTime('2026-01-01 00:00:00'),
            ],
        ]);
    }

    /**
     * Test index page requires admin
     */
    public function testIndexRequiresAdmin(): void
    {
        $this->get('/incomplete-chests');
        $this->assertResponseCode(302); // Redirect to login when unauthenticated
    }

    /**
     * Test index page renders with bulk controls and table
     */
    public function testIndexRendersForAdmin(): void
    {
        $this->signIn(1);
        $this->get('/incomplete-chests');

        $this->assertResponseOk();
        $this->assertResponseContains('id="masterCheckbox"');
        $this->assertResponseContains('id="btnSubmitBulk"');
        $this->assertResponseContains('class="chest-select-box"');
        $this->assertResponseContains('Pending Chest 1');
        $this->assertResponseNotContains('table table-hover text-nowrap');
    }

    /**
     * Test bulk unresolved with selected IDs
     */
    public function testBulkUnresolvedSelected(): void
    {
        $this->signIn(1);

        $this->post('/incomplete-chests/bulk-unresolved', [
            'ids' => [1, 2],
            'status' => 'pending',
        ]);

        $this->assertRedirect(['action' => 'index', '?' => ['status' => 'pending']]);
        $this->assertFlashMessage('2 chest(s) marked as not recoverable.');

        $table = $this->fetchTable('IncompleteChests');
        $chest1 = $table->get(1);
        $chest2 = $table->get(2);

        $this->assertSame('unresolved', $chest1->status);
        $this->assertSame(1, $chest1->reviewed_by);
        $this->assertNotNull($chest1->reviewed_at);

        $this->assertSame('unresolved', $chest2->status);
        $this->assertSame(1, $chest2->reviewed_by);
        $this->assertNotNull($chest2->reviewed_at);
    }

    /**
     * Test bulk unresolved marking all pending
     */
    public function testBulkUnresolvedMarkAllPending(): void
    {
        $this->signIn(1);

        $this->post('/incomplete-chests/bulk-unresolved', [
            'mark_all_pending' => '1',
            'status' => 'pending',
        ]);

        $this->assertRedirect(['action' => 'index', '?' => ['status' => 'pending']]);
        $this->assertFlashMessage('2 pending chest(s) marked as not recoverable.');

        $table = $this->fetchTable('IncompleteChests');
        $pendingCount = $table->find()->where(['status' => 'pending'])->count();
        $this->assertSame(0, $pendingCount);

        // Corrected chest should remain corrected
        $chest3 = $table->get(3);
        $this->assertSame('corrected', $chest3->status);
    }

    /**
     * Test bulk unresolved with empty selection
     */
    public function testBulkUnresolvedEmptySelection(): void
    {
        $this->signIn(1);

        $this->post('/incomplete-chests/bulk-unresolved', [
            'ids' => [],
            'status' => 'pending',
        ]);

        $this->assertRedirect(['action' => 'index', '?' => ['status' => 'pending']]);
        $this->assertFlashMessage('No chests selected.');
    }

    /**
     * Naming a chest of a new type names the type and the others waiting.
     */
    public function testCorrectingANewTypeNamesItsOtherChests(): void
    {
        $this->signIn(1);
        $incomplete = $this->fetchTable('IncompleteChests');
        $types = $this->fetchTable('GameChestTypes');
        $types->deleteAll(['game_type_id' => 9999]);
        $incomplete->updateAll(['name' => '', 'source' => '', 'game_type_id' => 9999, 'game_gift_id' => 7], ['id IN' => [1, 2]]);

        $this->post('/incomplete-chests/correct/1', ['name' => 'Mimic', 'player' => 'Player One', 'source' => 'Crypt']);

        $this->assertFlashMessageAt(1, '1 other chest(s) of this type were recorded with the same name.');
        $other = $incomplete->get(2);
        $this->assertSame('corrected', $other->status);
        $this->assertSame('Mimic', $other->name);

        $row = $this->fetchTable('CollectedChests')->get($other->collected_chest_id);
        $this->assertSame('Player Two', $row->player);
        $this->assertSame(9999, $row->get('game_type_id'));
        $this->assertSame(7, $row->get('game_gift_id'));

        $named = $types->get(9999);
        $this->assertSame(['Mimic', 'Crypt'], [$named->get('name'), $named->get('source')]);
        $types->deleteAll(['game_type_id' => 9999]);
    }

    /**
     * A chest with no game type is corrected alone, as always.
     */
    public function testCorrectingAChestWithoutATypeTouchesNoOther(): void
    {
        $this->signIn(1);

        $this->post('/incomplete-chests/correct/1', ['name' => 'Mimic', 'player' => 'Player One', 'source' => 'Crypt']);

        $this->assertSame('corrected', $this->fetchTable('IncompleteChests')->get(1)->status);
        $this->assertSame('pending', $this->fetchTable('IncompleteChests')->get(2)->status);
    }

    /**
     * A new type is one line on the queue, and its chests are not reviewed one by one.
     */
    public function testANewTypeIsOneLineAndItsChestsAreNotListed(): void
    {
        $this->signIn(1);
        $table = $this->fetchTable('IncompleteChests');
        $table->updateAll(['name' => '', 'game_type_id' => 9999], ['id IN' => [1, 2]]);

        $this->get('/incomplete-chests');
        $this->assertResponseOk();
        $this->assertResponseContains('id="typesToName"');
        $this->assertResponseContains('Code 9999');
        $this->assertResponseNotContains('Player One');

        $this->get('/incomplete-chests/view/1');
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * Naming a type from the queue records every chest with its own player.
     */
    public function testNamingACodeRecordsEveryChestWithItsOwnPlayer(): void
    {
        $this->signIn(1);
        $incomplete = $this->fetchTable('IncompleteChests');
        $types = $this->fetchTable('GameChestTypes');
        $types->deleteAll(['game_type_id' => 9999]);
        $incomplete->updateAll(['name' => '', 'source' => '', 'game_type_id' => 9999], ['id IN' => [1, 2]]);

        $this->post('/incomplete-chests/name-code', ['game_type_id' => 9999, 'name' => 'Mimic', 'source' => 'Crypt']);

        $this->assertFlashMessage('2 chest(s) recorded as "Mimic".');
        $players = [];
        foreach ([1, 2] as $id) {
            $chest = $incomplete->get($id);
            $this->assertSame('corrected', $chest->status);
            $row = $this->fetchTable('CollectedChests')->get($chest->collected_chest_id);
            $players[] = [$row->player, $row->name, $row->source, $row->get('game_type_id')];
        }
        $this->assertSame([['Player One', 'Mimic', 'Crypt', 9999], ['Player Two', 'Mimic', 'Crypt', 9999]], $players);
        $this->assertSame('Mimic', $types->get(9999)->get('name'));
        $types->deleteAll(['game_type_id' => 9999]);
    }

    /**
     * A type that cannot be identified leaves the queue whole; marking everything
     * pending as not recoverable does not reach the chests of a new type.
     */
    public function testDroppingACodeAndMarkingAllPending(): void
    {
        $this->signIn(1);
        $incomplete = $this->fetchTable('IncompleteChests');
        $incomplete->updateAll(['name' => '', 'game_type_id' => 9999], ['id' => 1]);

        $this->post('/incomplete-chests/bulk-unresolved', ['mark_all_pending' => 1]);
        $this->assertSame('pending', $incomplete->get(1)->status);
        $this->assertSame('unresolved', $incomplete->get(2)->status);

        $this->post('/incomplete-chests/drop-code', ['game_type_id' => 9999]);
        $this->assertSame('unresolved', $incomplete->get(1)->status);
    }
}
