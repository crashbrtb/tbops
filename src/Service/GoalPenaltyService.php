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
 * Besides this automatic penalty, an administrator can give a player a manual
 * one for a cycle (a row in manual_goal_penalties, always with a reason). It
 * raises the goals it names by its own percentage, whether or not the
 * automatic penalty is on, and adds to the automatic one when both hit the
 * same goal: +10% automatic and +5% manual make the base goal +15%. A release
 * only lifts the automatic penalty; a manual one is removed on its own.
 *
 * Every penalty carries a short reason: the manual one is typed by the
 * administrator, the automatic one says how far the player got in the previous
 * cycle ("Previous goal not reached (89%)").
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
     * Longest reason a penalty can carry.
     */
    public const REASON_MAX_LENGTH = 100;

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
     * were raised for them; every other goal is the base one. With the
     * automatic penalty off only manual penalties show up.
     *
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the cycle.
     * @return array<string, array<string, int>> Player name => target => raised goal.
     */
    public function goalsForCycle(FrozenTime $cycleStart): array
    {
        return array_map(fn (array $penalty): array => $penalty['goals'], $this->penaltiesForCycle($cycleStart));
    }

    /**
     * Every player carrying a raised goal in the cycle that starts at
     * $cycleStart, automatic and manual penalties together.
     *
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the cycle.
     * @return array<string, array{goals: array<string, int>, percent: float, automatic_reason: ?string, manual_reason: ?string}>
     *   - `goals`: target => raised goal, only for the goals that were raised;
     *   - `percent`: the highest increase among those goals;
     *   - `automatic_reason` / `manual_reason`: why, null when that penalty does not apply.
     */
    public function penaltiesForCycle(FrozenTime $cycleStart): array
    {
        return $this->computePenalties($cycleStart, 0, $this->settings()['enabled']);
    }

    /**
     * The reasons of a penalty, automatic first.
     *
     * @param array{automatic_reason: ?string, manual_reason: ?string} $penalty A row of penaltiesForCycle().
     * @return list<string>
     */
    public static function reasonsOf(array $penalty): array
    {
        return array_values(array_filter(
            [$penalty['automatic_reason'] ?? null, $penalty['manual_reason'] ?? null],
            fn (?string $reason): bool => $reason !== null && $reason !== ''
        ));
    }

    /**
     * Why the automatic penalty applies: how far the player got in the previous cycle.
     *
     * @param array<string, array{score: int, goal: int, missed: bool}> $previous Per watched goal, the previous cycle.
     * @return string At most REASON_MAX_LENGTH characters.
     */
    public static function missReason(array $previous): string
    {
        $labels = [self::TARGET_TOTAL => __('Total'), self::TARGET_EPIC => __('Epic')];
        $parts = [];
        foreach ($previous as $target => $row) {
            if (!$row['missed']) {
                continue;
            }
            // Rounded down so 99.9% never reads as a goal that was reached
            $percent = $row['goal'] > 0 ? (int)floor($row['score'] * 100 / $row['goal']) : 0;
            $parts[] = (count($previous) > 1 ? $labels[$target] . ' ' : '') . $percent . '%';
        }

        return mb_substr(__('Previous goal not reached ({0})', implode(', ', $parts)), 0, self::REASON_MAX_LENGTH);
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
     * @return array<string, array{status: string, previous: array<string, array{score: int, goal: int, missed: bool}>, raised: array<string, int>, goals: array<string, int>, reason: string}>
     *   - `previous`: per watched goal, the score and the goal of the previous cycle;
     *   - `raised`: the goals the automatic penalty raises for the player, i.e.
     *     the ones missed (only applied when the status is penalized);
     *   - `goals`: per watched goal, the goal the player has in this cycle, a
     *     manual penalty included;
     *   - `reason`: the short reason of the automatic penalty.
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
     * player was released, the release was undone, or a manual penalty was
     * added or removed.
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

        $penalty = $this->penaltiesForCycle($cycleStart)[$player] ?? null;
        $raised = $penalty['goals'] ?? [];
        foreach (self::SUMMARY_COLUMNS as $target => $column) {
            $summary->set($column, $raised[$target] ?? null);
        }
        $summary->penalty_target = self::targetLabel($raised);
        $summary->penalty_reason = $penalty['automatic_reason'] ?? null;

        $requiredScore = $raised[self::TARGET_TOTAL]
            ?? $summary->baseGoalFor(self::TARGET_TOTAL, $this->baseGoal($player, self::TARGET_TOTAL));
        $summary->goal_achieved = (int)$summary->total_score >= $requiredScore;
        $summary->fine_due = !$summary->goal_achieved;
        $summaries->saveOrFail($summary);
    }

    /**
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the cycle.
     * @param int $depth How many cycles back the live fallback already went.
     * @param bool $automatic Whether the automatic penalty counts; manual ones always do.
     * @return array<string, array{goals: array<string, int>, percent: float, automatic_reason: ?string, manual_reason: ?string}>
     */
    private function computePenalties(FrozenTime $cycleStart, int $depth, bool $automatic): array
    {
        $settings = $this->settings();

        // player => target => increase in percent
        $percents = [];
        $automaticReasons = [];
        $manualReasons = [];
        if ($automatic) {
            foreach ($this->computeEvaluation($cycleStart, $depth) as $player => $row) {
                if ($row['status'] !== self::STATUS_PENALIZED || $row['raised'] === []) {
                    continue;
                }
                foreach (array_keys($row['raised']) as $target) {
                    $percents[(string)$player][$target] = $settings['percent'];
                }
                $automaticReasons[(string)$player] = $row['reason'];
            }
        }
        foreach ($this->manualPenalties($cycleStart) as $player => $manual) {
            $player = (string)$player;
            foreach ($manual['targets'] as $target) {
                if ($this->baseGoal($player, $target) > 0) {
                    $percents[$player][$target] = ($percents[$player][$target] ?? 0.0) + $manual['percent'];
                    $manualReasons[$player] = $manual['reason'];
                }
            }
        }

        $penalties = [];
        foreach ($percents as $player => $byTarget) {
            $player = (string)$player;
            $goals = [];
            foreach (array_keys(self::SUMMARY_COLUMNS) as $target) {
                if (isset($byTarget[$target])) {
                    $goals[$target] = self::raisedGoal($this->baseGoal($player, $target), $byTarget[$target]);
                }
            }
            $penalties[$player] = [
                'goals' => $goals,
                'percent' => (float)max($byTarget),
                'automatic_reason' => $automaticReasons[$player] ?? null,
                'manual_reason' => $manualReasons[$player] ?? null,
            ];
        }

        return $penalties;
    }

    /**
     * The automatic penalty alone: who missed a goal in the previous cycle.
     * Manual penalties only show in `goals` (and in what the previous cycle
     * was judged by), never in `status` or `raised`.
     *
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the cycle.
     * @param int $depth How many cycles back the live fallback already went.
     * @return array<string, array{status: string, previous: array<string, array{score: int, goal: int, missed: bool}>, raised: array<string, int>, goals: array<string, int>, reason: string}>
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
            $previousManual = $this->manualPenalties($previousStart);
            foreach ($rows as $row) {
                $result = ['total_score' => (int)$row->total_score, 'epic_crypt_score' => (int)$row->epic_crypt_score];
                $waived = isset($previousWaivers[$row->player_name]);
                foreach ($targets as $target) {
                    $stored = $row->get(self::SUMMARY_COLUMNS[$target]);
                    // Rows written before goals by guard level have no base goal; today's is the best guess
                    $base = $row->get(self::BASE_COLUMNS[$target]);
                    $base = $base !== null ? (int)$base : $this->baseGoal((string)$row->player_name, $target);
                    if ($stored !== null && !$waived) {
                        $carried = (int)$stored;
                    } else {
                        // A release lifts the automatic penalty only
                        $manualPercent = self::manualPercent($previousManual[$row->player_name] ?? null, $target);
                        $carried = $manualPercent > 0 ? self::raisedGoal($base, $manualPercent) : $base;
                    }
                    $previous[$row->player_name][$target] = [self::scoreFor($result, $target), $carried];
                }
            }
        } else {
            $previousEnd = $cycleStart->sub(new \DateInterval('PT1S'));
            $scores = $summaries->scoresForDateRange($previousStart, $previousEnd);
            $previousGoals = [];
            if ($depth < self::MAX_FALLBACK_DEPTH && $scores !== []) {
                foreach ($this->computePenalties($previousStart, $depth + 1, true) as $player => $penalty) {
                    $previousGoals[(string)$player] = $penalty['goals'];
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
        $manual = $this->manualPenalties($cycleStart);

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
                $percent = self::manualPercent($manual[$player] ?? null, $target);
                if ($status === self::STATUS_PENALIZED && isset($raised[$target])) {
                    $percent += $settings['percent'];
                }
                $goals[$target] = $percent > 0 ? self::raisedGoal($base, $percent) : $base;
            }

            $evaluation[$player] = [
                'status' => $status,
                'previous' => $previousRows,
                'raised' => $raised,
                'goals' => $goals,
                'reason' => self::missReason($previousRows),
            ];
        }

        return $evaluation;
    }

    /**
     * Manual penalties administrators added to the given cycle.
     *
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the cycle.
     * @return array<string, array{percent: float, targets: list<string>, reason: string}> By player name.
     */
    private function manualPenalties(FrozenTime $cycleStart): array
    {
        $manual = [];
        $rows = $this->fetchTable('ManualGoalPenalties')->find()
            ->where(['cycle_start_date' => $cycleStart->format('Y-m-d')])
            ->all();
        /** @var \App\Model\Entity\ManualGoalPenalty $row */
        foreach ($rows as $row) {
            $manual[(string)$row->player_name] = [
                'percent' => (float)$row->percent,
                'targets' => $row->targets(),
                'reason' => (string)$row->reason,
            ];
        }

        return $manual;
    }

    /**
     * How much a manual penalty raises one goal, 0 when it does not touch it.
     *
     * @param array{percent: float, targets: list<string>}|null $manual A row of manualPenalties().
     * @param string $target TARGET_TOTAL or TARGET_EPIC.
     * @return float
     */
    private static function manualPercent(?array $manual, string $target): float
    {
        return $manual !== null && in_array($target, $manual['targets'], true) ? $manual['percent'] : 0.0;
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
