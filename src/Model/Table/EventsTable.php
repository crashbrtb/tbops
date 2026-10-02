<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Event;
use App\Model\Entity\EventReward;
use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Throwable;

/**
 * Events Model
 *
 * @property \App\Model\Table\EventChestsTable&\Cake\ORM\Association\HasMany $EventChests
 * @property \App\Model\Table\EventStandingsTable&\Cake\ORM\Association\HasMany $EventStandings
 * @property \App\Model\Table\EventRewardsTable&\Cake\ORM\Association\HasMany $EventRewards
 * @property \App\Model\Table\EventImportsTable&\Cake\ORM\Association\HasMany $EventImports
 * @method \App\Model\Entity\Event newEmptyEntity()
 * @method \App\Model\Entity\Event newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\Event get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Event patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\Event|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Event saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class EventsTable extends Table
{
    /**
     * Columns that never belong in a listing: the banner is hundreds of
     * kilobytes and is served by its own action.
     *
     * @var list<string>
     */
    public const LIST_FIELDS = [
        'id', 'event_number', 'name', 'description', 'criteria', 'custom_metric',
        'starts_at', 'ends_at', 'prize', 'contact_player', 'banner_mime',
        'status', 'finalized_at', 'published_at', 'game_result_uid', 'game_tournament_key', 'game_tournament_id',
        'created_by', 'created', 'modified',
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

        $this->setTable('events');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('EventChests', [
            'foreignKey' => 'event_id',
            'dependent' => true,
            'saveStrategy' => 'replace',
        ]);

        $this->hasMany('EventStandings', [
            'foreignKey' => 'event_id',
            'dependent' => true,
            'sort' => ['EventStandings.position' => 'ASC'],
        ]);

        $this->hasMany('EventRewards', [
            'foreignKey' => 'event_id',
            'dependent' => true,
            'saveStrategy' => 'replace',
            'sort' => ['EventRewards.sort' => 'ASC', 'EventRewards.id' => 'ASC'],
        ]);

        $this->hasMany('EventImports', [
            'foreignKey' => 'event_id',
            'dependent' => true,
        ]);

        $this->belongsTo('GameTournaments', [
            'foreignKey' => 'game_tournament_id',
        ]);
    }

    /**
     * Every column except the banner blob.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findWithoutBanner(SelectQuery $query): SelectQuery
    {
        // Qualified with the alias so the finder is still unambiguous when the
        // query is joined to something that shares a column name.
        return $query->select($query->aliasFields(self::LIST_FIELDS, $this->getAlias()));
    }

    /**
     * Events whose window contains this moment and which were not cancelled,
     * soonest to end first: if two overlap, the one about to close is the one
     * players need to see.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findRunning(SelectQuery $query): SelectQuery
    {
        $now = DateTime::now();

        return $query->find('withoutBanner')
            ->where([
                'Events.status !=' => Event::STATUS_CANCELLED,
                // A game tournament is announced by its published result, never
                // as a running event on the scoreboard banner.
                'Events.criteria !=' => Event::CRITERIA_IMPORTED,
                'Events.starts_at <=' => $now,
                'Events.ends_at >=' => $now,
            ])
            ->orderBy(['Events.ends_at' => 'ASC']);
    }

    /**
     * Events that have not started yet, next one first.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findUpcoming(SelectQuery $query): SelectQuery
    {
        return $query->find('withoutBanner')
            ->where([
                'Events.status !=' => Event::STATUS_CANCELLED,
                'Events.criteria !=' => Event::CRITERIA_IMPORTED,
                'Events.starts_at >' => DateTime::now(),
            ])
            ->orderBy(['Events.starts_at' => 'ASC']);
    }

    /**
     * Events whose window has closed, plus cancelled ones: everything the
     * history page shows, most recent first.
     *
     * Game tournaments only appear once their result is published: until an
     * administrator has reviewed the uploaded ranking there is nothing to show.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findPast(SelectQuery $query): SelectQuery
    {
        return $query->find('withoutBanner')
            ->where([
                'OR' => [
                    [
                        'Events.criteria !=' => Event::CRITERIA_IMPORTED,
                        'OR' => [
                            'Events.ends_at <' => DateTime::now(),
                            'Events.status' => Event::STATUS_CANCELLED,
                        ],
                    ],
                    [
                        'Events.criteria' => Event::CRITERIA_IMPORTED,
                        'Events.published_at IS NOT' => null,
                        'Events.status !=' => Event::STATUS_CANCELLED,
                    ],
                ],
            ])
            ->orderBy(['Events.ends_at' => 'DESC']);
    }

    /**
     * Game tournaments still waiting for their ranking to be uploaded or
     * published, newest first. This is the list the EventUploader offers.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findAwaitingImport(SelectQuery $query): SelectQuery
    {
        return $query->find('withoutBanner')
            ->where([
                'Events.criteria' => Event::CRITERIA_IMPORTED,
                'Events.published_at IS' => null,
                'Events.status !=' => Event::STATUS_CANCELLED,
            ])
            ->orderBy(['Events.starts_at' => 'DESC', 'Events.id' => 'DESC']);
    }

    /**
     * The event the banner and the "current event" page point at, or null.
     *
     * @return \App\Model\Entity\Event|null
     */
    public function currentEvent(): ?Event
    {
        /** @var \App\Model\Entity\Event|null $event */
        $event = $this->find('running')->first();

        return $event;
    }

    /**
     * Normalize the submitted form before validation.
     *
     * The date inputs are `datetime-local`, which posts "2026-09-11T18:30" with
     * no zone. The administrator is told the field is UTC and the whole
     * application stores UTC, so the string is read as UTC rather than being
     * left to the request locale.
     *
     * @param \Cake\Event\EventInterface $event The event.
     * @param \ArrayObject $data The data being marshalled.
     * @param \ArrayObject $options The marshalling options.
     * @return void
     */
    public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
                continue;
            }

            $raw = str_replace('T', ' ', trim($data[$field]));
            try {
                $data[$field] = new DateTime($raw, 'UTC');
            } catch (Throwable $e) {
                // Leave the raw value in place; validation reports it.
            }
        }

        foreach (['name', 'contact_player', 'prize', 'description'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }

        // The chest list only means anything for the custom criteria. Dropping it
        // otherwise stops a stale selection from surviving a change of criteria.
        if (($data['criteria'] ?? null) !== Event::CRITERIA_CUSTOM_CHESTS) {
            $data['event_chests'] = [];
            $data['custom_metric'] = Event::METRIC_SCORE;
        }

        // Rewards, likewise, belong to game tournaments only, and a tournament
        // can be registered without any ("no rewards" on the form): its result
        // is then published with the ranking alone.
        if (isset($data['criteria'])) {
            $noRewards = !empty($data['no_rewards']);
            $data['event_rewards'] = $data['criteria'] === Event::CRITERIA_IMPORTED && !$noRewards
                ? $this->normalizeRewards($data['event_rewards'] ?? [])
                : [];
        }
        unset($data['no_rewards']);
    }

    /**
     * Reward lines as the form posts them, minus the blank rows the form keeps
     * for adding more, numbered in the order they were entered.
     *
     * @param mixed $rows Posted rows.
     * @return list<array<string, mixed>>
     */
    private function normalizeRewards(mixed $rows): array
    {
        $out = [];
        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['item_name'] ?? ''));
            $quantity = trim((string)($row['quantity'] ?? ''));
            if ($name === '' && $quantity === '') {
                continue;
            }
            // Thousands separators are how people type big numbers; the
            // quantity is a whole number either way.
            $minPoints = trim((string)($row['min_points'] ?? ''));
            $clean = [
                'item_name' => $name,
                'quantity' => str_replace(['.', ',', ' '], '', $quantity),
                'rule' => $row['rule'] ?? EventReward::RULE_PROPORTIONAL,
                'min_points' => $minPoints === '' ? 1 : str_replace(['.', ',', ' '], '', $minPoints),
                'remainder' => $row['remainder'] ?? EventReward::REMAINDER_TOP_RANKED,
                'sort' => count($out),
            ];
            if (!empty($row['id'])) {
                $clean['id'] = (int)$row['id'];
            }
            $out[] = $clean;
        }

        return $out;
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
            ->scalar('name')
            ->maxLength('name', 120)
            ->requirePresence('name', 'create')
            ->notEmptyString('name', __('Give the event a name.'));

        $validator
            ->scalar('description')
            ->allowEmptyString('description');

        $validator
            ->scalar('criteria')
            ->requirePresence('criteria', 'create')
            ->inList('criteria', array_keys(Event::criteriaOptions()), __('Choose one of the listed criteria.'));

        $validator
            ->scalar('custom_metric')
            ->inList('custom_metric', [Event::METRIC_SCORE, Event::METRIC_COUNT]);

        $validator
            ->dateTime('starts_at')
            ->requirePresence('starts_at', 'create')
            ->notEmptyDateTime('starts_at', __('Choose when the event starts (UTC).'));

        $validator
            ->dateTime('ends_at')
            ->requirePresence('ends_at', 'create')
            ->notEmptyDateTime('ends_at', __('Choose when the event ends (UTC).'));

        // A game tournament describes its prize with reward lines; the text is
        // filled in from them when left empty.
        $notImported = fn (array $context): bool => ($context['data']['criteria'] ?? null) !== Event::CRITERIA_IMPORTED;
        $validator
            ->scalar('prize')
            ->requirePresence('prize', fn (array $context): bool => $context['newRecord'] && $notImported($context))
            ->notEmptyString('prize', __('Describe the prize the players are competing for.'), $notImported);

        $validator
            ->scalar('contact_player')
            ->maxLength('contact_player', 120)
            ->requirePresence('contact_player', 'create')
            ->notEmptyString('contact_player', __('Say who the winner should look for.'));

        $validator
            ->scalar('status')
            ->inList('status', [Event::STATUS_SCHEDULED, Event::STATUS_CANCELLED]);

        return $validator;
    }

    /**
     * Application rules.
     *
     * The window rules live here rather than in the validator because they are
     * about the record as a whole and have to know whether the row is new: an
     * event that started yesterday and is running now must stay editable, while
     * a brand new event may not be scheduled into the past.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        // These rules are about what is being saved. RulesChecker::add() would
        // run them on delete too, where the event is loaded without its chests
        // or rewards, and every such event would become impossible to delete.
        $onSave = function (callable $rule, string $name, array $options) use ($rules): void {
            $rules->addCreate($rule, $name, $options);
            $rules->addUpdate($rule, $name, $options);
        };

        $onSave(
            function (EntityInterface $entity) {
                if (!$entity->get('starts_at') || !$entity->get('ends_at')) {
                    return true;
                }

                return $entity->get('ends_at')->greaterThan($entity->get('starts_at'));
            },
            'endAfterStart',
            [
                'errorField' => 'ends_at',
                'message' => __('The event must end after it starts.'),
            ]
        );

        $onSave(
            function (EntityInterface $entity) {
                if (!$entity->get('starts_at')) {
                    return true;
                }

                // Only a start date being *set* to the past is rejected. Leaving an
                // already-running event's start where it is has to keep working.
                if (!$entity->isNew() && !$entity->isDirty('starts_at')) {
                    return true;
                }

                // A game tournament is registered after it was played.
                if ($entity->get('criteria') === Event::CRITERIA_IMPORTED) {
                    return true;
                }

                // A minute of slack, so a form submitted at the top of the chosen
                // minute is not rejected by the seconds it took to press save.
                return $entity->get('starts_at')->greaterThan(DateTime::now()->subMinutes(1));
            },
            'startNotInPast',
            [
                'errorField' => 'starts_at',
                'message' => __('The start date must be in the future.'),
            ]
        );

        $onSave(
            function (EntityInterface $entity) {
                if (!$entity->get('ends_at') || !$entity->isDirty('ends_at')) {
                    return true;
                }
                if ($entity->get('criteria') === Event::CRITERIA_IMPORTED) {
                    return true;
                }

                return $entity->get('ends_at')->greaterThan(DateTime::now());
            },
            'endNotInPast',
            [
                'errorField' => 'ends_at',
                'message' => __('The end date must be in the future.'),
            ]
        );

        $onSave(
            function (EntityInterface $entity) {
                if ($entity->get('criteria') !== Event::CRITERIA_CUSTOM_CHESTS) {
                    return true;
                }

                return !empty($entity->get('event_chests'));
            },
            'customChestsPicked',
            [
                'errorField' => 'event_chests',
                'message' => __('Pick at least one chest for a custom chest event.'),
            ]
        );

        $rules->add($rules->isUnique(['game_result_uid'], ['allowMultipleNulls' => true]), 'uniqueGameResult', [
            'errorField' => 'game_result_uid',
            'message' => __('This game tournament result is already registered.'),
        ]);

        $rules->add($rules->isUnique(['event_number']), 'uniqueNumber', [
            'errorField' => 'event_number',
        ]);

        return $rules;
    }

    /**
     * Assign the next event number to new events.
     *
     * Taken from the table rather than from a counter row so that a deleted
     * event never causes a number to be reused, and so the value is decided as
     * late as possible.
     *
     * @param \Cake\Event\EventInterface $event The event.
     * @param \Cake\Datasource\EntityInterface $entity The entity being saved.
     * @param \ArrayObject $options The save options.
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if ($entity->isNew() && !$entity->get('event_number')) {
            $entity->set('event_number', $this->nextEventNumber());
        }

        if ($entity->get('criteria') === Event::CRITERIA_IMPORTED && trim((string)$entity->get('prize')) === '') {
            $lines = [];
            foreach ((array)$entity->get('event_rewards') as $reward) {
                if ($reward instanceof EventReward) {
                    $lines[] = $reward->summary();
                }
            }
            $entity->set('prize', implode("\n", $lines));
        }
    }

    /**
     * The next sequential identifier.
     *
     * @return int
     */
    public function nextEventNumber(): int
    {
        $highest = $this->find()
            ->select(['highest' => $this->find()->func()->max('event_number')])
            ->disableHydration()
            ->first();

        return (int)($highest['highest'] ?? 0) + 1;
    }
}
