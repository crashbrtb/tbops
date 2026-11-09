<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Member $member
 * @var array $members
 * @var int $memberId
 * @var int $balance
 * @var \Cake\Collection\CollectionInterface|\App\Model\Entity\BankTransaction[] $transactions
 */
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div>
            <h1 class="score-title"><?= __('Account Statement') ?></h1>
            <p class="cycle-subtitle"><?= __('Track every transaction registered for your members. Amounts in millions of Silver ($)') ?></p>
        </div>
        <div class="actions">
            <?= $this->Html->link('<i class="fas fa-arrow-left mr-1"></i> ' . __('Back to Bank'), ['action' => 'index'], ['class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
        </div>
    </div>

    <div class="card ranking-card mb-4">
        <div class="card-body">
            <?= $this->Form->create(null, ['type' => 'get', 'class' => 'form-inline']) ?>
                <div class="form-group mr-2 mb-0">
                    <label class="mr-2"><?= __('Member') ?>:</label>
                    <?= $this->Form->select('member_id', $members, [
                        'value' => $memberId,
                        'class' => 'form-control',
                    ]) ?>
                </div>
                <?= $this->Form->button('<i class="fas fa-sync-alt mr-1"></i> ' . __('Refresh'), ['class' => 'btn btn-primary btn-sm', 'escapeTitle' => false]) ?>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <div class="goal-pill">
        <?= __('Member: {0} | Current balance: {1}', h($member->player), $this->silver($balance)) ?>
    </div>

    <div class="card ranking-card">
        <div class="ranking-header">
            <h3 class="card-title"><?= __('Statement Records') ?></h3>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table ranking-table table-hover">
                <thead>
                    <tr>
                        <th><?= __('Type') ?></th>
                        <th><?= __('Amount ($)') ?></th>
                        <th><?= __('Fee ($)') ?></th>
                        <th><?= __('Net effect ($)') ?></th>
                        <th><?= __('Status') ?></th>
                        <th><?= __('Created at') ?></th>
                        <th><?= __('Approval log') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($transactions) === 0): ?>
                        <tr>
                            <td colspan="7" class="text-muted"><?= __('No transactions registered yet.') ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($transactions as $transaction): ?>
                        <?php
                            $isSource = (int)$transaction->member_id === $memberId;
                            $isDestination = (int)$transaction->destination_member_id === $memberId;

                            $label = ucfirst($transaction->type);
                            if ($transaction->type === 'transfer') {
                                if ($isSource) {
                                    $label = __('Transfer to {0}', $transaction->destination_member->player ?? __('Unknown'));
                                } elseif ($isDestination) {
                                    $label = __('Transfer from {0}', $transaction->member->player ?? __('Unknown'));
                                }
                            }

                            $effectValue = (int)$transaction->final_amount;
                            $effectSign = '+';
                            if ($transaction->type === 'withdrawal') {
                                $effectSign = '-';
                            } elseif ($transaction->type === 'transfer') {
                                if ($isSource) {
                                    $effectSign = '-';
                                    $effectValue = (int)$transaction->amount + (int)$transaction->fee;
                                } elseif ($isDestination) {
                                    $effectSign = '+';
                                    $effectValue = (int)$transaction->amount;
                                }
                            }

                            $netEffect = ($effectSign === '-' ? -1 : 1) * $effectValue;
                        ?>
                        <tr>
                            <td><span class="badge badge-light"><?= h($label) ?></span></td>
                            <td class="font-weight-bold"><?= $this->silver($transaction->amount) ?></td>
                            <td><?= $this->silver($transaction->fee) ?></td>
                            <td class="font-weight-bold" style="color: <?= $netEffect > 0 ? 'var(--success)' : ($netEffect < 0 ? 'var(--danger)' : 'inherit') ?>;">
                                <?= ($netEffect > 0 ? '+' : '') . $this->silver($netEffect) ?>
                            </td>
                            <td>
                                <?php if ($transaction->status === 'approved'): ?>
                                    <span class="badge badge-success"><?= __('Approved') ?></span>
                                <?php elseif ($transaction->status === 'pending'): ?>
                                    <span class="badge badge-warning"><?= __('Pending') ?></span>
                                <?php elseif ($transaction->status === 'rejected'): ?>
                                    <span class="badge badge-danger"><?= __('Rejected') ?></span>
                                <?php else: ?>
                                    <span class="badge badge-light"><?= ucfirst($transaction->status) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= $transaction->created ? $transaction->created->i18nFormat('yyyy-MM-dd HH:mm') : '' ?></td>
                            <td>
                                <?php if (!empty($transaction->bank_approval_logs)): ?>
                                    <ul class="list-unstyled mb-0" style="font-size: 0.82rem;">
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
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
