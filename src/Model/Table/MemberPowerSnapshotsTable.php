<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * MemberPowerSnapshots Model: each member's power, one reading a day.
 *
 * @property \App\Model\Table\MembersTable&\Cake\ORM\Association\BelongsTo $Members
 * @method \App\Model\Entity\MemberPowerSnapshot newEmptyEntity()
 * @method \App\Model\Entity\MemberPowerSnapshot newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\MemberPowerSnapshot get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\MemberPowerSnapshot|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\MemberPowerSnapshot saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class MemberPowerSnapshotsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('member_power_snapshots');
        $this->setDisplayField('snapshot_date');
        $this->setPrimaryKey('id');

        $this->belongsTo('Members', [
            'foreignKey' => 'member_id',
            'joinType' => 'INNER',
        ]);
    }
}
