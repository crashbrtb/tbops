<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Config Model
 *
 * @method \App\Model\Entity\Config newEmptyEntity()
 * @method \App\Model\Entity\Config newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Config> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Config get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Config findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Config patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Config> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Config|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Config saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\Config>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Config>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Config>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Config> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Config>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Config>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Config>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Config> deleteManyOrFail(iterable $entities, array $options = [])
 */
class ConfigTable extends Table
{
    public const SECTION_GENERAL = 'general';
    public const SECTION_BANK = 'bank';
    public const SECTION_CHESTS = 'chests';

    /**
     * The sections the Configs page is split into, in the order they are shown.
     */
    public const SECTIONS = [self::SECTION_GENERAL, self::SECTION_BANK, self::SECTION_CHESTS];

    /**
     * Parameters of the bank and chests sections. Anything else, including
     * parameters added by hand, is general.
     *
     * @var array<string, list<string>>
     */
    private const SECTION_PARAMS = [
        self::SECTION_BANK => [
            'bank_function', 'caravan_fee', 'deposit_fee', 'transfer_fee', 'withdrawal_fee',
        ],
        self::SECTION_CHESTS => [
            'reference_day', 'every_how_many_days',
            'minimum_chest_score', 'minimum_epic_chest_score', 'minimum_epic_score',
            'chest_goal_mode', 'chest_goal_by_guard', 'epic_goal_by_guard',
            'collected_chests_retention_days',
        ],
    ];

    /**
     * Parameter prefixes that place a parameter in a section.
     *
     * @var array<string, list<string>>
     */
    private const SECTION_PREFIXES = [
        self::SECTION_BANK => ['bank_'],
        self::SECTION_CHESTS => ['goal_penalty_', 'score_color_'],
    ];

    /**
     * Which section of the Configs page a parameter belongs to.
     *
     * @param string $param Parameter name.
     * @return string One of the SECTION_* constants.
     */
    public static function sectionOf(string $param): string
    {
        foreach (self::SECTION_PARAMS as $section => $params) {
            if (in_array($param, $params, true)) {
                return $section;
            }
        }
        foreach (self::SECTION_PREFIXES as $section => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($param, $prefix)) {
                    return $section;
                }
            }
        }

        return self::SECTION_GENERAL;
    }

    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('config');
        $this->setDisplayField('param');
        $this->setPrimaryKey('id');
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
            ->scalar('param')
            ->maxLength('param', 45)
            ->requirePresence('param', 'create')
            ->notEmptyString('param');

        $validator
            ->scalar('value')
            // 255 since AddDatabaseBackupConfig widened the column: the backup
            // folder is a full filesystem path, which 45 characters cannot hold.
            ->maxLength('value', 255)
            ->requirePresence('value', 'create')
            ->notEmptyString('value');

        $validator
            ->scalar('description')
            ->maxLength('description', 512)
            ->requirePresence('description', 'create')
            ->notEmptyString('description');

        $validator
            ->add('value', 'validRetentionDays', [
                'rule' => function ($value, $context) {
                    $entity = $context['providers']['entity'] ?? null;
                    $paramName = $context['data']['param'] ?? ($entity ? $entity->param : null);

                    if ($paramName === 'collected_chests_retention_days') {
                        if (!is_numeric($value)) {
                            return false;
                        }
                        $val = (int)$value;
                        if ($val < 0) {
                            return false;
                        }
                        if ($val > 0 && $val <= 7) {
                            return false;
                        }
                    }

                    return true;
                },
                'message' => __('Retention time must be 0 (to disable automatic purge) or greater than 7 days.'),
            ]);

        return $validator;
    }
}
