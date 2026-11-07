<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\StandardChest[]|\Cake\Collection\CollectionInterface $standardChests
 * @var array<string, string> $filters
 * @var array<int, list<string>> $chestNames Chest names each source gives, by its id
 */
?>

<?php
$this->assign('title', __('Standard Chests'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('List Standard Chests')],
]);

// Somente os filtros realmente em uso, para repassá-los aos links de ordenação e paginação.
$activeFilters = array_filter($filters, fn ($value) => $value !== '');
$hasFilters = $activeFilters !== [];

$currentLimit = (string)$this->request->getQuery('limit', '');

$this->Paginator->options(['url' => ['?' => $activeFilters]]);
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-boxes text-primary mr-2"></i><?= __('Standard Chests') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('Catalogue of game chests, score values, and monster classifications') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-plus mr-1"></i> ' . __('New Standard Chest'),
                ['action' => 'add'],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-star mr-1"></i> ' . __('New Chests'),
                ['action' => 'newChests'],
                ['class' => 'btn btn-outline-warning btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-bullseye mr-1"></i> ' . __('Goals & Weights'),
                ['action' => 'weights'],
                ['class' => 'btn btn-default btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <div class="card card-primary card-outline">
        <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between">
            <h3 class="card-title font-weight-bold mb-2 mb-md-0">
                <i class="fas fa-filter mr-1 text-muted"></i>
                <?= __('Filters & Search') ?>
                <?php if ($hasFilters) : ?>
                    <span class="badge badge-primary ml-1"><?= count($activeFilters) ?></span>
                <?php endif; ?>
            </h3>
        </div>
    <!-- /.card-header -->
    <div class="card-body pb-2">
        <?= $this->Form->create(null, ['type' => 'get', 'url' => ['action' => 'index'], 'valueSources' => ['query']]) ?>
        <div class="form-row">
            <div class="col-12 col-lg-4 mb-2">
                <?= $this->Form->control('q', [
                    'type' => 'text',
                    'label' => __('Search'),
                    'placeholder' => __('Source, alias or chest name...'),
                    'value' => $filters['q'],
                    'class' => 'form-control form-control-sm',
                ]) ?>
            </div>
            <div class="col-6 col-lg-2 mb-2">
                <?= $this->Form->control('type', [
                    'type' => 'select',
                    'label' => __('Chest Type'),
                    'options' => [
                        '' => __('All'),
                        'monster' => __('Epic Monster'),
                        'regular' => __('Regular'),
                    ],
                    'value' => $filters['type'],
                    'class' => 'form-control form-control-sm',
                ]) ?>
            </div>
            <div class="col-6 col-lg-2 mb-2">
                <?= $this->Form->control('score', [
                    'type' => 'select',
                    'label' => __('Score'),
                    'options' => [
                        '' => __('All'),
                        'scored' => __('Scored'),
                        'unscored' => __('Without score'),
                    ],
                    'value' => $filters['score'],
                    'class' => 'form-control form-control-sm',
                ]) ?>
            </div>
            <div class="col-6 col-lg-2 mb-2">
                <?= $this->Form->control('alias', [
                    'type' => 'select',
                    'label' => __('Alias'),
                    'options' => [
                        '' => __('All'),
                        'with' => __('With alias'),
                        'without' => __('Without alias'),
                    ],
                    'value' => $filters['alias'],
                    'class' => 'form-control form-control-sm',
                ]) ?>
            </div>
            <div class="col-6 col-lg-2 mb-2">
                <?= $this->Form->control('limit', [
                    'type' => 'select',
                    'label' => __('Per page'),
                    'options' => ['20' => '20', '50' => '50', '100' => '100'],
                    'value' => $currentLimit,
                    'empty' => __('Default'),
                    'class' => 'form-control form-control-sm',
                ]) ?>
            </div>
        </div>
        <div class="d-flex">
            <?= $this->Form->button('<i class="fas fa-search mr-1"></i> ' . __('Filter'), [
                'class' => 'btn btn-primary btn-sm',
                'escapeTitle' => false,
            ]) ?>
            <?php if ($hasFilters || $currentLimit !== '') : ?>
                <?= $this->Html->link(
                    '<i class="fas fa-times mr-1"></i> ' . __('Clear filters'),
                    ['action' => 'index'],
                    ['class' => 'btn btn-default btn-sm ml-2', 'escape' => false]
                ) ?>
            <?php endif; ?>
        </div>
        <?= $this->Form->end() ?>
    </div>
    <!-- /.card-body -->
    <div class="card-body table-responsive p-0">
        <table class="table table-bordered table-hover">
            <thead>
                <tr>
                    <th><?= $this->Paginator->sort('source', 'Source') ?></th>
                    <th><?= __('Chests') ?></th>
                    <th><?= $this->Paginator->sort('alias', __('Alias')) ?></th>
                    <th><?= $this->Paginator->sort('score', 'Score') ?></th>
                    <th><?= $this->Paginator->sort('monster', 'Epic Monster') ?></th>
                    <th><?= $this->Paginator->sort('qty_chest', 'Chests Qty') ?></th>
                    <th class="actions"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $isEmpty = true; ?>
                <?php foreach ($standardChests as $standardChest): $isEmpty = false; ?>
                <tr>
                    <td class="font-weight-bold"><?= h($standardChest->source) ?></td>
                    <td class="small text-muted text-left" style="white-space: normal; min-width: 220px; max-width: 420px;">
                        <?= isset($chestNames[$standardChest->id])
                            ? h(implode(', ', $chestNames[$standardChest->id]))
                            : '<span class="text-muted">&mdash;</span>' ?>
                    </td>
                    <td><?= $standardChest->alias ? h($standardChest->alias) : '<span class="text-muted">&mdash;</span>' ?></td>
                    <td><?= $this->Number->format($standardChest->score) ?></td>
                    <td><?= $standardChest->monster ? __('Yes') : __('No') ?></td>
                    <td><?= $standardChest->qty_chest === null ? '' : $this->Number->format($standardChest->qty_chest) ?></td>
                    <td class="actions">
                        <?= $this->Html->link('<i class="fas fa-eye"></i>', ['action' => 'view', $standardChest->id], ['escape' => false, 'class' => 'btn btn-info btn-sm']) ?>
                        <?= $this->Html->link('<i class="fas fa-pencil-alt"></i>', ['action' => 'edit', $standardChest->id], ['escape' => false, 'class' => 'btn btn-warning btn-sm']) ?>
                        <?= $this->Form->postLink('<i class="fas fa-trash"></i>', ['action' => 'delete', $standardChest->id], ['escape' => false, 'class' => 'btn btn-danger btn-sm', 'confirm' => __('Are you sure you want to delete # {0}?', $standardChest->id)]) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ($isEmpty) : ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        <i class="fas fa-search mb-2 d-block" style="font-size: 1.5rem;"></i>
                        <?= $hasFilters
                            ? __('No standard chest matches the selected filters.')
                            : __('No standard chest registered yet.') ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- /.card-body -->
    <div class="card-footer d-flex flex-column flex-md-row">
        <div class="text-muted">
            <?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?>
        </div>
        <ul class="pagination pagination-sm mb-0 ml-auto">
            <?= $this->Paginator->first('<i class="fas fa-angle-double-left"></i>', ['escape' => false]) ?>
            <?= $this->Paginator->prev('<i class="fas fa-angle-left"></i>', ['escape' => false]) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next('<i class="fas fa-angle-right"></i>', ['escape' => false]) ?>
            <?= $this->Paginator->last('<i class="fas fa-angle-double-right"></i>', ['escape' => false]) ?>
        </ul>
    </div>
    <!-- /.card-footer -->
    </div>
</div>
