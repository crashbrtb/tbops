<?php
/**
 * A player's tournament fines, on the page of their goal history: what is still
 * to pay and every fine, newest first.
 *
 * @var \App\View\AppView $this
 * @var list<\App\Model\Entity\EventFine> $fines
 * @var array{pending: int, debt: int, owed: int, in_clan: bool}|null $owing
 * @var string $playerName
 * @var bool $isAdmin
 */

use App\Model\Entity\EventFine;

$labels = EventFine::statusOptions();
$badges = [
    EventFine::STATUS_PENDING => 'badge-warning',
    EventFine::STATUS_CHARGED => 'badge-danger',
    EventFine::STATUS_WAIVED => 'badge-secondary',
    EventFine::STATUS_REFUNDED => 'badge-info',
    EventFine::STATUS_LOST => 'badge-dark',
];
$number = fn (int $v): string => $this->Number->format($v);
$silver = fn (int $v): string => $this->silver($v);
?>
<section class="ranking-card" id="fines">
    <div class="ranking-header d-flex flex-wrap justify-content-between align-items-center" style="gap: 8px;">
        <span><i class="fas fa-gavel text-danger mr-1"></i><?= __('Tournament fines') ?></span>
        <span>
            <?php if ($owing !== null && $owing['owed'] > 0): ?>
                <span class="badge badge-danger"><?= h(__('To pay: {0}', $silver($owing['owed']))) ?></span>
            <?php else: ?>
                <span class="badge badge-success"><?= __('Nothing to pay') ?></span>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
                <?= $this->Html->link(__('Fine history'), ['controller' => 'EventFines', 'action' => 'index', '?' => ['player' => $playerName]], ['class' => 'ml-2']) ?>
            <?php endif; ?>
        </span>
    </div>
    <div class="ranking-table-wrap">
        <table class="ranking-table">
            <thead>
                <tr>
                    <th><?= __('Event') ?></th>
                    <th><?= __('Points') ?></th>
                    <th><?= __('Goal') ?></th>
                    <th><?= __('Fine') ?></th>
                    <th><?= __('Status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fines as $fine): ?>
                    <tr>
                        <td>
                            <?= $this->Html->link(h($fine->event->name), ['controller' => 'Events', 'action' => 'view', $fine->event_id], ['escape' => false]) ?>
                            <small class="text-muted d-block"><?= h($fine->event->ends_at?->format('d/m/Y')) ?></small>
                        </td>
                        <td><?= $number((int)$fine->points) ?> <small class="text-muted">(<?= $fine->reachedPercent() ?>%)</small></td>
                        <td><?= $number((int)$fine->goal) ?></td>
                        <td style="font-weight: 700;"><?= $silver((int)$fine->amount) ?></td>
                        <td>
                            <span class="badge <?= $badges[$fine->status] ?? 'badge-light' ?>"><?= h($labels[$fine->status] ?? $fine->status) ?></span>
                            <?php if ($fine->note): ?>
                                <small class="text-muted d-block"><?= h($fine->note) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
