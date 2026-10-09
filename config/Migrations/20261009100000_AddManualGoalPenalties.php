<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Manual goal penalties and the reason of every penalty.
 *
 * - manual_goal_penalties: a raised goal an administrator gave a player in one
 *   cycle, on top of the automatic penalty and whether or not that one is on.
 *   One row per player and cycle, always with a reason.
 * - player_cycle_summaries.penalty_reason: why the automatic penalty raised the
 *   goal in that cycle (e.g. "Previous goal not reached (89%)"). The reason of
 *   a manual penalty stays in its own row.
 */
class AddManualGoalPenalties extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        if (!$this->hasTable('manual_goal_penalties')) {
            $this->table('manual_goal_penalties')
                ->addColumn('player_name', 'string', [
                    'limit' => 255,
                    'null' => false,
                ])
                ->addColumn('cycle_start_date', 'date', [
                    'null' => false,
                ])
                ->addColumn('target', 'string', [
                    'limit' => 10,
                    'null' => false,
                    'default' => 'total',
                    'comment' => 'Which goal is raised: total, epic or both',
                ])
                ->addColumn('percent', 'decimal', [
                    'precision' => 6,
                    'scale' => 2,
                    'null' => false,
                    'comment' => 'How much the goal grows, in percent',
                ])
                ->addColumn('reason', 'string', [
                    'limit' => 100,
                    'null' => false,
                ])
                ->addColumn('user_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'comment' => 'Administrator who added the penalty',
                ])
                ->addColumn('created', 'datetime', [
                    'null' => false,
                    'default' => 'CURRENT_TIMESTAMP',
                ])
                ->addIndex(['player_name', 'cycle_start_date'], [
                    'unique' => true,
                    'name' => 'manual_goal_penalties_player_cycle_unique',
                ])
                ->addIndex(['cycle_start_date'])
                ->create();
        }

        $table = $this->table('player_cycle_summaries');
        if (!$table->hasColumn('penalty_reason')) {
            $table
                ->addColumn('penalty_reason', 'string', [
                    'limit' => 100,
                    'null' => true,
                    'default' => null,
                    'after' => 'penalty_target',
                    'comment' => 'Why the automatic goal penalty applied in this cycle',
                ])
                ->update();
        }
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        $table = $this->table('player_cycle_summaries');
        if ($table->hasColumn('penalty_reason')) {
            $table->removeColumn('penalty_reason')->update();
        }
        if ($this->hasTable('manual_goal_penalties')) {
            $this->table('manual_goal_penalties')->drop()->save();
        }
    }
}
