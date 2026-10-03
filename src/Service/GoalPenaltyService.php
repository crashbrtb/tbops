<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\FrozenTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Raised chest goals for players who missed the goal in the previous cycle.
 *
 * The penalty watches the chest score goal (total), the epic chest goal
 * (epic) or both. When it is on, a player whose score in cycle N stayed below
 * a goal they had in cycle N gets, in cycle N+1, that goal's base value
 * increased by the configured percentage. With both goals watched, missing
 * either one is a miss, and only the goal that was missed is raised.
 *
 * The increase does not stack: a player who keeps missing keeps the same
 * raised goal, and one who reaches it goes back to the base goal in the
 * following cycle. Reaching the base goal while carrying a raised one still
 * counts as a miss.
 *
 * Two things spare a player who missed:
 * - the missed cycle was their first one. Someone who joins the clan halfway
 *   through a cycle has no time to reach the goal, so that cycle never counts.
 *   "First" means no chest and no cycle summary of theirs before it.
 * - an administrator released them from the penalty for this cycle (a row in
 *   goal_penalty_waivers). A released player carries the base goals, so the
 *   next cycle judges them by the base goals too.
 *
 * The goals of the previous cycle come from player_cycle_summaries, which
 * stores the raised goals each player carried (penalty_goal for the total,
 * penalty_epic_goal for the epic one). When that cycle has not been
 * summarized yet (the maintenance job has not run since it closed), its
 * scores are read straight from collected_chests instead.
 *
 * Players with no chest at all in the previous cycle are not penalized: with
 * no row for them there is no telling a new member from an idle one.
 *
 * The base goals come from ChestGoalService, so with goals by guard level each
 * player's goal is the one of their level, and the raised goal is that goal
 * plus the percentage. The previous cycle is judged by the goal stored in its
 * summary (chest_goal / epic_goal), so a player who changed level since is not
 * judged by a goal they never had.
 */
class GoalPenaltyService
{
    use LocatorAwareTrait;

    public const TARGET_TOTAL = 'total';
    public const TARGET_EPIC = 'epic';
    public const TARGET_BOTH = 'both';

    /**
     * Why a player who missed the goal in the previous cycle has the goals they have.
     */
    public const STATUS_PENALIZED = 'penalized';
    public const STATUS_WAIVED = 'waived';
    public const STATUS_FIRST_CYCLE = 'first_cycle';

    /**
     * The player_cycle_summaries column holding each raised goal.
     */
    public const SUMMARY_COLUMNS = [
        self::TARGET_TOTAL => 'penalty_goal',
        self::TARGET_EPIC => 'penalty_epic_goal',
    ];

    /**
     * The player_cycle_summaries column holding each goal before the penalty.
     */
    public const BASE_COLUMNS = [
        self::TARGET_TOTAL => 'chest_goal',
        self::TARGET_EPIC => 'epic_goal',
    ];

    /**
     * How many cycles back the live fallback may go when summaries are missing.
     */
    private const MAX_FALLBACK_DEPTH = 1;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $settings = null;

    private ChestGoalService $goals;

    /**
     * @param \App\Service\ChestGoalService|null $goals Base goals; shared with the caller when it needs them too.
     */
    public function __construct(?ChestGoalService $goals = null)
    {
        $this->goals = $goals ?? new ChestGoalService();
    }

    /**
     * The base goals the penalty raises.
     *
     * @return \App\Service\ChestGoalService
     */
    public function goals(): ChestGoalService
    {
        return $this->goals;
    }

    /**
     * The penalty settings, read once from the config table.
     *
     * - `mode`: total, epic or both, as configured;
     * - `targets`: the goals actually watched, i.e. those of the mode that
     *   someone can have above 0;
     * - `base_goals` / `raised_goals`: the global goals, per target. With goals
     *   by guard level (`by_guard`) each player's goals differ; see
     *   ChestGoalService::levelTable();
     * - `enabled`: false whenever the penalty could not raise anything
     *   (switched off, a non-positive percentage, no goal), so callers only
     *   check it.
     *
     * @return array{enabled: bool, mode: string, targets: list<string>, percent: float, base_goals: array<string, int>, raised_goals: array<string, int>, by_guard: bool, cycle_days: int, reference_day: ?\Cake\I18n\FrozenTime}
     */
    public function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $configs = $this->fetchTable('Config')->find('list', keyField: 'param', valueField: 'value')
            ->where(['param IN' => [
                'goal_penalty_enabled', 'goal_penalty_percent', 'goal_penalty_target',
                'every_how_many_days', 'reference_day',
            ]])
            ->toArray();

