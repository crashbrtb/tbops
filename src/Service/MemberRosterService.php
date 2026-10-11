<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Event;
use App\Model\Entity\EventImport;
use App\Model\Entity\JobRun;
use Cake\Database\Driver\Mysql;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use InvalidArgumentException;
use Throwable;

/**
 * Keeps the members table in step with the clan as the game reports it.
 *
 * A tournament ranking lists every member of the clan, including those who
 * scored nothing, with their game id and power. That makes it the source of
 * truth for who is in the clan:
 *
 * - a player in the list is an active member: created if new, linked to their
 *   game id, renamed if they changed name in the game, power updated;
 * - a member not in the list is inactive.
 *
 * Only the most recent tournament may do this. A ranking from an older
 * tournament sent late would otherwise bring back players who have since left.
 *
 * The clan's own Members list, read from the game by the collector's members
 * module (POST /api/v1/members), is the same truth without a tournament around
 * it and goes through the same rules. The two sources are kept in order by the
 * moment each one describes: the end of the tournament, the reading of the list.
 * Whichever is older than what was already applied changes nothing.
 *
 * A player is linked by game id first, then by name when exactly one member
 * without a game id carries it. Two members with the same name and no id are
 * left alone and reported: guessing would tie the wrong history to a player.
 * Failing both, a player takes the one member without a game id whose name
 * differs only in spaces, accents, punctuation or case - the marks a name read
 * off the screen loses. That one is unreviewed by design (many clans run this
 * unattended); a wrong link is undone by merging players on the chests page.
 *
 * A member the list renames - the same game id under another name, which is
 * how a change of name in the game shows - takes their chests and their cycle
 * records along to the new name (followRenames()).
 *
 * A list with a player known only by an id placeholder ("id:123") that matches
 * no member is not applied at all: that player may be a member not yet linked
 * to their game id, and treating the list as the whole clan would deactivate
 * them.
 */
class MemberRosterService
{
    use LocatorAwareTrait;

    /** Below this many players with an id, a list is not trusted as the whole clan. */
    public const MIN_ROSTER_SIZE = 5;

    private const MAX_PLAYER_NAME = 45;

    /** How the uploader names a player whose profile it did not get. */
    public const PLACEHOLDER = 'id:';

    /** A clan holds 100 members; anything far beyond that is not a member list. */
    public const MAX_LIST_SIZE = 500;

    private const EMPTY_SUMMARY = [
        'applied' => false, 'reason' => null, 'created' => 0, 'linked' => 0, 'renamed' => 0,
        'power_updated' => 0, 'activated' => 0, 'deactivated' => 0, 'ambiguous' => [], 'unnamed' => 0,
        // Of the linked, those found by a similar name only.
        'similar' => 0,
        // Chests taken from the old name to the new one of the members renamed.
        'chests_moved' => 0,
    ];

    /**
     * Apply a ranking to the members table.
     *
     * @param \App\Model\Entity\Event $event The tournament the ranking belongs to.
     * @param list<array{position: int, name: string, points: int, game_player_id: int|null, power: int|null}> $rows Validated rows.
     * @return array{applied: bool, reason: string|null, created: int, linked: int, renamed: int, power_updated: int, activated: int, deactivated: int, ambiguous: list<string>, unnamed: int, similar: int, chests_moved: int}
     */
    public function apply(Event $event, array $rows): array
    {
        $summary = self::EMPTY_SUMMARY;

        $withId = array_values(array_filter($rows, fn (array $r): bool => !empty($r['game_player_id'])));
        if (count($withId) < self::MIN_ROSTER_SIZE) {
            $summary['reason'] = 'too_few_ids';

            return $summary;
        }

        $list = $this->lastMemberList();
        if ($this->newerRoster($event) !== null || ($list !== null && $list['read_at']->greaterThan($event->ends_at))) {
            $summary['reason'] = 'older';

            return $summary;
        }

        return $this->sync($withId, $summary);
    }

