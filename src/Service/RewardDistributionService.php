<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\EventReward;

/**
 * Splits a tournament's rewards among the players who earned them.
 *
 * Rewards are whole things (pieces, coins), so every split is done in integers
 * and always hands out exactly what the event gives: the parts plus whatever is
 * explicitly kept aside add up to the quantity, never one more or one less.
 *
 * - Equal parts: every recipient gets the same; the units that do not divide
 *   evenly go one each to the best placed, or stay with the clan.
 * - Proportional: largest remainder method. Each player first gets the whole
 *   part of `quantity * points / total`; the units still left go to the largest
 *   fractional parts (ties: more points, then better position).
 * - By position: a fixed amount per place (1st gets X, 2nd gets Y, ...). The
 *   places are counted among the recipients only, so when the best placed
 *   player cannot receive, the first place goes to the next one who can.
 *   Places nobody fills stay with the clan.
 *
 * A recipient is a player marked eligible (administrative accounts are not)
 * who is qualified (reached the event goal, when the goal is required) and
 * whose points reach the reward's minimum. Nobody else takes part in the split
 * or counts towards the total it is divided by.
 *
 * The arithmetic runs on bcmath: quantity times points easily passes 64 bits.
 *
 * Pure: no database, no framework state. The review page and the publishing
 * step call the same code, so what the administrator approves is what is saved.
 */
class RewardDistributionService
{
    /**
     * Split one reward.
     *
     * @param list<array{key: int|string, position: int, points: int, eligible: bool, qualified?: bool}> $players Ranking.
     * @param array{quantity: int, rule: string, min_points?: int, remainder?: string, positions?: list<int>} $reward Reward line.
     * @return array{
     *     amounts: array<int|string, int>,
     *     distributed: int,
     *     leftover: int,
     *     recipients: int,
     *     pool_points: int
     * }
     */
    public function split(array $players, array $reward): array
    {
        $quantity = max(0, (int)$reward['quantity']);
        $minPoints = max(0, (int)($reward['min_points'] ?? 1));
        $remainder = $reward['remainder'] ?? EventReward::REMAINDER_TOP_RANKED;

        $amounts = [];
        $recipients = [];
        foreach ($players as $player) {
            $amounts[$player['key']] = 0;
            $qualified = $player['qualified'] ?? true;
            if ($player['eligible'] && $qualified && (int)$player['points'] >= $minPoints) {
                $recipients[] = $player;
            }
        }

        // Best placed first: the order leftovers are handed out in.
        usort($recipients, fn (array $a, array $b): int => [$a['position'], $b['points']] <=> [$b['position'], $a['points']]);

        $pool = '0';
        foreach ($recipients as $player) {
            $pool = bcadd($pool, (string)(int)$player['points']);
        }

        $result = [
            'amounts' => $amounts,
            'distributed' => 0,
            'leftover' => $quantity,
            'recipients' => count($recipients),
            'pool_points' => (int)$pool,
        ];

        if ($quantity === 0 || !$recipients) {
            return $result;
        }

        if ($reward['rule'] === EventReward::RULE_EQUAL) {
            return $this->equal($result, $recipients, $quantity, $remainder);
        }

        if ($reward['rule'] === EventReward::RULE_POSITION) {
            return $this->byPosition($result, $recipients, $quantity, (array)($reward['positions'] ?? []));
        }

        if ($pool === '0') {
            // Everybody eligible scored nothing: there is no proportion to follow.
            return $result;
        }

        return $this->proportional($result, $recipients, $quantity, $pool, $remainder);
    }

    /**
     * Split every reward of an event over one ranking.
     *
     * @param list<array{key: int|string, position: int, points: int, eligible: bool, qualified?: bool}> $players Ranking.
     * @param iterable<int|string, array{quantity: int, rule: string, min_points?: int, remainder?: string, positions?: list<int>}> $rewards Reward lines by key.
     * @return array{
     *     rewards: array<int|string, array{amounts: array<int|string, int>, distributed: int, leftover: int, recipients: int, pool_points: int}>,
     *     participation: array<int|string, float>,
     *     eligible_points: int
     * }
     */
    public function distribute(array $players, iterable $rewards): array
    {
        $out = [];
        foreach ($rewards as $key => $reward) {
            $out[$key] = $this->split($players, $reward);
        }

        return [
            'rewards' => $out,
            'participation' => $this->participation($players),
            'eligible_points' => $this->eligiblePoints($players),
        ];
    }

