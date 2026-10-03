<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Goal penalty: a player who misses the chest goal gets a higher goal in the
 * next cycle.
 *
 * Three config rows switch it on and shape it, and player_cycle_summaries
 * records the raised goal a player carried in each cycle. Keeping it there
 * means deciding whether a player failed a cycle never has to walk the whole
 * history back: the previous cycle's row already says which goal applied.
 */
class AddGoalPenalty extends AbstractMigration
{
    /**
     * @var array<string, array{value: string, description: string}>
     */
    private const PARAMS = [
        'goal_penalty_enabled' => [
            'value' => '0',
            'description' => '1 = Goal penalty active / 0 = no goal penalty. A player who misses the goal gets a higher goal in the next cycle.',
        ],
        'goal_penalty_percent' => [
            'value' => '10',
            'description' => 'Goal penalty: how much the goal grows, in percent, for a player who missed it in the previous cycle (e.g. 10 = +10%). The raised goal is rounded up.',
        ],
        'goal_penalty_target' => [
            'value' => 'total',
            'description' => 'Goal penalty: which goal it applies to. "total" = Chest Score Goal (minimum_chest_score) / "epic" = Epic Chest Goal (minimum_epic_chest_score).',
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
        if (!$table->hasColumn('penalty_goal')) {
            $table
                ->addColumn('penalty_goal', 'integer', [
                    'null' => true,
                    'default' => null,
                    'after' => 'epic_crypt_score',
                    'comment' => 'Raised goal the player carried in this cycle; null when not penalized',
                ])
                ->addColumn('penalty_target', 'string', [
                    'limit' => 10,
                    'null' => true,
                    'default' => null,
                    'after' => 'penalty_goal',
                    'comment' => 'Which goal penalty_goal replaces: total or epic',
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
        if ($table->hasColumn('penalty_goal')) {
            $table->removeColumn('penalty_target')->removeColumn('penalty_goal')->update();
        }

        $this->execute(sprintf(
            "DELETE FROM config WHERE param IN ('%s')",
            implode("', '", array_keys(self::PARAMS))
        ));
    }
}