    /**
     * Check a member list sent by the collector and normalise it.
     *
     * Body: `{clan: {id, tag, name}, read_at, members: [{player_id, name, power}]}`.
     * Every member needs a game id and a name: this list decides who is
     * deactivated, so one that cannot be matched in full is refused whole.
     *
     * @param array<string, mixed> $data Decoded request body.
     * @return array{errors: array<string, string>, rows: list<array{name: string, game_player_id: int, power: int|null}>, read_at: \Cake\I18n\DateTime, clan: array{id: int|null, tag: string|null, name: string|null}}
     */
    public function validateMemberList(array $data): array
    {
        $errors = [];
        $members = $data['members'] ?? null;

        if (!is_array($members) || !array_is_list($members) || $members === []) {
            $errors['members'] = __('The member list must have at least one player.');
            $members = [];
        } elseif (count($members) > self::MAX_LIST_SIZE) {
            $errors['members'] = __('A member list has at most {0} players; {1} were sent.', self::MAX_LIST_SIZE, count($members));
            $members = [];
        }

        $rows = [];
        $seen = [];
        foreach ($members as $i => $member) {
            $line = $i + 1;
            if (!is_array($member)) {
                $errors["members.{$line}"] = __('Line {0} is not a player.', $line);
                continue;
            }

            $name = is_string($member['name'] ?? null) ? trim($member['name']) : '';
            $playerId = $this->wholeNumber($member['player_id'] ?? $member['game_player_id'] ?? null);
            $rawPower = $member['power'] ?? null;
            $power = $this->wholeNumber($rawPower);

            if ($name === '' || str_starts_with($name, self::PLACEHOLDER)) {
                $errors["members.{$line}.name"] = __('Line {0}: the player has no name.', $line);
            }
            if ($playerId === null || $playerId < 1) {
                $errors["members.{$line}.player_id"] = __('Line {0}: the player id must be a positive whole number.', $line);
            } elseif (isset($seen[$playerId])) {
                $errors["members.{$line}.player_id"] = __('Line {0}: player id {1} appears twice.', $line, $playerId);
            } else {
                $seen[$playerId] = true;
            }
            if ($rawPower !== null && $power === null) {
                $errors["members.{$line}.power"] = __('Line {0}: the power must be a whole number of 0 or more.', $line);
            }

            $rows[] = ['name' => $name, 'game_player_id' => (int)$playerId, 'power' => $power];
        }

        $now = DateTime::now();
        $readAt = $now;
        if (isset($data['read_at'])) {
            try {
                $readAt = is_string($data['read_at']) && trim($data['read_at']) !== ''
                    ? new DateTime($data['read_at'])
                    : throw new InvalidArgumentException();
                // A clock ahead of the server's must not outrank the lists still to come.
                $readAt = $readAt->greaterThan($now) ? $now : $readAt;
            } catch (Throwable) {
                $errors['read_at'] = __('read_at is not a valid date.');
            }
        }

        $clan = is_array($data['clan'] ?? null) ? $data['clan'] : [];
        $text = fn (string $key, int $max): ?string => is_string($clan[$key] ?? null) && trim($clan[$key]) !== ''
            ? mb_substr(trim($clan[$key]), 0, $max)
            : null;

        return [
            'errors' => $errors,
            'rows' => $errors ? [] : $rows,
            'read_at' => $readAt,
            'clan' => ['id' => $this->wholeNumber($clan['id'] ?? null) ?: null, 'tag' => $text('tag', 10), 'name' => $text('name', 60)],
        ];
    }

