<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * How the clan did in one tournament, from the Journal card with the clans'
 * ranking: its place among the clans listed, its points and what it received.
 *
 * @property int $id
 * @property string $entry_uid
 * @property string|null $result_uid
 * @property string $kind
 * @property int $game_type
 * @property string $game_tournament_key
 * @property \Cake\I18n\DateTime $ended_at
 * @property int|null $clan_id
 * @property int|null $position
 * @property int|null $points
 * @property int $clans_listed
 * @property string|null $rewards JSON list of {item_id, name, amount}
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 */
class ClanTournamentResult extends Entity
{
    /**
     * Written by ClanResultService only.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [];

    /**
     * What the clan received, in the order the game listed it.
     *
     * @return list<array{item_id: int, name: string, amount: int}>
     */
    public function rewardList(): array
    {
        $decoded = json_decode((string)$this->rewards, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $reward) {
            if (is_array($reward) && isset($reward['item_id'], $reward['amount'])) {
                $out[] = [
                    'item_id' => (int)$reward['item_id'],
                    'name' => (string)($reward['name'] ?? ''),
                    'amount' => (int)$reward['amount'],
                ];
            }
        }

        return $out;
    }

    /**
     * The amount the clan received of one item, 0 when none.
     *
     * @param int $itemId Game item id.
     * @return int
     */
    public function amountOf(int $itemId): int
    {
        $total = 0;
        foreach ($this->rewardList() as $reward) {
            if ($reward['item_id'] === $itemId) {
                $total += $reward['amount'];
            }
        }

        return $total;
    }

    /**
     * Whether the clan's place is known: the card lists only the best clans.
     *
     * @return bool
     */
    public function isRanked(): bool
    {
        return $this->position !== null;
    }
}
