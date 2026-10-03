<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Event;
use App\Model\Entity\EventReward;
use Cake\ORM\Locator\LocatorAwareTrait;
use DomainException;

/**
 * Who reached an event's goal, and what every player receives of its rewards.
 *
 * Shared by both kinds of event: a clan event is ranked from collected chests
 * (EventScoringService), a game tournament from its uploaded ranking
 * (EventImportService). Both hand their ranking here as a list of players and
 * get back the goal each one had, whether they reached it and their share of
 * every reward, so the review page, the live dashboard and the recorded result
 * all come from the same arithmetic.
 *
 * When the goal is required, a player who missed it is not qualified: they are
 * still ranked, but take no part in any reward. Proportional and equal rewards
 * are then divided among the qualified players only, and the places of a
 * reward by position are counted among them.
 */
class EventPrizeService
{
    use LocatorAwareTrait;

    private RewardDistributionService $distribution;

    /**
     * @param \App\Service\RewardDistributionService|null $distribution Splitter.
     */
    public function __construct(?RewardDistributionService $distribution = null)
    {
        $this->distribution = $distribution ?? new RewardDistributionService();
    }

    /**
     * The goal of each player and their share of every reward.
     *
     * @param \App\Model\Entity\Event $event Event with its rewards loaded.
     * @param list<array{key: int|string, position: int, points: int, eligible: bool, guard_level: int}> $players Ranking.
     * @return array{
     *     rewards: array<int|string, array{amounts: array<int|string, int>, distributed: int, leftover: int, recipients: int, pool_points: int}>,
     *     participation: array<int|string, float>,
     *     eligible_points: int,
     *     goals: array<int|string, array{goal: int|null, met: bool|null, level: int}>,
     *     goal: \App\Service\EventGoal,
     *     goal_met: int
     * }
     */
    public function distribute(Event $event, array $players): array
    {
        $goal = EventGoal::fromEntity($event);
        $qualified = $this->qualify($goal, $players);

        $result = $this->distribution->distribute($qualified, $this->rewardLines((array)$event->event_rewards));

        $goals = [];
        $met = 0;
        foreach ($qualified as $player) {
            $goals[$player['key']] = [
                'goal' => $player['goal'],
                'met' => $player['goal_met'],
                'level' => $player['guard_level'],
            ];
            $met += $player['goal_met'] === true ? 1 : 0;
        }

        return $result + ['goals' => $goals, 'goal' => $goal, 'goal_met' => $met];
    }

    /**
     * Rewards that would hand out nothing even if every eligible player had
     * reached the goal: those are a mistake in the reward (minimum too high,
     * nobody eligible). Nobody reaching a required goal is not a mistake: the
     * reward then stays with the clan.
     *
     * @param \App\Model\Entity\Event $event Event with its rewards loaded.
     * @param list<array{key: int|string, position: int, points: int, eligible: bool, guard_level: int}> $players Ranking.
     * @return list<string>
     */
    public function problems(Event $event, array $players): array
    {
        $result = $this->distribution->distribute($players, $this->rewardLines((array)$event->event_rewards));

        $problems = [];
        foreach ((array)$event->event_rewards as $reward) {
            $split = $result['rewards'][(int)$reward->id] ?? null;
            if ($split !== null && $split['distributed'] === 0) {
                $problems[] = __(
                    'Nobody qualifies for "{0}": check the eligible players and the minimum points.',
                    $reward->item_name
                );
            }
        }

        return $problems;
    }

