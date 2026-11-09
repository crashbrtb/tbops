<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Event;
use App\Model\Entity\EventFine;
use App\Model\Entity\GameTournament;
use App\Model\Table\BankTransactionsTable;
use App\Utility\GameNumber;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Fines for the players who miss the goal of a game tournament.
 *
 * The catalogue entry of the tournament holds the rule (fine_mode, fine_amount,
 * fine_tiers):
 * Every amount is Silver in whole millions, as the clan bank keeps it (1,000 is
 * 1 billion Silver): the rule only takes whole millions, and a fine is never
 * less than 1 million.
 *
 * - none: no fine;
 * - fixed: every player who missed the goal owes the amount;
 * - proportional: the amount times the share of the goal that was missing, so a
 *   player at 60% of the goal owes 40% of it, one who scored nothing all of it,
 *   rounded up to the whole million;
 * - tiers: ranges of the share of the goal reached, each with its own amount
 *   (0-10%: 1B, 10-50%: 500M, 50-100%: 100M). A range holds its start and not
 *   its end, so a player at exactly 10% falls in 10-50%. A share no range holds
 *   is not fined.
 *
 * When the event's result is published, propose() lists the fines from the
 * published standings: the eligible players whose goal_met is false. They wait
 * as pending for an administrator, who waives whom they choose and charges the
 * rest.
 *
 * Each charge is an approved withdrawal in the clan bank whose amount is 0 and
 * whose fee is the fine: the player's balance goes down by the fine, even below
 * zero (a debt they pay by depositing), and the fine joins the fees the bank
 * collects for the clan. So the silver held stays the players' balances plus the
 * fees: a player fined 500 owes -500, deposits 500 in the game, and ends at 0
 * while the clan's fees keep the 500.
 *
 * Refunding a charged fine cancels its withdrawal: the player gets the amount
 * back and it leaves the clan's fees (only approved fees count).
 *
 * What a player owes is their pending fines plus the debt of the charged ones:
 * the part of their negative balance the charged fines account for. A player who
 * left the clan owing can have it given up as lost: the pending fines are closed
 * as lost, and the debt is taken off both their balance and the fees of the
 * charged fines (newest first), so the clan's fees keep only what was paid.
 *
 * The amount of each fine is fixed when it is listed: changing the rule later
 * does not change past events, unless their fines are listed again.
 */
class TournamentFineService
{
    use LocatorAwareTrait;

    public const MODE_NONE = 'none';
    public const MODE_FIXED = 'fixed';
    public const MODE_PROPORTIONAL = 'proportional';
    public const MODE_TIERS = 'tiers';

    /**
     * Most ranges a tournament can have.
     */
    public const MAX_TIERS = 20;

    private BankingService $banking;

    /**
     * @param \App\Service\BankingService|null $banking Bank.
     */
    public function __construct(?BankingService $banking = null)
    {
        $this->banking = $banking ?? new BankingService();
    }

    /**
     * @return array<string, string>
     */
    public static function modeOptions(): array
    {
        return [
            self::MODE_NONE => __('No fine'),
            self::MODE_FIXED => __('Fixed amount'),
            self::MODE_PROPORTIONAL => __('In proportion to what was missing'),
            self::MODE_TIERS => __('By ranges of the goal reached'),
        ];
    }

    /**
     * The fine of a player with these points and this goal.
     *
     * @param string $mode Rule.
     * @param int $amount Amount of the rule.
     * @param int $points Points scored.
     * @param int $goal Goal.
     * @param list<array{from: int, to: int, amount: int}> $tiers Ranges (tiers mode).
     * @return int 0 when nothing is owed.
     */
    public static function amountFor(string $mode, int $amount, int $points, int $goal, array $tiers = []): int
    {
        if ($goal <= 0 || $points >= $goal) {
            return 0;
        }
        if ($mode === self::MODE_TIERS) {
            return self::tierFor($tiers, $points, $goal)['amount'] ?? 0;
        }
        if ($amount <= 0) {
            return 0;
        }

        return match ($mode) {
            self::MODE_FIXED => $amount,
            // Whole millions, rounded up, so a miss never costs less than 1 million.
            self::MODE_PROPORTIONAL => max(1, intdiv($amount * ($goal - max(0, $points)) + $goal - 1, $goal)),
            default => 0,
        };
    }

