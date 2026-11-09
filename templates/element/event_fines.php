<?php
/**
 * The fines of a published tournament, for an administrator: who missed the goal,
 * what each owes, and the actions to waive, charge or refund them.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Event $event
 * @var list<\App\Model\Entity\EventFine> $fines
 * @var array{0: string, 1: int, 2: list<array{from: int, to: int, amount: int}>} $fineRule [mode, amount, ranges]
 */

use App\Model\Entity\EventFine;
use App\Service\TournamentFineService;

[$mode] = $fineRule;
$statusLabels = EventFine::statusOptions();
$badges = [
    EventFine::STATUS_PENDING => 'badge-warning',
    EventFine::STATUS_CHARGED => 'badge-danger',
    EventFine::STATUS_WAIVED => 'badge-secondary',
    EventFine::STATUS_REFUNDED => 'badge-info',
    EventFine::STATUS_LOST => 'badge-dark',
];
$pending = array_filter($fines, fn (EventFine $f): bool => $f->status === EventFine::STATUS_PENDING);
$pendingTotal = array_sum(array_map(fn (EventFine $f): int => (int)$f->amount, $pending));
$button = 'btn btn-outline-secondary btn-xs';
?>
<div class="event-standings-card" id="fines">
    <div class="event-standings-header">
        <h2 class="event-standings-title">
            <i class="fas fa-gavel text-danger"></i> <?= __('Fines') ?>
            <small class="text-muted" style="font-size: .8rem;">
                &middot; <?= $this->element('fine_rule', ['rule' => $fineRule, 'inline' => true]) ?>
            </small>
        </h2>
        <div class="d-flex flex-wrap" style="gap: 6px;">
            <?= $this->Html->link(
                '<i class="fas fa-history mr-1"></i>' . __('Fine history'),
                ['controller' => 'EventFines', 'action' => 'index', '?' => ['event_id' => $event->id]],
                ['class' => 'btn btn-outline-secondary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Form->postLink(
                '<i class="fas fa-sync-alt mr-1"></i>' . __('List again'),
                ['controller' => 'EventFines', 'action' => 'propose', $event->id],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false,
                 'title' => __('Lists the pending fines again from the result and the tournament\'s current rule.')]
            ) ?>
            <?php if ($pending !== []): ?>
                <?= $this->Form->postLink(
                    '<i class="fas fa-coins mr-1"></i>' . __('Charge {0} fine(s) ({1})', count($pending), $this->silver($pendingTotal)),
                    ['controller' => 'EventFines', 'action' => 'charge', $event->id],
                    ['class' => 'btn btn-danger btn-sm', 'escape' => false,
                     'confirm' => __('Debit the pending fines from the players\' accounts in the clan bank? A balance may go below zero: the player pays it by depositing.')]
                ) ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($fines === []): ?>
        <p class="text-muted p-3 mb-0">
            <?= $mode === TournamentFineService::MODE_NONE
                ? __('Set a fine on the tournament\'s page in the catalogue, then list the fines again.')
                : __('Every player reached the goal: nobody is fined.') ?>
        </p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th><?= __('Player') ?></th>
                        <th class="text-right"><?= __('Points') ?></th>
                        <th class="text-right"><?= __('Goal') ?></th>
                        <th class="text-right"><?= __('Fine') ?></th>
                        <th><?= __('Status') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fines as $fine): ?>
                        <tr>
                            <td><?= h($fine->player) ?></td>
                            <td class="text-right"><?= $this->Number->format($fine->points) ?> <small class="text-muted">(<?= $fine->reachedPercent() ?>%)</small></td>
                            <td class="text-right"><?= $this->Number->format($fine->goal) ?></td>
                            <td class="text-right font-weight-bold"><?= $this->silver($fine->amount) ?></td>
                            <td>
                                <span class="badge <?= $badges[$fine->status] ?? 'badge-light' ?>"><?= h($statusLabels[$fine->status] ?? $fine->status) ?></span>
                                <?php if ($fine->note): ?>
                                    <small class="text-muted d-block"><?= h($fine->note) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-right text-nowrap">
                                <?php if ($fine->status === EventFine::STATUS_PENDING): ?>
                                    <?= $this->Form->postLink(__('Waive'), ['controller' => 'EventFines', 'action' => 'waive', $fine->id], ['class' => $button]) ?>
                                <?php elseif ($fine->status === EventFine::STATUS_WAIVED): ?>
                                    <?= $this->Form->postLink(__('Fine again'), ['controller' => 'EventFines', 'action' => 'waive', $fine->id, '?' => ['undo' => 1]], ['class' => $button]) ?>
                                <?php elseif ($fine->status === EventFine::STATUS_CHARGED): ?>
                                    <?= $this->Form->postLink(__('Refund'), ['controller' => 'EventFines', 'action' => 'refund', $fine->id], [
                                        'class' => $button, 'confirm' => __('Give {0} back to {1}?', $this->silver($fine->amount), $fine->player),
                                    ]) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
