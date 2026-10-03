<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\EventReward;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * EventRewards Model
 *
 * @property \App\Model\Table\EventsTable&\Cake\ORM\Association\BelongsTo $Events
 * @property \App\Model\Table\EventRewardAllocationsTable&\Cake\ORM\Association\HasMany $EventRewardAllocations
 * @method \App\Model\Entity\EventReward newEmptyEntity()
 * @method \App\Model\Entity\EventReward newEntity(array $data, array $options = [])
 */
class EventRewardsTable extends Table
{
    /**
     * Largest quantity one reward line may hand out. Far above anything a
     * tournament gives, and small enough that the arithmetic stays exact.
     */
    public const MAX_QUANTITY = 1000000000;

    /**
     * Most places a reward by position can name.
     */
    public const MAX_POSITIONS = 100;

    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('event_rewards');
        $this->setDisplayField('item_name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Events', [
            'foreignKey' => 'event_id',
            'joinType' => 'INNER',
        ]);
        $this->hasMany('EventRewardAllocations', [
            'foreignKey' => 'event_reward_id',
            'dependent' => true,
        ]);
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('item_name')
            ->maxLength('item_name', 120)
            ->requirePresence('item_name', 'create')
            ->notEmptyString('item_name', __('Say what is being handed out.'));

        $validator
            ->requirePresence('quantity', 'create')
            ->nonNegativeInteger('quantity', __('The quantity must be a whole number.'))
            ->greaterThan('quantity', 0, __('The quantity must be at least 1.'))
            ->lessThanOrEqual('quantity', self::MAX_QUANTITY);

        $validator
            ->requirePresence('rule', 'create')
            ->inList('rule', array_keys(EventReward::ruleOptions()), __('Choose how the reward is split.'));

        $validator
            ->nonNegativeInteger('min_points')
            ->allowEmptyString('min_points');

        $validator
            ->scalar('position_amounts')
            ->allowEmptyString('position_amounts')
            ->add('position_amounts', 'positionList', [
                'rule' => function ($value, array $context): bool {
                    if (($context['data']['rule'] ?? null) !== EventReward::RULE_POSITION) {
                        return true;
                    }
                    $list = json_decode((string)$value, true);

                    return is_array($list) && $list !== [] && count($list) <= self::MAX_POSITIONS;
                },
                'message' => __('Give the amount of each place, best place first, for example 500; 250; 100.'),
            ]);
        $validator->notEmptyString(
            'position_amounts',
            __('Give the amount of each place, best place first, for example 500; 250; 100.'),
            fn (array $context): bool => ($context['data']['rule'] ?? null) === EventReward::RULE_POSITION
        );

        $validator
            ->inList('remainder', array_keys(EventReward::remainderOptions()))
            ->allowEmptyString('remainder');

        return $validator;
    }
}
