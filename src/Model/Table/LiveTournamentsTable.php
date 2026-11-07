<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * LiveTournaments Model: the game's Tournaments window, as last read.
 *
 * @method \App\Model\Entity\LiveTournament newEmptyEntity()
 */
class LiveTournamentsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('live_tournaments');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }
}
