<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\Collection\CollectionInterface|\App\Model\Entity\BankTransaction[] $pendingTransactions
 */
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div>
            <h1 class="score-title"><?= __('Pending Bank Approvals') ?></h1>
            <p class="cycle-subtitle"><?= __('Approve or reject transactions. Approved entries update member balances immediately') ?></p>
        </div>
        <div class="actions">
            <?= $this->Html->link('<i class="fas fa-arrow-left mr-1"></i> ' . __('Back to Bank'), ['action' => 'index'], ['class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
        </div>
    </div>

    <div class="card ranking-card">
        <div class="ranking-header">
            <h3 class="card-title"><?= __('Pending Queue') ?></h3>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table ranking-table table-hover">
                <thead>
                    <tr>
                        <th><?= __('Type') ?></th>
                        <th><?= __('Member') ?></th>
                        <th><?= __('Requested by') ?></th>
                        <th><?= __('Amount ($)') ?></th>
                        <th><?= __('Fee ($)') ?></th>
                        <th><?= __('Net effect ($)') ?></th>
                        <th><?= __('Created at') ?></th>
                        <th><?= __('Comment') ?></th>
                        <th><?= __('Actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($pendingTransactions) === 0): ?>
                        <tr>
                            <td colspan="9" class="text-muted p-4"><?= __('No transactions awaiting approval.') ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($pendingTransactions as $transaction): ?>
                        <?php
                            $netEffect = (int)$transaction->final_amount;
                            if ($transaction->type === 'withdrawal') {
                                $netEffect = -$netEffect;
                            }
                        ?>
                        <tr>
                            <td><span class="badge badge-light"><?= ucfirst($transaction->type) ?></span></td>
                            <td class="font-weight-bold"><?= h($transaction->member->player ?? __('Unknown')) ?></td>
                            <td><?= h($transaction->user->name ?? __('Unknown')) ?></td>
                            <td class="font-weight-bold"><?= $this->silver($transaction->amount) ?></td>
                            <td><?= $this->silver($transaction->fee) ?></td>
                            <td class="font-weight-bold" style="color: <?= $netEffect > 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                                <?= ($netEffect > 0 ? '+' : '') . $this->silver($netEffect) ?>
                            </td>
                            <td><?= $transaction->created ? $transaction->created->i18nFormat('yyyy-MM-dd HH:mm') : '' ?></td>
                            <td><?= h($transaction->description ?? '-') ?></td>
                            <td class="text-nowrap">
                                <div class="d-flex align-items-center justify-content-center">
                                    <?= $this->Form->postLink(
                                        '<i class="fas fa-check mr-1"></i> ' . __('Approve'),
                                        ['action' => 'approve', $transaction->id],
                                        ['class' => 'btn btn-xs btn-success mr-2', 'confirm' => __('Approve this transaction?'), 'escape' => false]
                                    ) ?>

                                    <?= $this->Form->create(null, [
                                        'url' => ['action' => 'reject', $transaction->id],
                                        'class' => 'form-inline d-inline-flex',
                                    ]) ?>
                                        <?= $this->Form->control('reason', [
                                            'label' => false,
                                            'placeholder' => __('Reason (optional)'),
                                            'maxlength' => 255,
                                            'class' => 'form-control form-control-sm mr-1',
                                            'style' => 'width: 130px;',
                                        ]) ?>
                                        <?= $this->Form->button('<i class="fas fa-times mr-1"></i> ' . __('Reject'), ['class' => 'btn btn-xs btn-danger', 'escapeTitle' => false]) ?>
                                    <?= $this->Form->end() ?>
                                </div>

                                <?php if (!empty($transaction->bank_approval_logs)): ?>
                                    <div class="mt-2 text-left" style="font-size: 0.8rem;">
                                        <strong><?= __('Approval log:') ?></strong>
                                        <ul class="list-unstyled mb-0 text-muted">
                                            <?php foreach ($transaction->bank_approval_logs as $log): ?>
                                                <li>
                                                    <?= ($log->created ? $log->created->i18nFormat('yyyy-MM-dd HH:mm') : '') ?>
                                                    - <?= ucfirst($log->action) ?>
                                                    <?php if (!empty($log->admin_user->name)): ?>
                                                        (<?= h($log->admin_user->name) ?>)
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
