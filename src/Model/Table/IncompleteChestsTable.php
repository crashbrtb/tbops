<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\CollectedChest;
use App\Model\Entity\IncompleteChest;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Validation\Validator;

/**
 * IncompleteChests Model
 *
 * The queue of chests the collector could not read. Rows are never deleted once
 * reviewed - a corrected chest keeps its row, pointing at the collected_chests
 * row it became, so the history of what went wrong stays intact.
 *
 * @method \App\Model\Entity\IncompleteChest newEmptyEntity()
 * @method \App\Model\Entity\IncompleteChest newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\IncompleteChest get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\IncompleteChest patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\IncompleteChest|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\IncompleteChest saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class IncompleteChestsTable extends Table
{
    /**
     * What the game said about the chest, carried to the row it becomes. The game's
     * ids are not mass assignable: no form may set them.
     *
     * @var list<string>
     */
    private const GAME_FIELDS = ['game_gift_id', 'game_type_id', 'game_player_id'];

    /**
     * A chest of a game type nobody has named: what it needs is a name for the type,
     * and it is settled by type, not one row at a time. See typesToName.
     *
     * @var array<mixed>
     */
    public const AWAITING_NAME = [
        'status' => IncompleteChest::STATUS_PENDING,
        'game_type_id IS NOT' => null,
        'OR' => [['name IS' => null], ['name' => '']],
    ];

    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('incomplete_chests');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
    }

    /**
     * Default validation rules.
     *
     * Deliberately loose: these rows arrive precisely because something could
     * not be read, so every field is allowed to be empty on the way in. What a
     * person types when correcting is validated by `validationCorrection`.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 50)
            ->allowEmptyString('name');

        $validator
            ->scalar('player')
            ->maxLength('player', 50)
            ->allowEmptyString('player');

        $validator
            ->scalar('source')
            ->maxLength('source', 50)
            ->allowEmptyString('source');

        $validator
            ->scalar('status')
            ->inList('status', [
                IncompleteChest::STATUS_PENDING,
                IncompleteChest::STATUS_CORRECTED,
                IncompleteChest::STATUS_UNRESOLVED,
            ]);

        $validator
            ->scalar('review_notes')
            ->maxLength('review_notes', 255)
            ->allowEmptyString('review_notes');

        return $validator;
    }

    /**
     * Rules for the three fields a person fills in when correcting a chest.
     *
     * All three are required here, because that is the whole point: the row only
     * moves to collected_chests when it is complete.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationCorrection(Validator $validator): Validator
    {
        foreach (['name', 'player', 'source'] as $field) {
            $validator
                ->scalar($field)
                ->maxLength($field, 50)
                ->requirePresence($field)
                ->notEmptyString($field, __('Fill in the chest name, the player and the source.'));
        }

        return $validator;
    }

    /**
     * Only the chests still waiting to be looked at, oldest first.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to modify.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findPending(SelectQuery $query): SelectQuery
    {
        return $query
            ->where(['status' => IncompleteChest::STATUS_PENDING])
            ->orderBy(['collected_at' => 'ASC']);
    }

    /**
     * Every column except the image.
     *
     * Listing screens must never select the blob: a page of twenty rows would
     * drag megabytes out of the database to show none of it. The image has its
     * own action, which fetches one row.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to modify.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findWithoutImage(SelectQuery $query): SelectQuery
    {
        $fields = array_diff($this->getSchema()->columns(), ['screenshot']);

        return $query->select($fields);
    }

    /**
     * How many chests are still waiting.
     *
     * @return int
     */
    public function pendingCount(): int
    {
        return $this->find('pending')->count();
    }

    /**
     * The collected_chests row a reviewed chest becomes, not yet saved.
     *
     * It keeps the ORIGINAL collection time, since scoring runs in cycles, and
     * `type` 1, which the schema documents as Manual.
     *
     * @param \Cake\ORM\Table $collectedChests The CollectedChests table.
     * @param \App\Model\Entity\IncompleteChest $chest The chest reviewed.
     * @param string $player Player.
     * @param string $name Chest name.
     * @param string $source Source.
     * @return \App\Model\Entity\CollectedChest
     */
    public static function asCollected(Table $collectedChests, IncompleteChest $chest, string $player, string $name, string $source): CollectedChest
    {
        /** @var \App\Model\Entity\CollectedChest $row */
        $row = $collectedChests->newEntity([
            'name' => $name,
            'player' => $player,
            'source' => $source,
            'type' => 1,
            'collected_at' => $chest->collected_at,
        ]);
        foreach (self::GAME_FIELDS as $field) {
            $row->set($field, $chest->get($field), ['guard' => false]);
        }

        return $row;
    }

    /**
     * The game types waiting for a name, one entry each: how many chests wait, and
     * the picture of the first one with the player the game gave for that chest.
     *
     * @return list<array<string, mixed>>
     */
    public function typesToName(): array
    {
        $query = $this->find()->where(self::AWAITING_NAME);
        $rows = $query
            ->select([
                'game_type_id',
                'waiting' => $query->func()->count('*'),
                'first_seen' => $query->func()->min('collected_at'),
                'last_seen' => $query->func()->max('collected_at'),
            ])
            ->groupBy('game_type_id')
            ->orderBy(['first_seen' => 'ASC'])
            ->disableHydration()
            ->all()->toList();

        foreach ($rows as &$row) {
            $row['picture_id'] = $this->pictureOfType((int)$row['game_type_id']);
            $row['picture_player'] = $row['picture_id'] === null ? null
                : $this->find()->select(['player'])->where(['id' => $row['picture_id']])->first()?->player;
        }

        return $rows;
    }

    /**
     * Names a game type from the list of types: every chest waiting with it is
     * recorded with its own player and date.
     *
     * @param int $typeId Game type.
     * @param string $name Chest name.
     * @param string $source Source.
     * @param int|null $userId Who named it.
     * @return int How many chests were recorded.
     */
    public function nameCode(int $typeId, string $name, string $source, ?int $userId): int
    {
        /** @var \App\Model\Entity\IncompleteChest|null $first */
        $first = $this->find('withoutImage')->where(self::AWAITING_NAME)
            ->where(['game_type_id' => $typeId])->orderBy(['id' => 'ASC'])->first();
        if ($first === null) {
            return 0;
        }

        return $this->getConnection()->transactional(function () use ($first, $name, $source, $userId) {
            return $this->nameType($first, $name, $source, $userId)
                + ($this->recordNamed($first, $name, $source, $userId) ? 1 : 0);
        });
    }

    /**
     * Marks every chest waiting with a game type as examined and not recoverable.
     *
     * @param int $typeId Game type.
     * @param int|null $userId Who decided it.
     * @return int How many chests.
     */
    public function dropCode(int $typeId, ?int $userId): int
    {
        return $this->updateAll(
            [
                'status' => IncompleteChest::STATUS_UNRESOLVED,
                'reviewed_by' => $userId,
                'reviewed_at' => (new DateTime())->format('Y-m-d H:i:s'),
            ],
            ['game_type_id' => $typeId] + self::AWAITING_NAME
        );
    }

    /**
     * The id of a row of this game type with a picture, if any.
     *
     * @param int|null $typeId Game type.
     * @return int|null
     */
    public function pictureOfType(?int $typeId): ?int
    {
        if ($typeId === null) {
            return null;
        }
        $row = $this->find()->select(['id'])
            ->where(['game_type_id' => $typeId, 'screenshot IS NOT' => null])
            ->orderBy(['id' => 'ASC'])->first();

        return $row?->id;
    }

    /**
     * Names a game type from a chest just reviewed: for the collector from now on,
     * and for the unnamed chests of that type already waiting. A type already named
     * keeps its name. A chest whose player is missing gets the name and stays in
     * the queue for someone to fill in the player.
     *
     * @param \App\Model\Entity\IncompleteChest $chest The chest reviewed.
     * @param string $name Chest name.
     * @param string $source Source.
     * @param int|null $userId Who reviewed it.
     * @return int How many other chests were recorded.
     */
    public function nameType(IncompleteChest $chest, string $name, string $source, ?int $userId): int
    {
        if ($chest->game_type_id === null) {
            return 0;
        }

        return $this->getConnection()->transactional(function () use ($chest, $name, $source, $userId) {
            $types = TableRegistry::getTableLocator()->get('GameChestTypes');
            if (!$types->exists(['game_type_id' => $chest->game_type_id])) {
                $standard = TableRegistry::getTableLocator()->get('StandardChests')->find()
                    ->select(['id'])->where(['source' => $source])->first();
                $now = new DateTime();
                $types->saveOrFail($types->newEntity([
                    'game_type_id' => $chest->game_type_id,
                    'name' => $name,
                    'source' => $source,
                    'standard_chest_id' => $standard?->id,
                    'created' => $now,
                    'modified' => $now,
                ]));
            }

            $recorded = 0;
            foreach ($this->findOthersOfType($chest)->all() as $other) {
                $recorded += $this->recordNamed($other, $name, $source, $userId) ? 1 : 0;
            }

            return $recorded;
        });
    }

    /**
     * Gives a chest its name and, when it has a player, the collected_chests row it
     * becomes. One without a player stays in the queue for someone to fill it in.
     *
     * @param \App\Model\Entity\IncompleteChest $chest The chest.
     * @param string $name Chest name.
     * @param string $source Source.
     * @param int|null $userId Who reviewed it.
     * @return bool Whether it was recorded.
     */
    private function recordNamed(IncompleteChest $chest, string $name, string $source, ?int $userId): bool
    {
        $fields = ['name' => $name, 'source' => $source];
        $recorded = (string)$chest->player !== '';
        if ($recorded) {
            $collectedChests = TableRegistry::getTableLocator()->get('CollectedChests');
            $row = self::asCollected($collectedChests, $chest, $chest->player, $name, $source);
            $collectedChests->saveOrFail($row);
            $fields += [
                'status' => IncompleteChest::STATUS_CORRECTED,
                'collected_chest_id' => $row->id,
                'reviewed_by' => $userId,
                'reviewed_at' => new DateTime(),
            ];
        }
        $this->saveOrFail($this->patchEntity($chest, $fields));

        return $recorded;
    }

    /**
     * The other pending chests of this one's type that nobody has named.
     *
     * @param \App\Model\Entity\IncompleteChest $chest The chest.
     * @return \Cake\ORM\Query\SelectQuery
     */
    private function findOthersOfType(IncompleteChest $chest): SelectQuery
    {
        return $this->find('withoutImage')->where(self::AWAITING_NAME)->where([
            'game_type_id' => $chest->game_type_id,
            'id !=' => $chest->id,
        ]);
    }
}
