<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Service to calculate member performance, goal achievement statistics,
 * and ranking for tournaments/events and chest cycles.
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

        // 1. Load active members to ensure every clan member is accounted for
        $membersTable = $this->fetchTable('Members');
        $allMembers = $membersTable->find()->all();
        $memberMap = [];
        $playerStats = [];

        foreach ($allMembers as $m) {
            $normalizedName = trim((string)$m->player);
            $memberMap[mb_strtolower($normalizedName)] = $m;

            if ($m->active) {
                $playerStats[$normalizedName] = [
                    'member_id' => $m->id,
                    'player' => $normalizedName,
                    'active' => (bool)$m->active,
                    'guards' => (int)($m->guards ?? 0),
                    'power' => (int)($m->power ?? 0),
                    'administrative' => (bool)$m->administrative_account,
                    'score' => 0, // 1 point per event goal beaten in period
                    'events_met' => 0,
                    'events_missed' => 0,
                    'events_no_goal' => 0,
                    'events_participated' => 0,
                    'events_history' => [],
                    'chests_met' => 0,
                    'chests_missed' => 0,
                    'chests_participated' => 0,
                    'chests_history' => [],
                    'total_points_earned' => 0,
                ];
            }
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

            // If snapshot standings empty, try computing results through EventScoringService
            $rows = [];
            if ($standings->count() > 0) {
                foreach ($standings as $st) {
                    $level = (int)$st->guard_level;
                    if ($level === 0 && isset($memberMap[mb_strtolower((string)$st->player)])) {
                        $level = (int)($memberMap[mb_strtolower((string)$st->player)]->guards ?? 0);
                    }
                    $target = $st->goal !== null ? (int)$st->goal : ($goalActive ? $goal->goalForLevel($level) : null);
                    $met = $st->goal_met !== null ? (bool)$st->goal_met : (($goalActive && $target !== null) ? ((int)$st->points >= $target) : null);

                    $rows[] = [
                        'player' => (string)$st->player,
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
                            $rows[] = [
                                'player' => (string)$r['player'],
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
                $playerName = trim($row['player']);
                $lowerName = mb_strtolower($playerName);

                // Initialize player if not active member or not yet tracked
                if (!isset($playerStats[$playerName])) {
                    // Check if exists under another casing
                    $foundKey = null;
                    foreach (array_keys($playerStats) as $k) {
                        if (mb_strtolower($k) === $lowerName) {
                            $foundKey = $k;
                            break;
                        }
                    }

                    if ($foundKey !== null) {
                        $playerName = $foundKey;
                    } else {
                        $mem = $memberMap[$lowerName] ?? null;
                        $playerStats[$playerName] = [
                            'member_id' => $mem?->id,
                            'player' => $playerName,
                            'active' => $mem ? (bool)$mem->active : false,
                            'guards' => (int)($mem->guards ?? $row['guard_level'] ?? 0),
                            'power' => (int)($mem->power ?? 0),
                            'administrative' => $mem ? (bool)$mem->administrative_account : false,
                            'score' => 0,
                            'events_met' => 0,
                            'events_missed' => 0,
                            'events_no_goal' => 0,
                            'events_participated' => 0,
                            'events_history' => [],
                            'chests_met' => 0,
                            'chests_missed' => 0,
                            'chests_participated' => 0,
                            'chests_history' => [],
                            'total_points_earned' => 0,
                        ];
                    }
                }

                $met = $row['goal_met'];
                $scoreAward = ($met === true) ? 1 : 0;

                $playerStats[$playerName]['events_participated']++;
                $playerStats[$playerName]['total_points_earned'] += $row['points'];

                if ($met === true) {
                    $playerStats[$playerName]['score'] += 1;
                    $playerStats[$playerName]['events_met']++;
                    $eventMetCount++;
                    $totalEventGoalsMet++;
                    $totalEventGoalsEvaluated++;
                } elseif ($met === false) {
                    $playerStats[$playerName]['events_missed']++;
                    $eventMissedCount++;
                    $totalEventGoalsEvaluated++;
                } else {
                    $playerStats[$playerName]['events_no_goal']++;
                }

                $playerStats[$playerName]['events_history'][] = [
                    'event_id' => $event->id,
                    'event_name' => $event->name,
                    'starts_at' => $event->starts_at,
                    'ends_at' => $event->ends_at,
                    'position' => $row['position'],
                    'points' => $row['points'],
                    'goal' => $row['goal'],
                    'goal_met' => $met,
                    'score_awarded' => $scoreAward,
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
            // Keep recent cycles so chest charts and statistics are always visible and informative
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
                $playerName = trim((string)$cRow->player_name);
                $lowerName = mb_strtolower($playerName);
                $met = (bool)$cRow->goal_achieved;

                if ($met) {
                    $metCount++;
                    $totalChestGoalsMet++;
                } else {
                    $missedCount++;
                }
                $totalChestGoalsEvaluated++;

                // Locate player in playerStats
                $targetKey = $playerName;
                if (!isset($playerStats[$playerName])) {
                    $foundKey = null;
                    foreach (array_keys($playerStats) as $k) {
                        if (mb_strtolower($k) === $lowerName) {
                            $foundKey = $k;
                            break;
                        }
                    }
                    if ($foundKey !== null) {
                        $targetKey = $foundKey;
                    } else {
                        $mem = $memberMap[$lowerName] ?? null;
                        $playerStats[$playerName] = [
                            'member_id' => $mem?->id,
                            'player' => $playerName,
                            'active' => $mem ? (bool)$mem->active : false,
                            'guards' => (int)($mem->guards ?? $cRow->guard_level ?? 0),
                            'power' => (int)($mem->power ?? 0),
                            'administrative' => $mem ? (bool)$mem->administrative_account : false,
                            'score' => 0,
                            'events_met' => 0,
                            'events_missed' => 0,
                            'events_no_goal' => 0,
                            'events_participated' => 0,
                            'events_history' => [],
                            'chests_met' => 0,
                            'chests_missed' => 0,
                            'chests_participated' => 0,
                            'chests_history' => [],
                            'total_points_earned' => 0,
                        ];
                        $targetKey = $playerName;
                    }
                }

                $playerStats[$targetKey]['chests_participated']++;
                if ($met) {
                    $playerStats[$targetKey]['chests_met']++;
                } else {
                    $playerStats[$targetKey]['chests_missed']++;
                }

                $playerStats[$targetKey]['chests_history'][] = [
                    'cycle_start' => $start,
                    'cycle_end' => $end,
                    'total_chests' => (int)$cRow->total_chests,
                    'total_score' => (int)$cRow->total_score,
                    'epic_crypt_score' => (int)$cRow->epic_crypt_score,
                    'chest_goal' => (int)$cRow->chest_goal,
                    'goal_achieved' => $met,
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
            $evaluated = ($p['events_met'] + $p['events_missed']) + ($p['chests_met'] + $p['chests_missed']);
            $p['total_evaluated'] = $evaluated;
            $p['success_rate'] = $evaluated > 0 ? round(($p['total_goals_met'] / $evaluated) * 100, 1) : 0.0;
            $p['event_success_rate'] = ($p['events_met'] + $p['events_missed']) > 0
                ? round(($p['events_met'] / ($p['events_met'] + $p['events_missed'])) * 100, 1)
                : 0.0;
            $p['chest_success_rate'] = ($p['chests_met'] + $p['chests_missed']) > 0
                ? round(($p['chests_met'] / ($p['chests_met'] + $p['chests_missed'])) * 100, 1)
                : 0.0;

            // Sort event history newest first
            usort($p['events_history'], fn ($a, $b) => $b['starts_at'] <=> $a['starts_at']);
            // Sort chest history newest first
            usort($p['chests_history'], fn ($a, $b) => $b['cycle_start'] <=> $a['cycle_start']);
        }
        unset($p);

        // Sort ranking: primary by score (points from beaten event goals in period),
        // then by chest goals met, then power, then name
        usort($ranking, function (array $a, array $b): int {
            if ($b['score'] !== $a['score']) {
                return $b['score'] <=> $a['score'];
            }
            if ($b['events_met'] !== $a['events_met']) {
                return $b['events_met'] <=> $a['events_met'];
            }
            if ($b['chests_met'] !== $a['chests_met']) {
                return $b['chests_met'] <=> $a['chests_met'];
            }
            if ($b['total_goals_met'] !== $a['total_goals_met']) {
                return $b['total_goals_met'] <=> $a['total_goals_met'];
            }
            if ($b['power'] !== $a['power']) {
                return $b['power'] <=> $a['power'];
            }

            return strcasecmp($a['player'], $b['player']);
        });

        // Assign ranking positions
        $currentRank = 1;
        foreach ($ranking as $index => &$entry) {
            $entry['rank'] = $currentRank++;
        }
        unset($entry);

        // Clan overall statistics
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
            'top_scorer' => $ranking[0] ?? null,
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

