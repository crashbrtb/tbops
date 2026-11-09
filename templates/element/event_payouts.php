<?php
/**
 * The Silver won in a published tournament, for an administrator: what each
 * player is due and the actions to leave out, credit or reverse it.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Event $event
 * @var list<\App\Model\Entity\EventPayout> $payouts
 */

use App\Model\Entity\EventPayout;

$statusLabels = EventPayout::statusOptions();
$badges = [
    EventPayout::STATUS_PENDING => 'badge-warning',
    EventPayout::STATUS_CREDITED => 'badge-success',
    EventPayout::STATUS_WAIVED => 'badge-secondary',
    EventPayout::STATUS_REVERSED => 'badge-info',
];
$pending = array_filter($payouts, fn (EventPayout $p): bool => $p->status === EventPayout::STATUS_PENDING);
$pendingTotal = array_sum(array_map(fn (EventPayout $p): int => (int)$p->amount, $pending));
$button = 'btn btn-outline-secondary btn-xs';
?>
<div class="event-standings-card" id="payouts">
    <div class="event-standings-header">
        <h2 class="event-standings-title">
            <i class="fas fa-coins text-warning"></i> <?= __('Silver prize') ?>
            <small class="text-muted" style="font-size: .8rem;">&middot; <?= __('Credited to the players\' accounts in the clan bank once confirmed.') ?></small>
        </h2>
        <div class="d-flex flex-wrap" style="gap: 6px;">
            <?= $this->Form->postLink(
                '<i class="fas fa-sync-alt mr-1"></i>' . __('List again'),
                ['controller' => 'EventPayouts', 'action' => 'propose', $event->id],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false,
                 'title' => __('Lists the pending Silver again from the split of the rewards.')]
            ) ?>
            <?php if ($pending !== []): ?>
                <?= $this->Form->postLink(
                    '<i class="fas fa-hand-holding-usd mr-1"></i>' . __('Credit {0} player(s) ({1})', count($pending), $this->silver($pendingTotal)),
                    ['controller' => 'EventPayouts', 'action' => 'credit', $event->id],
                    ['class' => 'btn btn-success btn-sm', 'escape' => false,
                     'confirm' => __('Credit the pending Silver to the players\' accounts in the clan bank?')]
                ) ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th><?= __('Player') ?></th>
                    <th class="text-right"><?= __('Silver') ?></th>
                    <th><?= __('Status') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payouts as $payout): ?>
                    <tr>
                        <td><?= h($payout->player) ?></td>
                        <td class="text-right font-weight-bold"><?= $this->silver($payout->amount) ?></td>
                        <td>
                            <span class="badge <?= $badges[$payout->status] ?? 'badge-light' ?>"><?= h($statusLabels[$payout->status] ?? $payout->status) ?></span>
                            <?php if ($payout->note): ?>
                                <small class="text-muted d-block"><?= h($payout->note) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-right text-nowrap">
                            <?php if ($payout->status === EventPayout::STATUS_PENDING): ?>
                                <?= $this->Form->postLink(__('Do not credit'), ['controller' => 'EventPayouts', 'action' => 'waive', $payout->id], ['class' => $button]) ?>
                            <?php elseif ($payout->status === EventPayout::STATUS_WAIVED): ?>
                                <?= $this->Form->postLink(__('Credit again'), ['controller' => 'EventPayouts', 'action' => 'waive', $payout->id, '?' => ['undo' => 1]], ['class' => $button]) ?>
                            <?php elseif ($payout->status === EventPayout::STATUS_CREDITED): ?>
                                <?= $this->Form->postLink(__('Take back'), ['controller' => 'EventPayouts', 'action' => 'reverse', $payout->id], [
                                    'class' => $button, 'confirm' => __('Take {0} back from {1}? Their balance may go below zero.', $this->silver($payout->amount), $payout->player),
                                ]) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
