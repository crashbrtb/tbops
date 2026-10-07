<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Member[]|\Cake\Collection\CollectionInterface $members
 */

$this->assign('title', __('Members'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('List Members')],
]);
?>

<style>
    .members-table .troop-badges {
        white-space: normal;
    }
    .members-table .troop-badges .badge {
        display: inline-block;
        margin: 1px 2px;
    }
</style>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-users text-primary mr-2"></i><?= __('Members') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('Manage clan members roster, troop tiers, and tournament reward eligibility') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-user-plus mr-1"></i> ' . __('New Member'),
                ['action' => 'add'],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-sync-alt mr-1"></i> ' . __('Update from Chests'),
                ['action' => 'updateFromCollectedChests'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-exchange-alt mr-1"></i> ' . __('Names Mapping'),
                ['controller' => 'PlayerNameMappings', 'action' => 'index'],
                ['class' => 'btn btn-default btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <div class="card card-primary card-outline">
        <div class="card-header d-flex flex-column flex-md-row align-items-md-center justify-content-between">
            <h3 class="card-title font-weight-bold mb-2 mb-md-0">
                <i class="fas fa-list-alt text-primary mr-2"></i><?= __('Clan Members Roster') ?>
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
            <table class="table table-hover members-table mb-0">
                <thead>
                    <tr>
                        <th><?= $this->Paginator->sort('player', __('Player')) ?></th>
                        <th><?= $this->Paginator->sort('power', __('Power')) ?></th>
                        <th><?= __('Troops') ?></th>
                        <th><?= $this->Paginator->sort('active', __('Status')) ?></th>
                        <th><?= $this->Paginator->sort('administrative_account', __('Tournament Rewards')) ?></th>
                        <th class="actions text-right"><?= __('Actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($members) || count($members) === 0): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="fas fa-users-slash fa-2x mb-2 d-block"></i>
                                <?= __('No clan members found.') ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($members as $member): ?>
                            <tr class="<?= !$member->active ? 'table-secondary text-muted' : '' ?>">
                                <td class="font-weight-bold">
                                    <?= h($member->player) ?>
                                </td>
                                <td class="text-nowrap">
                                    <span class="font-weight-bold text-primary">
                                        <?= $this->Number->format($member->power) ?>
                                    </span>
                                </td>
                                <td class="troop-badges">
                                    <span class="badge badge-light border" title="<?= __('Guards') ?>">G<?= $this->Number->format($member->guards) ?></span>
                                    <span class="badge badge-light border" title="<?= __('Specialists') ?>">S<?= $this->Number->format($member->specialists) ?></span>
                                    <span class="badge badge-light border" title="<?= __('Monsters') ?>">M<?= $this->Number->format($member->monsters) ?></span>
                                    <span class="badge badge-light border" title="<?= __('Engineers') ?>">E<?= $this->Number->format($member->engineers) ?></span>
                                </td>
                                <td class="text-nowrap">
                                    <?php if ($member->active): ?>
                                        <span class="badge badge-success"><i class="fas fa-check-circle mr-1"></i> <?= __('Active') ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><i class="fas fa-user-slash mr-1"></i> <?= __('Inactive') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap">
                                    <?= $this->Form->postLink(
                                        $member->administrative_account
                                            ? '<span class="badge badge-secondary" title="' . __('Mark as a player who receives rewards') . '"><i class="fas fa-user-shield mr-1"></i>' . __('Administrative') . '</span>'
                                            : '<span class="badge badge-success" title="' . __('Mark as an administrative account') . '"><i class="fas fa-coins mr-1"></i>' . __('Receives Rewards') . '</span>',
                                        ['action' => 'toggleAdministrative', $member->id],
                                        [
                                            'escape' => false,
                                            'title' => $member->administrative_account
                                                ? __('Mark as a player who receives rewards')
                                                : __('Mark as an administrative account'),
                                        ]
                                    ) ?>
                                </td>
                                <td class="actions text-right text-nowrap">
                                    <?= $this->Html->link(
                                        '<i class="fas fa-eye"></i>',
                                        ['action' => 'view', $member->id],
                                        ['class' => 'btn btn-xs btn-outline-primary', 'escape' => false, 'title' => __('View')]
                                    ) ?>
                                    <?= $this->Html->link(
                                        '<i class="fas fa-edit"></i>',
                                        ['action' => 'edit', $member->id],
                                        ['class' => 'btn btn-xs btn-outline-primary', 'escape' => false, 'title' => __('Edit')]
                                    ) ?>
                                    <?= $this->Form->postLink(
                                        '<i class="fas fa-trash-alt"></i>',
                                        ['action' => 'delete', $member->id],
                                        [
                                            'class' => 'btn btn-xs btn-outline-danger',
                                            'escape' => false,
                                            'confirm' => __('Are you sure you want to delete {0}?', $member->player),
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