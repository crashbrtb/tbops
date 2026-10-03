<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ChestGoalService;
use App\Service\GoalPenaltyService;
use Cake\I18n\FrozenTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;

/**
 * App\Service\ChestGoalService Test Case
 *
 * Global goals are 15000 (total) and 6000 (epic). The level table gives G1
 * 5000 / 2000, G5 10000 / 4000 and G9 20000 / 8000; every other level is
 * left blank and so uses the global goal.
 *
 * @uses \App\Service\ChestGoalService
 */
class ChestGoalServiceTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Config',
        'app.Members',
        'app.PlayerCycleSummaries',
        'app.CollectedChests',
        'app.StandardChests',
        'app.GoalPenaltyWaivers',
    ];

    public function setUp(): void
    {
        parent::setUp();

        $this->fetchTable('Members')->deleteAll([]);
        $this->configure();
    }

    public function testGlobalModeGivesEveryoneTheGlobalGoal(): void
    {
        $this->configure(['chest_goal_mode' => 'global']);
        $this->member('Low', 1);

        $goals = new ChestGoalService();

        $this->assertFalse($goals->isByGuard());
        $this->assertSame(['total' => 15000, 'epic' => 6000], $goals->goalsFor('Low'));
        $this->assertSame(['total' => 15000, 'epic' => 6000], $goals->goalsFor('Nobody'));
    }

    public function testGuardModeFollowsTheMemberGuardLevel(): void
    {
        $this->member('Low', 1);
        $this->member('Mid', 5);
        $this->member('Top', 9);

        $goals = new ChestGoalService();

        $this->assertSame(['total' => 5000, 'epic' => 2000], $goals->goalsFor('Low'));
        $this->assertSame(['total' => 10000, 'epic' => 4000], $goals->goalsFor('Mid'));
        $this->assertSame(['total' => 20000, 'epic' => 8000], $goals->goalsFor('Top'));
        // Chest rows and members spell names the way the game does, but case may differ
        $this->assertSame(5, $goals->guardLevel('mid'));
    }

    public function testALevelLeftBlankUsesTheGlobalGoal(): void
    {
        $this->member('Seven', 7);

        $this->assertSame(['total' => 15000, 'epic' => 6000], (new ChestGoalService())->goalsFor('Seven'));
    }

    public function testUnknownLevelsGetTheHighestGoal(): void
    {
        $this->member('Unknown', 0);

        $goals = new ChestGoalService();

        $this->assertSame(['total' => 20000, 'epic' => 8000], $goals->goalsFor('Unknown'));
        // No member record at all is just as unknown
        $this->assertSame(0, $goals->guardLevel('NotAMember'));
        $this->assertSame(['total' => 20000, 'epic' => 8000], $goals->goalsFor('NotAMember'));
    }

    public function testTheHighestGoalIsTheHighestOfTheTableNotJustG9(): void
    {
        // A blank G9 falls back to the global goal, which is below G8 here
        $this->configure(['chest_goal_by_guard' => '{"8": 30000}', 'epic_goal_by_guard' => '{}']);
        $this->member('Unknown', 0);

        $this->assertSame(['total' => 30000, 'epic' => 6000], (new ChestGoalService())->goalsFor('Unknown'));
    }

    public function testSaveStoresTheTableAndLeavesBlankLevelsOut(): void
    {
        $goals = new ChestGoalService();
        $goals->save([
            'mode' => 'guard',
            'global' => ['total' => '12000', 'epic' => '5000'],
            'by_guard' => [
                'total' => [1 => '4000', 2 => '', 9 => '25000'],
                'epic' => [9 => '9000'],
            ],
        ]);

        $this->assertSame('{"1":4000,"9":25000}', $this->stored('chest_goal_by_guard'));
        $this->assertSame('{"9":9000}', $this->stored('epic_goal_by_guard'));
        $this->assertSame('12000', $this->stored('minimum_chest_score'));
        $this->assertSame(['total' => 12000, 'epic' => 5000], $goals->levelTable()[2]);
    }

    public function testSaveRefusesAGoalThatIsNotAWholeNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ChestGoalService())->save([
            'mode' => 'guard',
            'global' => ['total' => '15000', 'epic' => '6000'],
            'by_guard' => ['total' => [3 => '-5']],
        ]);
    }

    public function testDecodeIgnoresLevelsOutsideTheTable(): void
    {
        $this->assertSame([1 => 10, 9 => 90], ChestGoalService::decodeLevels('{"0": 5, "1": 10, "9": 90, "10": 100, "x": 1}'));
        $this->assertSame([], ChestGoalService::decodeLevels('not json'));
    }

    public function testThePenaltyRaisesThePlayersOwnGoal(): void
    {
        $this->member('Low', 1);
        $this->member('Top', 9);
        // Both veterans who missed their own goal last cycle
        foreach (['Low', 'Top'] as $player) {
            $this->summary($player, '2026-01-01', 99999, 99999);
        }
        $this->summary('Low', '2026-01-08', 4999, 0, 5000);
        $this->summary('Top', '2026-01-08', 19999, 0, 20000);

        $goals = (new GoalPenaltyService())->goalsForCycle(new FrozenTime('2026-01-15 00:00:00'));

        $this->assertSame(5500, $goals['Low']['total']);
        $this->assertSame(22000, $goals['Top']['total']);
    }

    public function testThePreviousCycleIsJudgedByTheGoalItStored(): void
    {
        // Was G1 (goal 5000) last cycle and is G9 now: 6000 reached last cycle's goal
        $this->member('Climber', 9);
        $this->summary('Climber', '2026-01-01', 99999, 99999);
        $this->summary('Climber', '2026-01-08', 6000, 0, 5000);

        $goals = (new GoalPenaltyService())->goalsForCycle(new FrozenTime('2026-01-15 00:00:00'));

        $this->assertArrayNotHasKey('Climber', $goals);
    }

    public function testProcessingACycleStoresTheGuardLevelAndGoals(): void
    {
        $this->configure(['goal_penalty_enabled' => '0']);
        $this->member('Low', 1);
        $this->fetchTable('StandardChests')->deleteAll([]);
        $this->fetchTable('CollectedChests')->deleteAll([]);
        $this->fetchTable('StandardChests')->saveOrFail(
            $this->fetchTable('StandardChests')->newEntity(['source' => 'Big Chest', 'score' => 3000, 'monster' => 0], ['validate' => false])
        );
        $chests = $this->fetchTable('CollectedChests');
        foreach (['Low', 'Low', 'Stranger', 'Stranger'] as $player) {
            $chests->saveOrFail($chests->newEntity([
                'name' => 'Big Chest',
                'player' => $player,
                'source' => 'Big Chest',
                'type' => 1,
                'collected_at' => new FrozenTime('2026-01-16 12:00:00'),
            ], ['validate' => false]), ['checkRules' => false]);
        }

        /** @var \App\Model\Table\PlayerCycleSummariesTable $summaries */
        $summaries = $this->fetchTable('PlayerCycleSummaries');
        $summaries->processCycleForDateRange(new FrozenTime('2026-01-15 00:00:00'), new FrozenTime('2026-01-21 23:59:59'), 15000);

        $low = $summaries->find()->where(['player_name' => 'Low'])->firstOrFail();
        $this->assertSame(1, $low->guard_level);
        $this->assertSame(5000, $low->chest_goal);
        $this->assertSame(2000, $low->epic_goal);
        // 6000 points: enough for G1, short of the global 15000
        $this->assertTrue($low->goal_achieved);

        $stranger = $summaries->find()->where(['player_name' => 'Stranger'])->firstOrFail();
        $this->assertSame(0, $stranger->guard_level);
        $this->assertSame(20000, $stranger->chest_goal);
        $this->assertFalse($stranger->goal_achieved);
    }

    /**
     * @param array<string, string> $overrides Config values to change.
     */
    private function configure(array $overrides = []): void
    {
        $values = $overrides + [
            'reference_day' => '2026-01-01 00:00:00',
            'every_how_many_days' => '7',
            'minimum_chest_score' => '15000',
            'minimum_epic_chest_score' => '6000',
            'chest_goal_mode' => 'guard',
            'chest_goal_by_guard' => '{"1": 5000, "5": 10000, "9": 20000}',
            'epic_goal_by_guard' => '{"1": 2000, "5": 4000, "9": 8000}',
            'goal_penalty_enabled' => '1',
            'goal_penalty_percent' => '10',
            'goal_penalty_target' => 'total',
        ];
        $config = $this->fetchTable('Config');
        foreach ($values as $param => $value) {
            $row = $config->find()->where(['param' => $param])->first()
                ?? $config->newEntity(['param' => $param, 'description' => $param], ['validate' => false]);
            $row->value = $value;
            $config->saveOrFail($row, ['checkRules' => false]);
        }
    }

    private function member(string $player, int $guards): void
    {
        $members = $this->fetchTable('Members');
        $members->saveOrFail($members->newEntity([
            'player' => $player,
            'power' => 1,
            'guards' => $guards,
            'specialists' => 0,
            'monsters' => 0,
            'engineers' => 0,
            'active' => 1,
        ], ['validate' => false]), ['checkRules' => false]);
    }

    private function summary(string $player, string $start, int $total, int $epic, ?int $chestGoal = null): void
    {
        $table = $this->fetchTable('PlayerCycleSummaries');
        $table->saveOrFail($table->newEntity([
            'player_name' => $player,
            'cycle_start_date' => $start,
            'cycle_end_date' => (new FrozenTime($start))->addDays(6)->format('Y-m-d'),
            'total_chests' => 1,
            'total_score' => $total,
            'epic_crypt_score' => $epic,
            'chest_goal' => $chestGoal,
            'goal_achieved' => false,
            'fine_due' => false,
            'fine_paid' => false,
        ]));
    }

    private function stored(string $param): ?string
    {
        $row = $this->fetchTable('Config')->find()->where(['param' => $param])->first();

        return $row->value ?? null;
    }
}
