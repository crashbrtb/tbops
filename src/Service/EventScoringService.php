<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Event;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Works out where every player stands in an event.
 *
 * One grouped query over `collected_chests` joined to `standard_chests`, narrowed
 * by the event window and by whatever the event's criteria says counts. The same
 * result feeds the live dashboard and the snapshot written when an event closes,
 * so a finished event shows exactly the numbers it showed on its last day.
 *
 * Every row also carries the player's goal, whether they reached it and their
 * share of each reward (EventPrizeService): live while the event runs, frozen
 * with the standings when it closes. Administrative accounts are ranked but
 * never rewarded, as in game tournaments.
 */
class EventScoringService
{
    use LocatorAwareTrait;

    private EventPrizeService $prizes;

    /**
     * @var array<string, array{id: int, administrative: bool, guards: int}>|null Lower-case name => member.
     */
    private ?array $members = null;

    /**
     * @param \App\Service\EventPrizeService|null $prizes Goals and rewards.
     */
    public function __construct(?EventPrizeService $prizes = null)
    {
        $this->prizes = $prizes ?? new EventPrizeService();
    }

    /**
     * Standings for an event, best first.
     *
     * @param \App\Model\Entity\Event $event The event to score.
     * @return array{
     *     rows: list<array{player: string, points: int, chest_count: int, chest_score: int, participation: float, position: int}>,
     *     total_points: int,
     *     total_chests: int,
     *     participants: int,
     *     leader: array{player: string, points: int, chest_count: int, chest_score: int, participation: float, position: int}|null,
     *     average_points: float
     * }
     */
    public function standings(Event $event): array
    {
        $rows = $this->rank($this->collect($event), $event);

        $totalPoints = 0;
        $totalChests = 0;
        foreach ($rows as $row) {
            $totalPoints += $row['points'];
            $totalChests += $row['chest_count'];
        }

        // Share of the event each player accounts for. Computed after the totals
        // are known, which is why it is a second pass rather than part of rank().
        foreach ($rows as $index => $row) {
            $rows[$index]['participation'] = $totalPoints > 0
                ? round($row['points'] * 100 / $totalPoints, 2)
                : 0.0;
        }

        $participants = count($rows);

        return [
            'rows' => $rows,
            'total_points' => $totalPoints,
            'total_chests' => $totalChests,
            'participants' => $participants,
            'leader' => $rows[0] ?? null,
            'average_points' => $participants > 0 ? round($totalPoints / $participants, 1) : 0.0,
        ];
    }

    /**
     * Standings as stored for a closed event, or computed live otherwise.
     *
     * A finished event whose snapshot exists is read from the snapshot: the
     * chests it was built from may already have been purged.
     *
     * Each row carries `eligible`, `guard_level`, `goal`, `goal_met` and
     * `amounts` (reward id => amount); `prizes` holds the rewards, what each
     * one hands out in total and the event goal.
     *
     * @param \App\Model\Entity\Event $event The event.
     * @return array{rows: list<array<string, mixed>>, total_points: int, total_chests: int, participants: int, leader: array<string, mixed>|null, average_points: float, source: string, prizes: array{rewards: list<\App\Model\Entity\EventReward>, totals: array<int, int>, goal: \App\Service\EventGoal, goal_met: int}}
     */
    public function resultsFor(Event $event): array
    {
        // A running event is always live, even if somebody recorded a snapshot of
        // it: the next chest collected would make that snapshot wrong.
        if ($event->finalized_at !== null && !$event->is_running) {
            $stored = $this->storedStandings($event);
            if ($stored['participants'] > 0) {
                return $stored + ['source' => 'snapshot'];
            }
        }

        $live = $this->standings($event);
        $annotated = $this->annotate($event, $live['rows']);
        $live['rows'] = $annotated['rows'];

        return $live + ['source' => 'live', 'prizes' => $annotated['prizes']];
    }