    /**
     * Apply the clan's Members list to the members table: everyone in it is an
     * active member, everyone else is inactive.
     *
     * Not applied (`reason`) when the list is too short to be a clan
     * (`too_few_ids`), when it is of another clan than the last one applied
     * (`other_clan`: a profile pointed at the wrong site must not empty this
     * clan), or when something newer was already applied (`older`).
     *
     * @param list<array{name: string, game_player_id: int, power: int|null}> $rows From validateMemberList().
     * @param \Cake\I18n\DateTime $readAt When the list was read from the game.
     * @param array{id?: int|null, tag?: string|null, name?: string|null} $clan The clan the list is of.
     * @param string|null $host Who sent it, for the run history.
     * @return array{applied: bool, reason: string|null, created: int, linked: int, renamed: int, power_updated: int, activated: int, deactivated: int, ambiguous: list<string>, unnamed: int, similar: int, chests_moved: int}
     */
    public function applyMemberList(array $rows, DateTime $readAt, array $clan = [], ?string $host = null): array
    {
        $summary = self::EMPTY_SUMMARY;
        if (count($rows) < self::MIN_ROSTER_SIZE) {
            $summary['reason'] = 'too_few_ids';

            return $summary;
        }

        $clanId = $clan['id'] ?? null;
        $last = $this->lastMemberList();
        if ($last !== null && $last['clan_id'] !== null && $clanId !== null && $last['clan_id'] !== (int)$clanId) {
            $summary['reason'] = 'other_clan';

            return $summary;
        }

        $tournament = $this->latestRosterEvent();
        if (
            ($last !== null && $last['read_at']->greaterThan($readAt))
            || ($tournament !== null && $tournament->ends_at->greaterThan($readAt))
        ) {
            $summary['reason'] = 'older';

            return $summary;
        }

        $runs = $this->fetchTable('JobRuns');

        // One transaction: the members follow the list and the list is
        // remembered, or neither - a list applied but forgotten would let an
        // older tournament undo it.
        return $runs->getConnection()->transactional(
            function () use ($runs, $rows, $summary, $readAt, $clan, $clanId, $host): array {
                $summary = $this->sync($rows, $summary);
                if (!$summary['applied']) {
                    return $summary;
                }

                $now = DateTime::now();
                $runs->saveOrFail($runs->newEntity([
                    'job' => JobRunRecorder::JOB_MEMBER_LIST,
                    'status' => JobRun::STATUS_SUCCESS,
                    'host' => mb_substr(trim((string)$host) ?: 'api', 0, 100),
                    'started_at' => $now,
                    'finished_at' => $now,
                    'summary' => [
                        'read_at' => $readAt->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                        'clan_id' => $clanId !== null ? (int)$clanId : null,
                        'clan_tag' => $clan['tag'] ?? null,
                        'members' => count($rows),
                        'created' => $summary['created'],
                        'activated' => $summary['activated'],
                        'deactivated' => $summary['deactivated'],
                    ],
                ]));

                return $summary;
            }
        );
    }

