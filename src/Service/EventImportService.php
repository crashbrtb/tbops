<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Event;
use App\Model\Entity\EventImport;
use App\Model\Entity\EventImportRow;
use App\Model\Entity\EventReward;
use App\Model\Entity\GameTournament;
use App\Model\Table\GameTournamentsTable;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use DomainException;
use Throwable;

/**
 * Everything between "the uploader sent a ranking" and "the result is public".
 *
 * 1. validate() checks an upload and brings it to one shape, whether it came
 *    from the API or from a CSV exported by the discovery tool.
 * 2. import() stores it as a draft, linked to members, replacing any earlier draft.
 * 3. preview() is what the review page shows: the ranking, each player's share
 *    of every reward, and what deserves a second look.
 * 4. applyReview() saves the administrator's corrections to a draft.
 * 5. publish() freezes the result into standings and allocations.
 *
 * Players are matched by the game's player id first. Names are a fallback only,
 * because two different players in the same clan can share one.
 */
class EventImportService
{
    use LocatorAwareTrait;

    public const MAX_ROWS = 100;
    public const MAX_NAME = 60;

    protected RewardDistributionService $distribution;

    /**
     * @param \App\Service\RewardDistributionService|null $distribution Split calculator.
     */
    public function __construct(?RewardDistributionService $distribution = null)
    {
        $this->distribution = $distribution ?? new RewardDistributionService();
    }

    // ---------------------------------------------------------------- upload

    /**
     * Check an upload and normalise it.
     *
     * @param array<string, mixed> $data Decoded request body.
     * @return array{errors: array<string, string>, payload: array{rows: list<array{position: int, name: string, points: int, game_player_id: int|null, power: int|null}>, game_event_name: string|null, game_event_at: \Cake\I18n\DateTime|null, capture_method: string, client_version: string|null}}
     */
    public function validate(array $data): array
    {
        $errors = [];
        $rows = $data['rows'] ?? null;

        if (!is_array($rows) || !array_is_list($rows) || $rows === []) {
            $errors['rows'] = __('The ranking must have at least one player.');
            $rows = [];
        } elseif (count($rows) > self::MAX_ROWS) {
            $errors['rows'] = __('A clan ranking has at most {0} players; {1} were sent.', self::MAX_ROWS, count($rows));
            $rows = [];
        }

        $clean = [];
        $positions = [];
        $playerIds = [];
        foreach ($rows as $i => $row) {
            $line = $i + 1;
            if (!is_array($row)) {
                $errors["rows.{$line}"] = __('Line {0} is not a player.', $line);
                continue;
            }

            $position = $this->wholeNumber($row['position'] ?? null);
            $points = $this->wholeNumber($row['points'] ?? null);
            $name = is_string($row['name'] ?? null) ? trim($row['name']) : '';
            $playerId = $this->wholeNumber($row['player_id'] ?? $row['game_player_id'] ?? null);
            $power = $this->wholeNumber($row['power'] ?? null);

            if ($position === null || $position < 1) {
                $errors["rows.{$line}.position"] = __('Line {0}: the position must be a whole number from 1.', $line);
            } elseif (isset($positions[$position])) {
                $errors["rows.{$line}.position"] = __('Line {0}: position {1} appears twice.', $line, $position);
            } else {
                $positions[$position] = true;
            }

            if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
                $errors["rows.{$line}.name"] = __('Line {0}: the name must have 1 to {1} characters.', $line, self::MAX_NAME);
            }

            if ($points === null) {
                $errors["rows.{$line}.points"] = __('Line {0}: the points must be a whole number of 0 or more.', $line);
            }

            if (($row['player_id'] ?? $row['game_player_id'] ?? null) !== null) {
                if ($playerId === null || $playerId < 1) {
                    $errors["rows.{$line}.player_id"] = __('Line {0}: the player id must be a positive whole number.', $line);
                } elseif (isset($playerIds[$playerId])) {
                    $errors["rows.{$line}.player_id"] = __('Line {0}: player id {1} appears twice.', $line, $playerId);
                } else {
                    $playerIds[$playerId] = true;
                }
            }

            $clean[] = [
                'position' => (int)$position,
                'name' => mb_substr($name, 0, self::MAX_NAME),
                'points' => (int)$points,
                'game_player_id' => $playerId,
                'power' => $power,
            ];
        }
        usort($clean, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        $method = (string)($data['capture_method'] ?? EventImport::METHOD_PACKET);
        if (!in_array($method, EventImport::captureMethods(), true)) {
            $errors['capture_method'] = __('Unknown capture method.');
        }

        $gameEventName = isset($data['game_event_name']) && is_string($data['game_event_name'])
            ? mb_substr(trim($data['game_event_name']), 0, 120)
            : null;

        $gameEventAt = null;
        if (!empty($data['game_event_at']) && is_string($data['game_event_at'])) {
            try {
                $gameEventAt = (new DateTime($data['game_event_at']))->setTimezone('UTC');
            } catch (Throwable) {
                $errors['game_event_at'] = __('The tournament date is not a valid date.');
            }
        }

        $clientVersion = isset($data['client_version']) && is_string($data['client_version'])
            ? mb_substr(trim($data['client_version']), 0, 32)
            : null;

        return [
            'errors' => $errors,
            'payload' => [
                'rows' => $clean,
                'game_event_name' => $gameEventName ?: null,
                'game_event_at' => $gameEventAt,
                'capture_method' => $method,
                'client_version' => $clientVersion ?: null,
            ],
        ];
    }

