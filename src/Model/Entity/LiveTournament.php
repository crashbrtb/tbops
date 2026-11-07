<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * A tournament of the game's Tournaments window: running now or about to start.
 *
 * @property int $id
 * @property string $game_key
 * @property int $game_type
 * @property int $variant
 * @property int $run
 * @property string $state
 * @property \Cake\I18n\DateTime|null $starts_at
 * @property \Cake\I18n\DateTime|null $ends_at
 * @property string $name
 * @property string|null $banner
 * @property string|null $icon
 * @property int|null $clan_points
 * @property int|null $clan_place
 * @property string|null $top_clans JSON list of {position, clan_id, tag, name, points}
 * @property string|null $members JSON list of {position, player_id, name, points}
 * @property string|null $rewards JSON list of {from, to, items: [{item_id, name, icon, amount}]}
 * @property \Cake\I18n\DateTime|null $read_at
 */
class LiveTournament extends Entity
{
    public const STATE_ACTIVE = 'active';
    public const STATE_UPCOMING = 'upcoming';

    /**
     * Written by LiveTournamentService only.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [];

    /**
     * @param string $field JSON column.
     * @return list<array<string, mixed>>
     */
    private function listOf(string $field): array
    {
        $decoded = json_decode((string)$this->get($field), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * @return list<array{position: int, clan_id: int, tag: string, name: string, points: int}>
     */
    public function topClans(): array
    {
        return $this->listOf('top_clans');
    }

    /**
     * @return list<array{position: int, player_id: int, name: string, points: int}>
     */
    public function memberList(): array
    {
        return $this->listOf('members');
    }

    /**
     * @return list<array{from: int, to: int, items: list<array{item_id: int, name: string, icon: string, amount: int}>}>
     */
    public function rewardTiers(): array
    {
        return $this->listOf('rewards');
    }

    /**
     * The rewards of a place, when the tournament has rewards by place.
     *
     * @param int|null $place Place.
     * @return list<array{item_id: int, name: string, icon: string, amount: int}>
     */
    public function rewardsOf(?int $place): array
    {
        if ($place === null) {
            return [];
        }
        foreach ($this->rewardTiers() as $tier) {
            if ($place >= (int)$tier['from'] && $place <= (int)$tier['to']) {
                return (array)$tier['items'];
            }
        }

        return [];
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }
}
