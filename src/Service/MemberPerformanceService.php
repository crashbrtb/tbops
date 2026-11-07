<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Service to calculate member performance, goal achievement statistics,
 * and ranking based on success rate (aproveitamento) for tournaments/events and chest cycles.
 */
class MemberPerformanceService
{
    use LocatorAwareTrait;

    public const PERIODS = [
        30 => 'Last month (30 days)',
        60 => 'Last 60 days',
        90 => 'Last 90 days',
        0 => 'All time',
    ];

    public const DEFAULT_PERIOD = 30;

    protected ?EventScoringService $eventScoringService = null;

    public function __construct(?EventScoringService $eventScoringService = null)
    {
        $this->eventScoringService = $eventScoringService ?? new EventScoringService();
    }

    /**
     * Get performance metrics, ranking and chart datasets for the given period.
     *
     * @param int $period Days to look back (0 for all).
     * @return array<string, mixed>
     */
    public function getPerformanceData(int $period = self::DEFAULT_PERIOD): array
    {
        $now = DateTime::now();
        $from = $period > 0 ? $now->subDays($period) : null;

        // 1. Load active members only - inactive players must NOT appear
        $membersTable = $this->fetchTable('Members');
        $activeMembers = $membersTable->find()
            ->where(['Members.active' => true])
            ->all();

        $memberMap = [];
        $playerStats = [];

        foreach ($activeMembers as $m) {
            $normalizedName = trim((string)$m->player);
            $lowerName = mb_strtolower($normalizedName);
            $memberMap[$lowerName] = $m;

            $playerStats[$lowerName] = [
                'member_id' => $m->id,
                'player' => $normalizedName,
                'active' => true,
                'created_at' => $m->created_at,
                'guards' => (int)($m->guards ?? 0),
                'power' => (int)($m->power ?? 0),
                'administrative' => (bool)$m->administrative_account,
                'events_met' => 0,
                'events_evaluated' => 0, // only events that have a goal and started after join date
                'events_participated' => 0,
                'events_history' => [],
                'chests_met' => 0,
                'chests_evaluated' => 0, // only cycles that started after join date
                'chests_participated' => 0,
                'chests_history' => [],
                'total_goals_met' => 0,
                'total_evaluated' => 0,
                'success_rate' => 0.0,
            ];
        }

        // 2. Fetch events in window
        $eventsTable = $this->fetchTable('Events');
        $standingsTable = $this->fetchTable('EventStandings');

        $eventsQuery = $eventsTable->find()->order(['starts_at' => 'ASC']);
        if ($from !== null) {
            $eventsQuery->where(['starts_at >=' => $from]);
        }
        $events = $eventsQuery->all();

        $eventChartList = [];
        $totalEventGoalsMet = 0;
        $totalEventGoalsEvaluated = 0;

        foreach ($events as $event) {
            $goal = $event->goal();
            $goalActive = $goal->isActive();

            // Retrieve standings
            $standings = $standingsTable->find()
                ->where(['event_id' => $event->id])
                ->orderBy(['position' => 'ASC'])
                ->all();

            $rows = [];
            if ($standings->count() > 0) {
                foreach ($standings as $st) {
                    $lowerName = mb_strtolower(trim((string)$st->player));
                    // Only process active members
                    if (!isset($memberMap[$lowerName])) {
                        continue;
                    }
                    $mem = $memberMap[$lowerName];
                    $level = (int)$st->guard_level;
                    if ($level === 0) {
                        $level = (int)($mem->guards ?? 0);
                    }
                    $target = $st->goal !== null ? (int)$st->goal : ($goalActive ? $goal->goalForLevel($level) : null);
                    $met = $st->goal_met !== null ? (bool)$st->goal_met : (($goalActive && $target !== null) ? ((int)$st->points >= $target) : null);

                    $rows[] = [
                        'player' => (string)$st->player,
                        'lower_player' => $lowerName,
                        'position' => (int)$st->position,
                        'points' => (int)$st->points,
                        'guard_level' => $level,
                        'goal' => $target,
                        'goal_met' => $met,
                    ];
                }
            } else {
                try {
                    $results = $this->eventScoringService->resultsFor($event);
                    if (!empty($results['rows'])) {
                        foreach ($results['rows'] as $r) {
                            $lowerName = mb_strtolower(trim((string)$r['player']));
                            if (!isset($memberMap[$lowerName])) {
                                continue;
                            }
                            $rows[] = [
                                'player' => (string)$r['player'],
                                'lower_player' => $lowerName,
                                'position' => (int)$r['position'],
                                'points' => (int)$r['points'],
                                'guard_level' => (int)($r['guard_level'] ?? 0),
                                'goal' => $r['goal'] ?? null,
                                'goal_met' => $r['goal_met'] ?? null,
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    $rows = [];
                }
            }

            $eventMetCount = 0;
            $eventMissedCount = 0;

            foreach ($rows as $row) {
                $lowerName = $row['lower_player'];
                if (!isset($playerStats[$lowerName])) {
                    continue;
                }

                $memberObj = $memberMap[$lowerName];
                $memberEntry = $memberObj->created_at;

                // Rule: If player entered clan after event started, disregard it for this player
                $isDisregardedMidEvent = false;
                if ($memberEntry !== null && $event->starts_at !== null && $event->starts_at < $memberEntry) {
                    $isDisregardedMidEvent = true;
                }

                $met = $row['goal_met'];
                $hasGoal = $goalActive && $row['goal'] !== null && $row['goal'] > 0;

                $playerStats[$lowerName]['events_participated']++;

                if (!$isDisregardedMidEvent) {
                    if ($hasGoal) {
                        $playerStats[$lowerName]['events_evaluated']++;
                        $totalEventGoalsEvaluated++;
                        if ($met === true) {
                            $playerStats[$lowerName]['events_met']++;
                            $eventMetCount++;
                            $totalEventGoalsMet++;
                        } else {
                            $eventMissedCount++;
                        }
                    }
                }

                $playerStats[$lowerName]['events_history'][] = [
                    'event_id' => $event->id,
                    'event_name' => $event->name,
                    'starts_at' => $event->starts_at,
                    'ends_at' => $event->ends_at,
                    'position' => $row['position'],
                    'points' => $row['points'],
                    'goal' => $row['goal'],
                    'goal_met' => $met,
                    'has_goal' => $hasGoal,
                    'disregarded' => $isDisregardedMidEvent,
                ];
            }

            if (!empty($rows)) {
                $eventChartList[] = [
                    'id' => $event->id,
                    'name' => $event->name,
                    'date' => $event->starts_at ? $event->starts_at->format('d/m/Y') : '',
                    'short_date' => $event->starts_at ? $event->starts_at->format('d/m') : '',
                    'met' => $eventMetCount,
                    'missed' => $eventMissedCount,
                    'total' => count($rows),
                    'goal_active' => $goalActive,
                ];
            }
        }

        // 3. Fetch chest cycles from PlayerCycleSummaries
        $summariesTable = $this->fetchTable('PlayerCycleSummaries');
        $allSummaries = $summariesTable->find()
            ->order(['cycle_start_date' => 'DESC', 'player_name' => 'ASC'])
            ->all();

        // Group summaries by cycle start date
        $cycleGroups = [];
        foreach ($allSummaries as $s) {
            $key = $s->cycle_start_date->toDateString();
            $cycleGroups[$key][] = $s;
        }

        // Filter cycles within period, or fallback to the latest 4 cycles if none in period
        $selectedCycleKeys = [];
        $isFallbackCycles = false;
        if ($from !== null) {
            $fromDateString = $from->format('Y-m-d');
            foreach (array_keys($cycleGroups) as $ck) {
                if ($ck >= $fromDateString) {
                    $selectedCycleKeys[] = $ck;
                }
            }
        } else {
            $selectedCycleKeys = array_keys($cycleGroups);
        }

        if (empty($selectedCycleKeys) && !empty($cycleGroups)) {
            $selectedCycleKeys = array_slice(array_keys($cycleGroups), 0, 4);
            $isFallbackCycles = true;
        }

        $chestChartList = [];
        $totalChestGoalsMet = 0;
        $totalChestGoalsEvaluated = 0;

        // Process cycles in chronological order for chart display
        $orderedCycleKeys = array_reverse($selectedCycleKeys);
        foreach ($orderedCycleKeys as $ck) {
            $cycleRows = $cycleGroups[$ck];
            $metCount = 0;
            $missedCount = 0;
            $start = $cycleRows[0]->cycle_start_date;
            $end = $cycleRows[0]->cycle_end_date;

            foreach ($cycleRows as $cRow) {
                $lowerName = mb_strtolower(trim((string)$cRow->player_name));
                // Only active clan members
                if (!isset($playerStats[$lowerName])) {
                    continue;
                }

                $memberObj = $memberMap[$lowerName];
                $memberEntry = $memberObj->created_at;

                // Rule: If player entered clan after cycle started, disregard that cycle for the player
                $isDisregardedMidCycle = false;
                if ($memberEntry !== null && $start < $memberEntry->startOfDay()) {
                    $isDisregardedMidCycle = true;
                }

                $met = (bool)$cRow->goal_achieved;
                $playerStats[$lowerName]['chests_participated']++;

                if (!$isDisregardedMidCycle) {
                    $playerStats[$lowerName]['chests_evaluated']++;
                    $totalChestGoalsEvaluated++;
                    if ($met) {
                        $playerStats[$lowerName]['chests_met']++;
                        $metCount++;
                        $totalChestGoalsMet++;
                    } else {
                        $missedCount++;
                    }
                }

                $playerStats[$lowerName]['chests_history'][] = [
                    'cycle_start' => $start,
                    'cycle_end' => $end,
                    'total_chests' => (int)$cRow->total_chests,
                    'total_score' => (int)$cRow->total_score,
                    'epic_crypt_score' => (int)$cRow->epic_crypt_score,
                    'chest_goal' => (int)$cRow->chest_goal,
                    'goal_achieved' => $met,
                    'disregarded' => $isDisregardedMidCycle,
                ];
            }

            $chestChartList[] = [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'label' => $start->format('d/m') . ' - ' . $end->format('d/m'),
                'full_label' => $start->format('d/m/Y') . ' a ' . $end->format('d/m/Y'),
                'met' => $metCount,
                'missed' => $missedCount,
                'total' => count($cycleRows),
            ];
        }

        // 4. Calculate final player statistics, totals and success rates
        $ranking = array_values($playerStats);
        foreach ($ranking as &$p) {
            $p['total_goals_met'] = $p['events_met'] + $p['chests_met'];
            $evaluated = $p['events_evaluated'] + $p['chests_evaluated'];
            $p['total_evaluated'] = $evaluated;
            $p['success_rate'] = $evaluated > 0 ? round(($p['total_goals_met'] / $evaluated) * 100, 1) : 0.0;
            $p['event_success_rate'] = $p['events_evaluated'] > 0
                ? round(($p['events_met'] / $p['events_evaluated']) * 100, 1)
                : 0.0;
            $p['chest_success_rate'] = $p['chests_evaluated'] > 0
                ? round(($p['chests_met'] / $p['chests_evaluated']) * 100, 1)
                : 0.0;

            // Sort event history newest first
            usort($p['events_history'], fn ($a, $b) => $b['starts_at'] <=> $a['starts_at']);
            // Sort chest history newest first
            usort($p['chests_history'], fn ($a, $b) => $b['cycle_start'] <=> $a['cycle_start']);
        }
        unset($p);

        // Sort ranking: Primary by Success Rate (aproveitamento %),
        // then by total goals met, then events met, then chests met, then power, then name
        usort($ranking, function (array $a, array $b): int {
            if ($b['success_rate'] !== $a['success_rate']) {
                return $b['success_rate'] <=> $a['success_rate'];
            }
            if ($b['total_goals_met'] !== $a['total_goals_met']) {
                return $b['total_goals_met'] <=> $a['total_goals_met'];
            }
            if ($b['events_met'] !== $a['events_met']) {
                return $b['events_met'] <=> $a['events_met'];
            }
            if ($b['chests_met'] !== $a['chests_met']) {
                return $b['chests_met'] <=> $a['chests_met'];
            }
            if ($b['power'] !== $a['power']) {
                return $b['power'] <=> $a['power'];
            }

            return strcasecmp($a['player'], $b['player']);
        });

        // Assign ranking positions
        $currentRank = 1;
        foreach ($ranking as &$entry) {
            $entry['rank'] = $currentRank++;
        }
        unset($entry);

        // Overall statistics
        $totalEvaluatedOverall = $totalEventGoalsEvaluated + $totalChestGoalsEvaluated;
        $totalMetOverall = $totalEventGoalsMet + $totalChestGoalsMet;

        $stats = [
            'total_players_ranked' => count($ranking),
            'events_count' => count($eventChartList),
            'events_goals_met' => $totalEventGoalsMet,
            'events_goals_evaluated' => $totalEventGoalsEvaluated,
            'events_success_rate' => $totalEventGoalsEvaluated > 0 ? round(($totalEventGoalsMet / $totalEventGoalsEvaluated) * 100, 1) : 0.0,
            'chests_cycles_count' => count($chestChartList),
            'chests_goals_met' => $totalChestGoalsMet,
            'chests_goals_evaluated' => $totalChestGoalsEvaluated,
            'chests_success_rate' => $totalChestGoalsEvaluated > 0 ? round(($totalChestGoalsMet / $totalChestGoalsEvaluated) * 100, 1) : 0.0,
            'total_goals_met' => $totalMetOverall,
            'total_goals_evaluated' => $totalEvaluatedOverall,
            'overall_success_rate' => $totalEvaluatedOverall > 0 ? round(($totalMetOverall / $totalEvaluatedOverall) * 100, 1) : 0.0,
            'top_performer' => $ranking[0] ?? null,
            'is_fallback_cycles' => $isFallbackCycles,
        ];

        return [
            'period' => $period,
            'periods' => self::PERIODS,
            'ranking' => $ranking,
            'stats' => $stats,
            'eventChart' => $eventChartList,
            'chestChart' => $chestChartList,
        ];
    }
}