    /**
     * Store a validated upload as the event's draft.
     *
     * An upload identical to the current draft is not stored twice: the
     * uploader may retry after a timeout without creating noise.
     *
     * @param \App\Model\Entity\Event $event Imported event.
     * @param array{rows: list<array{position: int, name: string, points: int, game_player_id: int|null, power: int|null}>, game_event_name: string|null, game_event_at: \Cake\I18n\DateTime|null, capture_method: string, client_version: string|null} $payload From validate().
     * @param int|null $userId Who sent it.
     * @param int|null $tokenId Token it came with, when it came through the API.
     * @return array{import: \App\Model\Entity\EventImport, created: bool}
     * @throws \DomainException When the event cannot take an upload.
     */
    public function import(Event $event, array $payload, ?int $userId, ?int $tokenId = null): array
    {
        $this->assertImportable($event);

        $imports = $this->fetchTable('EventImports');
        $hash = $this->payloadHash($payload);

        /** @var \App\Model\Entity\EventImport|null $existing */
        $existing = $imports->find()
            ->where([
                'event_id' => $event->id,
                'payload_hash' => $hash,
                'status' => EventImport::STATUS_DRAFT,
            ])
            ->first();
        if ($existing !== null) {
            return ['import' => $existing, 'created' => false];
        }

        $matched = $this->matchRows($payload['rows']);

        /** @var \App\Model\Entity\EventImport $import */
        $import = $imports->getConnection()->transactional(function () use ($imports, $event, $payload, $matched, $hash, $userId, $tokenId) {
            $imports->updateAll(
                ['status' => EventImport::STATUS_SUPERSEDED],
                ['event_id' => $event->id, 'status' => EventImport::STATUS_DRAFT]
            );

            $import = $imports->newEmptyEntity();
            $import->set([
                'event_id' => $event->id,
                'user_id' => $userId,
                'api_token_id' => $tokenId,
                'status' => EventImport::STATUS_DRAFT,
                'game_event_name' => $payload['game_event_name'],
                'game_event_at' => $payload['game_event_at'],
                'capture_method' => $payload['capture_method'],
                'client_version' => $payload['client_version'],
                'payload_hash' => $hash,
                'row_count' => count($matched),
            ], ['guard' => false]);
            $imports->saveOrFail($import);

            $rowsTable = $this->fetchTable('EventImportRows');
            $entities = [];
            foreach ($matched as $row) {
                $entity = $rowsTable->newEmptyEntity();
                $entity->set($row + ['event_import_id' => $import->id], ['guard' => false]);
                $entities[] = $entity;
            }
            $rowsTable->saveManyOrFail($entities);

            return $import;
        });

        return ['import' => $import, 'created' => true];
    }

    /**
     * Link each uploaded player to a member.
     *
     * @param list<array{position: int, name: string, points: int, game_player_id: int|null, power: int|null}> $rows Validated rows.
     * @return list<array<string, mixed>> Rows ready to be saved as EventImportRow.
     */
    public function matchRows(array $rows): array
    {
        $members = $this->fetchTable('Members');

        $ids = array_values(array_filter(array_column($rows, 'game_player_id')));
        $byId = [];
        if ($ids) {
            foreach ($members->find()->where(['game_player_id IN' => $ids])->all() as $member) {
                $byId[(int)$member->game_player_id] = $member;
            }
        }

        $byName = [];
        foreach ($members->find()->select(['id', 'player', 'game_player_id', 'administrative_account'])->all() as $member) {
            $byName[$this->normalize((string)$member->player)][] = $member;
        }

        $mappings = [];
        foreach ($this->fetchTable('PlayerNameMappings')->find()->all() as $mapping) {
            $mappings[$this->normalize((string)$mapping->ocr_text)] = $this->normalize((string)$mapping->correct_name);
        }

        $out = [];
        foreach ($rows as $row) {
            $member = null;
            $type = EventImportRow::MATCH_NONE;
            $playerId = $row['game_player_id'];

            if ($playerId !== null && isset($byId[$playerId])) {
                $member = $byId[$playerId];
                $type = EventImportRow::MATCH_PLAYER_ID;
            } else {
                $key = $this->normalize($row['name']);
                $candidate = $this->uniqueNameMatch($byName[$key] ?? [], $playerId);
                if ($candidate !== null) {
                    $member = $candidate;
                    $type = EventImportRow::MATCH_NAME;
                } elseif (isset($mappings[$key])) {
                    $candidate = $this->uniqueNameMatch($byName[$mappings[$key]] ?? [], $playerId);
                    if ($candidate !== null) {
                        $member = $candidate;
                        $type = EventImportRow::MATCH_MAPPING;
                    }
                }
            }

            $out[] = [
                'position' => $row['position'],
                'game_player_id' => $playerId,
                'raw_name' => $row['name'],
                'points' => $row['points'],
                'original_points' => null,
                'power' => $row['power'],
                'member_id' => $member?->id,
                'match_type' => $type,
                'eligible' => !($member !== null && $member->administrative_account),
            ];
        }

        return $out;
    }

