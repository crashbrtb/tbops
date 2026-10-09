<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Service\GoalPenaltyService;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * ManualGoalPenalties Model
 *
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 * @method \App\Model\Entity\ManualGoalPenalty newEmptyEntity()
 * @method \App\Model\Entity\ManualGoalPenalty newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\ManualGoalPenalty get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\ManualGoalPenalty|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class ManualGoalPenaltiesTable extends Table
{
    public const MAX_PERCENT = 1000;

    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('manual_goal_penalties');
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
            ->requirePresence('target', 'create')
            ->inList('target', [
                GoalPenaltyService::TARGET_TOTAL,
                GoalPenaltyService::TARGET_EPIC,
                GoalPenaltyService::TARGET_BOTH,
            ]);

        $validator
            ->requirePresence('percent', 'create')
            ->numeric('percent')
            ->greaterThan('percent', 0, __('The increase must be above 0 and at most {0}%.', self::MAX_PERCENT))
            ->lessThanOrEqual('percent', self::MAX_PERCENT, __('The increase must be above 0 and at most {0}%.', self::MAX_PERCENT));

        $validator
            ->scalar('reason')
            ->requirePresence('reason', 'create', __('The reason is required.'))
            ->notEmptyString('reason', __('The reason is required.'))
            ->maxLength('reason', GoalPenaltyService::REASON_MAX_LENGTH);

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
            'message' => __('This player already has a manual penalty in this cycle.'),
        ]);

        return $rules;
    }
}
