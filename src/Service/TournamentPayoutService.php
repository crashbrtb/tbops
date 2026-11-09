<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\Event;
use App\Model\Entity\EventPayout;
use App\Model\Table\BankTransactionsTable;
use App\Utility\GameNumber;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * The Silver the players win in a game tournament.
 *
 * A prize line of an event can be Silver (event_rewards.is_silver), its amount
 * in millions as the clan bank keeps it. It is split like any prize; when the
 * result is published, propose() lists what each player is due, the sum of
 * their Silver lines. The credits wait as pending for an administrator, who
 * leaves out whom they choose and credits the rest.
 *
 * Each credit is an approved deposit in the clan bank with no fee: the
 * player's balance goes up by the amount. Reversing a credited one cancels the
 * deposit and takes the amount back off the balance, even below zero.
 *
 * The amounts are fixed when they are listed: changing the prizes later does
 * not change what was credited; the pending ones follow when they are listed
 * again (the event page does that after its rewards are split again).
 */
class TournamentPayoutService
{
    use LocatorAwareTrait;

    private BankingService $banking;

    /**
     * @param \App\Service\BankingService|null $banking Bank.
     */
    public function __construct(?BankingService $banking = null)
    {
        $this->banking = $banking ?? new BankingService();
    }

    /**
     * The Silver each player of the published result is due, by standing.
     *
     * @param \App\Model\Entity\Event $event Event.
     * @return list<array{member_id: int|null, player: string, amount: int}>
     */
    public function dueOf(Event $event): array
    {
        $silver = $this->fetchTable('EventRewards')->find()->select(['id'])
            ->where(['event_id' => $event->id, 'is_silver' => true])
            ->disableHydration()->all()->extract('id')->toList();
        if ($silver === []) {
            return [];
        }
        $rows = $this->fetchTable('EventRewardAllocations')->find()
            ->select([
                'member_id' => 'EventStandings.member_id',
                'player' => 'EventStandings.player',
                'position' => 'EventStandings.position',
                'amount' => 'SUM(EventRewardAllocations.amount)',
            ])
            ->innerJoinWith('EventStandings')
            ->where(['EventRewardAllocations.event_reward_id IN' => $silver, 'EventStandings.event_id' => $event->id])
            ->groupBy(['EventStandings.id', 'EventStandings.member_id', 'EventStandings.player', 'EventStandings.position'])
            ->orderBy(['EventStandings.position' => 'ASC'])
            ->disableHydration()
            ->all();
        $out = [];
        foreach ($rows as $row) {
            if ((int)$row['amount'] > 0) {
                $out[] = [
                    'member_id' => $row['member_id'] !== null ? (int)$row['member_id'] : null,
                    'player' => (string)$row['player'],
                    'amount' => (int)$row['amount'],
                ];
            }
        }

        return $out;
    }

    /**
     * Lists the Silver of a published event. The pending credits are listed
     * again; the ones left out, credited or reversed are kept as they are.
     *
     * @param \App\Model\Entity\Event $event Event.
     * @return int Pending credits after listing.
     */
    public function propose(Event $event): int
    {
        $payouts = $this->fetchTable('EventPayouts');
        $payouts->deleteAll(['event_id' => $event->id, 'status' => EventPayout::STATUS_PENDING]);
        if ($event->get('published_at') === null) {
            return 0;
        }
        $kept = $payouts->find()->select(['player'])->where(['event_id' => $event->id])
            ->disableHydration()->all()->extract('player')->map(fn ($p): string => mb_strtolower((string)$p))->toList();

        $count = 0;
        foreach ($this->dueOf($event) as $due) {
            if (in_array(mb_strtolower($due['player']), $kept, true)) {
                continue;
            }
            $payout = $payouts->newEmptyEntity();
            $payout->set([
                'event_id' => $event->id,
                'member_id' => $due['member_id'],
                'player' => $due['player'],
                'amount' => $due['amount'],
                'status' => EventPayout::STATUS_PENDING,
                'note' => $due['member_id'] ? null : __('Not a member of the clan bank'),
            ], ['guard' => false]);
            $payouts->saveOrFail($payout);
            $kept[] = mb_strtolower($due['player']);
            $count++;
        }

        return $count;
    }

    /**
     * The Silver credits of an event, the pending ones first, then by player.
     *
     * @param \App\Model\Entity\Event $event Event.
     * @return list<\App\Model\Entity\EventPayout>
     */
    public function payoutsOf(Event $event): array
    {
        $order = [EventPayout::STATUS_PENDING => 0, EventPayout::STATUS_CREDITED => 1,
            EventPayout::STATUS_WAIVED => 2, EventPayout::STATUS_REVERSED => 3];
        $list = $this->fetchTable('EventPayouts')->find()->where(['event_id' => $event->id])->all()->toList();
        usort($list, fn (EventPayout $a, EventPayout $b): int => [$order[$a->status] ?? 9, mb_strtolower($a->player)]
            <=> [$order[$b->status] ?? 9, mb_strtolower($b->player)]);

        return $list;
    }

