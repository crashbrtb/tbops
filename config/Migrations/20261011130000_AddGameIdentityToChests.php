<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * What the game itself says about each chest, kept beside what is shown.
 *
 * A chest read from the game's traffic comes with three numbers the screen
 * never showed: the gift it belongs to, its type and the player who sent it.
 * They go with the row, in `collected_chests` and in `incomplete_chests`:
 *
 * - game_gift_id: the gift's id in the clan. A gift is one kill, one crypt, one
 *   purchase, and may be several chests - an epic monster drops up to six - so
 *   the same id repeats on that many rows. It is what keeps a chest from being
 *   written twice, and counting the distinct ids of a source counts the kills.
 * - game_type_id: the chest type (see game_chest_types).
 * - game_player_id: the player's id in the game (see members.game_player_id),
 *   which unlike the name does not change and is never misread.
 *
 * All three stay empty on rows read from the screen, and on everything
 * collected before.
 */
class AddGameIdentityToChests extends AbstractMigration
{
    /**
     * The tables that hold a chest.
     *
     * @var array<string>
     */
    public const TABLES = ['collected_chests', 'incomplete_chests'];

    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            $table = $this->table($name);

            if (!$table->hasColumn('game_gift_id')) {
                $table->addColumn('game_gift_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'signed' => false,
                    'comment' => 'Gift id in the game; repeats on every chest of the same gift',
                ]);
            }

            if (!$table->hasColumn('game_type_id')) {
                $table->addColumn('game_type_id', 'integer', [
                    'null' => true,
                    'default' => null,
                    'signed' => false,
                    'comment' => 'Chest type id in the game (game_chest_types)',
                ]);
            }

            if (!$table->hasColumn('game_player_id')) {
                $table->addColumn('game_player_id', 'biginteger', [
                    'null' => true,
                    'default' => null,
                    'signed' => false,
                    'comment' => 'Id of the player who sent it (members.game_player_id)',
                ]);
            }

            if (!$table->hasIndexByName($name . '_game_gift')) {
                $table->addIndex(['game_gift_id'], ['name' => $name . '_game_gift']);
            }

            $table->update();
        }
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        foreach (self::TABLES as $name) {
            $table = $this->table($name);

            if ($table->hasIndexByName($name . '_game_gift')) {
                $table->removeIndexByName($name . '_game_gift')->update();
            }

            foreach (['game_gift_id', 'game_type_id', 'game_player_id'] as $column) {
                if ($table->hasColumn($column)) {
                    $table->removeColumn($column);
                }
            }

            $table->update();
        }
    }
}
