<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Event;
use App\Model\Entity\EventImport;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

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
 * A player is linked by game id first, then by name when exactly one member
 * without a game id carries it. Two members with the same name and no id are
 * left alone and reported: guessing would tie the wrong history to a player.
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

    /**
     * Apply a ranking to the members table.
     *
     * @param \App\Model\Entity\Event $event The tournament the ranking belongs to.
     * @param list<array{position: int, name: string, points: int, game_player_id: int|null, power: int|null}> $rows Validated rows.
     * @return array{applied: bool, reason: string|null, created: int, linked: int, renamed: int, power_updated: int, activated: int, deactivated: int, ambiguous: list<string>, unnamed: int}
     */
    public function apply(Event $event, array $rows): array
    {
        $summary = [
            'applied' => false, 'reason' => null, 'created' => 0, 'linked' => 0, 'renamed' => 0,
            'power_updated' => 0, 'activated' => 0, 'deactivated' => 0, 'ambiguous' => [], 'unnamed' => 0,
        ];

        $withId = array_values(array_filter($rows, fn (array $r): bool => !empty($r['game_player_id'])));
        if (count($withId) < self::MIN_ROSTER_SIZE) {
            $summary['reason'] = 'too_few_ids';

            return $summary;
        }

        $newer = $this->newerRoster($event);
        if ($newer !== null) {
            $summary['reason'] = 'older';

            return $summary;
        }

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

        $present = [];
        $changed = [];

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
                if (count($candidates) === 1) {
                    $member = $candidates[0];
                    $member->set('game_player_id', $gameId, ['guard' => false]);
                    $byName[$this->normalize($name)] = [];
                    $byId[$gameId] = $member;
                    $summary['linked']++;
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

        $members->getConnection()->transactional(function () use ($members, $changed): void {
            foreach ($changed as $member) {
                $members->saveOrFail($member);
            }
        });
        $summary['applied'] = true;

        return $summary;
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
     * Whether a roster has ever been applied: from then on the tournaments,
     * not chest activity, decide who is active.
     *
     * @return bool
     */
    public function rosterManaged(): bool
    {
        return $this->fetchTable('EventImports')->exists(['roster_applied_at IS NOT' => null]);
    }

    /**
     * The event of the most recent roster applied, when it ended after this one.
     *
     * @param \App\Model\Entity\Event $event The tournament being sent.
     * @return \App\Model\Entity\Event|null
     */
    private function newerRoster(Event $event): ?Event
    {
        /** @var \App\Model\Entity\EventImport|null $latest */
        $latest = $this->fetchTable('EventImports')->find()
            ->contain(['Events' => ['finder' => 'withoutBanner']])
            ->where(['EventImports.roster_applied_at IS NOT' => null, 'EventImports.event_id !=' => $event->id])
            ->orderBy(['Events.ends_at' => 'DESC'])
            ->first();

        if ($latest === null || $latest->event === null) {
            return null;
        }

        return $latest->event->ends_at->greaterThan($event->ends_at) ? $latest->event : null;
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
