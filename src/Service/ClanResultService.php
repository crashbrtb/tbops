<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\AdminNotification;
use App\Model\Entity\ClanTournamentResult;
use App\Model\Entity\Event;
use App\Model\Entity\EventReward;
use App\Model\Entity\GameTournament;
use App\Model\Table\GameTournamentsTable;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * The clan's results in the game's tournaments, and the clan prizes they bring.
 *
 * The collector reads the Journal card with the clans' ranking of every
 * tournament (the second card of a tournament, next to the one with the
 * clanmates' ranking) and sends how the clan did: its place, its points and
 * what the clan received. They are kept here for the clan's history.
 *
 * A tournament of the catalogue can name some of those prizes to split among
 * the players. Every event of that tournament not published yet then gets one
 * reward line per prize, with the amount the clan received in that event, beside
 * whatever rewards were added by hand.
 */
class ClanResultService
{
    use LocatorAwareTrait;

    public const MAX_RESULTS = 200;

    /** How a clan prize can be split: its total changes from one event to the next. */
    public const PRIZE_RULES = [EventReward::RULE_PROPORTIONAL, EventReward::RULE_EQUAL];

    /**
     * Check what the collector sent.
     *
     * Body: `{results: [{entry_uid, result_uid, kind, tournament_key, ended_at,
     * clan_id, position, points, clans_listed, rewards: [{item_id, name, amount}]}]}`.
     *
     * @param array<string, mixed> $data Decoded body.
     * @return array{errors: array<string, string>, results: list<array<string, mixed>>}
     */
    public function validate(array $data): array
    {
        $list = $data['results'] ?? null;
        if (!is_array($list) || $list === [] || count($list) > self::MAX_RESULTS) {
            return ['errors' => ['results' => __('Send results as a list of 1 to {0} clan results.', self::MAX_RESULTS)], 'results' => []];
        }

        $errors = [];
        $results = [];
        foreach (array_values($list) as $i => $item) {
            $problem = null;
            $row = is_array($item) ? $this->checkOne($item, $problem) : null;
            if ($row === null) {
                $errors["results.$i"] = $problem ?? __('This clan result is not valid.');
                continue;
            }
            $results[] = $row;
        }

        return ['errors' => $errors, 'results' => $results];
    }

    /**
     * @param array<string, mixed> $item One result.
     * @param string|null $problem Set to what is wrong.
     * @return array<string, mixed>|null
     */
    private function checkOne(array $item, ?string &$problem): ?array
    {
        $uid = is_string($item['entry_uid'] ?? null) ? trim($item['entry_uid']) : '';
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $uid)) {
            $problem = __('The Journal entry id is missing or invalid.');

            return null;
        }
        $resultUid = is_string($item['result_uid'] ?? null) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $item['result_uid'])
            ? $item['result_uid'] : null;
        $key = is_string($item['tournament_key'] ?? null) ? trim($item['tournament_key']) : '';
        [$type] = GameTournamentsTable::parseKey($key);
        if ($type === null || $type === 0) {
            $problem = __('The tournament type is missing or invalid.');

            return null;
        }
        try {
            $endedAt = (new DateTime((string)($item['ended_at'] ?? '')))->setTimezone('UTC');
        } catch (Throwable) {
            $problem = __('The tournament date is not a valid date.');

            return null;
        }
        if (empty($item['ended_at']) || $endedAt->greaterThan(DateTime::now()->addDays(1))) {
            $problem = __('The tournament date is not a valid date.');

            return null;
        }
        $whole = fn (mixed $v): ?int => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int)$v : null;
        $position = $whole($item['position'] ?? null);
        $points = $whole($item['points'] ?? null);

        $rewards = [];
        foreach ((array)($item['rewards'] ?? []) as $reward) {
            $itemId = is_array($reward) ? $whole($reward['item_id'] ?? null) : null;
            $amount = is_array($reward) ? $whole($reward['amount'] ?? null) : null;
            if ($itemId === null || $amount === null || $amount <= 0) {
                continue;
            }
            $rewards[] = [
                'item_id' => $itemId,
                'name' => mb_substr(trim((string)($reward['name'] ?? '')), 0, 120),
                'amount' => $amount,
            ];
        }

        return [
            'entry_uid' => $uid,
            'result_uid' => $resultUid,
            'kind' => mb_substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string)($item['kind'] ?? ''))) ?: 'unknown', 0, 80),
            'game_type' => $type,
            'game_tournament_key' => mb_substr($key, 0, 32),
            'ended_at' => $endedAt,
            'clan_id' => $whole($item['clan_id'] ?? null),
            'position' => $position !== null && $position > 0 ? $position : null,
            'points' => $position !== null && $position > 0 ? $points : null,
            'clans_listed' => max(0, (int)$whole($item['clans_listed'] ?? 0)),
            'rewards' => $rewards,
        ];
    }

    /**
     * Keep the results (sending one again replaces it) and bring their prizes
     * to the events not published yet.
     *
     * @param list<array<string, mixed>> $results From validate().
     * @return array{created: int, updated: int, events: int}
     */
    public function record(array $results): array
    {
        $table = $this->fetchTable('ClanTournamentResults');
        $summary = ['created' => 0, 'updated' => 0, 'events' => 0];
        foreach ($results as $row) {
            /** @var \App\Model\Entity\ClanTournamentResult|null $entity */
            $entity = $table->find()->where(['entry_uid' => $row['entry_uid']])->first();
            $created = $entity === null;
            $entity ??= $table->newEmptyEntity();
            $entity->set([
                'entry_uid' => $row['entry_uid'],
                'result_uid' => $row['result_uid'],
                'kind' => $row['kind'],
                'game_type' => $row['game_type'],
                'game_tournament_key' => $row['game_tournament_key'],
                'ended_at' => $row['ended_at'],
                'clan_id' => $row['clan_id'],
                'position' => $row['position'],
                'points' => $row['points'],
                'clans_listed' => $row['clans_listed'],
                'rewards' => json_encode($row['rewards'], JSON_UNESCAPED_UNICODE),
            ], ['guard' => false]);
            $table->saveOrFail($entity);
            $summary[$created ? 'created' : 'updated']++;
            $summary['events'] += $this->refreshEvents($entity);
        }

        return $summary;
    }

    /**
     * Bring a result's prizes to the events of its tournament not published yet.
     *
     * @param \App\Model\Entity\ClanTournamentResult $result Result.
     * @return int Events changed.
     */
    public function refreshEvents(ClanTournamentResult $result): int
    {
        $window = \App\Model\Table\ClanTournamentResultsTable::SAME_TOURNAMENT_HOURS;
        $events = $this->fetchTable('Events')->find('withoutBanner')
            ->where([
                'Events.criteria' => Event::CRITERIA_IMPORTED,
                'Events.published_at IS' => null,
                'OR' => [
                    ['Events.game_tournament_key' => (string)$result->game_type],
                    ['Events.game_tournament_key LIKE' => $result->game_type . ':%'],
                ],
                'Events.ends_at >=' => $result->ended_at->subHours($window),
                'Events.ends_at <=' => $result->ended_at->addHours($window),
            ])
            ->all();
        $changed = 0;
        foreach ($events as $event) {
            $changed += $this->applyPrizes($event) > 0 ? 1 : 0;
            $this->settleNotice($event);
        }

        return $changed;
    }

    /**
     * Fill the event's prize lines from its tournament: one per clan prize it
     * splits, with what the clan received in it, and one per extra prize the
     * clan gives (Silver or items), which needs no clan result. Lines added by
     * hand are left alone. A published event is not changed.
     *
     * @param \App\Model\Entity\Event $event Imported event.
     * @return int Prize lines written.
     */
    public function applyPrizes(Event $event): int
    {
        if ($event->published_at !== null || $event->game_tournament_id === null) {
            return 0;
        }
        /** @var \App\Model\Entity\GameTournament|null $tournament */
        $tournament = $this->fetchTable('GameTournaments')->find('withoutImage')
            ->where(['GameTournaments.id' => $event->game_tournament_id])
            ->first();
        if ($tournament === null) {
            return 0;
        }

        $rewards = $this->fetchTable('EventRewards');
        $rewards->deleteAll(['event_id' => $event->id, 'OR' => ['game_item_id IS NOT' => null, 'from_catalogue' => true]]);

        $written = 0;
        foreach ($tournament->extraPrizes() as $i => $prize) {
            $line = $rewards->newEntity([
                'item_name' => mb_substr($prize['silver'] ? __('Silver') : $prize['name'], 0, 120),
                'quantity' => min($prize['quantity'], \App\Model\Table\EventRewardsTable::MAX_QUANTITY),
                'rule' => in_array($prize['rule'], self::PRIZE_RULES, true) ? $prize['rule'] : EventReward::RULE_PROPORTIONAL,
                'min_points' => $prize['min_points'],
                'remainder' => $prize['remainder'],
                'sort' => 200 + $i,
            ]);
            $line->set(['event_id' => $event->id, 'is_silver' => $prize['silver'], 'from_catalogue' => true], ['guard' => false]);
            $rewards->saveOrFail($line);
            $written++;
        }

        $selection = $tournament->prizeSelection();
        [$type] = GameTournamentsTable::parseKey($event->game_tournament_key);
        if ($selection === [] || $type === null) {
            return $written;
        }
        $result = $this->fetchTable('ClanTournamentResults')->forTournament($type, $event->ends_at);
        if ($result === null) {
            return $written;
        }

        foreach ($selection as $i => $prize) {
            $amount = $result->amountOf($prize['item_id']);
            if ($amount <= 0) {
                continue;
            }
            $name = $prize['name'];
            foreach ($result->rewardList() as $received) {
                if ($received['item_id'] === $prize['item_id'] && $received['name'] !== '') {
                    $name = $received['name'];
                }
            }
            $line = $rewards->newEntity([
                'item_name' => mb_substr($name !== '' ? $name : __('Item {0}', $prize['item_id']), 0, 120),
                'quantity' => min($amount, \App\Model\Table\EventRewardsTable::MAX_QUANTITY),
                'rule' => in_array($prize['rule'], self::PRIZE_RULES, true) ? $prize['rule'] : EventReward::RULE_PROPORTIONAL,
                'min_points' => $prize['min_points'],
                'remainder' => $prize['remainder'],
                'sort' => 100 + $i,
            ]);
            $line->set(['event_id' => $event->id, 'game_item_id' => $prize['item_id']], ['guard' => false]);
            $rewards->saveOrFail($line);
            $written++;
        }

        return $written;
    }

    /**
     * What keeps the event's clan prizes from being split as chosen: its
     * tournament splits some, and the clan's result has not arrived, or did not
     * bring some of them. Null when nothing is wrong (or nothing is chosen).
     *
     * @param \App\Model\Entity\Event $event Imported event.
     * @return array{no_result: bool, missing: list<string>}|null
     */
    public function prizeProblems(Event $event): ?array
    {
        if ($event->game_tournament_id === null) {
            return null;
        }
        /** @var \App\Model\Entity\GameTournament|null $tournament */
        $tournament = $this->fetchTable('GameTournaments')->find('withoutImage')
            ->where(['GameTournaments.id' => $event->game_tournament_id])
            ->first();
        $selection = $tournament?->prizeSelection() ?? [];
        [$type] = GameTournamentsTable::parseKey($event->game_tournament_key);
        if ($selection === [] || $type === null) {
            return null;
        }
        $result = $this->fetchTable('ClanTournamentResults')->forTournament($type, $event->ends_at);
        if ($result === null) {
            return ['no_result' => true, 'missing' => []];
        }
        $missing = [];
        foreach ($selection as $prize) {
            if ($result->amountOf($prize['item_id']) <= 0) {
                $missing[] = $prize['name'] !== '' ? $prize['name'] : __('Item {0}', $prize['item_id']);
            }
        }

        return $missing === [] ? null : ['no_result' => false, 'missing' => $missing];
    }

    /**
     * Tell the administrators that an event is held for review because of
     * its clan prizes. Said once per event while it is not dealt with.
     *
     * @param \App\Model\Entity\Event $event Event held.
     * @param array{no_result: bool, missing: list<string>} $problems From prizeProblems().
     * @return bool Whether a new alert was raised.
     */
    public function notifyHeld(Event $event, array $problems): bool
    {
        /** @var \App\Model\Table\AdminNotificationsTable $notifications */
        $notifications = $this->fetchTable('AdminNotifications');

        return $notifications->raise(AdminNotification::TYPE_CLAN_PRIZE_MISSING, $this->noticeKey($event), [
            'event_id' => $event->id,
            'event_number' => $event->event_number,
            'event_name' => $event->name,
            'no_result' => $problems['no_result'],
            'missing' => $problems['missing'],
        ]);
    }

    /**
     * Close the event's alert once it no longer applies: published, or its
     * prizes all there now.
     *
     * @param \App\Model\Entity\Event $event Event.
     * @return void
     */
    public function settleNotice(Event $event): void
    {
        if ($event->published_at === null && $this->prizeProblems($event) !== null) {
            return;
        }
        /** @var \App\Model\Table\AdminNotificationsTable $notifications */
        $notifications = $this->fetchTable('AdminNotifications');
        $open = $notifications->find('unread')->where(['dedupe_key' => $this->noticeKey($event)])->first();
        if ($open !== null) {
            $notifications->resolve($open);
        }
    }

    /**
     * @param \App\Model\Entity\Event $event Event.
     * @return string
     */
    private function noticeKey(Event $event): string
    {
        return AdminNotification::TYPE_CLAN_PRIZE_MISSING . ':' . $event->id;
    }

    /**
     * The prizes the clan has received in a tournament type, from its results:
     * each item with its name, how often it came and what it was by place.
     *
     * @param int $gameType Game type.
     * @return list<array{item_id: int, name: string, times: int, by_position: array<int, int>, last_amount: int}>
     */
    public function knownPrizes(int $gameType): array
    {
        $items = [];
        $results = $this->fetchTable('ClanTournamentResults')->find()
            ->where(['game_type' => $gameType])
            ->orderBy(['ended_at' => 'DESC'])
            ->limit(200)
            ->all();
        foreach ($results as $result) {
            foreach ($result->rewardList() as $reward) {
                $id = $reward['item_id'];
                $items[$id] ??= ['item_id' => $id, 'name' => $reward['name'], 'times' => 0, 'by_position' => [], 'last_amount' => $reward['amount']];
                $items[$id]['times']++;
                if ($items[$id]['name'] === '') {
                    $items[$id]['name'] = $reward['name'];
                }
                if ($result->position !== null) {
                    // Newest first: the first amount seen for a place is the latest.
                    $items[$id]['by_position'][(int)$result->position] ??= $reward['amount'];
                }
            }
        }
        foreach ($items as &$item) {
            ksort($item['by_position']);
        }

        return array_values($items);
    }

    /**
     * The game types the catalogue knows, with the name to show for each.
     *
     * @return array<int, string>
     */
    public function typeNames(): array
    {
        $names = [];
        foreach (
            $this->fetchTable('GameTournaments')->find('withoutImage')
                ->orderBy(['GameTournaments.ranking' => 'ASC', 'GameTournaments.id' => 'ASC'])
                ->all() as $entry
        ) {
            /** @var \App\Model\Entity\GameTournament $entry */
            if (!isset($names[$entry->game_type]) || $entry->ranking === GameTournament::RANKING_DEFAULT) {
                $names[$entry->game_type] = $entry->displayName();
            }
        }

        return $names;
    }
}
