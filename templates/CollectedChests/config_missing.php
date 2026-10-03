<?php
/**
 * Shown when a required row of the `config` table is missing or invalid.
 *
 * @var \App\View\AppView $this
 * @var string $param
 * @var string $reason
 */

$this->assign('title', __('Missing Configuration'));
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-exclamation-triangle text-warning mr-2"></i><?= __('Incomplete Configuration') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('A required system parameter is missing or has an invalid configuration') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-cogs mr-1"></i> ' . __('System Configurations'),
                ['controller' => 'Config', 'action' => 'index', '?' => ['section' => 'chests']],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <div class="card card-warning card-outline">
        <div class="card-header">
            <h3 class="card-title font-weight-bold">
                <i class="fas fa-tools mr-1"></i> <?= __('Parameter missing: {0}', h($param)) ?>
            </h3>
        </div>
        <div class="card-body">
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-circle mr-1"></i>
                <?= __('The parameter <strong>{0}</strong> is {1} in the <code>config</code> table, preventing score calculations.', h($param), h($reason)) ?>
            </div>
            <p class="mb-2"><?= __('If this is a fresh setup or update, execute the initial migration seed in your terminal:') ?></p>
            <pre class="bg-dark text-white p-3 rounded mb-3"><code>php bin/cake.php migrations seed --seed InitialDataSeed</code></pre>
            <p class="mb-0">
                <?= __('Then review and configure parameters in {0}.', $this->Html->link(__('Configurations'), ['controller' => 'Config', 'action' => 'index', '?' => ['section' => 'chests']], ['class' => 'font-weight-bold'])) ?>
            </p>
        </div>
    </div>
</div>
