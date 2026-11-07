<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\ClanTournamentResult;
use Cake\I18n\DateTime;
use Cake\ORM\Table;

/**
 * ClanTournamentResults Model: the clan's place and prizes in each tournament.
 *
 * @method \App\Model\Entity\ClanTournamentResult newEmptyEntity()
 * @method \App\Model\Entity\ClanTournamentResult get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 */
class ClanTournamentResultsTable extends Table
{
    /**
     * How far apart the clans' card and an event's end may be and still be the
     * same tournament. The two cards of one tournament come seconds apart.
     */
    public const SAME_TOURNAMENT_HOURS = 6;

    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('clan_tournament_results');
        $this->setDisplayField('game_tournament_key');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
    }

    /**
     * The clans' result of the tournament of this type that ended closest to
     * `$endsAt`, within SAME_TOURNAMENT_HOURS.
     *
     * @param int $gameType Game type id.
     * @param \Cake\I18n\DateTime $endsAt End of the event.
     * @return \App\Model\Entity\ClanTournamentResult|null
     */
    public function forTournament(int $gameType, DateTime $endsAt): ?ClanTournamentResult
    {
        $best = null;
        $bestGap = null;
        $candidates = $this->find()
            ->where([
                'game_type' => $gameType,
                'ended_at >=' => $endsAt->subHours(self::SAME_TOURNAMENT_HOURS),
                'ended_at <=' => $endsAt->addHours(self::SAME_TOURNAMENT_HOURS),
            ])
            ->all();
        foreach ($candidates as $candidate) {
            $gap = abs($candidate->ended_at->getTimestamp() - $endsAt->getTimestamp());
            if ($bestGap === null || $gap < $bestGap) {
                [$best, $bestGap] = [$candidate, $gap];
            }
        }

        return $best;
    }
}