    /**
     * Credits every pending Silver of the event that has a member: one approved
     * deposit each in the clan bank.
     *
     * @param \App\Model\Entity\Event $event Event.
     * @param int $userId Administrator.
     * @return array{credited: int, total: int, no_member: int}
     */
    public function credit(Event $event, int $userId): array
    {
        $out = ['credited' => 0, 'total' => 0, 'no_member' => 0];
        $pending = $this->fetchTable('EventPayouts')->find()
            ->where(['event_id' => $event->id, 'status' => EventPayout::STATUS_PENDING])->all();
        foreach ($pending as $payout) {
            /** @var \App\Model\Entity\EventPayout $payout */
            if (!$payout->member_id) {
                $out['no_member']++;
                continue;
            }
            $this->creditOne($event, $payout, $userId);
            $out['credited']++;
            $out['total'] += (int)$payout->amount;
        }

        return $out;
    }

    /**
     * @param \App\Model\Entity\Event $event Event.
     * @param \App\Model\Entity\EventPayout $payout Pending credit with a member.
     * @param int $userId Administrator.
     * @return void
     */
    private function creditOne(Event $event, EventPayout $payout, int $userId): void
    {
        $payouts = $this->fetchTable('EventPayouts');
        $transactions = $this->fetchTable('BankTransactions');
        $transactions->getConnection()->transactional(function () use ($event, $payout, $userId, $payouts, $transactions): void {
            $this->banking->addToBalance((int)$payout->member_id, (int)$payout->amount);
            $transaction = $transactions->newEmptyEntity();
            $transaction->set([
                'member_id' => $payout->member_id,
                'user_id' => $userId,
                'type' => BankTransactionsTable::TYPE_DEPOSIT,
                'amount' => $payout->amount,
                'fee' => 0,
                'final_amount' => $payout->amount,
                'description' => mb_substr(__('Tournament prize: {0} - {1}', (string)$event->name, GameNumber::silver((int)$payout->amount)), 0, 512),
                'status' => BankTransactionsTable::STATUS_APPROVED,
            ], ['guard' => false]);
            $transactions->saveOrFail($transaction);
            $payout->set([
                'status' => EventPayout::STATUS_CREDITED,
                'bank_transaction_id' => $transaction->id,
                'user_id' => $userId,
                'note' => null,
            ], ['guard' => false]);
            $payouts->saveOrFail($payout);
        });
    }

    /**
     * A pending credit is left out; a left-out one goes back to pending.
     *
     * @param \App\Model\Entity\EventPayout $payout Credit.
     * @param int $userId Administrator.
     * @param bool $waive Leave it out, or take that back.
     * @return bool Whether it changed.
     */
    public function waive(EventPayout $payout, int $userId, bool $waive = true): bool
    {
        $from = $waive ? EventPayout::STATUS_PENDING : EventPayout::STATUS_WAIVED;
        if ($payout->status !== $from) {
            return false;
        }
        $payout->set(['status' => $waive ? EventPayout::STATUS_WAIVED : EventPayout::STATUS_PENDING, 'user_id' => $userId], ['guard' => false]);
        $this->fetchTable('EventPayouts')->saveOrFail($payout);

        return true;
    }

    /**
     * Takes a credited Silver back: its deposit is canceled and the amount
     * leaves the player's balance, even below zero.
     *
     * @param \App\Model\Entity\EventPayout $payout Credited payout.
     * @param int $userId Administrator.
     * @return bool Whether it was reversed.
     */
    public function reverse(EventPayout $payout, int $userId): bool
    {
        if ($payout->status !== EventPayout::STATUS_CREDITED || !$payout->member_id) {
            return false;
        }
        $payouts = $this->fetchTable('EventPayouts');
        $transactions = $this->fetchTable('BankTransactions');
        $transactions->getConnection()->transactional(function () use ($payout, $userId, $payouts, $transactions): void {
            $this->banking->chargeDebt((int)$payout->member_id, (int)$payout->amount);
            if ($payout->bank_transaction_id) {
                $transactions->updateAll(
                    ['status' => BankTransactionsTable::STATUS_CANCELED, 'modified' => DateTime::now()],
                    ['id' => $payout->bank_transaction_id]
                );
            }
            $payout->set(['status' => EventPayout::STATUS_REVERSED, 'user_id' => $userId], ['guard' => false]);
            $payouts->saveOrFail($payout);
        });

        return true;
    }

    /**
     * When an event's result is withdrawn, the credits not made go with it.
     *
     * @param \App\Model\Entity\Event $event Event.
     * @return void
     */
    public function withdraw(Event $event): void
    {
        $this->fetchTable('EventPayouts')->deleteAll([
            'event_id' => $event->id,
            'status IN' => [EventPayout::STATUS_PENDING, EventPayout::STATUS_WAIVED],
        ]);
    }
}
