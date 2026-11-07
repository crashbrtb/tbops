<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\CollectedChest[]|\Cake\Collection\CollectionInterface $collectedChests
 */

$this->assign('title', __('Collected Chests'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('List Collected Chests')],
]);
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-archive text-primary mr-2"></i><?= __('Collected Chests') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('History of all individual chest openings recorded by collectors') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-plus mr-1"></i> ' . __('New Collected Chest'),
                ['action' => 'add'],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-chart-line mr-1"></i> ' . __('Scoreboard'),
                ['action' => 'score'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <div class="card card-primary card-outline">
        <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between">
            <h3 class="card-title font-weight-bold mb-2 mb-md-0">
                <i class="fas fa-history text-primary mr-2"></i><?= __('Opened Chests Log') ?>
            </h3>
            <div class="d-flex align-items-center">
                <span class="mr-2 small text-muted"><?= __('Show:') ?></span>
                <?= $this->Paginator->limitControl([], null, [
                    'label' => false,
                    'class' => 'form-control form-control-sm',
                    'templates' => ['inputContainer' => '{{content}}']
                ]); ?>
            </div>
        </div>

        <div class="card-body table-responsive p-0">
            <table class="table table-hover text-nowrap">
                <thead>
                    <tr>
                        <th><?= $this->Paginator->sort('id', '#') ?></th>
                        <th><?= $this->Paginator->sort('name', __('Chest Name')) ?></th>
                        <th><?= $this->Paginator->sort('player', __('Player')) ?></th>
                        <th><?= $this->Paginator->sort('source', __('Source')) ?></th>
                        <th><?= $this->Paginator->sort('type', __('Type')) ?></th>
                        <th><?= $this->Paginator->sort('collected_at', __('Collected At')) ?></th>
                        <th class="actions text-right"><?= __('Actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($collectedChests)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <?= __('No collected chests recorded.') ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($collectedChests as $collectedChest): ?>
                            <tr>
                                <td>#<?= $this->Number->format($collectedChest->id) ?></td>
                                <td class="font-weight-bold"><?= h($collectedChest->name) ?></td>
                                <td><span class="badge badge-light border"><i class="fas fa-user mr-1 text-primary"></i><?= h($collectedChest->player) ?></span></td>
                                <td class="text-muted"><?= h($collectedChest->source) ?></td>
                                <td><span class="badge badge-secondary"><?= $this->Number->format($collectedChest->type) ?></span></td>
                                <td><?= !empty($collectedChest->collected_at) ? h($collectedChest->collected_at->format('d/m/Y H:i')) : '-' ?></td>
                                <td class="actions text-right">
                                    <?= $this->Html->link(
                                        '<i class="fas fa-eye"></i>',
                                        ['action' => 'view', $collectedChest->id],
                                        ['class' => 'btn btn-xs btn-outline-primary', 'escape' => false, 'title' => __('View')]
                                    ) ?>
                                    <?= $this->Html->link(
                                        '<i class="fas fa-edit"></i>',
                                        ['action' => 'edit', $collectedChest->id],
                                        ['class' => 'btn btn-xs btn-outline-primary', 'escape' => false, 'title' => __('Edit')]
                                    ) ?>
                                    <?= $this->Form->postLink(
                                        '<i class="fas fa-trash-alt"></i>',
                                        ['action' => 'delete', $collectedChest->id],
                                        [
                                            'class' => 'btn btn-xs btn-outline-danger',
                                            'escape' => false,
                                            'confirm' => __('Are you sure you want to delete # {0}?', $collectedChest->id),
                                            'title' => __('Delete')
                                        ]
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer d-flex flex-column flex-md-row align-items-center justify-content-between">
            <div class="text-muted small mb-2 mb-md-0">
                <?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?>
            </div>
            <ul class="pagination pagination-sm mb-0">
                <?= $this->Paginator->first('<i class="fas fa-angle-double-left"></i>', ['escape' => false]) ?>
                <?= $this->Paginator->prev('<i class="fas fa-angle-left"></i>', ['escape' => false]) ?>
                <?= $this->Paginator->numbers() ?>
                <?= $this->Paginator->next('<i class="fas fa-angle-right"></i>', ['escape' => false]) ?>
                <?= $this->Paginator->last('<i class="fas fa-angle-double-right"></i>', ['escape' => false]) ?>
            </ul>
        </div>
    </div>
</div>