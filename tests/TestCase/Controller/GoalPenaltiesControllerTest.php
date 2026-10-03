<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\GoalPenaltyService;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The cycles are set so the current one started three days ago, which keeps
 * the tests independent of today's date.
 *
 * @uses \App\Controller\GoalPenaltiesController
 */
class GoalPenaltiesControllerTest extends TestCase
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
        'app.PlayerCycleSummaries',
        'app.CollectedChests',
        'app.StandardChests',
        'app.GoalPenaltyWaivers',
    ];

    private string $current;
    private string $previous;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();

        $reference = DateTime::now()->subDays(3)->setTime(0, 0);
        $this->current = $reference->format('Y-m-d');
        $this->previous = $reference->subDays(7)->format('Y-m-d');

        $config = $this->fetchTable('Config');
        foreach ([
            'reference_day' => $reference->format('Y-m-d H:i:s'),
            'every_how_many_days' => '7',
            'minimum_chest_score' => '15000',
            'minimum_epic_chest_score' => '6000',
            'goal_penalty_enabled' => '1',
            'goal_penalty_percent' => '10',
            'goal_penalty_target' => 'total',
        ] as $param => $value) {
            $config->saveOrFail($config->newEntity(['param' => $param, 'value' => $value, 'description' => $param], ['validate' => false]));
        }

        // Slacker missed the previous cycle and had played before it; Rookie joined in it.
        $older = $reference->subDays(14)->format('Y-m-d');
        $this->summary('Slacker', $older, 20000);
        $this->summary('Slacker', $this->previous, 1000);
        $this->summary('Rookie', $this->previous, 1000);
    }

    private function summary(string $player, string $start, int $total): void
    {
        $table = $this->fetchTable('PlayerCycleSummaries');
        $table->saveOrFail($table->newEntity([
            'player_name' => $player,
            'cycle_start_date' => $start,
            'cycle_end_date' => $start,
            'total_chests' => 1,
            'total_score' => $total,
            'epic_crypt_score' => 0,
            'goal_achieved' => false,
            'fine_due' => true,
            'fine_paid' => false,
        ]));
    }

    private function signIn(int $id): void
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

    public function testPageIsClosedToNonAdministrators(): void
    {
        $this->signIn(2);

        $this->get('/goal-penalties');

        $this->assertResponseCode(403);
    }

    public function testPageListsPenalizedAndFirstCyclePlayers(): void
    {
        $this->signIn(1);

        $this->get('/goal-penalties');

        $this->assertResponseOk();
        $this->assertResponseContains('Slacker');
        $this->assertResponseContains('Rookie');
        $this->assertResponseContains('Spared: first cycle in the clan');
        $this->assertResponseContains('16,500');
    }

    public function testPagePreviewsThePenaltyWhileItIsOff(): void
    {
        $config = $this->fetchTable('Config');
        $row = $config->find()->where(['param' => 'goal_penalty_enabled'])->firstOrFail();
        $row->value = '0';
        $config->saveOrFail($row);
        $this->signIn(1);

        $this->get('/goal-penalties');

        $this->assertResponseOk();
        $this->assertResponseContains('Preview');
        $this->assertResponseContains('Players who would get a raised goal');
        $this->assertResponseContains('Slacker');
        $this->assertResponseContains('Rookie');
        // The preview is only a view: the penalty itself stays off.
        $this->assertSame([], (new GoalPenaltyService())->goalsForCycle(new DateTime($this->current)));
    }

    public function testReleaseAndUndo(): void
    {
        $this->signIn(1);

        $this->post('/goal-penalties/release', ['player_name' => 'Slacker', 'cycle' => '0', 'reason' => 'Travelling']);

        $this->assertRedirect();
        $waivers = $this->fetchTable('GoalPenaltyWaivers');
        $waiver = $waivers->find()->where(['player_name' => 'Slacker'])->firstOrFail();
        $this->assertSame($this->current, $waiver->cycle_start_date->format('Y-m-d'));
        $this->assertSame('Travelling', $waiver->reason);
        $this->assertSame(1, $waiver->user_id);
        $this->assertSame([], (new GoalPenaltyService())->goalsForCycle(new DateTime($this->current)));

        // Releasing twice is refused instead of failing on the unique index.
        $this->post('/goal-penalties/release', ['player_name' => 'Slacker', 'cycle' => '0']);
        $this->assertFlashElement('flash/error');
        $this->assertSame(1, $waivers->find()->count());

        $this->post('/goal-penalties/revoke/' . $waiver->id);

        $this->assertRedirect();
        $this->assertSame(0, $waivers->find()->count());
        $this->assertSame(['Slacker' => ['total' => 16500]], (new GoalPenaltyService())->goalsForCycle(new DateTime($this->current)));
    }
}
