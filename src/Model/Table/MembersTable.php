<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Members Model
 *
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 * @method \App\Model\Entity\Member newEmptyEntity()
 * @method \App\Model\Entity\Member newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Member> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Member get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Member findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Member patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Member> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Member|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Member saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\Member>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Member>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Member>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Member> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Member>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Member>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Member>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Member> deleteManyOrFail(iterable $entities, array $options = [])
 */
class MembersTable extends Table
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

        $this->setTable('members');
        $this->setDisplayField('player');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created_at' => 'new',
                    'modified_at' => 'always'
                ]
            ]
        ]);

        $this->belongsTo('Users', [
            'foreignKey' => 'user_id',
        ]);

        $this->hasOne('BankAccounts', [
            'foreignKey' => 'member_id',
            'className' => 'BankAccounts',
            'dependent' => true,
        ]);

        $this->hasMany('BankTransactions', [
            'foreignKey' => 'member_id',
            'className' => 'BankTransactions',
        ]);

        $this->hasMany('IncomingBankTransactions', [
            'foreignKey' => 'destination_member_id',
            'className' => 'BankTransactions',
        ]);

        $this->hasMany('MemberPowerSnapshots', [
            'foreignKey' => 'member_id',
            'dependent' => true,
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('player')
            ->maxLength('player', 45)
            ->requirePresence('player', 'create')
            ->notEmptyString('player');

        $validator
            ->integer('power')
            ->notEmptyString('power');

        $validator
            ->integer('guards')
            ->notEmptyString('guards');

        $validator
            ->integer('specialists')
            ->notEmptyString('specialists');

        $validator
            ->integer('monsters')
            ->notEmptyString('monsters');

        $validator
            ->integer('engineers')
            ->notEmptyString('engineers');

        $validator
            ->requirePresence('active', 'create')
            ->notEmptyString('active');

        $validator
            ->integer('user_id')
            ->allowEmptyString('user_id');

        // Id do jogador dentro do jogo. E ele, e nao o nome, que liga o membro ao
        // ranking importado: dois jogadores do clan podem ter o mesmo nome.
        $validator
            ->nonNegativeInteger('game_player_id')
            ->allowEmptyString('game_player_id');

        $validator
            ->boolean('administrative_account')
            ->allowEmptyString('administrative_account');

        // created_at e modified_at são gerenciados pelo TimestampBehavior
        // não precisam de validação explícita aqui, a menos que haja regras muito específicas.

        return $validator;
    }

    /**
     * Application rules.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['game_player_id'], ['allowMultipleNulls' => true]), [
            'errorField' => 'game_player_id',
            'message' => __('Another member already has this game player id.'),
        ]);

        return $rules;
    }

}