        $mode = strtolower(trim((string)($configs['goal_penalty_target'] ?? '')));
        if (!in_array($mode, [self::TARGET_EPIC, self::TARGET_BOTH], true)) {
            $mode = self::TARGET_TOTAL;
        }
        $percent = is_numeric($configs['goal_penalty_percent'] ?? null) ? (float)$configs['goal_penalty_percent'] : 0.0;
        $cycleDays = is_numeric($configs['every_how_many_days'] ?? null) ? (int)$configs['every_how_many_days'] : 0;
        $referenceDay = !empty($configs['reference_day']) ? new FrozenTime($configs['reference_day']) : null;

        $baseGoals = [];
        foreach (array_keys(self::SUMMARY_COLUMNS) as $target) {
            $baseGoals[$target] = $this->goals->globalGoal($target);
        }
        $targets = [];
        $raisedGoals = [];
        foreach (self::targetsOf($mode) as $target) {
            if ($this->goals->hasGoal($target)) {
                $targets[] = $target;
                $raisedGoals[$target] = self::raisedGoal($baseGoals[$target], $percent);
            }
        }

        $enabled = (string)($configs['goal_penalty_enabled'] ?? '0') === '1'
            && $percent > 0
            && $targets !== []
            && $cycleDays > 0;

        return $this->settings = [
            'enabled' => $enabled,
            'mode' => $mode,
            'targets' => $targets,
            'percent' => $percent,
            'base_goals' => $baseGoals,
            'raised_goals' => $raisedGoals,
            'by_guard' => $this->goals->isByGuard(),
            'cycle_days' => $cycleDays,
            'reference_day' => $referenceDay,
        ];
    }

    /**
     * The goals a mode watches.
     *
     * @param string $mode One of the TARGET_* constants.
     * @return list<string>
     */
    public static function targetsOf(string $mode): array
    {
        return $mode === self::TARGET_BOTH ? [self::TARGET_TOTAL, self::TARGET_EPIC] : [$mode];
    }

    /**
     * Start of the current cycle, or of one before it, the way the score page counts cycles.
     *
     * @param int $cyclesAgo 0 for the current cycle, 1 for the previous one.
     * @return \Cake\I18n\FrozenTime|null Null when reference_day or the cycle length is missing.
     */
    public function cycleStart(int $cyclesAgo = 0): ?FrozenTime
    {
        $settings = $this->settings();
        if ($settings['reference_day'] === null || $settings['cycle_days'] <= 0) {
            return null;
        }
        $reference = $settings['reference_day'];
        $currentOffset = (int)floor($reference->diffInDays(FrozenTime::now()) / $settings['cycle_days']);

        return $reference->addDays(($currentOffset - $cyclesAgo) * $settings['cycle_days']);
    }

    /**
     * The base goal raised by the given percentage, rounded up to a whole point.
     *
     * The product is rounded to a few decimals before ceil() so float noise
     * (15000 * 1.1 = 16500.000000000002) does not add a point.
     *
     * @param int $baseGoal Goal before the penalty.
     * @param float $percent Increase in percent.
     * @return int
     */
    public static function raisedGoal(int $baseGoal, float $percent): int
    {
        return (int)ceil(round($baseGoal * (100 + $percent) / 100, 6));
    }

    /**
     * The score a cycle result is judged by for the given target.
     *
     * @param array{total_score: int, epic_crypt_score: int} $result A player's cycle result.
     * @param string $target TARGET_TOTAL or TARGET_EPIC.
     * @return int
     */
    public static function scoreFor(array $result, string $target): int
    {
        return (int)($target === self::TARGET_EPIC ? $result['epic_crypt_score'] : $result['total_score']);
    }

    /**
     * Which goals a set of raised goals covers, as stored in penalty_target.
     *
     * @param array<string, int> $raised Target => raised goal.
     * @return string|null
     */
    public static function targetLabel(array $raised): ?string
    {
        if ($raised === []) {
            return null;
        }

        return count($raised) > 1 ? self::TARGET_BOTH : (string)array_key_first($raised);
    }

    /**
     * Raised goals for the cycle that starts at $cycleStart.
     *
     * Only penalized players appear in the result, each with the goals that
     * were raised for them; every other goal is the base one. Empty when the
     * penalty is off.
     *
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the cycle.
     * @return array<string, array<string, int>> Player name => target => raised goal.
     */
    public function goalsForCycle(FrozenTime $cycleStart): array
    {
        $goals = [];
        foreach ($this->evaluateCycle($cycleStart) as $player => $row) {
            if ($row['status'] === self::STATUS_PENALIZED) {
                $goals[(string)$player] = $row['raised'];
            }
        }

        return $goals;
    }

    /**
     * Every player who missed a goal in the cycle before $cycleStart, and
     * what that means for them in this cycle.
     *
     * With $preview the evaluation runs even while the penalty is switched off,
     * so administrators can see whom it would hit before turning it on. It
     * then reads as "if the penalty were on now": no stored summary carries a
     * raised goal, so everyone is judged by the base goals.
     *
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the cycle.
     * @param bool $preview Evaluate even when the penalty is off.
     * @return array<string, array{status: string, previous: array<string, array{score: int, goal: int, missed: bool}>, raised: array<string, int>, goals: array<string, int>}>
     *   - `previous`: per watched goal, the score and the goal of the previous cycle;
     *   - `raised`: the goals raised for the player, i.e. the ones missed (only
     *     applied when the status is penalized);
     *   - `goals`: per watched goal, the goal the player has in this cycle.
     */
    public function evaluateCycle(FrozenTime $cycleStart, bool $preview = false): array
    {
        $settings = $this->settings();
        $evaluable = $settings['targets'] !== [] && $settings['cycle_days'] > 0;
        if (!$settings['enabled'] && !($preview && $evaluable)) {
            return [];
        }

        return $this->computeEvaluation($cycleStart, 0);
    }

    /**
     * Bring a stored cycle summary in line with the current evaluation, after a
     * player was released or the release was undone.
     *
     * Nothing happens when that cycle has not been summarized yet: the summary
     * will be written with the right goals when the cycle is processed.
     *
     * @param string $player Player name.
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the cycle.
     * @return void
     */
    public function syncSummary(string $player, FrozenTime $cycleStart): void
    {
        $summaries = $this->fetchTable('PlayerCycleSummaries');
        $summary = $summaries->find()
            ->where(['player_name' => $player, 'cycle_start_date' => $cycleStart->format('Y-m-d')])
            ->first();
        if ($summary === null) {
            return;
        }

        $raised = $this->goalsForCycle($cycleStart)[$player] ?? [];
        foreach (self::SUMMARY_COLUMNS as $target => $column) {
            $summary->set($column, $raised[$target] ?? null);
        }
        $summary->penalty_target = self::targetLabel($raised);

        $requiredScore = $raised[self::TARGET_TOTAL]
            ?? $summary->baseGoalFor(self::TARGET_TOTAL, $this->baseGoal($player, self::TARGET_TOTAL));
        $summary->goal_achieved = (int)$summary->total_score >= $requiredScore;
        $summary->fine_due = !$summary->goal_achieved;
        $summaries->saveOrFail($summary);
    }

    /**
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the cycle.
     * @param int $depth How many cycles back the live fallback already went.
     * @return array<string, array{status: string, previous: array<string, array{score: int, goal: int, missed: bool}>, raised: array<string, int>, goals: array<string, int>}>
     */
    private function computeEvaluation(FrozenTime $cycleStart, int $depth): array
    {
        $settings = $this->settings();
        $targets = $settings['targets'];
        $previousStart = $cycleStart->subDays($settings['cycle_days']);

        /** @var \App\Model\Table\PlayerCycleSummariesTable $summaries */
        $summaries = $this->fetchTable('PlayerCycleSummaries');

        // player => target => [score, goal the player had in that cycle]
        $previous = [];
        $rows = $summaries->find()
            ->select([
                'player_name', 'total_score', 'epic_crypt_score',
                'penalty_goal', 'penalty_epic_goal', 'chest_goal', 'epic_goal',
            ])
            ->where(['cycle_start_date' => $previousStart->format('Y-m-d')])
            ->all();

        if (!$rows->isEmpty()) {
            $previousWaivers = $this->waivedPlayers($previousStart);
            foreach ($rows as $row) {
                $result = ['total_score' => (int)$row->total_score, 'epic_crypt_score' => (int)$row->epic_crypt_score];
                $waived = isset($previousWaivers[$row->player_name]);
                foreach ($targets as $target) {
                    $stored = $row->get(self::SUMMARY_COLUMNS[$target]);
                    // Rows written before goals by guard level have no base goal; today's is the best guess
                    $base = $row->get(self::BASE_COLUMNS[$target]);
                    $base = $base !== null ? (int)$base : $this->baseGoal((string)$row->player_name, $target);
                    $carried = $stored !== null && !$waived ? (int)$stored : $base;
                    $previous[$row->player_name][$target] = [self::scoreFor($result, $target), $carried];
                }
            }
        } else {
            $previousEnd = $cycleStart->sub(new \DateInterval('PT1S'));
            $scores = $summaries->scoresForDateRange($previousStart, $previousEnd);
            $previousGoals = [];
            if ($depth < self::MAX_FALLBACK_DEPTH && $scores !== []) {
                foreach ($this->computeEvaluation($previousStart, $depth + 1) as $player => $row) {
                    if ($row['status'] === self::STATUS_PENALIZED) {
                        $previousGoals[(string)$player] = $row['raised'];
                    }
                }
            }
            foreach ($scores as $player => $result) {
                foreach ($targets as $target) {
                    $previous[$player][$target] = [
                        self::scoreFor($result, $target),
                        $previousGoals[$player][$target] ?? $this->baseGoal((string)$player, $target),
                    ];
                }
            }
        }

        $missed = array_filter($previous, function (array $byTarget): bool {
            foreach ($byTarget as [$score, $goal]) {
                if ($score < $goal) {
                    return true;
                }
            }

            return false;
        });
        if ($missed === []) {
            return [];
        }

        $veterans = $this->playersActiveBefore(array_map('strval', array_keys($missed)), $previousStart);
        $waivers = $this->waivedPlayers($cycleStart);

        $evaluation = [];
        foreach ($missed as $player => $byTarget) {
            $player = (string)$player;
            if (!isset($veterans[$player])) {
                $status = self::STATUS_FIRST_CYCLE;
            } elseif (isset($waivers[$player])) {
                $status = self::STATUS_WAIVED;
            } else {
                $status = self::STATUS_PENALIZED;
            }

            $previousRows = [];
            $raised = [];
            $goals = [];
            foreach ($byTarget as $target => [$score, $goal]) {
                $previousRows[$target] = ['score' => $score, 'goal' => $goal, 'missed' => $score < $goal];
                $base = $this->baseGoal($player, $target);
                if ($score < $goal && $base > 0) {
                    $raised[$target] = self::raisedGoal($base, $settings['percent']);
                }
                $goals[$target] = $status === self::STATUS_PENALIZED && isset($raised[$target])
                    ? $raised[$target]
                    : $base;
            }

            $evaluation[$player] = [
                'status' => $status,
                'previous' => $previousRows,
                'raised' => $raised,
                'goals' => $goals,
            ];
        }

        return $evaluation;
    }

    /**
     * A player's goal before the penalty, as it stands now.
     *
     * @param string $player Player name.
     * @param string $target TARGET_TOTAL or TARGET_EPIC.
     * @return int
     */
    private function baseGoal(string $player, string $target): int
    {
        return $this->goals->goalsFor($player)[$target];
    }

    /**
     * Which of the given players had any chest or cycle summary before $before.
     *
     * @param list<string> $players Player names.
     * @param \Cake\I18n\FrozenTime $before Start of the cycle they missed.
     * @return array<string, true>
     */
    private function playersActiveBefore(array $players, FrozenTime $before): array
    {
        $active = [];
        foreach (array_chunk($players, 200) as $chunk) {
            $fromSummaries = $this->fetchTable('PlayerCycleSummaries')->find()
                ->select(['player_name'])
                ->distinct(['player_name'])
                ->where(['player_name IN' => $chunk, 'cycle_start_date <' => $before->format('Y-m-d')])
                ->all()
                ->extract('player_name');
            foreach ($fromSummaries as $player) {
                $active[(string)$player] = true;
            }

            $fromChests = $this->fetchTable('CollectedChests')->find()
                ->select(['player'])
                ->distinct(['player'])
                ->where(['player IN' => $chunk, 'collected_at <' => $before])
                ->all()
                ->extract('player');
            foreach ($fromChests as $player) {
                $active[(string)$player] = true;
            }
        }

        return $active;
    }

    /**
     * Players an administrator released from the penalty in the given cycle.
     *
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the cycle.
     * @return array<string, true>
     */
    private function waivedPlayers(FrozenTime $cycleStart): array
    {
        $waived = [];
        $names = $this->fetchTable('GoalPenaltyWaivers')->find()
            ->select(['player_name'])
            ->where(['cycle_start_date' => $cycleStart->format('Y-m-d')])
            ->all()
            ->extract('player_name');
        foreach ($names as $player) {
            $waived[(string)$player] = true;
        }

        return $waived;
    }
}
