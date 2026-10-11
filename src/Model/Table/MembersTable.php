<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Cake\I18n\FrozenTime;
use Cake\ORM\TableRegistry;

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

    /**
     * Sincroniza players da tabela collected_chests com a tabela members
     * e atualiza o status active baseado na última atividade (3 semanas).
     *
     * @param bool $isDryRun Se verdadeiro, não grava no banco de dados.
     * @return array{playersCount: int, samplePlayerNames: array, newMembersCount: int, updatedMembersCount: int, errors: array}
     */
    public function updateFromCollectedChests(bool $isDryRun = false): array
    {
        $collectedChestsTable = TableRegistry::getTableLocator()->get('CollectedChests');

        // Buscar todos os players únicos da tabela collected_chests
        $allPlayers = $collectedChestsTable->find()
            ->select(['player'])
            ->distinct(['player'])
            ->where(['player IS NOT' => null, 'player !=' => ''])
            ->toArray();

        $playersCount = count($allPlayers);
        $samplePlayers = array_slice($allPlayers, 0, 3);
        $samplePlayerNames = [];
        foreach ($samplePlayers as $player) {
            $samplePlayerNames[] = $player->player;
        }

        $newMembersCount = 0;
        $updatedMembersCount = 0;
        $errorMessages = [];
        $threeWeeksAgo = FrozenTime::now()->subWeeks(3);

        // Depois que o jogo enviou a lista do clan (um torneio, ou a lista de
        // membros), ela e a fonte da verdade (MemberRosterService): a atividade
        // de baus nao liga nem desliga ninguem, e tambem nao cria membros. Um
        // nome de bau sem membro e uma leitura errada, alguem que ja saiu ou o
        // nome antigo de quem trocou de nome no jogo - criar um membro para ele
        // era o que enchia a tabela de duplicados inativos.
        $rosterManaged = (new \App\Service\MemberRosterService())->rosterManaged();

        // Indexar membros existentes por player para otimizar busca
        $existingMembers = $this->find()
            ->all()
            ->indexBy('player')
            ->toArray();

        foreach ($allPlayers as $playerData) {
            $playerName = trim((string)$playerData->player);
            if ($playerName === '') {
                continue;
            }

            // Buscar a última atividade deste player específico
            $lastActivity = $collectedChestsTable->find()
                ->select(['collected_at'])
                ->where(['player' => $playerName])
                ->order(['collected_at' => 'DESC'])
                ->first();

            if (!$lastActivity || !$lastActivity->collected_at) {
                continue;
            }

            $lastCollectedAt = $lastActivity->collected_at;
            $isActive = $lastCollectedAt >= $threeWeeksAgo ? 1 : 0;

            if (isset($existingMembers[$playerName])) {
                $existingMember = $existingMembers[$playerName];
                if (!$rosterManaged && (int)$existingMember->active !== $isActive) {
                    $existingMember->active = $isActive;

                    if (!$isDryRun) {
                        if ($this->save($existingMember)) {
                            $updatedMembersCount++;
                        }
                    } else {
                        $updatedMembersCount++;
                    }
                }
            } elseif (!$rosterManaged) {
                $newMember = $this->newEmptyEntity();
                $newMember = $this->patchEntity($newMember, [
                    'player' => $playerName,
                    'active' => $isActive,
                    'power' => 0,
                    'guards' => 0,
                    'specialists' => 0,
                    'monsters' => 0,
                    'engineers' => 0,
                ]);

                if (!$isDryRun) {
                    if ($this->save($newMember)) {
                        $newMembersCount++;
                        $existingMembers[$playerName] = $newMember;
                    } else {
                        $errors = $newMember->getErrors();
                        if (!empty($errors)) {
                            $errorMessages[] = __('Error saving member {0}: {1}', $playerName, json_encode($errors));
                        }
                    }
                } else {
                    $newMembersCount++;
                }
            }
        }

        if (!$isDryRun) {
            (new \App\Service\DuplicatePlayerNameService())->scanQuietly();
        }

        return [
            'playersCount' => $playersCount,
            'samplePlayerNames' => $samplePlayerNames,
            'newMembersCount' => $newMembersCount,
            'updatedMembersCount' => $updatedMembersCount,
            'errors' => $errorMessages,
        ];
    }
}
