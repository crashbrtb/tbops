<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * The goal penalty can now watch both goals at once (goal_penalty_target =
 * both), and a player may carry a raised total goal, a raised epic goal or
 * both. player_cycle_summaries gets a second column for that:
 *
 * - penalty_goal holds the raised chest score (total) goal;
 * - penalty_epic_goal holds the raised epic chest goal;
 * - penalty_target only records which of them were raised: total, epic or both.
 *
 * Rows written before this split kept an epic goal in penalty_goal; they are
 * moved to the new column.
 */
class AddGoalPenaltyEpicGoal extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('player_cycle_summaries');
        if (!$table->hasColumn('penalty_epic_goal')) {
            $table
                ->addColumn('penalty_epic_goal', 'integer', [
                    'null' => true,
                    'default' => null,
                    'after' => 'penalty_goal',
                    'comment' => 'Raised epic chest goal the player carried in this cycle; null when not raised',
                ])
                ->update();

            $this->execute(
                "UPDATE player_cycle_summaries SET penalty_epic_goal = penalty_goal, penalty_goal = NULL WHERE penalty_target = 'epic'"
            );
        }

        $this->execute(sprintf(
            "UPDATE config SET description = '%s' WHERE param = 'goal_penalty_target'",
            str_replace("'", "''", 'Goal penalty: which goal it applies to. "total" = Chest Score Goal (minimum_chest_score) / "epic" = Epic Chest Goal (minimum_epic_chest_score) / "both" = both goals: missing either one is a miss, and only the goal that was missed is raised.')
        ));
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        $table = $this->table('player_cycle_summaries');
        if ($table->hasColumn('penalty_epic_goal')) {
            $this->execute(
                "UPDATE player_cycle_summaries SET penalty_goal = penalty_epic_goal WHERE penalty_target = 'epic'"
            );
            $table->removeColumn('penalty_epic_goal')->update();
        }
    }
}
