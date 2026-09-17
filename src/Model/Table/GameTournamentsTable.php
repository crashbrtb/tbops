<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\GameTournament;
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
        'id', 'game_type', 'ranking', 'name', 'name_source', 'duration_days', 'image_mime',
        'last_variant', 'first_seen_at', 'last_seen_at', 'created', 'modified',
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
            ->nonNegativeInteger('duration_days', __('The duration must be a whole number of days.'))
            ->range('duration_days', [1, self::MAX_DURATION_DAYS], __('The duration must be between {0} and {1} days.', 1, self::MAX_DURATION_DAYS))
            ->allowEmptyString('duration_days');

        return $validator;
    }

    /**
     * @param \Cake\ORM\RulesChecker $rules Rules.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['game_type', 'ranking']), ['errorField' => 'game_type']);

        return $rules;
    }

    /**
     * The catalogue entry for one ranking of a game type, created on first sight.
     *
     * @param int $gameType Type id.
     * @param string|null $variant Rest of the key, such as `1` in `1024:1`.
     * @param \Cake\I18n\DateTime|null $seenAt When a tournament of this type ended.
     * @param string $ranking Which ranking of the type, from normalizeRanking().
     * @return \App\Model\Entity\GameTournament
     */
    public function touchType(
        int $gameType,
        ?string $variant = null,
        ?DateTime $seenAt = null,
        string $ranking = GameTournament::RANKING_DEFAULT
    ): GameTournament {
        /** @var \App\Model\Entity\GameTournament|null $entry */
        $entry = $this->find('withoutImage')
            ->where(['GameTournaments.game_type' => $gameType, 'GameTournaments.ranking' => $ranking])
            ->first();
        if ($entry === null) {
            $entry = $this->newEmptyEntity();
            $entry->set(['game_type' => $gameType, 'ranking' => $ranking], ['guard' => false]);
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
