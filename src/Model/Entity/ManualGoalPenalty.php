<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Service\GoalPenaltyService;
use Cake\ORM\Entity;

/**
 * A raised goal an administrator gave a player in one cycle.
 *
 * @property int $id
 * @property string $player_name
 * @property \Cake\I18n\FrozenDate $cycle_start_date
 * @property string $target Which goal is raised: total, epic or both
 * @property string|float $percent How much the goal grows, in percent
 * @property string $reason
 * @property int|null $user_id Administrator who added the penalty
 * @property \Cake\I18n\FrozenTime $created
 * @property \App\Model\Entity\User|null $user
 */
class ManualGoalPenalty extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'player_name' => true,
        'cycle_start_date' => true,
        'target' => true,
        'percent' => true,
        'reason' => true,
        'user_id' => true,
    ];

    /**
     * The goals this penalty raises.
     *
     * @return list<string>
     */
    public function targets(): array
    {
        return GoalPenaltyService::targetsOf((string)$this->target);
    }
}
