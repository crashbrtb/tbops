<?php
declare(strict_types=1);

namespace App\Model\Table;

use ArrayObject;
use Cake\Event\EventInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * StandardChests Model
 *
 * @method \App\Model\Entity\StandardChest newEmptyEntity()
 * @method \App\Model\Entity\StandardChest newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\StandardChest> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\StandardChest get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\StandardChest findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\StandardChest patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\StandardChest> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\StandardChest|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\StandardChest saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\StandardChest>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\StandardChest>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\StandardChest>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\StandardChest> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\StandardChest>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\StandardChest>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\StandardChest>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\StandardChest> deleteManyOrFail(iterable $entities, array $options = [])
 */
class StandardChestsTable extends Table
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

        $this->setTable('standard_chests');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
    }

    /**
     * Normalize the alias before marshalling: trim it and store blanks as null,
     * so reports fall back to the raw source name.
     *
     * @param \Cake\Event\EventInterface $event The event.
     * @param \ArrayObject $data The data being marshalled.
     * @param \ArrayObject $options The marshalling options.
     * @return void
     */
    public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
    {
        if (array_key_exists('source', (array)$data) && is_string($data['source'])) {
            $data['source'] = trim($data['source']);
        }

        if (array_key_exists('alias', (array)$data)) {
            $alias = trim((string)($data['alias'] ?? ''));
            $data['alias'] = $alias !== '' ? $alias : null;
        }
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
            ->scalar('source')
            ->maxLength('source', 50)
            ->requirePresence('source', 'create')
            ->notEmptyString('source');

        $validator
            ->scalar('alias')
            ->maxLength('alias', 50)
            ->allowEmptyString('alias');

        $validator
            ->integer('score')
            ->requirePresence('score', 'create')
            ->notEmptyString('score');

        $validator
            ->boolean('monster')
            ->allowEmptyString('monster');

        $validator
            ->integer('qty_chest')
            ->allowEmptyString('qty_chest');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        // A chest source identifies the chest type, so it can only be registered once.
        $rules->add(
            $rules->isUnique(
                ['source'],
                __('This chest source is already registered.')
            ),
            'uniqueSource',
            ['errorField' => 'source']
        );

        return $rules;
    }
}