    /**
     * Complete earlier drafts with what a new ranking knows about its players.
     *
     * A ranking sent while the uploader had no profile for a player carries
     * "id:<game id>" as the name and links to nobody. Once a ranking arrives
     * with that player's name, or the members table has learned their game id,
     * the draft rows get the name and the member.
     *
     * @param list<array{position: int, name: string, points: int, game_player_id: int|null, power: int|null}> $rows Validated rows.
     * @return int Draft rows changed.
     */
    public function completeDrafts(array $rows): int
    {
        $names = [];
        foreach ($rows as $row) {
            if ($row['game_player_id'] !== null && !str_starts_with($row['name'], MemberRosterService::PLACEHOLDER)) {
                $names[(int)$row['game_player_id']] = $row['name'];
            }
        }

        $draftRows = $this->fetchTable('EventImportRows')->find()
            ->innerJoinWith('EventImports', fn ($q) => $q->where(['EventImports.status' => EventImport::STATUS_DRAFT]))
            ->where([
                'EventImportRows.game_player_id IS NOT' => null,
                'OR' => [
                    'EventImportRows.member_id IS' => null,
                    'EventImportRows.raw_name LIKE' => MemberRosterService::PLACEHOLDER . '%',
                ],
            ])
            ->all()
            ->toList();
        if ($draftRows === []) {
            return 0;
        }

        $members = [];
        $ids = array_values(array_unique(array_map(fn ($r): int => (int)$r->game_player_id, $draftRows)));
        foreach ($this->fetchTable('Members')->find()->where(['game_player_id IN' => $ids])->all() as $member) {
            $members[(int)$member->game_player_id] = $member;
        }

        $table = $this->fetchTable('EventImportRows');
        $changed = 0;
        foreach ($draftRows as $draftRow) {
            $id = (int)$draftRow->game_player_id;
            $member = $members[$id] ?? null;
            if (str_starts_with((string)$draftRow->raw_name, MemberRosterService::PLACEHOLDER)) {
                $name = $names[$id] ?? $member?->player;
                if ($name !== null && $name !== '') {
                    $draftRow->set('raw_name', $name, ['guard' => false]);
                }
            }
            if ($draftRow->member_id === null && $member !== null) {
                $draftRow->set([
                    'member_id' => $member->id,
                    'match_type' => EventImportRow::MATCH_PLAYER_ID,
                    'eligible' => !$member->administrative_account,
                ], ['guard' => false]);
            }
            if ($draftRow->isDirty()) {
                $table->saveOrFail($draftRow);
                $changed++;
            }
        }

        return $changed;
    }

    // ----------------------------------------------------------- tournaments