    /**
     * Add to each standings row the player's goal and their share of every reward.
     *
     * @param \App\Model\Entity\Event $event The event.
     * @param list<array{player: string, points: int, chest_count: int, chest_score: int, participation: float, position: int}> $rows Standings.
     * @return array{rows: list<array<string, mixed>>, prizes: array{rewards: list<\App\Model\Entity\EventReward>, totals: array<int, int>, goal: \App\Service\EventGoal, goal_met: int}}
     */
    public function annotate(Event $event, array $rows): array
    {
        $this->loadRewards($event);

        $players = [];
        foreach ($rows as $index => $row) {
            $member = $this->member((string)$row['player']);
            $players[] = [
                'key' => $index,
                'position' => (int)$row['position'],
                'points' => (int)$row['points'],
                'eligible' => !($member['administrative'] ?? false),
                'guard_level' => (int)($member['guards'] ?? 0),
            ];
        }
        $distribution = $this->prizes->distribute($event, $players);

        $totals = [];
        foreach ($rows as $index => $row) {
            $amounts = [];
            foreach ($distribution['rewards'] as $rewardId => $split) {
                $amounts[(int)$rewardId] = (int)($split['amounts'][$index] ?? 0);
                $totals[(int)$rewardId] = ($totals[(int)$rewardId] ?? 0) + $amounts[(int)$rewardId];
            }
            $goal = $distribution['goals'][$index];
            $rows[$index] += [
                'member_id' => $this->member((string)$row['player'])['id'] ?? null,
                'eligible' => $players[$index]['eligible'],
                'guard_level' => $goal['level'],
                'goal' => $goal['goal'],
                'goal_met' => $goal['met'],
                'amounts' => $amounts,
            ];
        }

        return [
            'rows' => $rows,
            'prizes' => [
                'rewards' => (array)$event->event_rewards,
                'totals' => $totals,
                'goal' => $distribution['goal'],
                'goal_met' => $distribution['goal_met'],
            ],
        ];
    }

    /**
     * Freeze the current standings onto the event.
     *
     * Replaces any previous snapshot, so re-running it after a late correction
     * to the chest data produces a clean result rather than a merged one.
     *
     * @param \App\Model\Entity\Event $event The event to close.
     * @return int Number of players recorded.
     */
    public function finalize(Event $event): int
    {
        // A game tournament's standings come from its published ranking
        // (EventImportService::publish). Recomputing them from chests would
        // wipe that result and write nothing in its place.
        if ($event->criteria === Event::CRITERIA_IMPORTED) {
            return 0;
        }

        $standings = $this->standings($event);
        $annotated = $this->annotate($event, $standings['rows']);
        $eventStandings = $this->fetchTable('EventStandings');

        $connection = $eventStandings->getConnection();

        return $connection->transactional(function () use ($event, $annotated, $eventStandings): int {
            // Allocations go with their standings (ON DELETE CASCADE).
            $eventStandings->deleteAll(['event_id' => $event->id]);

            $standingIds = [];
            $amounts = [];
            foreach ($annotated['rows'] as $index => $row) {
                $entity = $eventStandings->newEntity([
                    'event_id' => $event->id,
                    'position' => $row['position'],
                    'player' => $row['player'],
                    'points' => $row['points'],
                    'chest_count' => $row['chest_count'],
                    'chest_score' => $row['chest_score'],
                    'participation' => $row['participation'],
                    'member_id' => $row['member_id'],
                    'eligible' => $row['eligible'],
                    'guard_level' => $row['guard_level'],
                    'goal' => $row['goal'],
                    'goal_met' => $row['goal_met'],
                ]);
                $eventStandings->saveOrFail($entity);
                $standingIds[$index] = (int)$entity->id;
                foreach ($row['amounts'] as $rewardId => $amount) {
                    $amounts[$rewardId]['amounts'][$index] = $amount;
                }
            }

            $this->prizes->saveAllocations(['rewards' => $amounts], $standingIds);

            return count($standingIds);
        });
    }

