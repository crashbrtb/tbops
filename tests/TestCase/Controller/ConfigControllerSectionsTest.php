<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Table\ConfigTable;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The Configs page split into sections, and the chest goals form.
 *
 * @uses \App\Controller\ConfigController::index()
 * @uses \App\Controller\ConfigController::chestGoals()
 */
class ConfigControllerSectionsTest extends TestCase
{
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Config',
        'app.Users',
        'app.Roles',
        // User 1 holds role 1, which is what requireAdmin() looks for.
        'app.RolesUsers',
    ];

    /**
     * @inheritDoc
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();
    }

    public function testParametersFallIntoTheirSections(): void
    {
        $this->assertSame(ConfigTable::SECTION_BANK, ConfigTable::sectionOf('deposit_fee'));
        $this->assertSame(ConfigTable::SECTION_BANK, ConfigTable::sectionOf('bank_function'));
        $this->assertSame(ConfigTable::SECTION_CHESTS, ConfigTable::sectionOf('minimum_chest_score'));
        $this->assertSame(ConfigTable::SECTION_CHESTS, ConfigTable::sectionOf('goal_penalty_percent'));
        $this->assertSame(ConfigTable::SECTION_CHESTS, ConfigTable::sectionOf('score_color_end_r'));
        $this->assertSame(ConfigTable::SECTION_GENERAL, ConfigTable::sectionOf('clan_name'));
        // Anything nobody placed is general
        $this->assertSame(ConfigTable::SECTION_GENERAL, ConfigTable::sectionOf('something_new'));
    }

    public function testIndexShowsTheThreeSectionsAndOpensTheRequestedOne(): void
    {
        $this->setConfig('deposit_fee', '2');
        $this->setConfig('goal_penalty_percent', '10');
        $this->signIn(1);

        $this->get('/config?section=bank');

        $this->assertResponseOk();
        $this->assertResponseContains('id="config-section-general"');
        $this->assertResponseContains('id="config-section-bank"');
        $this->assertResponseContains('id="config-section-chests"');
        $this->assertMatchesRegularExpression(
            '/tab-pane fade show active"\s+id="config-section-bank"/',
            (string)$this->_response->getBody()
        );
        $this->assertResponseContains('deposit_fee');
        $this->assertResponseContains('id="chest-goals-form"');
    }

    public function testIndexIsClosedToNonAdministrators(): void
    {
        $this->signIn(2);

        $this->get('/config');

        $this->assertResponseCode(403);
    }

    public function testChestGoalsAreSaved(): void
    {
        $this->signIn(1);

        $this->post('/config/chest-goals', [
            'mode' => 'guard',
            'global' => ['total' => '15000', 'epic' => '6000'],
            'by_guard' => [
                'total' => ['1' => '5000', '2' => '', '9' => '20000'],
                'epic' => ['9' => '8000'],
            ],
        ]);

        $this->assertRedirect(['controller' => 'Config', 'action' => 'index', '?' => ['section' => 'chests']]);
        $this->assertFlashElement('flash/success');
        $this->assertSame('guard', $this->storedConfig('chest_goal_mode'));
        $this->assertSame('{"1":5000,"9":20000}', $this->storedConfig('chest_goal_by_guard'));
        $this->assertSame('{"9":8000}', $this->storedConfig('epic_goal_by_guard'));
    }

    public function testAnInvalidGoalIsRefusedAndNothingChanges(): void
    {
        $this->setConfig('chest_goal_mode', 'global');
        $this->signIn(1);

        $this->post('/config/chest-goals', [
            'mode' => 'guard',
            'global' => ['total' => '15000', 'epic' => '6000'],
            'by_guard' => ['total' => ['4' => 'abc']],
        ]);

        $this->assertFlashElement('flash/error');
        $this->assertSame('global', $this->storedConfig('chest_goal_mode'));
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Put a user in the session.
     *
     * @param int $id The user id; 1 holds the admin role in the fixtures.
     * @return void
     */
    protected function signIn(int $id): void
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

    private function setConfig(string $param, string $value): void
    {
        $config = $this->fetchTable('Config');
        $row = $config->find()->where(['param' => $param])->first()
            ?? $config->newEntity(['param' => $param, 'description' => $param], ['validate' => false]);
        $row->value = $value;
        $config->saveOrFail($row, ['checkRules' => false]);
    }

    private function storedConfig(string $param): ?string
    {
        $row = $this->fetchTable('Config')->find()->where(['param' => $param])->first();

        return $row->value ?? null;
    }
}
