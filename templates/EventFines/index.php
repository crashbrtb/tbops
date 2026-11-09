<?php
/**
 * The tournament fines: what each status adds up to, who still owes (those who
 * left the clan can have it given up as lost), and every fine, filtered by event,
 * status and player.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\EventFine> $fines
 * @var array{event_id: int, status: string, player: string} $filters
 * @var array<int, string> $events
 * @var array<string, array{count: int, amount: int}> $totals
 * @var list<array{key: string, member_id: int|null, player: string, in_clan: bool, pending: int, debt: int, owed: int, fines: int}> $owing
 * @var array<string, string> $statusOptions
 */

use App\Model\Entity\EventFine;

$this->assign('title', __('Tournament Fines'));
$badges = [
    EventFine::STATUS_PENDING => 'badge-warning',
    EventFine::STATUS_CHARGED => 'badge-danger',
    EventFine::STATUS_WAIVED => 'badge-secondary',
    EventFine::STATUS_REFUNDED => 'badge-info',
    EventFine::STATUS_LOST => 'badge-dark',
];
$number = fn (int $v): string => $this->Number->format($v);
$silver = fn (int $v): string => $this->silver($v);
$playerLink = fn (string $name): string => $this->Html->link($name, ['controller' => 'PlayerCycleSummaries', 'action' => 'playerHistory', urlencode($name)]);
$outside = array_values(array_filter($owing, fn (array $r): bool => !$r['in_clan']));
?>
<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title"><i class="fas fa-gavel text-danger mr-2"></i><?= __('Tournament Fines') ?></h1>
            <p class="cycle-subtitle"><?= __('Every fine of the game tournaments, who still owes and what was given up') ?></p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-trophy mr-1"></i>' . __('Tournament Catalogue'),
                ['controller' => 'GameTournaments', 'action' => 'index'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <div class="row">
        <?php foreach ($statusOptions as $status => $label): ?>
            <div class="col-6 col-md">
                <div class="small-box bg-light">
                    <div class="inner">
                        <h4 class="mb-0"><?= $silver($totals[$status]['amount']) ?></h4>
                        <p class="mb-0"><span class="badge <?= $badges[$status] ?>"><?= h($label) ?></span> &middot; <?= __('{0} fine(s)', $totals[$status]['count']) ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card card-danger card-outline" id="owing">
        <div class="card-header">
            <h3 class="card-title font-weight-bold">
                <i class="fas fa-hand-holding-usd text-danger mr-2"></i><?= __('Fines to pay, by player') ?>
                <span class="badge badge-danger ml-1"><?= count($owing) ?></span>
                <?php if ($outside !== []): ?>
                    <span class="badge badge-dark ml-1" title="<?= h(__('Left the clan')) ?>"><?= __('{0} out of the clan', count($outside)) ?></span>
                <?php endif; ?>
            </h3>
        </div>
        <div class="card-body table-responsive p-0">
            <?php if ($owing === []): ?>
                <p class="text-muted p-3 mb-0"><?= __('Nobody owes a fine.') ?></p>
            <?php else: ?>
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th><?= __('Player') ?></th>
                            <th><?= __('In the clan') ?></th>
                            <th class="text-right"><?= __('Not charged yet') ?></th>
                            <th class="text-right"><?= __('Debt in the bank') ?></th>
                            <th class="text-right"><?= __('To pay') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($owing as $row): ?>
                            <tr class="<?= $row['in_clan'] ? '' : 'table-secondary' ?>">
                                <td class="align-middle">
                                    <?= $playerLink($row['player']) ?>
                                    <?= $this->Html->link('<i class="fas fa-filter"></i>', ['?' => ['player' => $row['player']]], ['escape' => false, 'class' => 'text-muted ml-1', 'title' => __('Show their fines')]) ?>
                                </td>
                                <td class="align-middle">
                                    <?= $row['in_clan']
                                        ? '<span class="badge badge-success">' . __('Yes') . '</span>'
                                        : '<span class="badge badge-dark">' . ($row['member_id'] ? __('Left the clan') : __('Not registered')) . '</span>' ?>
                                </td>
                                <td class="align-middle text-right"><?= $silver($row['pending']) ?></td>
                                <td class="align-middle text-right"><?= $silver($row['debt']) ?></td>
                                <td class="align-middle text-right font-weight-bold"><?= $silver($row['owed']) ?></td>
                                <td class="align-middle text-right">
                                    <?php if (!$row['in_clan']): ?>
                                        <?= $this->Form->postLink(
                                            '<i class="fas fa-times-circle mr-1"></i>' . __('Give up as lost'),
                                            ['action' => 'writeOff'],
                                            [
                                                'data' => ['key' => $row['key']],
                                                'class' => 'btn btn-outline-dark btn-xs',
                                                'escape' => false,
                                                'confirm' => __('Give up the {0} {1} owes? The debt leaves their balance and the clan\'s fees.', $silver($row['owed']), $row['player']),
                                            ]
                                        ) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="card card-outline card-primary">
        <div class="card-header">
            <h3 class="card-title font-weight-bold"><i class="fas fa-history text-primary mr-2"></i><?= __('Fine history') ?></h3>
        </div>
        <div class="card-body border-bottom">
            <?= $this->Form->create(null, ['type' => 'get', 'class' => 'form-inline flex-wrap', 'style' => 'gap: 8px;']) ?>
                <?= $this->Form->select('event_id', $events, ['empty' => __('All events'), 'value' => $filters['event_id'] ?: null, 'class' => 'form-control form-control-sm']) ?>
                <?= $this->Form->select('status', $statusOptions, ['empty' => __('Any status'), 'value' => $filters['status'] ?: null, 'class' => 'form-control form-control-sm']) ?>
                <?= $this->Form->text('player', ['value' => $filters['player'], 'placeholder' => __('Player'), 'class' => 'form-control form-control-sm']) ?>
                <?= $this->Form->button('<i class="fas fa-filter mr-1"></i>' . __('Filter'), ['class' => 'btn btn-primary btn-sm', 'escapeTitle' => false]) ?>
                <?php if ($filters['event_id'] || $filters['status'] !== '' || $filters['player'] !== ''): ?>
                    <?= $this->Html->link(__('Clear'), ['action' => 'index'], ['class' => 'btn btn-link btn-sm']) ?>
                <?php endif; ?>
            <?= $this->Form->end() ?>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th><?= $this->Paginator->sort('Events.ends_at', __('Event')) ?></th>
                        <th><?= $this->Paginator->sort('EventFines.player', __('Player')) ?></th>
                        <th class="text-right"><?= __('Points') ?></th>
                        <th class="text-right"><?= __('Goal') ?></th>
                        <th class="text-right"><?= $this->Paginator->sort('EventFines.amount', __('Fine')) ?></th>
                        <th><?= $this->Paginator->sort('EventFines.status', __('Status')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $any = false; ?>
                    <?php foreach ($fines as $fine): ?>
                        <?php $any = true; ?>
                        <tr>
                            <td>
                                <?= $this->Html->link(h($fine->event->name), ['controller' => 'Events', 'action' => 'view', $fine->event_id, '#' => 'fines'], ['escape' => false]) ?>
                                <small class="text-muted d-block"><?= h($fine->event->ends_at?->format('d/m/Y')) ?></small>
                            </td>
                            <td>
                                <?= $playerLink($fine->player) ?>
                                <?php if ($fine->member !== null && (int)$fine->member->active !== 1): ?>
                                    <span class="badge badge-dark ml-1"><?= __('Left the clan') ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><?= $number((int)$fine->points) ?> <small class="text-muted">(<?= $fine->reachedPercent() ?>%)</small></td>
                            <td class="text-right"><?= $number((int)$fine->goal) ?></td>
                            <td class="text-right font-weight-bold"><?= $silver((int)$fine->amount) ?></td>
                            <td>
                                <span class="badge <?= $badges[$fine->status] ?? 'badge-light' ?>"><?= h($statusOptions[$fine->status] ?? $fine->status) ?></span>
                                <?php if ($fine->status === EventFine::STATUS_LOST && $fine->lost_amount): ?>
                                    <small class="text-muted"><?= h(__('{0} given up', $silver((int)$fine->lost_amount))) ?></small>
                                <?php endif; ?>
                                <?php if ($fine->note): ?>
                                    <small class="text-muted d-block"><?= h($fine->note) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$any): ?>
                        <tr><td colspan="6" class="text-muted"><?= __('No fine matches.') ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-wrap justify-content-between align-items-center">
            <div class="text-muted small mb-2 mb-md-0">
                <?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?>
            </div>
            <ul class="pagination pagination-sm mb-0">
                <?= $this->Paginator->prev('<i class="fas fa-angle-left"></i>', ['escape' => false]) ?>
                <?= $this->Paginator->numbers() ?>
                <?= $this->Paginator->next('<i class="fas fa-angle-right"></i>', ['escape' => false]) ?>
            </ul>
        </div>
    </div>
</div>