    /**
     * The range a player with these points falls in: the one whose start is at
     * or below the share of the goal reached and whose end is above it.
     *
     * @param list<array{from: int, to: int, amount: int}> $tiers Ranges.
     * @param int $points Points scored.
     * @param int $goal Goal.
     * @return array{from: int, to: int, amount: int}|null
     */
    public static function tierFor(array $tiers, int $points, int $goal): ?array
    {
        if ($goal <= 0) {
            return null;
        }
        // Compared in whole points, so 10% of 1,000 is exactly 100 with no rounding.
        $scaled = 100 * max(0, $points);
        foreach ($tiers as $tier) {
            if ($scaled >= $tier['from'] * $goal && $scaled < $tier['to'] * $goal) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * The ranges as the form posts them: the rows left blank are dropped, the
     * others cast to whole numbers (-1 for what is not one, a fraction included) and sorted by their
     * start. Whether they can be saved is tierProblem()'s job.
     *
     * @param array<mixed> $rows Rows of from, to and amount.
     * @return list<array{from: int, to: int, amount: int}>
     */
    public static function marshalTiers(array $rows): array
    {
        // Whole numbers only, as the bank takes them: a fraction of a million is refused.
        $number = fn (string $v): int => preg_match('/^\d{1,12}$/', $v) ? (int)$v : -1;
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $values = array_map(
                fn ($v): string => trim((string)$v),
                [$row['from'] ?? '', $row['to'] ?? '', $row['amount'] ?? '']
            );
            if ($values === ['', '', '']) {
                continue;
            }
            $out[] = ['from' => $number($values[0]), 'to' => $number($values[1]), 'amount' => $number($values[2])];
        }
        usort($out, fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);

        return $out;
    }

    /**
     * What is wrong with the ranges, or null when they can be saved.
     *
     * @param list<array{from: int, to: int, amount: int}> $tiers Ranges, sorted by their start.
     * @return string|null
     */
    public static function tierProblem(array $tiers): ?string
    {
        if ($tiers === []) {
            return __('Add at least one range, or choose "No fine".');
        }
        if (count($tiers) > self::MAX_TIERS) {
            return __('At most {0} ranges.', self::MAX_TIERS);
        }
        $end = null;
        foreach ($tiers as $tier) {
            if ($tier['from'] < 0 || $tier['to'] < 0 || $tier['amount'] < 0) {
                return __('The ranges take whole numbers: percentages from 0 to 100, and the fine in millions of Silver.');
            }
            if ($tier['to'] > 100 || $tier['from'] >= $tier['to']) {
                return __('The range {0}% to {1}% is not valid: it must start below where it ends, between 0% and 100%.', $tier['from'], $tier['to']);
            }
            if ($tier['amount'] <= 0) {
                return __('Set the fine of the range {0}% to {1}%, at least 1 million Silver.', $tier['from'], $tier['to']);
            }
            if ($end !== null && $tier['from'] < $end) {
                return __('The range {0}% to {1}% overlaps the one before it.', $tier['from'], $tier['to']);
            }
            $end = $tier['to'];
        }

        return null;
    }

    /**
     * The rule of a catalogue entry: [mode, amount, ranges], mode none when it
     * fines nothing. The ranges are only given in the tiers mode, the amount only
     * in the others.
     *
     * @param \App\Model\Entity\GameTournament|null $entry Entry.
     * @return array{0: string, 1: int, 2: list<array{from: int, to: int, amount: int}>}
     */
    public static function ruleOf(?GameTournament $entry): array
    {
        $mode = (string)($entry?->get('fine_mode') ?: self::MODE_NONE);
        if ($mode === self::MODE_TIERS) {
            $tiers = $entry?->fineTiers() ?? [];

            return self::tierProblem($tiers) === null ? [$mode, 0, $tiers] : [self::MODE_NONE, 0, []];
        }
        $amount = (int)($entry?->get('fine_amount') ?? 0);
        if (!in_array($mode, [self::MODE_FIXED, self::MODE_PROPORTIONAL], true) || $amount <= 0) {
            return [self::MODE_NONE, 0, []];
        }

        return [$mode, $amount, []];
    }

    /**
     * The rule an event is fined by: its tournament's.
     *
     * @param \App\Model\Entity\Event $event Event.
     * @return array{0: string, 1: int, 2: list<array{from: int, to: int, amount: int}>}
     */
    public function ruleFor(Event $event): array
    {
        if (!$event->get('game_tournament_id')) {
            return [self::MODE_NONE, 0, []];
        }
        /** @var \App\Model\Entity\GameTournament|null $entry */
        $entry = $this->fetchTable('GameTournaments')->find('withoutImage')
            ->where(['GameTournaments.id' => $event->get('game_tournament_id')])
            ->first();

        return self::ruleOf($entry);
    }

    /**
     * Lists the fines of a published event from its standings. The pending ones are
     * listed again (the rule or the standings may have changed); the waived,
     * charged and refunded ones are kept as they are.
     *
     * @param \App\Model\Entity\Event $event Event.
     * @return int Pending fines after listing.
     */
    public function propose(Event $event): int
    {
        $fines = $this->fetchTable('EventFines');
        $fines->deleteAll(['event_id' => $event->id, 'status' => EventFine::STATUS_PENDING]);
        if ($event->get('published_at') === null) {
            return 0;
        }
        [$mode, $amount, $tiers] = $this->ruleFor($event);
        if ($mode === self::MODE_NONE) {
            return 0;
        }
        $kept = $fines->find()->select(['player'])->where(['event_id' => $event->id])
            ->disableHydration()->all()->extract('player')->map(fn ($p): string => mb_strtolower((string)$p))->toList();

        $standings = $this->fetchTable('EventStandings')->find()
            ->where(['event_id' => $event->id, 'eligible' => true, 'goal_met' => false])
            ->orderBy(['position' => 'ASC'])
            ->all();
        $count = 0;
        foreach ($standings as $standing) {
            $player = (string)$standing->get('player');
            $owed = self::amountFor($mode, $amount, (int)$standing->get('points'), (int)$standing->get('goal'), $tiers);
            if ($owed <= 0 || in_array(mb_strtolower($player), $kept, true)) {
                continue;
            }
            $fine = $fines->newEmptyEntity();
            $fine->set([
                'event_id' => $event->id,
                'member_id' => $standing->get('member_id'),
                'player' => $player,
                'points' => (int)$standing->get('points'),
                'goal' => (int)$standing->get('goal'),
                'amount' => $owed,
                'status' => EventFine::STATUS_PENDING,
                'note' => $standing->get('member_id') ? null : __('Not a member of the clan bank'),
            ], ['guard' => false]);
            $fines->saveOrFail($fine);
            $count++;
        }

        return $count;
    }

    /**
     * The fines of an event, the pending ones first, then by player.
     *
     * @param \App\Model\Entity\Event $event Event.
     * @return list<\App\Model\Entity\EventFine>
     */
    public function finesOf(Event $event): array
    {
        $order = [EventFine::STATUS_PENDING => 0, EventFine::STATUS_CHARGED => 1, EventFine::STATUS_WAIVED => 2,
            EventFine::STATUS_REFUNDED => 3, EventFine::STATUS_LOST => 4];
        $list = $this->fetchTable('EventFines')->find()->where(['event_id' => $event->id])->all()->toList();
        usort($list, fn (EventFine $a, EventFine $b): int => [$order[$a->status] ?? 9, mb_strtolower($a->player)]
            <=> [$order[$b->status] ?? 9, mb_strtolower($b->player)]);

        return $list;
    }

    /**
     * Charges every pending fine of the event that has a member: one approved
     * withdrawal each in the clan bank.
     *
     * @param \App\Model\Entity\Event $event Event.
     * @param int $userId Administrator.
     * @return array{charged: int, total: int, no_member: int}
     */
    public function charge(Event $event, int $userId): array
    {
        $out = ['charged' => 0, 'total' => 0, 'no_member' => 0];
        $fines = $this->fetchTable('EventFines');
        $pending = $fines->find()->where(['event_id' => $event->id, 'status' => EventFine::STATUS_PENDING])->all();
        foreach ($pending as $fine) {
            /** @var \App\Model\Entity\EventFine $fine */
            if (!$fine->member_id) {
                $out['no_member']++;
                continue;
            }
            $this->chargeOne($event, $fine, $userId);
            $out['charged']++;
            $out['total'] += (int)$fine->amount;
        }

        return $out;
    }

    /**
     * @param \App\Model\Entity\Event $event Event.
     * @param \App\Model\Entity\EventFine $fine Pending fine with a member.
     * @param int $userId Administrator.
     * @return void
     */
    private function chargeOne(Event $event, EventFine $fine, int $userId): void
    {
        $fines = $this->fetchTable('EventFines');
        $transactions = $this->fetchTable('BankTransactions');
        $transactions->getConnection()->transactional(function () use ($event, $fine, $userId, $fines, $transactions): void {
            $this->banking->chargeDebt((int)$fine->member_id, (int)$fine->amount);
            $transaction = $transactions->newEmptyEntity();
            $transaction->set([
                'member_id' => $fine->member_id,
                'user_id' => $userId,
                'type' => BankTransactionsTable::TYPE_WITHDRAWAL,
                // Nothing leaves the bank: the fine is a fee the clan collects.
                'amount' => 0,
                'fee' => $fine->amount,
                'final_amount' => $fine->amount,
                'description' => mb_substr(__('Tournament fine: {0} - {1} of {2} points ({3}%)', (string)$event->name,
                    number_format($fine->points), number_format($fine->goal), $fine->reachedPercent()), 0, 512),
                'status' => BankTransactionsTable::STATUS_APPROVED,
            ], ['guard' => false]);
            $transactions->saveOrFail($transaction);
            $fine->set([
                'status' => EventFine::STATUS_CHARGED,
                'bank_transaction_id' => $transaction->id,
                'user_id' => $userId,
                'note' => null,
            ], ['guard' => false]);
            $fines->saveOrFail($fine);
        });
    }

    /**
     * A pending fine is waived; a waived one goes back to pending.
     *
     * @param \App\Model\Entity\EventFine $fine Fine.
     * @param int $userId Administrator.
     * @param bool $waive Waive it, or take the waiver back.
     * @return bool Whether it changed.
     */
    public function waive(EventFine $fine, int $userId, bool $waive = true): bool
    {
        $from = $waive ? EventFine::STATUS_PENDING : EventFine::STATUS_WAIVED;
        if ($fine->status !== $from) {
            return false;
        }
        $fine->set(['status' => $waive ? EventFine::STATUS_WAIVED : EventFine::STATUS_PENDING, 'user_id' => $userId], ['guard' => false]);
        $this->fetchTable('EventFines')->saveOrFail($fine);

        return true;
    }

    /**
     * Gives a charged fine back: its withdrawal is canceled, so the amount returns
     * to the player's account and leaves the clan's fees.
     *
     * @param \App\Model\Entity\EventFine $fine Charged fine.
     * @param int $userId Administrator.
     * @return bool Whether it was refunded.
     */
    public function refund(EventFine $fine, int $userId): bool
    {
        if ($fine->status !== EventFine::STATUS_CHARGED || !$fine->member_id) {
            return false;
        }
        $fines = $this->fetchTable('EventFines');
        $transactions = $this->fetchTable('BankTransactions');
        $transactions->getConnection()->transactional(function () use ($fine, $userId, $fines, $transactions): void {
            $this->banking->addToBalance((int)$fine->member_id, (int)$fine->amount);
            if ($fine->bank_transaction_id) {
                $transactions->updateAll(
                    ['status' => BankTransactionsTable::STATUS_CANCELED, 'modified' => \Cake\I18n\DateTime::now()],
                    ['id' => $fine->bank_transaction_id]
                );
            }
            $fine->set(['status' => EventFine::STATUS_REFUNDED, 'user_id' => $userId], ['guard' => false]);
            $fines->saveOrFail($fine);
        });

        return true;
    }

    /**
     * The fines, newest first, narrowed by `event_id`, `status`, `player` (part of
     * the name) and `member_id`, with their event and member.
     *
     * @param array<string, mixed> $filters Filters.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function search(array $filters = []): \Cake\ORM\Query\SelectQuery
    {
        $query = $this->fetchTable('EventFines')->find()
            ->contain([
                'Events' => ['fields' => ['Events.id', 'Events.name', 'Events.ends_at']],
                'Members' => ['fields' => ['Members.id', 'Members.player', 'Members.active']],
            ])
            ->orderBy(['Events.ends_at' => 'DESC', 'EventFines.player' => 'ASC']);
        if ((int)($filters['event_id'] ?? 0) > 0) {
            $query->where(['EventFines.event_id' => (int)$filters['event_id']]);
        }
        if (array_key_exists((string)($filters['status'] ?? ''), EventFine::statusOptions())) {
            $query->where(['EventFines.status' => (string)$filters['status']]);
        }
        $player = trim((string)($filters['player'] ?? ''));
        if ($player !== '') {
            $query->where(['EventFines.player LIKE' => '%' . str_replace(['%', '_'], ['\%', '\_'], $player) . '%']);
        }
        if ((int)($filters['member_id'] ?? 0) > 0) {
            $query->where(['EventFines.member_id' => (int)$filters['member_id']]);
        }

        return $query;
    }

    /**
     * The total of the fines in each status, and how much of it was given up.
     *
     * @return array<string, array{count: int, amount: int}>
     */
    public function totals(): array
    {
        $out = [];
        foreach (array_keys(EventFine::statusOptions()) as $status) {
            $out[$status] = ['count' => 0, 'amount' => 0];
        }
        $rows = $this->fetchTable('EventFines')->find()
            ->select(['status', 'count' => 'COUNT(*)', 'amount' => 'SUM(amount)', 'lost' => 'SUM(COALESCE(lost_amount, 0))'])
            ->groupBy(['status'])
            ->disableHydration()
            ->all();
        foreach ($rows as $row) {
            $out[(string)$row['status']] = ['count' => (int)$row['count'], 'amount' => (int)$row['amount']];
            if ($row['status'] === EventFine::STATUS_LOST) {
                $out[EventFine::STATUS_LOST]['amount'] = (int)$row['lost'];
            }
        }

        return $out;
    }

    /**
     * Who owes fines: the pending ones and the debt of the charged ones, per
     * player, the ones who left the clan first, then the biggest debts.
     *
     * @return list<array{key: string, member_id: int|null, player: string, in_clan: bool, pending: int, debt: int, owed: int, fines: int}>
     */
    public function owing(): array
    {
        $fines = $this->fetchTable('EventFines')->find()
            ->contain(['Members' => ['fields' => ['Members.id', 'Members.player', 'Members.active']]])
            ->where(['EventFines.status IN' => [EventFine::STATUS_PENDING, EventFine::STATUS_CHARGED]])
            ->all();
        $players = [];
        foreach ($fines as $fine) {
            /** @var \App\Model\Entity\EventFine $fine */
            $key = $fine->member_id ? 'm' . $fine->member_id : 'p' . mb_strtolower($fine->player);
            $players[$key] ??= [
                'key' => $key,
                'member_id' => $fine->member_id,
                'player' => $fine->member?->player ?? $fine->player,
                'in_clan' => $fine->member !== null && (int)$fine->member->active === 1,
                'pending' => 0,
                'charged' => 0,
                'fines' => 0,
            ];
            $players[$key][$fine->status === EventFine::STATUS_PENDING ? 'pending' : 'charged'] += (int)$fine->amount;
            $players[$key]['fines']++;
        }
        $out = [];
        foreach ($players as $row) {
            $debt = 0;
            if ($row['member_id'] && $row['charged'] > 0) {
                $debt = min($row['charged'], max(0, -$this->banking->getBalance((int)$row['member_id'])));
            }
            unset($row['charged']);
            $row['debt'] = $debt;
            $row['owed'] = $row['pending'] + $debt;
            if ($row['owed'] > 0) {
                $out[] = $row;
            }
        }
        usort($out, fn (array $a, array $b): int => [$a['in_clan'], -$a['owed']] <=> [$b['in_clan'], -$b['owed']]);

        return $out;
    }

    /**
     * What a player owes, by member id or, without one, by name.
     *
     * @param string $key `m<member id>` or `p<lowercase name>`, as owing() gives it.
     * @return array{key: string, member_id: int|null, player: string, in_clan: bool, pending: int, debt: int, owed: int, fines: int}|null
     */
    public function owingOf(string $key): ?array
    {
        foreach ($this->owing() as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Gives up what a player owes: their pending fines are closed as lost, and the
     * debt of their charged fines is taken off their balance and off those fines'
     * fees, newest first, so the clan's fees keep only what the player paid.
     *
     * @param string $key `m<member id>` or `p<lowercase name>`.
     * @param int $userId Administrator.
     * @return int How much was given up.
     */
    public function writeOff(string $key, int $userId): int
    {
        $owing = $this->owingOf($key);
        if ($owing === null) {
            return 0;
        }
        $fines = $this->fetchTable('EventFines');
        $transactions = $this->fetchTable('BankTransactions');
        $lost = 0;
        $transactions->getConnection()->transactional(function () use ($owing, $userId, $fines, $transactions, &$lost): void {
            $where = $owing['member_id']
                ? ['EventFines.member_id' => $owing['member_id']]
                : ['EventFines.member_id IS' => null, 'EventFines.player' => $owing['player']];

            foreach ($fines->find()->where($where + ['EventFines.status' => EventFine::STATUS_PENDING])->all() as $fine) {
                $fine->set(['status' => EventFine::STATUS_LOST, 'lost_amount' => $fine->amount, 'user_id' => $userId], ['guard' => false]);
                $fines->saveOrFail($fine);
                $lost += (int)$fine->amount;
            }

            $debt = $owing['debt'];
            $charged = $fines->find()->where($where + ['EventFines.status' => EventFine::STATUS_CHARGED])
                ->orderBy(['EventFines.created' => 'DESC', 'EventFines.id' => 'DESC'])->all();
            foreach ($charged as $fine) {
                if ($debt <= 0) {
                    break;
                }
                $part = min($debt, (int)$fine->amount);
                $debt -= $part;
                $lost += $part;
                $this->banking->addToBalance((int)$fine->member_id, $part);
                if ($fine->bank_transaction_id) {
                    $transaction = $transactions->get($fine->bank_transaction_id);
                    $paid = max(0, (int)$transaction->fee - $part);
                    $transaction->set([
                        'fee' => $paid,
                        'final_amount' => $paid,
                        'status' => $paid > 0 ? $transaction->status : \App\Model\Table\BankTransactionsTable::STATUS_CANCELED,
                        'description' => mb_substr((string)$transaction->description . ' - ' . __('{0} given up as lost', GameNumber::silver($part)), 0, 512),
                    ], ['guard' => false]);
                    $transactions->saveOrFail($transaction);
                }
                $fine->set([
                    'status' => EventFine::STATUS_LOST,
                    'lost_amount' => $part,
                    'user_id' => $userId,
                    'note' => $part < (int)$fine->amount ? __('{0} paid, {1} given up', GameNumber::silver((int)$fine->amount - $part), GameNumber::silver($part)) : null,
                ], ['guard' => false]);
                $fines->saveOrFail($fine);
            }
        });

        return $lost;
    }

    /**
     * The fines of a player, newest first, by the name the goal pages use.
     *
     * @param string $player Name.
     * @return list<\App\Model\Entity\EventFine>
     */
    public function finesOfPlayer(string $player): array
    {
        $member = $this->fetchTable('Members')->find()->select(['id'])->where(['player' => $player])->first();
        $query = $this->search()->limit(100);
        $query->where($member !== null
            ? ['OR' => ['EventFines.member_id' => $member->id, 'EventFines.player' => $player]]
            : ['EventFines.player' => $player]);

        return $query->all()->toList();
    }

    /**
     * When an event's result is withdrawn, the fines not charged go with it.
     *
     * @param \App\Model\Entity\Event $event Event.
     * @return void
     */
    public function withdraw(Event $event): void
    {
        $this->fetchTable('EventFines')->deleteAll([
            'event_id' => $event->id,
            'status IN' => [EventFine::STATUS_PENDING, EventFine::STATUS_WAIVED],
        ]);
    }
}
