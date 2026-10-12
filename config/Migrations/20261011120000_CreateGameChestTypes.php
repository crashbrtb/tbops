<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * The chest types of the game, as the collector reads them from its traffic.
 *
 * The game does not send a chest's name or where it came from: it sends a
 * number, and that number is one chest of one source - 5512 is the "Bone Chest"
 * of a "Level 25 Crypt", 702062 the "Briareus Chest" of the "Epic Briareus
 * squad".
 *
 * - game_chest_types: one row per type, with the name and the source the
 *   collector writes to `collected_chests` for it. A type is learned once, from
 *   the screen, the first time it is met.
 *
 * It is a table of its own, and not columns of `standard_chests`, because the
 * two are different things: `standard_chests` has one row per SOURCE - what is
 * scored - and a source gives several chests (a "Level 25 Crypt" alone has
 * twelve). `standard_chest_id` is the row of the type's source, when the clan
 * scores it; `source` is kept beside it because a source nobody scores has no
 * row there, and is still what gets written with the chest.
 */
class CreateGameChestTypes extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        if ($this->hasTable('game_chest_types')) {
            return;
        }

        $this->table('game_chest_types', [
            'id' => false,
            'primary_key' => ['game_type_id'],
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_general_ci',
        ])
            ->addColumn('game_type_id', 'integer', [
                'null' => false,
                'signed' => false,
                'comment' => 'Chest type id in the game: 5512 in the gift [id, [player], 5512, expires, quantity, 1]',
            ])
            ->addColumn('name', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => 'Chest name, as written to collected_chests.name',
            ])
            ->addColumn('source', 'string', [
                'limit' => 50,
                'null' => false,
                'comment' => 'Where the chest comes from, as written to collected_chests.source',
            ])
            ->addColumn('standard_chest_id', 'integer', [
                'null' => true,
                'default' => null,
                'comment' => 'standard_chests row of that source; empty while the clan does not score it',
            ])
            ->addColumn('created', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('modified', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['standard_chest_id'], ['name' => 'game_chest_types_standard_chest'])
            ->addIndex(['source'], ['name' => 'game_chest_types_source'])
            ->addForeignKey('standard_chest_id', 'standard_chests', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_game_chest_types_standard_chest',
            ])
            ->create();
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        if ($this->hasTable('game_chest_types')) {
            $this->table('game_chest_types')->drop()->save();
        }
    }
}
