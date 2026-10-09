<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\GoalPenaltyService;
use Cake\I18n\FrozenTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * App\Service\GoalPenaltyService Test Case
 *
 * Cycles are a week long and start on 2026-01-01, so the cycle under test
 * (2026-01-15) has 2026-01-08 before it and 2026-01-01 before that. The base
 * goals are 15000 (total) and 6000 (epic); +10% raises them to 16500 and 6600.
 *
 * @uses \App\Service\GoalPenaltyService
 */
class GoalPenaltyServiceTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Config',
        'app.PlayerCycleSummaries',
        'app.CollectedChests',
        'app.StandardChests',
        'app.GoalPenaltyWaivers',
        'app.ManualGoalPenalties',
    ];

    private const CYCLE = '2026-01-15 00:00:00';
    private const PREVIOUS = '2026-01-08';
    private const BEFORE_PREVIOUS = '2026-01-01';

    public function testRaisedGoalRoundsUpToAWholePoint(): void
    {
        $this->assertSame(16500, GoalPenaltyService::raisedGoal(15000, 10));
        $this->assertSame(6602, GoalPenaltyService::raisedGoal(6001, 10));
        $this->assertSame(1013, GoalPenaltyService::raisedGoal(1000, 1.25));
    }

    public function testNoGoalsWhenSwitchedOff(): void
    {
        $this->configure(['goal_penalty_enabled' => '0']);
        $this->veteran('Missed');
        $this->summary('Missed', self::PREVIOUS, 100, 0);

        $this->assertSame([], (new GoalPenaltyService())->goalsForCycle(new FrozenTime(self::CYCLE)));
    }

    public function testPreviewEvaluatesWhileSwitchedOff(): void
    {
        $this->configure(['goal_penalty_enabled' => '0']);
        $this->veteran('Missed');
        $this->summary('Missed', self::PREVIOUS, 100, 0);

        $evaluation = (new GoalPenaltyService())->evaluateCycle(new FrozenTime(self::CYCLE), true);

        $this->assertSame(GoalPenaltyService::STATUS_PENALIZED, $evaluation['Missed']['status']);
        $this->assertSame(['total' => 16500], $evaluation['Missed']['goals']);
    }

    public function testPlayersBelowTheirPreviousGoalGetTheRaisedGoal(): void
    {
        $this->configure();
        foreach (['Missed', 'Reached', 'MissedAgain', 'Recovered'] as $player) {
            $this->veteran($player);
        }
        $this->summary('Missed', self::PREVIOUS, 14999, 0);
        $this->summary('Reached', self::PREVIOUS, 15000, 0);
        // Carried the raised goal and only reached the base one: still a miss.
        $this->summary('MissedAgain', self::PREVIOUS, 16000, 0, 16500);
        $this->summary('Recovered', self::PREVIOUS, 16500, 0, 16500);

        $goals = (new GoalPenaltyService())->goalsForCycle(new FrozenTime(self::CYCLE));

        $this->assertSame(['Missed' => ['total' => 16500], 'MissedAgain' => ['total' => 16500]], $goals);
    }

    public function testEpicTargetJudgesTheEpicScore(): void
    {
        $this->configure(['goal_penalty_target' => 'epic', 'goal_penalty_percent' => '20']);
        $this->veteran('LowEpic');
        $this->veteran('GoodEpic');
        $this->summary('LowEpic', self::PREVIOUS, 99999, 5999);
        $this->summary('GoodEpic', self::PREVIOUS, 0, 6000);

        $goals = (new GoalPenaltyService())->goalsForCycle(new FrozenTime(self::CYCLE));

        $this->assertSame(['LowEpic' => ['epic' => 7200]], $goals);
    }

    public function testBothTargetsRaiseOnlyTheGoalThatWasMissed(): void
    {
        $this->configure(['goal_penalty_target' => 'both']);
        foreach (['TotalOnly', 'EpicOnly', 'BothMissed', 'BothReached'] as $player) {
            $this->veteran($player);
        }
        $this->summary('TotalOnly', self::PREVIOUS, 14000, 7000);
        $this->summary('EpicOnly', self::PREVIOUS, 20000, 5000);
        $this->summary('BothMissed', self::PREVIOUS, 100, 100);
        $this->summary('BothReached', self::PREVIOUS, 15000, 6000);

        $service = new GoalPenaltyService();
        $goals = $this->sorted($service->goalsForCycle(new FrozenTime(self::CYCLE)));

        $this->assertSame([
            'BothMissed' => ['total' => 16500, 'epic' => 6600],
            'EpicOnly' => ['epic' => 6600],
            'TotalOnly' => ['total' => 16500],
        ], $goals);
        // The goal that was reached stays at its base value.
        $this->assertSame(
            ['total' => 15000, 'epic' => 6600],
            $service->evaluateCycle(new FrozenTime(self::CYCLE))['EpicOnly']['goals']
        );
    }

    public function testBothTargetsJudgeEachGoalByWhatTheyCarried(): void
    {
        $this->configure(['goal_penalty_target' => 'both']);
        $this->veteran('Player');
        // Carried a raised epic goal: reached it, but missed the base total goal.
        $this->summary('Player', self::PREVIOUS, 14000, 6600, null, 6600);

        $goals = (new GoalPenaltyService())->goalsForCycle(new FrozenTime(self::CYCLE));

        $this->assertSame(['Player' => ['total' => 16500]], $goals);
    }

    public function testProcessingACycleStoresEachRaisedGoal(): void
    {
        $this->configure(['goal_penalty_target' => 'both']);
        $this->veteran('Player');
        $this->summary('Player', self::PREVIOUS, 100, 100);
        $this->fetchTable('StandardChests')->deleteAll([]);
        $this->fetchTable('CollectedChests')->deleteAll([]);
        $this->fetchTable('StandardChests')->saveOrFail(
            $this->fetchTable('StandardChests')->newEntity(['source' => 'Big Chest', 'score' => 5000, 'monster' => 0], ['validate' => false])
        );
        $this->chests('Player', 3, '2026-01-16 12:00:00');

        /** @var \App\Model\Table\PlayerCycleSummariesTable $summaries */
        $summaries = $this->fetchTable('PlayerCycleSummaries');
        $summaries->processCycleForDateRange(new FrozenTime(self::CYCLE), new FrozenTime('2026-01-21 23:59:59'), 15000);

        $row = $summaries->find()->where(['player_name' => 'Player', 'cycle_start_date' => '2026-01-15'])->firstOrFail();
        $this->assertSame(16500, $row->penalty_goal);
        $this->assertSame(6600, $row->penalty_epic_goal);
        $this->assertSame('both', $row->penalty_target);
        // 15000 reaches the base goal, not the raised one.
        $this->assertFalse($row->goal_achieved);
        $this->assertSame(6600, $row->goalFor('epic', 6000));
    }

    public function testTheFirstCycleOfAPlayerNeverCountsAsAMiss(): void
    {
        $this->configure();
        $this->veteran('Veteran');
        $this->summary('Veteran', self::PREVIOUS, 100, 0);
        // Joined during the previous cycle: nothing of theirs before it.
        $this->summary('Newcomer', self::PREVIOUS, 100, 0);
        // A single chest before the previous cycle is enough to not be new.
        $this->summary('OldChest', self::PREVIOUS, 100, 0);
        $this->chests('OldChest', 1, '2026-01-05 12:00:00');

        $service = new GoalPenaltyService();
        $evaluation = $service->evaluateCycle(new FrozenTime(self::CYCLE));

        $this->assertSame(GoalPenaltyService::STATUS_FIRST_CYCLE, $evaluation['Newcomer']['status']);
        $this->assertSame(['total' => 15000], $evaluation['Newcomer']['goals']);
        $this->assertSame(
            ['OldChest' => ['total' => 16500], 'Veteran' => ['total' => 16500]],
            $this->sorted($service->goalsForCycle(new FrozenTime(self::CYCLE)))
        );
    }

    public function testReleasedPlayersKeepTheBaseGoal(): void
    {
        $this->configure();
        $this->veteran('Released');
        $this->veteran('Kept');
        $this->summary('Released', self::PREVIOUS, 100, 0);
        $this->summary('Kept', self::PREVIOUS, 100, 0);
        $this->waive('Released', '2026-01-15');

        $service = new GoalPenaltyService();
        $evaluation = $service->evaluateCycle(new FrozenTime(self::CYCLE));

        $this->assertSame(GoalPenaltyService::STATUS_WAIVED, $evaluation['Released']['status']);
        $this->assertSame(['Kept' => ['total' => 16500]], $service->goalsForCycle(new FrozenTime(self::CYCLE)));
    }

    public function testAReleaseInThePreviousCycleMeansItWasJudgedByTheBaseGoal(): void
    {
        $this->configure();
        $this->veteran('Released');
        // Carried 16500 but was released, so 15500 reached the goal that applied.
        $this->summary('Released', self::PREVIOUS, 15500, 0, 16500);
        $this->waive('Released', self::PREVIOUS);

        $this->assertSame([], (new GoalPenaltyService())->goalsForCycle(new FrozenTime(self::CYCLE)));
    }

    public function testSyncSummaryFollowsTheRelease(): void
    {
        $this->configure();
        $this->veteran('Player');
        $this->summary('Player', self::PREVIOUS, 100, 0);
        $this->summary('Player', '2026-01-15', 15200, 0, 16500);
        $summaries = $this->fetchTable('PlayerCycleSummaries');
        $service = new GoalPenaltyService();

        $this->waive('Player', '2026-01-15');
        $service->syncSummary('Player', new FrozenTime(self::CYCLE));
        $row = $summaries->find()->where(['player_name' => 'Player', 'cycle_start_date' => '2026-01-15'])->firstOrFail();
        $this->assertNull($row->penalty_goal);
        $this->assertNull($row->penalty_target);
        $this->assertTrue($row->goal_achieved);
        $this->assertFalse($row->fine_due);

        $this->fetchTable('GoalPenaltyWaivers')->deleteAll([]);
        $service->syncSummary('Player', new FrozenTime(self::CYCLE));
        $row = $summaries->find()->where(['player_name' => 'Player', 'cycle_start_date' => '2026-01-15'])->firstOrFail();
        $this->assertSame(16500, $row->penalty_goal);
        $this->assertSame('total', $row->penalty_target);
        $this->assertFalse($row->goal_achieved);
        $this->assertTrue($row->fine_due);
    }

    public function testUnsummarizedPreviousCycleIsReadFromCollectedChests(): void
    {
        $this->configure();
        $this->fetchTable('StandardChests')->deleteAll([]);
        $this->fetchTable('CollectedChests')->deleteAll([]);
        $this->fetchTable('StandardChests')->saveOrFail(
            $this->fetchTable('StandardChests')->newEntity(['source' => 'Big Chest', 'score' => 5000, 'monster' => 0], ['validate' => false])
        );
        // Low missed the goal two cycles ago (and was not new then), so in the
        // previous cycle it carried 16500.
        $this->summary('Low', '2025-12-25', 20000, 0);
        $this->summary('Low', self::BEFORE_PREVIOUS, 0, 0);
        $this->summary('Fine', self::BEFORE_PREVIOUS, 20000, 0);
        $this->summary('Short', self::BEFORE_PREVIOUS, 20000, 0);
        $this->chests('Low', 3);    // 15000: enough for the base goal, not for the raised one
        $this->chests('Fine', 3);   // 15000
        $this->chests('Short', 2);  // 10000
        $this->chests('New', 1);    // 5000, but this was the first cycle of New

        $goals = (new GoalPenaltyService())->goalsForCycle(new FrozenTime(self::CYCLE));

        $this->assertSame(['Low' => ['total' => 16500], 'Short' => ['total' => 16500]], $this->sorted($goals));
    }

    public function testAutomaticPenaltyTellsHowFarThePlayerGot(): void
    {
        $this->configure();
        $this->veteran('Missed');
        // 13499 of 15000 is 89.99%: rounded down, never up to a goal that was not reached
        $this->summary('Missed', self::PREVIOUS, 13499, 0);

        $penalties = (new GoalPenaltyService())->penaltiesForCycle(new FrozenTime(self::CYCLE));

        $this->assertSame('Previous goal not reached (89%)', $penalties['Missed']['automatic_reason']);
        $this->assertNull($penalties['Missed']['manual_reason']);
        $this->assertSame(10.0, $penalties['Missed']['percent']);
    }

    public function testAutomaticReasonNamesEachMissedGoalWhenBothAreWatched(): void
    {
        $this->configure(['goal_penalty_target' => 'both']);
        $this->veteran('Player');
        $this->summary('Player', self::PREVIOUS, 7500, 3000);

        $penalties = (new GoalPenaltyService())->penaltiesForCycle(new FrozenTime(self::CYCLE));

        $this->assertSame('Previous goal not reached (Total 50%, Epic 50%)', $penalties['Player']['automatic_reason']);
    }

    public function testManualPenaltyAppliesWhileTheAutomaticOneIsOff(): void
    {
        $this->configure(['goal_penalty_enabled' => '0']);
        $this->veteran('Missed');
        $this->summary('Missed', self::PREVIOUS, 100, 0);
        $this->manual('Punished', '2026-01-15', 'epic', 20, 'Skipped the clan war');

        $penalties = (new GoalPenaltyService())->penaltiesForCycle(new FrozenTime(self::CYCLE));

        $this->assertSame(['Punished'], array_keys($penalties));
        $this->assertSame(['epic' => 7200], $penalties['Punished']['goals']);
        $this->assertNull($penalties['Punished']['automatic_reason']);
        $this->assertSame('Skipped the clan war', $penalties['Punished']['manual_reason']);
    }

    public function testManualPenaltyAddsToTheAutomaticOne(): void
    {
        $this->configure();
        $this->veteran('Player');
        $this->summary('Player', self::PREVIOUS, 100, 0);
        $this->manual('Player', '2026-01-15', 'both', 5, 'Rude in chat');

        $service = new GoalPenaltyService();
        $penalty = $service->penaltiesForCycle(new FrozenTime(self::CYCLE))['Player'];

        // total: +10% automatic and +5% manual; epic: manual only
        $this->assertSame(['total' => 17250, 'epic' => 6300], $penalty['goals']);
        $this->assertSame(15.0, $penalty['percent']);
        $this->assertSame(['Previous goal not reached (0%)', 'Rude in chat'], GoalPenaltyService::reasonsOf($penalty));
        // The evaluation keeps telling the automatic penalty apart
        $evaluation = $service->evaluateCycle(new FrozenTime(self::CYCLE));
        $this->assertSame(['total' => 16500], $evaluation['Player']['raised']);
        $this->assertSame(['total' => 17250], $evaluation['Player']['goals']);
    }

    public function testAReleaseLeavesTheManualPenaltyInPlace(): void
    {
        $this->configure();
        $this->veteran('Player');
        $this->summary('Player', self::PREVIOUS, 100, 0);
        $this->manual('Player', '2026-01-15', 'total', 5, 'Rude in chat');
        $this->waive('Player', '2026-01-15');

        $penalty = (new GoalPenaltyService())->penaltiesForCycle(new FrozenTime(self::CYCLE))['Player'];

        $this->assertSame(['total' => 15750], $penalty['goals']);
        $this->assertNull($penalty['automatic_reason']);
    }

    public function testAManualPenaltyInThePreviousCycleIsWhatThatCycleIsJudgedBy(): void
    {
        $this->configure();
        $this->veteran('Player');
        // Reached the base goal but not the 16500 the manual penalty asked for
        $this->summary('Player', self::PREVIOUS, 15200, 0, 16500);
        $this->manual('Player', self::PREVIOUS, 'total', 10, 'Rude in chat');

        $this->assertSame(['Player' => ['total' => 16500]], (new GoalPenaltyService())->goalsForCycle(new FrozenTime(self::CYCLE)));
    }

    public function testProcessingACycleStoresTheReasonOfTheAutomaticPenalty(): void
    {
        $this->configure();
        $this->fetchTable('StandardChests')->deleteAll([]);
        $this->fetchTable('CollectedChests')->deleteAll([]);
        $this->fetchTable('StandardChests')->saveOrFail(
            $this->fetchTable('StandardChests')->newEntity(['source' => 'Big Chest', 'score' => 5000, 'monster' => 0], ['validate' => false])
        );
        $this->veteran('Missed');
        $this->summary('Missed', self::PREVIOUS, 7500, 0);
        $this->chests('Missed', 1, '2026-01-16 12:00:00');
        $this->chests('Punished', 1, '2026-01-16 12:00:00');
        $this->manual('Punished', '2026-01-15', 'total', 20, 'Skipped the clan war');

        $summaries = $this->fetchTable('PlayerCycleSummaries');
        $summaries->processCycleForDateRange(new FrozenTime(self::CYCLE), new FrozenTime('2026-01-21 23:59:59'), 0);

        $missed = $summaries->find()->where(['player_name' => 'Missed', 'cycle_start_date' => '2026-01-15'])->firstOrFail();
        $this->assertSame(16500, $missed->penalty_goal);
        $this->assertSame('Previous goal not reached (50%)', $missed->penalty_reason);
        // The reason of a manual penalty stays in its own row
        $punished = $summaries->find()->where(['player_name' => 'Punished', 'cycle_start_date' => '2026-01-15'])->firstOrFail();
        $this->assertSame(18000, $punished->penalty_goal);
        $this->assertNull($punished->penalty_reason);
    }

    private function manual(string $player, string $cycleStart, string $target, float $percent, string $reason): void
    {
        $table = $this->fetchTable('ManualGoalPenalties');
        $table->saveOrFail($table->newEntity([
            'player_name' => $player,
            'cycle_start_date' => $cycleStart,
            'target' => $target,
            'percent' => $percent,
            'reason' => $reason,
        ]));
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

    /**
     * A cycle summary before the previous cycle, so the player is not a newcomer.
     */
    private function veteran(string $player): void
    {
        $this->summary($player, self::BEFORE_PREVIOUS, 20000, 9000);
    }

    private function summary(string $player, string $start, int $total, int $epic, ?int $penaltyGoal = null, ?int $penaltyEpicGoal = null): void
    {
        $raised = array_filter(['total' => $penaltyGoal, 'epic' => $penaltyEpicGoal], fn ($goal) => $goal !== null);
        $table = $this->fetchTable('PlayerCycleSummaries');
        $table->saveOrFail($table->newEntity([
            'player_name' => $player,
            'cycle_start_date' => $start,
            'cycle_end_date' => (new FrozenTime($start))->addDays(6)->format('Y-m-d'),
            'total_chests' => 1,
            'total_score' => $total,
            'epic_crypt_score' => $epic,
            'penalty_goal' => $penaltyGoal,
            'penalty_epic_goal' => $penaltyEpicGoal,
            'penalty_target' => GoalPenaltyService::targetLabel($raised),
            'goal_achieved' => false,
            'fine_due' => false,
            'fine_paid' => false,
        ]));
    }

    private function waive(string $player, string $cycleStart): void
    {
        $table = $this->fetchTable('GoalPenaltyWaivers');
        $table->saveOrFail($table->newEntity(['player_name' => $player, 'cycle_start_date' => $cycleStart]));
    }

    private function chests(string $player, int $count, string $at = '2026-01-10 12:00:00'): void
    {
        $table = $this->fetchTable('CollectedChests');
        for ($i = 0; $i < $count; $i++) {
            $table->saveOrFail($table->newEntity([
                'name' => 'Big Chest',
                'player' => $player,
                'source' => 'Big Chest',
                'type' => 1,
                'collected_at' => new FrozenTime($at),
            ], ['validate' => false]), ['checkRules' => false]);
        }
    }

    /**
     * @param array<string, mixed> $goals
     * @return array<string, mixed>
     */
    private function sorted(array $goals): array
    {
        ksort($goals);

        return $goals;
    }
}