    /**
     * Read back a snapshot in the shape the dashboard expects.
     *
     * @param \App\Model\Entity\Event $event The event.
     * @return array{rows: list<array<string, mixed>>, total_points: int, total_chests: int, participants: int, leader: array<string, mixed>|null, average_points: float}
     */
    private function storedStandings(Event $event): array
    {
        $this->loadRewards($event);
        $stored = $this->fetchTable('EventStandings')->find()
            ->where(['EventStandings.event_id' => $event->id])
            ->contain(['EventRewardAllocations'])
            ->orderBy(['EventStandings.position' => 'ASC'])
            ->disableHydration()
            ->toArray();

        $rows = [];
        $totalPoints = 0;
        $totalChests = 0;
        $totals = [];
        $goalMet = 0;
        foreach ($stored as $row) {
            $amounts = [];
            foreach ((array)$row['event_reward_allocations'] as $allocation) {
                $rewardId = (int)$allocation['event_reward_id'];
                $amounts[$rewardId] = (int)$allocation['amount'];
                $totals[$rewardId] = ($totals[$rewardId] ?? 0) + (int)$allocation['amount'];
            }
            $rows[] = [
                'player' => (string)$row['player'],
                'points' => (int)$row['points'],
                'chest_count' => (int)$row['chest_count'],
                'chest_score' => (int)$row['chest_score'],
                'participation' => (float)$row['participation'],
                'position' => (int)$row['position'],
                'member_id' => $row['member_id'] !== null ? (int)$row['member_id'] : null,
                'eligible' => (bool)$row['eligible'],
                'guard_level' => (int)$row['guard_level'],
                'goal' => $row['goal'] !== null ? (int)$row['goal'] : null,
                'goal_met' => $row['goal_met'] !== null ? (bool)$row['goal_met'] : null,
                'amounts' => $amounts,
            ];
            $totalPoints += (int)$row['points'];
            $totalChests += (int)$row['chest_count'];
            $goalMet += $row['goal_met'] ? 1 : 0;
        }

        $participants = count($rows);

        return [
            'prizes' => [
                'rewards' => (array)$event->event_rewards,
                'totals' => $totals,
                'goal' => $event->goal(),
                'goal_met' => $goalMet,
            ],
            'rows' => $rows,
            'total_points' => $totalPoints,
            'total_chests' => $totalChests,
            'participants' => $participants,
            'leader' => $rows[0] ?? null,
            'average_points' => $participants > 0 ? round($totalPoints / $participants, 1) : 0.0,
        ];
    }

    /**
     * Load the event's rewards when the caller did not.
     *
     * @param \App\Model\Entity\Event $event The event.
     * @return void
     */
    private function loadRewards(Event $event): void
    {
        if ($event->event_rewards !== null || $event->isNew()) {
            return;
        }
        $event->set('event_rewards', $this->fetchTable('EventRewards')->find()
            ->where(['EventRewards.event_id' => $event->id])
            ->orderBy(['EventRewards.sort' => 'ASC', 'EventRewards.id' => 'ASC'])
            ->all()
            ->toList(), ['guard' => false]);
        $event->setDirty('event_rewards', false);
    }

    /**
     * The member a chest player name belongs to: chests carry names, matched
     * ignoring case the way the database does.
     *
     * @param string $player Player name.
     * @return array{id: int, administrative: bool, guards: int}|null
     */
    private function member(string $player): ?array
    {
        if ($this->members === null) {
            $this->members = [];
            $rows = $this->fetchTable('Members')->find()
                ->select(['id', 'player', 'administrative_account', 'guards'])
                ->disableHydration()
                ->all();
            foreach ($rows as $row) {
                $this->members[mb_strtolower((string)$row['player'])] = [
                    'id' => (int)$row['id'],
                    'administrative' => (bool)$row['administrative_account'],
                    'guards' => (int)$row['guards'],
                ];
            }
        }

        return $this->members[mb_strtolower($player)] ?? null;
    }