    /**
     * Check the part of an upload that identifies the tournament in the game.
     *
     * @param array<string, mixed> $data Decoded request body.
     * @return array{errors: array<string, string>, tournament: array{result_uid: string, tournament_key: string, ranking: string, name: string|null, ended_at: \Cake\I18n\DateTime|null}}
     */
    public function validateTournament(array $data): array
    {
        $errors = [];

        $uid = is_string($data['result_uid'] ?? null) ? trim($data['result_uid']) : '';
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uid)) {
            $errors['result_uid'] = __('The tournament result id is missing or invalid.');
        }

        $key = is_string($data['tournament_key'] ?? null) ? trim($data['tournament_key']) : '';
        if (!preg_match('/^\d{1,10}(:\d{1,10}){0,2}$/', $key)) {
            $errors['tournament_key'] = __('The tournament type is missing or invalid.');
        }

        $ranking = GameTournamentsTable::normalizeRanking($data['ranking'] ?? null);
        if ($ranking === null) {
            $errors['ranking'] = __('The tournament ranking is invalid.');
        }

        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if (mb_strlen($name) > 120) {
            $errors['name'] = __('The tournament name has more than {0} characters.', 120);
        }

        $endedAt = null;
        if (!empty($data['ended_at'])) {
            try {
                $endedAt = (new DateTime((string)$data['ended_at']))->setTimezone('UTC');
            } catch (Throwable) {
                $errors['ended_at'] = __('The tournament date is not a valid date.');
            }
            if ($endedAt !== null && $endedAt->greaterThan(DateTime::now()->addDays(1))) {
                $errors['ended_at'] = __('The tournament cannot end in the future.');
            }
        }

        return [
            'errors' => $errors,
            'tournament' => [
                'result_uid' => $uid,
                'tournament_key' => $key,
                'ranking' => $ranking ?? GameTournament::RANKING_DEFAULT,
                'name' => $name !== '' ? $name : null,
                'ended_at' => $endedAt,
            ],
        ];
    }

    /**
     * The event for a tournament result, created when this is the first time
     * the result is seen.
     *
     * A new event takes its name, contact and rewards from the most recent
     * event of the same tournament type, so a tournament that repeats every day
     * is ready to review without anyone filling in a form. A name sent by the
     * uploader wins over the inherited one.
     *
     * @param array{result_uid: string, tournament_key: string, ranking?: string, name: string|null, ended_at: \Cake\I18n\DateTime|null} $tournament From validateTournament().
     * @param int $userId Who sent it.
     * @param string|null $userName Their name, the contact when nothing is inherited.
     * @return array{event: \App\Model\Entity\Event, created: bool, name_source: string, rewards_copied: int}
     */
    public function registerTournament(array $tournament, int $userId, ?string $userName): array
    {
        $events = $this->fetchTable('Events');

        /** @var \App\Model\Entity\Event|null $existing */
        $existing = $events->find('withoutBanner')
            ->where(['Events.game_result_uid' => $tournament['result_uid']])
            ->contain(['EventRewards'])
            ->first();
        if ($existing !== null) {
            return ['event' => $existing, 'created' => false, 'name_source' => 'existing', 'rewards_copied' => 0];
        }

        $endsAt = $tournament['ended_at'] ?? DateTime::now();

        // The catalogue knows the tournament by its type and ranking: its name
        // (read off the Journal by the mapper, or set by an administrator) and
        // its duration.
        $catalogue = $this->fetchTable('GameTournaments');
        [$type, $variant] = GameTournamentsTable::parseKey($tournament['tournament_key']);
        $ranking = $tournament['ranking'] ?? GameTournament::RANKING_DEFAULT;
        $entry = $type !== null ? $catalogue->touchType($type, $variant, $endsAt, $ranking) : null;
        if ($entry !== null && $tournament['name'] !== null) {
            $catalogue->offerName($entry, $tournament['name'], GameTournament::SOURCE_UPLOADER);
        }

        $previous = $this->previousTournament($tournament['tournament_key'], $entry?->id);

        if ($entry !== null && $entry->name) {
            [$name, $source] = [$entry->name, 'catalog'];
        } elseif ($tournament['name'] !== null) {
            [$name, $source] = [$tournament['name'], 'sent'];
        } elseif ($previous !== null) {
            [$name, $source] = [$previous->name, 'previous'];
        } else {
            [$name, $source] = [__('Game tournament {0}', $tournament['tournament_key']), 'default'];
        }

        // Only the end is known from the game; the start is the end minus the
        // tournament's duration, one day when nobody has set it. The
        // administrator can still correct it on the review page.
        $days = $entry?->effectiveDurationDays() ?? GameTournament::DEFAULT_DURATION_DAYS;
        $startsAt = $endsAt->subDays($days);

        $rewards = [];
        foreach ((array)$previous?->event_rewards as $reward) {
            $rewards[] = [
                'item_name' => $reward->item_name,
                'quantity' => $reward->quantity,
                'rule' => $reward->rule,
                'min_points' => $reward->min_points,
                'remainder' => $reward->remainder,
            ];
        }

        $event = $events->newEntity([
            'name' => $name,
            'criteria' => Event::CRITERIA_IMPORTED,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'prize' => '',
            'contact_player' => $previous?->contact_player ?: ($userName ?: '-'),
            'description' => $previous?->description,
            'event_rewards' => $rewards,
        ], ['associated' => ['EventRewards']]);
        $event->set([
            'game_result_uid' => $tournament['result_uid'],
            'game_tournament_key' => $tournament['tournament_key'],
            'game_tournament_id' => $entry?->id,
            'created_by' => $userId,
        ], ['guard' => false]);

        $events->saveOrFail($event, ['associated' => ['EventRewards'], 'allowNoRewards' => true]);

        return [
            'event' => $events->get($event->id, contain: ['EventRewards']),
            'created' => true,
            'name_source' => $source,
            'rewards_copied' => count($rewards),
        ];
    }

    /**
     * What the site already knows about each tournament type: the name and
     * rewards of its most recent event. The uploader uses it to fill in the
     * name before sending.
     *
     * @return list<array{game_type: int, ranking: string, tournament_key: string, name: string, last_ended_at: string|null, rewards: list<array{item_name: string, quantity: int, rule: string}>}>
     */
    public function knownTournaments(): array
    {
        $known = [];
        foreach ($this->fetchTable('GameTournaments')->find('withoutImage')->orderBy(['game_type' => 'ASC'])->all() as $entry) {
            $previous = $this->previousTournament(null, $entry->id);
            $known[] = [
                'game_type' => $entry->game_type,
                'ranking' => $entry->ranking,
                'tournament_key' => $entry->game_type . ($entry->last_variant !== null ? ':' . $entry->last_variant : ''),
                'name' => $entry->name,
                'name_source' => $entry->name_source,
                'duration_days' => $entry->duration_days,
                'has_image' => $entry->has_image,
                'last_ended_at' => $entry->last_seen_at?->toIso8601String(),
                'rewards' => array_map(fn (EventReward $r): array => [
                    'item_name' => $r->item_name,
                    'quantity' => (int)$r->quantity,
                    'rule' => $r->rule,
                ], (array)$previous?->event_rewards),
            ];
        }

        return $known;
    }

    /**
     * The latest event of a tournament type, preferring one that has rewards.
     *
     * Matched by catalogue entry, which groups every variant of a type (the
     * game changes the second number of the key from one run to the next), or
     * by the exact key for events registered before the catalogue existed. Only
     * those: two rankings of one tournament share the key, not the entry.
     *
     * @param string|null $key Tournament key.
     * @param int|null $catalogueId Catalogue entry.
     * @return \App\Model\Entity\Event|null
     */
    private function previousTournament(?string $key, ?int $catalogueId = null): ?Event
    {
        $match = [];
        if ($catalogueId !== null) {
            $match[] = ['Events.game_tournament_id' => $catalogueId];
        }
        if ($key !== null) {
            $match[] = ['Events.game_tournament_key' => $key, 'Events.game_tournament_id IS' => null];
        }
        if (!$match) {
            return null;
        }

        $candidates = $this->fetchTable('Events')->find('withoutBanner')
            ->where(['OR' => $match, 'Events.criteria' => Event::CRITERIA_IMPORTED])
            ->contain(['EventRewards'])
            ->orderBy(['Events.ends_at' => 'DESC', 'Events.id' => 'DESC'])
            ->limit(10)
            ->all()
            ->toList();

        foreach ($candidates as $candidate) {
            if ($candidate->event_rewards) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    // ---------------------------------------------------------------- review

    /**
     * What the review page shows for a draft or a published import.
     *
     * @param \App\Model\Entity\Event $event Event with its rewards loaded.
     * @param \App\Model\Entity\EventImport $import Import with rows and members loaded.
     * @return array{
     *     rows: list<\App\Model\Entity\EventImportRow>,
     *     rewards: list<\App\Model\Entity\EventReward>,
     *     distribution: array{rewards: array<int|string, array{amounts: array<int|string, int>, distributed: int, leftover: int, recipients: int, pool_points: int}>, participation: array<int|string, float>, eligible_points: int},
     *     warnings: list<array{level: string, text: string}>,
     *     totals: array{players: int, eligible: int, administrative: int, unmatched: int, points: int}
     * }
     */
    public function preview(Event $event, EventImport $import): array
    {
        $rows = (array)$import->event_import_rows;
        $rewards = (array)$event->event_rewards;

        $distribution = $this->distribution->distribute($this->players($rows), $this->rewardLines($rewards));

        $totals = ['players' => count($rows), 'eligible' => 0, 'administrative' => 0, 'unmatched' => 0, 'points' => 0];
        foreach ($rows as $row) {
            $totals['points'] += (int)$row->points;
            $totals['eligible'] += $row->eligible ? 1 : 0;
            $totals['administrative'] += $row->member !== null && $row->member->administrative_account ? 1 : 0;
            $totals['unmatched'] += $row->member_id === null ? 1 : 0;
        }

        return [
            'rows' => $rows,
            'rewards' => $rewards,
            'distribution' => $distribution,
            'warnings' => $this->warnings($event, $import),
            'totals' => $totals,
        ];
    }

    /**
     * Things the administrator should look at before publishing.
     *
     * @param \App\Model\Entity\Event $event The event.
     * @param \App\Model\Entity\EventImport $import Import with rows and members loaded.
     * @return list<array{level: string, text: string}>
     */
    public function warnings(Event $event, EventImport $import): array
    {
        $rows = (array)$import->event_import_rows;
        $out = [];

        $unmatched = array_filter($rows, fn (EventImportRow $r): bool => $r->member_id === null);
        if ($unmatched) {
            $out[] = ['level' => 'warning', 'text' => __(
                '{0} player(s) are not linked to a member: {1}. They still receive their share; link them to keep the history together.',
                count($unmatched),
                implode(', ', array_slice(array_map(fn (EventImportRow $r): string => $r->raw_name, $unmatched), 0, 8))
            )];
        }

        $byName = array_filter($rows, fn (EventImportRow $r): bool => in_array($r->match_type, [EventImportRow::MATCH_NAME, EventImportRow::MATCH_MAPPING], true));
        if ($byName) {
            $out[] = ['level' => 'info', 'text' => __(
                '{0} player(s) were linked by name. Confirm them: when the result is published their game id is saved on the member.',
                count($byName)
            )];
        }

        $positions = array_map(fn (EventImportRow $r): int => (int)$r->position, $rows);
        if ($positions && $positions !== range(1, count($positions))) {
            $out[] = ['level' => 'warning', 'text' => __('The positions are not 1 to {0} without gaps: a part of the ranking may be missing.', count($positions))];
        }

        $outOfOrder = [];
        $previous = null;
        foreach ($rows as $row) {
            if ($previous !== null && (int)$row->points > (int)$previous->points) {
                $outOfOrder[] = $row->raw_name;
            }
            $previous = $row;
        }
        if ($outOfOrder) {
            $out[] = ['level' => 'warning', 'text' => __(
                'Points are not in descending order at: {0}. Check for a reading error.',
                implode(', ', array_slice($outOfOrder, 0, 8))
            )];
        }

        $names = [];
        foreach ($rows as $row) {
            $names[$this->normalize($row->raw_name)][] = $row->raw_name;
        }
        $repeated = array_filter($names, fn (array $n): bool => count($n) > 1);
        if ($repeated) {
            $out[] = ['level' => 'info', 'text' => __(
                'Different players share a name: {0}. They are told apart by their game id.',
                implode(', ', array_map(fn (array $n): string => $n[0], $repeated))
            )];
        }

        $edited = array_filter($rows, fn (EventImportRow $r): bool => $r->original_points !== null);
        if ($edited) {
            $out[] = ['level' => 'info', 'text' => __('Points were corrected by hand for {0} player(s).', count($edited))];
        }

        if (!$event->event_rewards) {
            $out[] = ['level' => 'warning', 'text' => __('This event has no reward to split. Add one before publishing.')];
        }

        foreach ($this->preflightRewards($event, $rows) as $text) {
            $out[] = ['level' => 'warning', 'text' => $text];
        }

        return $out;
    }

    /**
     * Save the administrator's corrections to a draft.
     *
     * @param \App\Model\Entity\EventImport $import Draft with rows loaded.
     * @param array<int|string, array<string, mixed>> $posted Row id => [eligible, member_id, points].
     * @return int Rows changed.
     * @throws \DomainException When the import is not a draft.
     */
    public function applyReview(EventImport $import, array $posted): int
    {
        if ($import->status !== EventImport::STATUS_DRAFT) {
            throw new DomainException(__('Only a draft can be changed. Unpublish the result first.'));
        }

        $rowsTable = $this->fetchTable('EventImportRows');
        $memberIds = array_flip(array_map('intval', $this->fetchTable('Members')->find()->select(['id'])->all()->extract('id')->toList()));

        $changed = [];
        foreach ((array)$import->event_import_rows as $row) {
            $input = $posted[$row->id] ?? null;
            if (!is_array($input)) {
                continue;
            }

            // Entity::set() marks a field dirty even when the value is the same,
            // so every field is compared first: an untouched row stays untouched.
            if (array_key_exists('eligible', $input) && (bool)$input['eligible'] !== (bool)$row->eligible) {
                $row->set('eligible', (bool)$input['eligible'], ['guard' => false]);
            }

            if (array_key_exists('member_id', $input)) {
                $memberId = $input['member_id'] === '' || $input['member_id'] === null ? null : (int)$input['member_id'];
                if ($memberId !== null && !isset($memberIds[$memberId])) {
                    $memberId = $row->member_id;
                }
                if ($memberId !== $row->member_id) {
                    $row->set('member_id', $memberId, ['guard' => false]);
                    $row->set('match_type', $memberId === null ? EventImportRow::MATCH_NONE : EventImportRow::MATCH_MANUAL, ['guard' => false]);
                }
            }

            if (array_key_exists('points', $input)) {
                $points = $this->wholeNumber(is_string($input['points']) ? str_replace(['.', ',', ' '], '', $input['points']) : $input['points']);
                if ($points !== null && $points !== (int)$row->points) {
                    $original = $row->original_points ?? (int)$row->points;
                    $row->set('points', $points, ['guard' => false]);
                    $row->set('original_points', $points === (int)$original ? null : $original, ['guard' => false]);
                }
            }

            if ($row->isDirty()) {
                $changed[] = $row;
            }
        }

        if ($changed) {
            $rowsTable->saveManyOrFail($changed);
        }

        return count($changed);
    }

    // --------------------------------------------------------------- publish

    /**
     * Freeze a reviewed draft into the event's official result.
     *
     * @param \App\Model\Entity\Event $event Event with rewards loaded.
     * @param \App\Model\Entity\EventImport $import Draft with rows and members loaded.
     * @return int Players recorded.
     * @throws \DomainException When there is nothing valid to publish.
     */
    public function publish(Event $event, EventImport $import): int
    {
        if ($import->status !== EventImport::STATUS_DRAFT || $import->event_id !== $event->id) {
            throw new DomainException(__('This ranking is not a draft of this event.'));
        }
        if (!$event->event_rewards) {
            throw new DomainException(__('This event has no reward to split. Add one before publishing.'));
        }
        $problems = $this->preflightRewards($event, (array)$import->event_import_rows);
        if ($problems) {
            throw new DomainException(implode(' ', $problems));
        }

        $rows = (array)$import->event_import_rows;
        $distribution = $this->distribution->distribute($this->players($rows), $this->rewardLines((array)$event->event_rewards));

        $standings = $this->fetchTable('EventStandings');
        $allocations = $this->fetchTable('EventRewardAllocations');
        $members = $this->fetchTable('Members');
        $imports = $this->fetchTable('EventImports');
        $events = $this->fetchTable('Events');

        $standings->getConnection()->transactional(function () use ($event, $import, $rows, $distribution, $standings, $allocations, $members, $imports, $events) {
            $this->clearResult($event);

            foreach ($rows as $row) {
                $standing = $standings->newEntity([
                    'event_id' => $event->id,
                    'position' => (int)$row->position,
                    'player' => mb_substr($row->displayName(), 0, 50),
                    'points' => (int)$row->points,
                    'chest_count' => 0,
                    'chest_score' => 0,
                    'participation' => $distribution['participation'][$row->id] ?? 0.0,
                    'member_id' => $row->member_id,
                    'game_player_id' => $row->game_player_id,
                    'power' => $row->power,
                    'eligible' => (bool)$row->eligible,
                ]);
                $standings->saveOrFail($standing);

                foreach ($distribution['rewards'] as $rewardId => $split) {
                    $amount = $split['amounts'][$row->id] ?? 0;
                    if ($amount > 0) {
                        $allocations->saveOrFail($allocations->newEntity([
                            'event_reward_id' => $rewardId,
                            'event_standing_id' => $standing->id,
                            'amount' => $amount,
                        ]));
                    }
                }

                $this->rememberPlayerId($members, $row);
            }

            $imports->updateAll(
                ['status' => EventImport::STATUS_SUPERSEDED],
                ['event_id' => $event->id, 'status' => EventImport::STATUS_PUBLISHED]
            );
            $imports->updateAll(['status' => EventImport::STATUS_PUBLISHED], ['id' => $import->id]);

            $now = DateTime::now();
            $event->set('published_at', $now, ['guard' => false]);
            $event->set('finalized_at', $now, ['guard' => false]);
            $events->saveOrFail($event, ['checkRules' => false, 'associated' => false]);
        });

        $import->set('status', EventImport::STATUS_PUBLISHED, ['guard' => false]);

        return count($rows);
    }

    /**
     * Take a published result down, back to a draft that can be corrected.
     *
     * @param \App\Model\Entity\Event $event The event.
     * @return void
     */
    public function unpublish(Event $event): void
    {
        $this->fetchTable('EventStandings')->getConnection()->transactional(function () use ($event) {
            $this->clearResult($event);

            $imports = $this->fetchTable('EventImports');
            $imports->updateAll(
                ['status' => EventImport::STATUS_SUPERSEDED],
                ['event_id' => $event->id, 'status' => EventImport::STATUS_DRAFT]
            );
            $imports->updateAll(
                ['status' => EventImport::STATUS_DRAFT],
                ['event_id' => $event->id, 'status' => EventImport::STATUS_PUBLISHED]
            );

            $event->set('published_at', null, ['guard' => false]);
            $event->set('finalized_at', null, ['guard' => false]);
            $this->fetchTable('Events')->saveOrFail($event, ['checkRules' => false, 'associated' => false]);
        });
    }

    /**
     * The published result as the event page shows it.
     *
     * @param \App\Model\Entity\Event $event Event with rewards loaded.
     * @return array{
     *     rows: list<array{standing: \App\Model\Entity\EventStanding, amounts: array<int, int>}>,
     *     rewards: list<\App\Model\Entity\EventReward>,
     *     totals: array<int, int>,
     *     players: int,
     *     recipients: int,
     *     points: int
     * }
     */
    public function publishedResult(Event $event): array
    {
        $rewards = (array)$event->event_rewards;
        $standings = $this->fetchTable('EventStandings')->find()
            ->where(['EventStandings.event_id' => $event->id])
            ->contain(['EventRewardAllocations'])
            ->orderBy(['EventStandings.position' => 'ASC'])
            ->all()
            ->toList();

        $rows = [];
        $totals = [];
        $recipients = 0;
        $points = 0;
        foreach ($standings as $standing) {
            $amounts = [];
            foreach ((array)$standing->event_reward_allocations as $allocation) {
                $amounts[(int)$allocation->event_reward_id] = (int)$allocation->amount;
                $totals[(int)$allocation->event_reward_id] = ($totals[(int)$allocation->event_reward_id] ?? 0) + (int)$allocation->amount;
            }
            $recipients += array_sum($amounts) > 0 ? 1 : 0;
            $points += (int)$standing->points;
            $rows[] = ['standing' => $standing, 'amounts' => $amounts];
        }

        return [
            'rows' => $rows,
            'rewards' => $rewards,
            'totals' => $totals,
            'players' => count($rows),
            'recipients' => $recipients,
            'points' => $points,
        ];
    }

    // ------------------------------------------------------------------- csv

    /**
     * Read the `ranking.csv` the discovery tool exports, or any CSV with a
     * position, name and points column.
     *
     * @param string $contents File contents.
     * @return array<string, mixed> Data in the shape validate() expects.
     */
    public function parseCsv(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\r\n|\r|\n/', trim($contents)) ?: [];
        if (!$lines || trim($lines[0]) === '') {
            return ['rows' => [], 'capture_method' => EventImport::METHOD_CSV];
        }

        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $aliases = [
            'position' => ['posicao', 'posição', 'position', 'pos', 'rank'],
            'name' => ['nome', 'name', 'jogador', 'player'],
            'points' => ['pontos', 'points', 'pontuacao', 'pontuação', 'score'],
            'power' => ['poder', 'power'],
            'player_id' => ['id_jogador', 'player_id', 'game_player_id', 'id'],
        ];

        $header = array_map(fn ($c) => mb_strtolower(trim((string)$c)), str_getcsv($lines[0], $delimiter, '"', ''));
        $columns = [];
        foreach ($aliases as $field => $names) {
            foreach ($header as $index => $label) {
                if (in_array($label, $names, true)) {
                    $columns[$field] = $index;
                    break;
                }
            }
        }

        $hasHeader = isset($columns['name']) || isset($columns['points']);
        if (!$hasHeader) {
            $columns = ['position' => 0, 'name' => 1, 'points' => 2, 'power' => 3, 'player_id' => 4];
        }

        $rows = [];
        foreach (array_slice($lines, $hasHeader ? 1 : 0) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $delimiter, '"', '');
            $cell = fn (string $field): ?string => isset($columns[$field]) && isset($cells[$columns[$field]])
                ? trim((string)$cells[$columns[$field]])
                : null;
            $digits = fn (?string $value): ?string => $value === null || $value === '' ? null : preg_replace('/\D/', '', $value);

            $rows[] = array_filter([
                'position' => $digits($cell('position')) ?? (string)(count($rows) + 1),
                'name' => $cell('name'),
                'points' => $digits($cell('points')),
                'power' => $digits($cell('power')),
                'player_id' => $digits($cell('player_id')),
            ], fn ($v) => $v !== null && $v !== '');
        }

        return ['rows' => $rows, 'capture_method' => EventImport::METHOD_CSV];
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param \App\Model\Entity\Event $event The event.
     * @return void
     * @throws \DomainException When the event cannot take an upload.
     */
    public function assertImportable(Event $event): void
    {
        if (!$event->is_imported) {
            throw new DomainException(__('Event #{0} does not take an imported ranking.', $event->event_number));
        }
        if ($event->status === Event::STATUS_CANCELLED) {
            throw new DomainException(__('Event #{0} was cancelled.', $event->event_number));
        }
        if ($event->published_at !== null) {
            throw new DomainException(__('The result of event #{0} is already published. Unpublish it to send a new ranking.', $event->event_number));
        }
    }

    /**
     * @param list<\App\Model\Entity\EventImportRow> $rows Rows.
     * @return list<array{key: int, position: int, points: int, eligible: bool}>
     */
    private function players(array $rows): array
    {
        return array_map(fn (EventImportRow $row): array => [
            'key' => (int)$row->id,
            'position' => (int)$row->position,
            'points' => (int)$row->points,
            'eligible' => (bool)$row->eligible,
        ], $rows);
    }

    /**
     * @param list<\App\Model\Entity\EventReward> $rewards Rewards.
     * @return array<int, array{quantity: int, rule: string, min_points: int, remainder: string}>
     */
    private function rewardLines(array $rewards): array
    {
        $lines = [];
        foreach ($rewards as $reward) {
            $lines[(int)$reward->id] = [
                'quantity' => (int)$reward->quantity,
                'rule' => (string)$reward->rule,
                'min_points' => (int)$reward->min_points,
                'remainder' => (string)($reward->remainder ?: EventReward::REMAINDER_TOP_RANKED),
            ];
        }

        return $lines;
    }

    /**
     * Reward lines that would hand out nothing.
     *
     * @param \App\Model\Entity\Event $event Event with rewards loaded.
     * @param list<\App\Model\Entity\EventImportRow> $rows Rows.
     * @return list<string>
     */
    private function preflightRewards(Event $event, array $rows): array
    {
        $problems = [];
        $result = $this->distribution->distribute($this->players($rows), $this->rewardLines((array)$event->event_rewards));
        foreach ((array)$event->event_rewards as $reward) {
            $split = $result['rewards'][(int)$reward->id] ?? null;
            if ($split !== null && $split['distributed'] === 0) {
                $problems[] = __('Nobody qualifies for "{0}": check the eligible players and the minimum points.', $reward->item_name);
            }
        }

        return $problems;
    }

    /**
     * Save the game id on a member linked by name or by hand, so next time the
     * link is certain. Never overwrites an id the member already has.
     *
     * @param \Cake\ORM\Table $members Members table.
     * @param \App\Model\Entity\EventImportRow $row Row.
     * @return void
     */
    private function rememberPlayerId($members, EventImportRow $row): void
    {
        if ($row->member_id === null || $row->game_player_id === null || $row->match_type === EventImportRow::MATCH_PLAYER_ID) {
            return;
        }
        $taken = $members->exists(['game_player_id' => $row->game_player_id]);
        if (!$taken) {
            $members->updateAll(
                ['game_player_id' => $row->game_player_id],
                ['id' => $row->member_id, 'game_player_id IS' => null]
            );
        }
    }

    /**
     * Remove a published result: allocations first, which the database would
     * cascade on MySQL but not on every engine the tests run on.
     *
     * @param \App\Model\Entity\Event $event The event.
     * @return void
     */
    private function clearResult(Event $event): void
    {
        $standings = $this->fetchTable('EventStandings');
        $ids = $standings->find()->select(['id'])->where(['event_id' => $event->id])->all()->extract('id')->toList();
        if ($ids) {
            $this->fetchTable('EventRewardAllocations')->deleteAll(['event_standing_id IN' => $ids]);
        }
        $standings->deleteAll(['event_id' => $event->id]);
    }

    /**
     * A member with this exact name, when there is exactly one that could be
     * this player. A member already tied to a different game id is someone else.
     *
     * @param list<\App\Model\Entity\Member> $candidates Members with the name.
     * @param int|null $playerId The uploaded player's game id.
     * @return \App\Model\Entity\Member|null
     */
    private function uniqueNameMatch(array $candidates, ?int $playerId): mixed
    {
        $possible = array_values(array_filter(
            $candidates,
            fn ($m): bool => $m->game_player_id === null || $playerId === null || (int)$m->game_player_id === $playerId
        ));

        return count($possible) === 1 ? $possible[0] : null;
    }

    /**
     * @param array{rows: list<array<string, mixed>>, game_event_name: string|null} $payload Payload.
     * @return string
     */
    private function payloadHash(array $payload): string
    {
        return hash('sha256', (string)json_encode([$payload['game_event_name'], $payload['rows']]));
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

    /**
     * @param mixed $value Value.
     * @return int|null
     */
    private function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value) && strlen($value) <= 18) {
            return (int)$value;
        }
        if (is_float($value) && floor($value) === $value && $value >= 0 && $value < 9.0E18) {
            return (int)$value;
        }

        return null;
    }
}
