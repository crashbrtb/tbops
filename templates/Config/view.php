<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Config $config
 */

$this->assign('title', __('Config: {0}', $config->param));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('List Config'), 'url' => ['action' => 'index']],
    ['title' => h($config->param)],
]);
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-cog text-primary mr-2"></i><?= h($config->param) ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('Configuration parameter details and values') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-edit mr-1"></i> ' . __('Edit'),
                ['action' => 'edit', $config->id],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-list mr-1"></i> ' . __('List Configs'),
                ['action' => 'index'],
                ['class' => 'btn btn-default btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <div class="card card-primary card-outline">
        <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between">
            <h3 class="card-title font-weight-bold mb-2 mb-md-0">
                <code class="text-primary font-weight-bold" style="font-size: 1.1rem;"><?= h($config->param) ?></code>
                <span class="text-muted small ml-1">#<?= $this->Number->format($config->id) ?></span>
            </h3>
        </div>

        <div class="card-body p-0 table-responsive">
            <table class="table table-hover mb-0">
                <tbody>
                    <tr>
                        <th style="width: 250px;" class="bg-light"><?= __('Parameter Key') ?></th>
                        <td><code><?= h($config->param) ?></code></td>
                    </tr>
                    <tr>
                        <th class="bg-light"><?= __('Current Value') ?></th>
                        <td>
                            <span class="badge badge-light border px-2 py-1 font-weight-bold text-dark" style="font-size: 0.95rem;">
                                <?= h($config->value) ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th class="bg-light"><?= __('Description') ?></th>
                        <td><?= h(__($config->description)) ?></td>
                    </tr>
                    <tr>
                        <th class="bg-light"><?= __('Internal ID') ?></th>
                        <td>#<?= $this->Number->format($config->id) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card-footer d-flex flex-column flex-sm-row align-items-center justify-content-between">
            <div class="mb-2 mb-sm-0">
                <?= $this->Form->postLink(
                    '<i class="fas fa-trash-alt mr-1"></i> ' . __('Delete'),
                    ['action' => 'delete', $config->id],
                    [
                        'confirm' => __('Are you sure you want to delete parameter "{0}"?', $config->param),
                        'class' => 'btn btn-danger',
                        'escape' => false,
                    ]
                ) ?>
            </div>
            <div class="d-flex" style="gap: 8px;">
                <?= $this->Html->link(
                    '<i class="fas fa-arrow-left mr-1"></i> ' . __('Back to List'),
                    ['action' => 'index'],
                    ['class' => 'btn btn-default', 'escape' => false]
                ) ?>
                <?= $this->Html->link(
                    '<i class="fas fa-edit mr-1"></i> ' . __('Edit'),
                    ['action' => 'edit', $config->id],
                    ['class' => 'btn btn-primary', 'escape' => false]
                ) ?>
            </div>
        </div>
    </div>
</div>
