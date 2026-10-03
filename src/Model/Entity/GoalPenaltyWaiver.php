<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * A player released from the goal penalty in one cycle.
 *
 * @property int $id
 * @property string $player_name
 * @property \Cake\I18n\FrozenDate $cycle_start_date
 * @property string|null $reason
 * @property int|null $user_id Administrator who released the player
 * @property \Cake\I18n\FrozenTime $created
 * @property \App\Model\Entity\User|null $user
 */
class GoalPenaltyWaiver extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'player_name' => true,
        'cycle_start_date' => true,
        'reason' => true,
        'user_id' => true,
    ];
}
