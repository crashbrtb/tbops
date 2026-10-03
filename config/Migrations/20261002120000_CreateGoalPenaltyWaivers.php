<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Players an administrator released from the goal penalty in a given cycle.
 *
 * One row per player and cycle. The cycle is identified the way
 * player_cycle_summaries identifies it, by the date it starts.
 */
class CreateGoalPenaltyWaivers extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        if ($this->hasTable('goal_penalty_waivers')) {
            return;
        }

        $this->table('goal_penalty_waivers')
            ->addColumn('player_name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('cycle_start_date', 'date', [
                'null' => false,
            ])
            ->addColumn('reason', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('user_id', 'integer', [
                'null' => true,
                'default' => null,
                'comment' => 'Administrator who released the player',
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['player_name', 'cycle_start_date'], [
                'unique' => true,
                'name' => 'goal_penalty_waivers_player_cycle_unique',
            ])
            ->addIndex(['cycle_start_date'])
            ->create();
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        if ($this->hasTable('goal_penalty_waivers')) {
            $this->table('goal_penalty_waivers')->drop()->save();
        }
    }
}
