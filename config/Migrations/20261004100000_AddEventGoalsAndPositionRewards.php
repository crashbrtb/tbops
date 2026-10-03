<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Event goals, rewards for clan events and rewards split by position.
 *
 * - events: a goal the players have to reach. Global (one number for all) or
 *   by guard level (G1 to G9; G0 = unknown level gets the highest goal), and
 *   whether reaching it is required to receive a reward.
 * - game_tournaments: the same four columns, as the default goal every new
 *   event of that tournament starts with.
 * - event_rewards: amounts per position for the new "by position" rule
 *   (1st gets X, 2nd gets Y, ...).
 * - event_standings: the goal each player had and whether they reached it,
 *   frozen with the result like the points are.
 *
 * Column comments carry no commas or semicolons (see CreateEventImports).
 */
class AddEventGoalsAndPositionRewards extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        foreach (['events', 'game_tournaments'] as $name) {
            $table = $this->table($name);
            if ($table->hasColumn('goal_mode')) {
                continue;
            }
            $table
                ->addColumn('goal_mode', 'string', [
                    'limit' => 10,
                    'null' => false,
                    'default' => 'none',
                    'comment' => 'none or global or guard',
                ])
                ->addColumn('goal_points', 'biginteger', [
                    'null' => true,
                    'default' => null,
                    'signed' => false,
                    'comment' => 'Global goal and the goal of any guard level left blank',
                ])
                ->addColumn('goal_by_guard', 'string', [
                    'limit' => 255,
                    'null' => true,
                    'default' => null,
                    'comment' => 'JSON map of guard level to goal',
                ])
                ->addColumn('goal_required', 'boolean', [
                    'null' => false,
                    'default' => false,
                    'comment' => 'Only players who reached the goal receive a reward',
                ])
                ->update();
        }

        $rewards = $this->table('event_rewards');
        if (!$rewards->hasColumn('position_amounts')) {
            $rewards
                ->addColumn('position_amounts', 'string', [
                    'limit' => 512,
                    'null' => true,
                    'default' => null,
                    'after' => 'quantity',
                    'comment' => 'Rule position only: JSON list of the amount for each place',
                ])
                ->update();
        }

        $standings = $this->table('event_standings');
        if (!$standings->hasColumn('goal')) {
            $standings
                ->addColumn('guard_level', 'integer', [
                    'null' => true,
                    'default' => null,
                    'comment' => 'Guard level of the player when the result was recorded and 0 when unknown',
                ])
                ->addColumn('goal', 'biginteger', [
                    'null' => true,
                    'default' => null,
                    'signed' => false,
                    'comment' => 'Goal the player had in this event and null when the event has none',
                ])
                ->addColumn('goal_met', 'boolean', [
                    'null' => true,
                    'default' => null,
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
        $standings = $this->table('event_standings');
        if ($standings->hasColumn('goal')) {
            $standings->removeColumn('goal_met')->removeColumn('goal')->removeColumn('guard_level')->update();
        }

        $rewards = $this->table('event_rewards');
        if ($rewards->hasColumn('position_amounts')) {
            $rewards->removeColumn('position_amounts')->update();
        }

        foreach (['events', 'game_tournaments'] as $name) {
            $table = $this->table($name);
            if ($table->hasColumn('goal_mode')) {
                $table
                    ->removeColumn('goal_required')
                    ->removeColumn('goal_by_guard')
                    ->removeColumn('goal_points')
                    ->removeColumn('goal_mode')
                    ->update();
            }
        }
    }
}