    /**
     * Each eligible player's share of the eligible total, in percent. Players
     * who are not eligible get 0: they are shown, but they do not share.
     *
     * @param list<array{key: int|string, position: int, points: int, eligible: bool}> $players Ranking.
     * @return array<int|string, float>
     */
    public function participation(array $players): array
    {
        $total = $this->eligiblePoints($players);
        $shares = [];
        foreach ($players as $player) {
            $shares[$player['key']] = $total > 0 && $player['eligible']
                ? round((int)$player['points'] * 100 / $total, 2)
                : 0.0;
        }

        return $shares;
    }

    /**
     * @param list<array{key: int|string, position: int, points: int, eligible: bool}> $players Ranking.
     * @return int
     */
    private function eligiblePoints(array $players): int
    {
        $total = 0;
        foreach ($players as $player) {
            if ($player['eligible']) {
                $total += (int)$player['points'];
            }
        }

        return $total;
    }

    /**
     * @param array{amounts: array<int|string, int>, distributed: int, leftover: int, recipients: int, pool_points: int} $result Result so far.
     * @param list<array{key: int|string, position: int, points: int, eligible: bool}> $recipients Best placed first.
     * @param int $quantity Units to hand out.
     * @param string $remainder Remainder policy.
     * @return array{amounts: array<int|string, int>, distributed: int, leftover: int, recipients: int, pool_points: int}
     */
    private function equal(array $result, array $recipients, int $quantity, string $remainder): array
    {
        $count = count($recipients);
        $base = intdiv($quantity, $count);
        $left = $quantity % $count;

        foreach ($recipients as $index => $player) {
            $bonus = $remainder === EventReward::REMAINDER_TOP_RANKED && $index < $left ? 1 : 0;
            $result['amounts'][$player['key']] = $base + $bonus;
        }

        return $this->totals($result, $quantity);
    }

    /**
     * @param array{amounts: array<int|string, int>, distributed: int, leftover: int, recipients: int, pool_points: int} $result Result so far.
     * @param list<array{key: int|string, position: int, points: int, eligible: bool}> $recipients Best placed first.
     * @param int $quantity Units to hand out.
     * @param string $pool Sum of the recipients' points.
     * @param string $remainder Remainder policy.
     * @return array{amounts: array<int|string, int>, distributed: int, leftover: int, recipients: int, pool_points: int}
     */
    private function proportional(array $result, array $recipients, int $quantity, string $pool, string $remainder): array
    {
        $fractions = [];
        $given = 0;
        foreach ($recipients as $order => $player) {
            $product = bcmul((string)$quantity, (string)(int)$player['points']);
            $whole = (int)bcdiv($product, $pool, 0);
            $result['amounts'][$player['key']] = $whole;
            $given += $whole;
            $fractions[] = [
                'key' => $player['key'],
                'fraction' => bcmod($product, $pool),
                'points' => (int)$player['points'],
                'order' => $order,
            ];
        }

        if ($remainder === EventReward::REMAINDER_TOP_RANKED) {
            usort($fractions, function (array $a, array $b): int {
                $byFraction = bccomp($b['fraction'], $a['fraction']);

                return $byFraction !== 0 ? $byFraction : [$b['points'], $a['order']] <=> [$a['points'], $b['order']];
            });
            // Fewer units are left than there are recipients, so one pass is enough.
            $left = $quantity - $given;
            foreach (array_slice($fractions, 0, $left) as $fraction) {
                $result['amounts'][$fraction['key']]++;
            }
        }

        return $this->totals($result, $quantity);
    }

    /**
     * @param array{amounts: array<int|string, int>, distributed: int, leftover: int, recipients: int, pool_points: int} $result Result so far.
     * @param list<array{key: int|string, position: int, points: int, eligible: bool}> $recipients Best placed first.
     * @param int $quantity Units to hand out: the sum of the places.
     * @param list<int> $positions Amount for each place, first place first.
     * @return array{amounts: array<int|string, int>, distributed: int, leftover: int, recipients: int, pool_points: int}
     */
    private function byPosition(array $result, array $recipients, int $quantity, array $positions): array
    {
        foreach (array_values($positions) as $place => $amount) {
            if (!isset($recipients[$place])) {
                break;
            }
            $result['amounts'][$recipients[$place]['key']] = max(0, (int)$amount);
        }

        return $this->totals($result, $quantity);
    }

    /**
     * @param array{amounts: array<int|string, int>, distributed: int, leftover: int, recipients: int, pool_points: int} $result Result.
     * @param int $quantity Units the reward gives.
     * @return array{amounts: array<int|string, int>, distributed: int, leftover: int, recipients: int, pool_points: int}
     */
    private function totals(array $result, int $quantity): array
    {
        $result['distributed'] = array_sum($result['amounts']);
        $result['leftover'] = $quantity - $result['distributed'];

        return $result;
    }
}
