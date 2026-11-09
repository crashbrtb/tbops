<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * EventPayouts Model: the Silver the players won in the game tournaments.
 *
 * @method \App\Model\Entity\EventPayout newEmptyEntity()
 * @method \App\Model\Entity\EventPayout get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 */
class EventPayoutsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('event_payouts');
        $this->setDisplayField('player');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Events', ['foreignKey' => 'event_id', 'joinType' => 'INNER']);
        $this->belongsTo('Members', ['foreignKey' => 'member_id']);
    }
}
