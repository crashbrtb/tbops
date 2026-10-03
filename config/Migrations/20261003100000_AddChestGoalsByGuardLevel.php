<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Chest goals can follow the player's guardsmen level (members.guards, G1 to
 * G9) instead of being one goal for the whole clan.
 *
 * Three config rows hold the choice:
 * - chest_goal_mode: "global" keeps minimum_chest_score / minimum_epic_chest_score
 *   for everyone, "guard" uses the goal of the player's guard level;
 * - chest_goal_by_guard / epic_goal_by_guard: JSON maps of guard level => goal.
 *   A level left out falls back to the global goal.
 *
 * player_cycle_summaries now records the guard level and the goals each player
 * had in that cycle. A player who levels up changes goal from then on, and the
 * cycles already closed keep telling the story they were judged by.
 */
class AddChestGoalsByGuardLevel extends AbstractMigration
{
    /**
     * @var array<string, array{value: string, description: string}>
     */
    private const PARAMS = [
        'chest_goal_mode' => [
            'value' => 'global',
            'description' => 'Chest goals: "global" = the same goal for everyone (minimum_chest_score / minimum_epic_chest_score) / "guard" = the goal set for the player\'s guard level (G1 to G9). G0 means the level is unknown and gets the highest goal.',
        ],
        'chest_goal_by_guard' => [
            'value' => '{}',
            'description' => 'Chest Score Goal per guard level, as JSON ({"1": 5000, ..., "9": 20000}). A level left out uses minimum_chest_score. Edited in Configs > Chests.',
        ],
        'epic_goal_by_guard' => [
            'value' => '{}',
            'description' => 'Epic Chest Goal per guard level, as JSON ({"1": 2000, ..., "9": 8000}). A level left out uses minimum_epic_chest_score. Edited in Configs > Chests.',
        ],
    ];

    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        foreach (self::PARAMS as $param => $row) {
            if ($this->fetchRow(sprintf("SELECT id FROM config WHERE param = '%s'", $param))) {
                continue;
            }
            $this->execute(sprintf(
                "INSERT INTO config (param, value, description) VALUES ('%s', '%s', '%s')",
                $param,
                $row['value'],
                str_replace("'", "''", $row['description'])
            ));
        }

        $table = $this->table('player_cycle_summaries');
        if (!$table->hasColumn('guard_level')) {
            $table
                ->addColumn('guard_level', 'integer', [
                    'null' => true,
                    'default' => null,
                    'after' => 'epic_crypt_score',
                    'comment' => 'Guard level (members.guards) the player had when the cycle was summarized; 0 = unknown',
                ])
                ->addColumn('chest_goal', 'integer', [
                    'null' => true,
                    'default' => null,
                    'after' => 'guard_level',
                    'comment' => 'Chest score goal of the player in this cycle before any penalty; null on rows written before goals by guard level',
                ])
                ->addColumn('epic_goal', 'integer', [
                    'null' => true,
                    'default' => null,
                    'after' => 'chest_goal',
                    'comment' => 'Epic chest goal of the player in this cycle before any penalty; null on rows written before goals by guard level',
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
        if ($table->hasColumn('guard_level')) {
            $table->removeColumn('epic_goal')->removeColumn('chest_goal')->removeColumn('guard_level')->update();
        }

        $this->execute(sprintf(
            "DELETE FROM config WHERE param IN ('%s')",
            implode("', '", array_keys(self::PARAMS))
        ));
    }
}