    /**
     * Split the event's current rewards and goal again over its recorded result.
     *
     * Used when an administrator edits an event whose result is already
     * recorded (a published tournament, a closed clan event) to change its
     * rewards or its goal. Every player's points, position, eligibility and
     * guard level stay exactly as recorded: only the goal they had, whether
     * they reached it and what they receive are recalculated.
     *
     * @param \App\Model\Entity\Event $event Event with its rewards loaded.
     * @return int Allocations recorded.
     * @throws \DomainException When a reward would go to nobody.
     */
    public function redistribute(Event $event): int
    {
        $standingsTable = $this->fetchTable('EventStandings');
        $standings = $standingsTable->find()
            ->where(['EventStandings.event_id' => $event->id])
            ->orderBy(['EventStandings.position' => 'ASC'])
            ->all()
            ->toList();

        $players = array_map(fn ($standing): array => [
            'key' => (int)$standing->id,
            'position' => (int)$standing->position,
            'points' => (int)$standing->points,
            'eligible' => (bool)$standing->eligible,
            'guard_level' => (int)$standing->guard_level,
        ], $standings);

        $problems = $this->problems($event, $players);
        if ($problems) {
            throw new DomainException(implode(' ', $problems));
        }

        $distribution = $this->distribute($event, $players);
        $allocations = $this->fetchTable('EventRewardAllocations');

        $connection = $allocations->getConnection();

        return $connection->transactional(function () use ($allocations, $standingsTable, $standings, $distribution) {
            $ids = array_map(fn ($standing): int => (int)$standing->id, $standings);
            if ($ids) {
                $allocations->deleteAll(['event_standing_id IN' => $ids]);
            }

            foreach ($standings as $standing) {
                $goal = $distribution['goals'][(int)$standing->id] ?? ['goal' => null, 'met' => null];
                $standingsTable->updateAll(
                    ['goal' => $goal['goal'], 'goal_met' => $goal['met']],
                    ['id' => $standing->id]
                );
            }

            return $this->saveAllocations($distribution);
        });
    }

    /**
     * Store every non-zero share of a distribution keyed by standing id.
     *
     * @param array{rewards: array<int|string, array{amounts: array<int|string, int>}>} $distribution Result of distribute().
     * @param array<int|string, int>|null $standingIds Player key => standing id, when the keys are not standing ids.
     * @return int Allocations recorded.
     */
    public function saveAllocations(array $distribution, ?array $standingIds = null): int
    {
        $allocations = $this->fetchTable('EventRewardAllocations');
        $recorded = 0;
        foreach ($distribution['rewards'] as $rewardId => $split) {
            foreach ($split['amounts'] as $key => $amount) {
                $standingId = $standingIds === null ? (int)$key : ($standingIds[$key] ?? null);
                if ($amount <= 0 || $standingId === null) {
                    continue;
                }
                $allocations->saveOrFail($allocations->newEntity([
                    'event_reward_id' => $rewardId,
                    'event_standing_id' => $standingId,
                    'amount' => $amount,
                ]));
                $recorded++;
            }
        }

        return $recorded;
    }

    /**
     * Reward lines in the shape RewardDistributionService expects.
     *
     * @param list<\App\Model\Entity\EventReward> $rewards Rewards.
     * @return array<int, array{quantity: int, rule: string, min_points: int, remainder: string, positions: list<int>}>
     */
    public function rewardLines(array $rewards): array
    {
        $lines = [];
        foreach ($rewards as $reward) {
            $lines[(int)$reward->id] = [
                'quantity' => (int)$reward->quantity,
                'rule' => (string)$reward->rule,
                'min_points' => (int)$reward->min_points,
                'remainder' => (string)($reward->remainder ?: EventReward::REMAINDER_TOP_RANKED),
                'positions' => $reward->positionList(),
            ];
        }

        return $lines;
    }

    /**
     * Each player with the goal of their guard level, whether they reached it
     * and whether that lets them take part in the rewards.
     *
     * @param \App\Service\EventGoal $goal The event goal.
     * @param list<array{key: int|string, position: int, points: int, eligible: bool, guard_level: int}> $players Ranking.
     * @return list<array{key: int|string, position: int, points: int, eligible: bool, guard_level: int, goal: int|null, goal_met: bool|null, qualified: bool}>
     */
    private function qualify(EventGoal $goal, array $players): array
    {
        $active = $goal->isActive();
        $required = $goal->isRequired();

        $out = [];
        foreach ($players as $player) {
            $level = (int)($player['guard_level'] ?? 0);
            $target = $active ? $goal->goalForLevel($level) : null;
            $met = $target !== null ? (int)$player['points'] >= $target : null;
            $out[] = $player + [
                'guard_level' => $level,
                'goal' => $target,
                'goal_met' => $met,
                'qualified' => !$required || $met === true,
            ];
        }

        return $out;
    }
}
