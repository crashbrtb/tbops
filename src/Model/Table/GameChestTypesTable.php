<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * GameChestTypes Model
 *
 * The game sends a chest's type as a number; this table names it, once, for the
 * collector and for the chests waiting in the review queue. Its key is the game's
 * own number, not one of ours.
 *
 * The site does not fall back to a generic table on the web (see Application::bootstrap),
 * so the table needs this class to be used at all.
 */
class GameChestTypesTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('game_chest_types');
        $this->setDisplayField('name');
        $this->setPrimaryKey('game_type_id');
    }
}
