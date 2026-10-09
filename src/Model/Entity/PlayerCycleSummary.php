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
 * @property int|null $guard_level Guard level the player had when the cycle was summarized; 0 = unknown
 * @property int|null $chest_goal Chest score goal before any penalty; null on rows older than goals by guard level
 * @property int|null $epic_goal Epic chest goal before any penalty; null on rows older than goals by guard level
 * @property int|null $penalty_goal Raised chest score (total) goal carried in this cycle, null when not raised
 * @property int|null $penalty_epic_goal Raised epic chest goal carried in this cycle, null when not raised
 * @property string|null $penalty_target Which goals were raised: total, epic or both
 * @property string|null $penalty_reason Why the automatic goal penalty applied in this cycle
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
        'guard_level' => true,
        'chest_goal' => true,
        'epic_goal' => true,
        'penalty_goal' => true,
        'penalty_epic_goal' => true,
        'penalty_target' => true,
        'penalty_reason' => true,
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
     * @param int $fallback The goal to assume when the row stores none (rows
     *   written before goals by guard level).
     * @return int
     */
    public function goalFor(string $target, int $fallback): int
    {
        $raised = $this->get(GoalPenaltyService::SUMMARY_COLUMNS[$target] ?? '');

        return $raised !== null ? (int)$raised : $this->baseGoalFor($target, $fallback);
    }

    /**
     * The goal this player had in this cycle before any penalty.
     *
     * @param string $target 'total' or 'epic'.
     * @param int $fallback The goal to assume when the row stores none.
     * @return int
     */
    public function baseGoalFor(string $target, int $fallback): int
    {
        $base = $this->get(GoalPenaltyService::BASE_COLUMNS[$target] ?? '');

        return $base !== null ? (int)$base : $fallback;
    }
} 