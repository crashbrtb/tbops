<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * EventFines Model: the fines of the players who missed a tournament's goal.
 *
 * @method \App\Model\Entity\EventFine newEmptyEntity()
 * @method \App\Model\Entity\EventFine get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 */
class EventFinesTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('event_fines');
        $this->setDisplayField('player');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Events', ['foreignKey' => 'event_id', 'joinType' => 'INNER']);
        $this->belongsTo('Members', ['foreignKey' => 'member_id']);
    }
}
