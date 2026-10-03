<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * GoalPenaltyWaivers Model
 *
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 * @method \App\Model\Entity\GoalPenaltyWaiver newEmptyEntity()
 * @method \App\Model\Entity\GoalPenaltyWaiver newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\GoalPenaltyWaiver get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\GoalPenaltyWaiver|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class GoalPenaltyWaiversTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('goal_penalty_waivers');
        $this->setDisplayField('player_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
            'joinType' => 'LEFT',
        ]);
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('player_name')
            ->maxLength('player_name', 255)
            ->requirePresence('player_name', 'create')
            ->notEmptyString('player_name');

        $validator
            ->date('cycle_start_date')
            ->requirePresence('cycle_start_date', 'create')
            ->notEmptyDate('cycle_start_date');

        $validator
            ->scalar('reason')
            ->maxLength('reason', 255)
            ->allowEmptyString('reason');

        return $validator;
    }

    /**
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['player_name', 'cycle_start_date']), [
            'errorField' => 'player_name',
            'message' => __('This player is already released in this cycle.'),
        ]);

        return $rules;
    }
}