    /**
     * Bring the members table in step with a list of the whole clan.
     *
     * @param list<array{name: string, game_player_id: int|null, power: int|null}> $withId Players, each with a game id.
     * @param array<string, mixed> $summary The counters to fill.
     * @return array{applied: bool, reason: string|null, created: int, linked: int, renamed: int, power_updated: int, activated: int, deactivated: int, ambiguous: list<string>, unnamed: int, similar: int, chests_moved: int}
     */
    private function sync(array $withId, array $summary): array
    {
        $members = $this->fetchTable('Members');
        $all = $members->find()->all()->toList();

        $byId = [];
        $byName = [];
        foreach ($all as $member) {
            if ($member->game_player_id !== null) {
                $byId[(int)$member->game_player_id] = $member;
            } else {
                $byName[$this->normalize((string)$member->player)][] = $member;
            }
        }

        foreach ($withId as $row) {
            if (str_starts_with(trim($row['name']), self::PLACEHOLDER) && !isset($byId[(int)$row['game_player_id']])) {
                $summary['unnamed']++;
            }
        }
        if ($summary['unnamed'] > 0) {
            $summary['reason'] = 'unnamed';

            return $summary;
        }

        // The last resort for a player no id and no name finds: the one member
        // without a game id whose name is the same once spaces, accents,
        // punctuation and case are set aside - how a name read off the screen
        // by the chest collector ("TonyStanna", "Malvadao") differs from the
        // game's own. Nobody reviews this, so it only links when there is one
        // such member and one such player; the member then takes the game's
        // spelling. A member that carries a listed player's name as it is
        // belongs to that player and is not offered here.
        $listed = [];
        foreach ($withId as $row) {
            $listed[$this->normalize(mb_substr(trim($row['name']), 0, self::MAX_PLAYER_NAME))] = true;
        }
        $bySimilar = [];
        foreach ($byName as $key => $sameName) {
            if (!isset($listed[$key])) {
                foreach ($sameName as $member) {
                    $bySimilar[$this->similar((string)$member->player)][] = $member;
                }
            }
        }
        $seekers = [];
        foreach ($withId as $row) {
            $name = mb_substr(trim($row['name']), 0, self::MAX_PLAYER_NAME);
            if (!isset($byId[(int)$row['game_player_id']]) && !isset($byName[$this->normalize($name)])) {
                $seekers[$this->similar($name)] = ($seekers[$this->similar($name)] ?? 0) + 1;
            }
        }

        $present = [];
        $changed = [];
        $renames = [];

        foreach ($withId as $row) {
            $gameId = (int)$row['game_player_id'];
            $name = mb_substr(trim($row['name']), 0, self::MAX_PLAYER_NAME);
            $member = $byId[$gameId] ?? null;

            if ($member === null) {
                $candidates = $byName[$this->normalize($name)] ?? [];
                if (count($candidates) > 1) {
                    $summary['ambiguous'][] = $name;
                    continue;
                }
                $similar = $this->similar($name);
                if (count($candidates) === 1) {
                    $member = $candidates[0];
                    $byName[$this->normalize($name)] = [];
                    $summary['linked']++;
                } elseif ($similar !== '' && ($seekers[$similar] ?? 0) === 1 && count($bySimilar[$similar] ?? []) === 1) {
                    $member = $bySimilar[$similar][0];
                    $summary['linked']++;
                    $summary['similar']++;
                }
                if ($member !== null) {
                    $member->set('game_player_id', $gameId, ['guard' => false]);
                    $byId[$gameId] = $member;
                }
            }

            if ($member === null) {
                // A name that is only an id placeholder is not worth a member.
                if (str_starts_with($name, self::PLACEHOLDER)) {
                    continue;
                }
                $member = $members->newEntity([
                    'player' => $name,
                    'active' => 1,
                    'power' => (int)($row['power'] ?? 0),
                    'guards' => 0,
                    'specialists' => 0,
                    'monsters' => 0,
                    'engineers' => 0,
                ]);
                $member->set('game_player_id', $gameId, ['guard' => false]);
                $byId[$gameId] = $member;
                $changed[spl_object_id($member)] = $member;
                $summary['created']++;
                continue;
            }

            if ($name !== '' && !str_starts_with($name, self::PLACEHOLDER) && $member->player !== $name) {
                $renames[] = [(string)$member->player, $name];
                $member->set('player', $name, ['guard' => false]);
                $summary['renamed']++;
            }
            if ($row['power'] !== null && (int)$member->power !== (int)$row['power']) {
                $member->set('power', (int)$row['power'], ['guard' => false]);
                $summary['power_updated']++;
            }
            if ((int)$member->active !== 1) {
                $member->set('active', 1, ['guard' => false]);
                $summary['activated']++;
            }
            $present[$member->id] = true;
            if ($member->isDirty()) {
                $changed[spl_object_id($member)] = $member;
            }
        }

        foreach ($all as $member) {
            if (!isset($present[$member->id]) && (int)$member->active === 1 && !$member->isNew()
                && !in_array($member, $changed, true)) {
                $member->set('active', 0, ['guard' => false]);
                $changed[spl_object_id($member)] = $member;
                $summary['deactivated']++;
            }
        }

        // Every name a member carries once this is saved, and every name in the list.
        $carried = [];
        foreach ($all as $member) {
            $carried[(string)$member->player] = true;
        }
        foreach ($changed as $member) {
            $carried[(string)$member->player] = true;
        }
        $inList = [];
        foreach ($withId as $row) {
            $inList[mb_substr(trim($row['name']), 0, self::MAX_PLAYER_NAME)] = true;
        }

        $summary['chests_moved'] = $members->getConnection()->transactional(
            function () use ($members, $changed, $renames, $carried, $inList): int {
                foreach ($changed as $member) {
                    $members->saveOrFail($member);
                }

                return $this->followRenames($renames, $carried, $inList);
            }
        );
        $summary['applied'] = true;

        // The game lets two players carry the same name; the administrators are told.
        (new DuplicatePlayerNameService())->scanQuietly();

        return $summary;
    }

