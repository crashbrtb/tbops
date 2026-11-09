<?php
/**
 * @var \App\View\AppView $this
 * @var \Cake\Collection\CollectionInterface|\App\Model\Entity\Member[] $members
 * @var float $totalSilver
 * @var float $totalFees
 * @var bool $isAdmin
 */
?>
<div class="content-page-wrap">
    <div class="score-toolbar">
        <div>
            <h1 class="score-title"><?= __('Bank Members Overview') ?></h1>
            <p class="cycle-subtitle"><?= __('All values are stored in millions of Silver ($)') ?></p>
        </div>
        <div class="actions">
            <?= $this->Html->link('<i class="fas fa-arrow-circle-down mr-1"></i> ' . __('Deposit Silver'), ['action' => 'deposit'], ['class' => 'btn btn-success btn-sm mr-1', 'escape' => false]) ?>
            <?= $this->Html->link('<i class="fas fa-arrow-circle-up mr-1"></i> ' . __('Withdraw Silver'), ['action' => 'withdraw'], ['class' => 'btn btn-warning btn-sm mr-1', 'escape' => false]) ?>
            <?= $this->Html->link('<i class="fas fa-exchange-alt mr-1"></i> ' . __('Transfer Silver'), ['action' => 'transfer'], ['class' => 'btn btn-primary btn-sm', 'escape' => false]) ?>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6 mb-2">
            <div class="card ranking-card mb-0">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted font-weight-bold text-uppercase" style="font-size: 0.8rem; letter-spacing: 0.05em;"><?= __('Total Silver in Bank') ?></div>
                        <div class="font-weight-bold" style="font-size: 1.8rem; color: var(--accent);"><?= $this->silver($totalSilver) ?></div>
                    </div>
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: var(--accent-light); color: var(--accent); display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                        <i class="fas fa-coins"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-2">
            <div class="card ranking-card mb-0">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <div class="text-muted font-weight-bold text-uppercase" style="font-size: 0.8rem; letter-spacing: 0.05em;"><?= __('Total Fees Collected') ?></div>
                        <div class="font-weight-bold" style="font-size: 1.8rem; color: var(--success);"><?= $this->silver($totalFees) ?></div>
                    </div>
                    <div style="width: 48px; height: 48px; border-radius: 12px; background: var(--success-light); color: var(--success); display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                        <i class="fas fa-receipt"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card ranking-card">
        <div class="ranking-header">
            <h3 class="card-title"><?= __('Members Account Balances') ?></h3>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table ranking-table table-hover">
                <thead>
                    <tr>
                        <th class="text-left"><?= __('Player') ?></th>
                        <th class="text-center"><?= __('Balance ($)') ?></th>
                        <th class="text-right"><?= __('Actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $member): ?>
                        <?php $balance = (int)round($member->bank_account->balance ?? 0); ?>
                        <tr>
                            <td class="text-left">
                                <?php if ($isAdmin): ?>
                                    <?= $this->Html->link(h($member->player), ['action' => 'history', $member->id], ['class' => 'player-link']) ?>
                                <?php else: ?>
                                    <span class="font-weight-bold"><?= h($member->player) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center font-weight-bold" style="color: <?= $balance > 0 ? 'var(--text-dark)' : 'var(--muted)' ?>;">
                                <?= $this->silver($balance) ?>
                            </td>
                            <td class="text-right">
                                <?php if ($isAdmin): ?>
                                    <?= $this->Html->link('<i class="fas fa-history mr-1"></i> ' . __('View history'), ['action' => 'history', $member->id], ['class' => 'btn btn-xs btn-outline-primary', 'escape' => false]) ?>
                                <?php else: ?>
                                    <span class="badge badge-light"><?= __('Member') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