    /**
     * The grouped query: one row per player inside the event window.
     *
     * The join to `standard_chests` is a LEFT join so a chest type nobody has
     * scored yet still counts toward the chest total; its score contribution is
     * simply zero.
     *
     * @param \App\Model\Entity\Event $event The event.
     * @return list<array{player: string, chest_count: int, chest_score: int}>
     */
    private function collect(Event $event): array
    {
        // A game tournament is not scored from chests at all: its ranking is
        // uploaded and published through EventImportService.
        if ($event->criteria === Event::CRITERIA_IMPORTED) {
            return [];
        }

        $collectedChests = $this->fetchTable('CollectedChests');

        $query = $collectedChests->find()
            ->select([
                'player' => 'CollectedChests.player',
                'chest_count' => $collectedChests->find()->func()->count('*'),
                'chest_score' => 'COALESCE(SUM(COALESCE(StandardChests.score, 0)), 0)',
            ])
            ->leftJoin(
                ['StandardChests' => 'standard_chests'],
                ['StandardChests.source = CollectedChests.source']
            )
            ->where([
                'CollectedChests.collected_at >=' => $event->starts_at,
                'CollectedChests.collected_at <=' => $event->ends_at,
            ])
            ->groupBy(['CollectedChests.player'])
            ->disableHydration();

        if ($event->criteria === Event::CRITERIA_EPIC_MONSTER) {
            $query->where(['StandardChests.monster' => 1]);
        }

        if ($event->criteria === Event::CRITERIA_CUSTOM_CHESTS) {
            $sources = $this->eventSources($event);
            if (!$sources) {
                return [];
            }
            $query->where(['CollectedChests.source IN' => $sources]);
        }

        $rows = [];
        foreach ($query->toArray() as $row) {
            $rows[] = [
                'player' => (string)$row['player'],
                'chest_count' => (int)$row['chest_count'],
                'chest_score' => (int)$row['chest_score'],
            ];
        }

        return $rows;
    }

    /**
     * The chest sources a custom event counts.
     *
     * Read from the event's own rows, which snapshot the source name, so a chest
     * renamed after the event was created still resolves to what was picked.
     *
     * @param \App\Model\Entity\Event $event The event.
     * @return list<string>
     */
    private function eventSources(Event $event): array
    {
        $chests = $event->event_chests ?? null;
        if ($chests === null) {
            $chests = $this->fetchTable('EventChests')->find()
                ->where(['event_id' => $event->id])
                ->all()
                ->toList();
        }

        $sources = [];
        foreach ($chests as $chest) {
            $source = is_array($chest) ? ($chest['source'] ?? null) : $chest->source;
            if ($source) {
                $sources[] = (string)$source;
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * Turn per-player totals into an ordered table, applying the event criteria.
     *
     * Ties are broken by chest count and then by name, so the order is stable
     * between two views of the same data instead of following whatever the
     * database happened to return.
     *
     * @param list<array{player: string, chest_count: int, chest_score: int}> $rows Collected totals.
     * @param \App\Model\Entity\Event $event The event.
     * @return list<array{player: string, points: int, chest_count: int, chest_score: int, participation: float, position: int}>
     */
    private function rank(array $rows, Event $event): array
    {
        $ranked = [];
        foreach ($rows as $row) {
            $points = match ($event->criteria) {
                Event::CRITERIA_CHEST_COUNT, Event::CRITERIA_EPIC_MONSTER => $row['chest_count'],
                Event::CRITERIA_CUSTOM_CHESTS => $event->custom_metric === Event::METRIC_COUNT
                    ? $row['chest_count']
                    : $row['chest_score'],
                default => $row['chest_score'],
            };

            if ($points <= 0 && $row['chest_count'] <= 0) {
                continue;
            }

            $ranked[] = $row + ['points' => $points, 'participation' => 0.0, 'position' => 0];
        }

        usort($ranked, function (array $a, array $b): int {
            return [$b['points'], $b['chest_count'], $a['player']]
                <=> [$a['points'], $a['chest_count'], $b['player']];
        });

        foreach ($ranked as $index => $row) {
            $ranked[$index]['position'] = $index + 1;
        }

        return $ranked;
    }
}