    /**
     * Take a renamed member's history along to the new name.
     *
     * Chests carry the player's name and nothing else, and the scores, the
     * goals and the penalties are all added up by that name. So when the game
     * says a member is now called something else, what was recorded under the
     * old name is moved - what "Merge Player Names" does by hand:
     *
     * - the chests under the old name (that exact spelling) go to the new one;
     * - the old name is mapped to the new one, so a chest still read with it
     *   lands on the right player, and mappings that pointed at the old name
     *   point at the new;
     * - the cycle records kept by name (summaries, manual penalties, releases)
     *   follow, wherever the new name has none of its own for that cycle - or
     *   the player would look like a newcomer with no past goals.
     *
     * Nothing is moved while another member carries the old name: those chests
     * may be theirs. And a name somebody in the list carries now is never
     * mapped away: that would send that player's chests to someone else.
     *
     * @param list<array{0: string, 1: string}> $renames Old name, new name.
     * @param array<string, true> $carried The names the members carry now.
     * @param array<string, true> $listed The names in the list being applied.
     * @return int Chests moved.
     */
    private function followRenames(array $renames, array $carried, array $listed): int
    {
        $mappings = $this->fetchTable('PlayerNameMappings');
        $connection = $mappings->getConnection();
        $moved = 0;

        // MySQL compares these columns without telling case or accents apart:
        // BINARY keeps "EOMER" from taking the chests of "ÉOMER". `column = ?`
        // goes beside it so the index still narrows the search down. Elsewhere
        // (the tests' SQLite) a plain comparison is already exact.
        $exactly = fn (string $column): string => $connection->getDriver() instanceof Mysql ? "BINARY {$column}" : $column;
        $player = $exactly('player');
        $playerName = $exactly('player_name');

        foreach ($renames as [$old, $new]) {
            if ($old === '' || $old === $new || isset($carried[$old])) {
                continue;
            }

            $moved += $connection->execute(
                "UPDATE collected_chests SET player = ? WHERE player = ? AND {$player} = ?",
                [$new, $old, $old]
            )->rowCount();

            foreach (['player_cycle_summaries', 'manual_goal_penalties', 'goal_penalty_waivers'] as $table) {
                try {
                    $taken = array_column($connection->execute(
                        "SELECT cycle_start_date FROM {$table} WHERE player_name = ? AND {$playerName} <> ?",
                        [$new, $old]
                    )->fetchAll('assoc') ?: [], 'cycle_start_date');
                    $free = $taken ? ' AND cycle_start_date NOT IN (' . implode(', ', array_fill(0, count($taken), '?')) . ')' : '';
                    $connection->execute(
                        "UPDATE {$table} SET player_name = ? WHERE player_name = ? AND {$playerName} = ?{$free}",
                        array_merge([$new, $old, $old], $taken)
                    );
                } catch (Throwable $e) {
                    // The members and the chests matter more than a record of a
                    // past cycle (one of these tables cannot even hold every name).
                    Log::warning(sprintf('Could not move %s from "%s" to "%s": %s', $table, $old, $new, $e->getMessage()));
                }
            }

            $mappings->updateAll(['correct_name' => $new], ['correct_name' => $old]);
            $mapping = $mappings->find()->where(['ocr_text' => $old])->first()
                ?? $mappings->newEntity(['ocr_text' => $old, 'correct_name' => $new]);
            $mapping->set('correct_name', $new);
            $mappings->saveOrFail($mapping);
        }

        foreach ($mappings->find()->all() as $mapping) {
            if (isset($listed[(string)$mapping->ocr_text]) && $mapping->correct_name !== $mapping->ocr_text) {
                $mappings->deleteOrFail($mapping);
            }
        }

        return $moved;
    }

