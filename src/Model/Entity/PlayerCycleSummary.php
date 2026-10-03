<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Service\GoalPenaltyService;
use Cake\ORM\Entity;

/**
 * PlayerCycleSummary Entity
 *
 * @property int $id
 * @property string $player_name
 * @property \Cake\I18n\FrozenDate $cycle_start_date
 * @property \Cake\I18n\FrozenDate $cycle_end_date
 * @property int $total_chests
 * @property int $total_score
 * @property int $epic_crypt_score
 * @property int|null $penalty_goal Raised chest score (total) goal carried in this cycle, null when not raised
 * @property int|null $penalty_epic_goal Raised epic chest goal carried in this cycle, null when not raised
 * @property string|null $penalty_target Which goals were raised: total, epic or both
 * @property bool $goal_achieved
 * @property bool $fine_due
 * @property bool $fine_paid
 * @property \Cake\I18n\FrozenTime $created
 * @property \Cake\I18n\FrozenTime $modified
 */
class PlayerCycleSummary extends Entity
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
        'player_name' => true,
        'cycle_start_date' => true,
        'cycle_end_date' => true,
        'total_chests' => true,
        'total_score' => true,
        'epic_crypt_score' => true,
        'penalty_goal' => true,
        'penalty_epic_goal' => true,
        'penalty_target' => true,
        'goal_achieved' => true,
        'fine_due' => true,
        'fine_paid' => true,
        'created' => true,
        'modified' => true,
    ];

    /**
     * The goal this player had in this cycle for the given target: the raised
     * goal when the goal penalty applied to it, the base goal otherwise.
     *
     * @param string $target 'total' or 'epic'.
     * @param int $baseGoal The configured goal for that target.
     * @return int
     */
    public function goalFor(string $target, int $baseGoal): int
    {
        $raised = $this->get(GoalPenaltyService::SUMMARY_COLUMNS[$target] ?? '');

        return $raised !== null ? (int)$raised : $baseGoal;
    }
} 