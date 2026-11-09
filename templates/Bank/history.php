<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Member $member
 * @var \Cake\Collection\CollectionInterface|\App\Model\Entity\BankTransaction[] $transactions
 * @var int $memberId
 */

$balance = (int)round($member->bank_account->balance ?? 0);
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div>
            <h1 class="score-title"><?= __('Transaction history for {0}', h($member->player)) ?></h1>
            <p class="cycle-subtitle"><?= __('Values are saved in millions of Silver ($)') ?></p>
        </div>
        <div class="actions">
            <?= $this->Html->link('<i class="fas fa-arrow-left mr-1"></i> ' . __('Back to Bank'), ['action' => 'index'], ['class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
        </div>
    </div>

    <div class="goal-pill">
        <?= __('Current balance: {0}', $this->silver($balance)) ?>
    </div>

    <div class="card ranking-card">
        <div class="ranking-header">
            <h3 class="card-title"><?= __('Transactions') ?></h3>
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
                        <th><?= __('Comment') ?></th>
                        <th><?= __('Approval log') ?></th>
                        <th><?= __('Created at') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($transactions) === 0): ?>
                        <tr>
                            <td colspan="8" class="text-muted"><?= __('No transactions found.') ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($transactions as $transaction): ?>
                        <?php
                            $isSource = (int)$transaction->member_id === $memberId;
                            $isDestination = (int)$transaction->destination_member_id === $memberId;
                            $typeLabel = ucfirst($transaction->type);

                            if ($transaction->type === 'transfer') {
                                if ($isSource) {
                                    $target = $transaction->destination_member->player ?? __('Unknown');
                                    $typeLabel = __('Transfer to {0}', h($target));
                                } elseif ($isDestination) {
                                    $origin = $transaction->member->player ?? __('Unknown');
                                    $typeLabel = __('Transfer from {0}', h($origin));
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
                            if ($transaction->type === 'deposit') {
                                $effectSign = '+';
                            }

                            $netEffect = ($effectSign === '-' ? -1 : 1) * $effectValue;
                        ?>
                        <tr>
                            <td><span class="badge badge-light"><?= $typeLabel ?></span></td>
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
                            <td><?= h($transaction->description ?? '-') ?></td>
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
                            <td><?= $transaction->created ? $transaction->created->i18nFormat('yyyy-MM-dd HH:mm') : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
