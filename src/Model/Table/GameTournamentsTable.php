<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\GameTournament;
use App\Service\EventGoal;
use App\Service\TournamentFineService;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * GameTournaments Model: the catalogue of the game's tournaments.
 *
 * @property \App\Model\Table\EventsTable&\Cake\ORM\Association\HasMany $Events
 * @method \App\Model\Entity\GameTournament newEmptyEntity()
 * @method \App\Model\Entity\GameTournament get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 */
class GameTournamentsTable extends Table
{
    public const MAX_DURATION_DAYS = 60;

    /**
     * Every column but the image, for lists.
     *
     * @var list<string>
     */
    public const LIST_FIELDS = [
        'id', 'game_type', 'variant', 'ranking', 'name', 'name_source', 'duration_days', 'image_mime',
        'last_variant', 'first_seen_at', 'last_seen_at', 'created', 'modified',
        'goal_mode', 'goal_points', 'goal_by_guard', 'goal_required',
        'display_name', 'description', 'prize_items', 'extra_prizes', 'auto_publish', 'game_icon', 'game_banner',
        'fine_mode', 'fine_amount', 'fine_tiers',
    ];

    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('game_tournaments');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->hasMany('Events', [
            'foreignKey' => 'game_tournament_id',
        ]);
    }

    /**
     * @param \Cake\ORM\Query\SelectQuery $query Query.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findWithoutImage(SelectQuery $query): SelectQuery
    {
        return $query->select($query->aliasFields(self::LIST_FIELDS, $this->getAlias()));
    }

    /**
     * The tournaments nobody has named yet, newest first, with the size of their picture.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findNameless(SelectQuery $query): SelectQuery
    {
        return $query
            ->select($query->aliasFields(self::LIST_FIELDS, $this->getAlias()))
            ->select(['picture_bytes' => 'LENGTH(GameTournaments.pending_picture)'])
            ->where(['OR' => [['GameTournaments.name IS' => null], ['GameTournaments.name' => '']]])
            ->orderBy(['GameTournaments.last_seen_at' => 'DESC']);
    }

    /**
     * How many tournaments are waiting for a name.
     *
     * @return int
     */
    public function namelessCount(): int
    {
        return $this->find()
            ->where(['OR' => [['GameTournaments.name IS' => null], ['GameTournaments.name' => '']]])
            ->count();
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 120)
            ->allowEmptyString('name');

        $validator
            ->scalar('display_name')
            ->maxLength('display_name', 120)
            ->allowEmptyString('display_name');

        $validator
            ->scalar('description')
            ->maxLength('description', 2000)
            ->allowEmptyString('description');

        $validator
            ->nonNegativeInteger('duration_days', __('The duration must be a whole number of days.'))
            ->range('duration_days', [1, self::MAX_DURATION_DAYS], __('The duration must be between {0} and {1} days.', 1, self::MAX_DURATION_DAYS))
            ->allowEmptyString('duration_days');

        $validator
            ->nonNegativeInteger('goal_points', __('The goal must be a whole number.'))
            ->allowEmptyString('goal_points');

        $validator
            ->inList('fine_mode', array_keys(TournamentFineService::modeOptions()))
            ->nonNegativeInteger('fine_amount', __('The fine must be a whole number of millions of Silver.'))
            ->allowEmptyString('fine_amount');

        return $validator;
    }

    /**
     * @param \Cake\ORM\RulesChecker $rules Rules.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['game_type', 'variant', 'ranking']), ['errorField' => 'game_type']);
        $rules->add(
            fn (EntityInterface $entity): bool => (string)$entity->get('goal_mode') === EventGoal::MODE_NONE
                || !$entity->get('goal_mode')
                || EventGoal::fromEntity($entity)->isActive(),
            'goalSet',
            ['errorField' => 'goal_points', 'message' => __('Set the goal, or choose "No goal".')]
        );
        $rules->add(
            fn (EntityInterface $entity): bool => in_array((string)$entity->get('fine_mode'), ['', TournamentFineService::MODE_NONE, TournamentFineService::MODE_TIERS], true)
                || (int)$entity->get('fine_amount') > 0,
            'fineSet',
            ['errorField' => 'fine_amount', 'message' => __('Set the fine, or choose "No fine".')]
        );
        $rules->add(
            function (EntityInterface $entity): bool|string {
                if ((string)$entity->get('fine_mode') !== TournamentFineService::MODE_TIERS) {
                    return true;
                }
                /** @var \App\Model\Entity\GameTournament $entity */
                return TournamentFineService::tierProblem($entity->fineTiers()) ?? true;
            },
            'fineTiersSet',
            ['errorField' => 'fine_tiers']
        );

        return $rules;
    }

    /**
     * The catalogue entry of one tournament, created on first sight.
     *
     * One game type can hold several tournaments, told apart by the variant (1007:1
     * Doomsday, 1007:14 Arachne's Swarm), while one tournament can come back under
     * another variant (Ragnarök 1026:1 and 1026:2). So the entry is the one made for
     * this variant; else, when the name is known, the type's entry of that name;
     * else an entry made for any variant (0), which from then on is this variant's;
     * else, without a name, the type's first entry; else a new one for the variant.
     *
     * @param int $gameType Type id.
     * @param string|null $variant Rest of the key, such as `1` in `1024:1`.
     * @param \Cake\I18n\DateTime|null $seenAt When a tournament of this type ended.
     * @param string $ranking Which ranking of the type, from normalizeRanking().
     * @param string $name The tournament's name, when known.
     * @return \App\Model\Entity\GameTournament
     */
    public function touchType(
        int $gameType,
        ?string $variant = null,
        ?DateTime $seenAt = null,
        string $ranking = GameTournament::RANKING_DEFAULT,
        string $name = ''
    ): GameTournament {
        $number = $variant !== null && ctype_digit($variant) ? (int)$variant : 0;
        /** @var list<\App\Model\Entity\GameTournament> $entries */
        $entries = $this->find('withoutImage')
            ->where(['GameTournaments.game_type' => $gameType, 'GameTournaments.ranking' => $ranking])
            ->orderBy(['GameTournaments.id' => 'ASC'])
            ->all()
            ->toList();
        $entry = null;
        foreach ($entries as $candidate) {
            if ((int)$candidate->variant === $number) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null && trim($name) !== '') {
            foreach ($entries as $candidate) {
                if ($candidate->isNamed($name)) {
                    $entry = $candidate;
                    break;
                }
            }
        }
        if ($entry === null) {
            foreach ($entries as $candidate) {
                if ((int)$candidate->variant === 0) {
                    $entry = $candidate;
                    if ($number > 0) {
                        $entry->set('variant', $number, ['guard' => false]);
                    }
                    break;
                }
            }
        }
        if ($entry === null && trim($name) === '') {
            $entry = $entries[0] ?? null;
        }
        if ($entry === null) {
            $entry = $this->newEmptyEntity();
            $entry->set(['game_type' => $gameType, 'variant' => $number, 'ranking' => $ranking], ['guard' => false]);
        }

        if ($variant !== null && $variant !== '') {
            $entry->set('last_variant', $variant, ['guard' => false]);
        }
        if ($seenAt !== null) {
            if ($entry->first_seen_at === null || $seenAt->lessThan($entry->first_seen_at)) {
                $entry->set('first_seen_at', $seenAt, ['guard' => false]);
            }
            if ($entry->last_seen_at === null || $seenAt->greaterThan($entry->last_seen_at)) {
                $entry->set('last_seen_at', $seenAt, ['guard' => false]);
            }
        }

        if ($entry->isNew() || $entry->isDirty()) {
            $this->saveOrFail($entry);
        }

        return $entry;
    }

    /**
     * Record a name, unless an administrator already chose one.
     *
     * @param \App\Model\Entity\GameTournament $entry Entry.
     * @param string $name Name.
     * @param string $source Where it came from.
     * @return bool Whether the name changed.
     */
    public function offerName(GameTournament $entry, string $name, string $source): bool
    {
        $name = mb_substr(trim($name), 0, 120);
        if ($name === '' || $entry->name === $name) {
            return false;
        }
        if ($entry->name_source === GameTournament::SOURCE_MANUAL) {
            return false;
        }
        // What the Journal says beats what somebody typed in the uploader.
        if ($entry->name_source === GameTournament::SOURCE_JOURNAL && $source === GameTournament::SOURCE_UPLOADER) {
            return false;
        }

        $entry->set(['name' => $name, 'name_source' => $source], ['guard' => false]);
        $this->saveOrFail($entry);

        return true;
    }

    /**
     * A ranking name as the uploader sends it, checked.
     *
     * The game names each ranking by the kind of its Journal message, followed
     * by the statistic when it carries one: `clan_points_mining_tournament_clan_statistics_entry`,
     * `clan_members_item_gain_tracking_final_tracker_statistic_entry:omens_damage`.
     * Nothing, or the classic tournament result, is the default ranking.
     *
     * @param mixed $ranking Value sent.
     * @return string|null The ranking, or null when it is not a valid one.
     */
    public static function normalizeRanking(mixed $ranking): ?string
    {
        if ($ranking === null) {
            return GameTournament::RANKING_DEFAULT;
        }
        if (!is_string($ranking)) {
            return null;
        }
        $ranking = strtolower(trim($ranking));
        if ($ranking === '' || $ranking === 'global_tournament_user_result') {
            return GameTournament::RANKING_DEFAULT;
        }

        return preg_match('/^[a-z0-9_]{1,80}(:[a-z0-9_]{1,39})?$/', $ranking) ? $ranking : null;
    }

    /**
     * Split a tournament key into the type and the rest.
     *
     * @param string|null $key Key such as `1024:1`.
     * @return array{0: int|null, 1: string|null}
     */
    public static function parseKey(?string $key): array
    {
        if ($key === null || !preg_match('/^(\d{1,10})(?::(.+))?$/', $key, $match)) {
            return [null, null];
        }

        return [(int)$match[1], $match[2] ?? null];
    }
}