    /**
     * Note on the import that its ranking updated the members table. Rosters
     * are applied before the ranking is stored, so players link by game id.
     *
     * @param \App\Model\Entity\EventImport $import Import.
     * @param array<string, mixed> $summary From apply().
     * @return void
     */
    public function markApplied(EventImport $import, array $summary): void
    {
        if (empty($summary['applied'])) {
            return;
        }
        $import->set([
            'roster_applied_at' => DateTime::now(),
            'roster_summary' => json_encode($summary),
        ], ['guard' => false]);
        $this->fetchTable('EventImports')->saveOrFail($import);
    }

    /**
     * Whether a roster has ever been applied: from then on the game's lists,
     * not chest activity, decide who is active.
     *
     * @return bool
     */
    public function rosterManaged(): bool
    {
        return $this->fetchTable('EventImports')->exists(['roster_applied_at IS NOT' => null])
            || $this->lastMemberList() !== null;
    }

    /**
     * The newest Members list applied: when it was read, and of which clan.
     *
     * Kept in `job_runs`, whose purge never drops the last good run of a job.
     *
     * @return array{read_at: \Cake\I18n\DateTime, clan_id: int|null}|null
     */
    public function lastMemberList(): ?array
    {
        /** @var \App\Model\Table\JobRunsTable $runs */
        $runs = $this->fetchTable('JobRuns');
        $summary = $runs->latestGood(JobRunRecorder::JOB_MEMBER_LIST)?->summary;
        if (!is_array($summary) || !is_string($summary['read_at'] ?? null)) {
            return null;
        }
        try {
            $readAt = new DateTime($summary['read_at']);
        } catch (Throwable) {
            return null;
        }

        return ['read_at' => $readAt, 'clan_id' => isset($summary['clan_id']) ? (int)$summary['clan_id'] : null];
    }

    /**
     * The event of the most recent roster applied, when it ended after this one.
     *
     * @param \App\Model\Entity\Event $event The tournament being sent.
     * @return \App\Model\Entity\Event|null
     */
    private function newerRoster(Event $event): ?Event
    {
        $latest = $this->latestRosterEvent($event->id);

        return $latest !== null && $latest->ends_at->greaterThan($event->ends_at) ? $latest : null;
    }

    /**
     * The tournament that ended last among those whose ranking was applied as a roster.
     *
     * @param int|null $except An event to leave out.
     * @return \App\Model\Entity\Event|null
     */
    private function latestRosterEvent(?int $except = null): ?Event
    {
        $conditions = ['EventImports.roster_applied_at IS NOT' => null];
        if ($except !== null) {
            $conditions['EventImports.event_id !='] = $except;
        }

        /** @var \App\Model\Entity\EventImport|null $latest */
        $latest = $this->fetchTable('EventImports')->find()
            ->contain(['Events' => ['finder' => 'withoutBanner']])
            ->where($conditions)
            ->orderBy(['Events.ends_at' => 'DESC'])
            ->first();

        return $latest?->event;
    }

    /**
     * A name with everything a screen reading gets wrong taken out: accents,
     * spaces, punctuation and case. '' when nothing is left to compare.
     *
     * @param string $name Name.
     * @return string
     */
    private function similar(string $name): string
    {
        $name = class_exists(\Normalizer::class) ? (\Normalizer::normalize($name, \Normalizer::FORM_KD) ?: $name) : $name;

        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', preg_replace('/\p{Mn}+/u', '', $name) ?? $name) ?? '');
    }

    /**
     * @param mixed $value A value from the request.
     * @return int|null The whole number of 0 or more it holds, if it does.
     */
    private function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1) {
            return (int)$value;
        }

        return null;
    }

    /**
     * @param string $name Name.
     * @return string
     */
    private function normalize(string $name): string
    {
        $name = class_exists(\Normalizer::class) ? (\Normalizer::normalize($name, \Normalizer::FORM_KC) ?: $name) : $name;

        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)) ?? $name);
    }
}
