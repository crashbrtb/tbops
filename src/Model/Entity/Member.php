<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Member Entity
 *
 * @property int $id
 * @property string $player
 * @property int $power
 * @property int $guards
 * @property int $specialists
 * @property int $monsters
 * @property int $engineers
 * @property int $active
 * @property int|null $user_id
 * @property int|null $game_player_id
 * @property bool $administrative_account
 * @property \Cake\I18n\DateTime $created_at
 * @property \Cake\I18n\DateTime $modified_at
 *
 * @property \App\Model\Entity\User $user
 */
class Member extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * Note that when '*' is set to true, this allows all unspecified fields to
     * be mass assigned. For security purposes, it is advised to set '*' to false
     * (or remove it), and explicitly make individual fields accessible as needed.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'player' => true,
        'power' => true,
        'guards' => true,
        'specialists' => true,
        'monsters' => true,
        'engineers' => true,
        'active' => true,
        'created_at' => true,
        'modified_at' => true,
        'user_id' => true,
        'game_player_id' => true,
        'administrative_account' => true,
    ];

    /**
     * Whether all troops (guards, specialists, monsters, engineers) are level 9.
     */
    public function isT9(): bool
    {
        return (int)$this->guards === 9
            && (int)$this->specialists === 9
            && (int)$this->monsters === 9
            && (int)$this->engineers === 9;
    }

    /**
     * Level for goals: T9 (10) if all troops are 9, guards level (1..9), or 0.
     */
    public function guardLevel(): int
    {
        return \App\Service\EventGoal::levelForMember($this);
    }
}
