<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Config[]|\Cake\Collection\CollectionInterface $config
 */

$this->assign('title', __('System Configurations'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('List Config')],
]);
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-cogs text-primary mr-2"></i><?= __('System Configurations') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('Global environment settings, feature toggles, and application variables') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-plus mr-1"></i> ' . __('New Config'),
                ['action' => 'add'],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-paint-brush mr-1"></i> ' . __('Branding'),
                ['action' => 'branding'],
                ['class' => 'btn btn-default btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-palette mr-1"></i> ' . __('Theme'),
                ['action' => 'theme'],
                ['class' => 'btn btn-default btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-tools mr-1"></i> ' . __('Maintenance'),
                ['action' => 'maintenance'],
                ['class' => 'btn btn-outline-warning btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <div class="card card-primary card-outline">
        <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between">
            <h3 class="card-title font-weight-bold mb-2 mb-md-0">
                <i class="fas fa-sliders-h text-primary mr-2"></i><?= __('Parameter Values') ?>
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
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th><?= $this->Paginator->sort('id', '#') ?></th>
                        <th><?= $this->Paginator->sort('param', __('Parameter')) ?></th>
                        <th><?= $this->Paginator->sort('value', __('Value')) ?></th>
                        <th class="text-wrap text-left"><?= $this->Paginator->sort('description', __('Description')) ?></th>
                        <th class="actions text-right"><?= __('Actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($config)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                <?= __('No configurations found.') ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($config as $c): ?>
                            <tr>
                                <td>#<?= $this->Number->format($c->id) ?></td>
                                <td>
                                    <code class="font-weight-bold text-primary" style="font-size: 0.95rem;">
                                        <?= h($c->param) ?>
                                    </code>
                                </td>
                                <td>
                                    <span class="badge badge-light border px-2 py-1 font-weight-bold text-dark" style="font-size: 0.9rem;">
                                        <?= h($c->value) ?>
                                    </span>
                                </td>
                                <td class="text-muted small text-wrap text-left"><?= h($c->description) ?></td>
                                <td class="actions text-right">
                                    <?= $this->Html->link(
                                        '<i class="fas fa-eye"></i>',
                                        ['action' => 'view', $c->id],
                                        ['class' => 'btn btn-xs btn-outline-primary', 'escape' => false, 'title' => __('View')]
                                    ) ?>
                                    <?= $this->Html->link(
                                        '<i class="fas fa-edit"></i>',
                                        ['action' => 'edit', $c->id],
                                        ['class' => 'btn btn-xs btn-outline-primary', 'escape' => false, 'title' => __('Edit')]
                                    ) ?>
                                    <?= $this->Form->postLink(
                                        '<i class="fas fa-trash-alt"></i>',
                                        ['action' => 'delete', $c->id],
                                        [
                                            'class' => 'btn btn-xs btn-outline-danger',
                                            'escape' => false,
                                            'confirm' => __('Are you sure you want to delete parameter {0}?', $c->param),
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