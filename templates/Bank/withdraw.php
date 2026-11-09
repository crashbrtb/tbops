<?php
/**
 * @var \App\View\AppView $this
 * @var array $allMembers
 * @var array $memberBalances
 * @var float $withdrawFee
 * @var bool $isAdmin
 */

$hasMembers = !empty($allMembers);
$hasPositiveBalance = false;
foreach ($memberBalances as $balance) {
    if ($balance > 0) {
        $hasPositiveBalance = true;
        break;
    }
}
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div>
            <h1 class="score-title"><?= __('Request Withdrawal') ?></h1>
            <p class="cycle-subtitle"><?= __('Select a member and enter the Silver amount. All values represent millions of Silver ($)') ?></p>
        </div>
        <div class="actions">
            <?= $this->Html->link('<i class="fas fa-arrow-left mr-1"></i> ' . __('Back to Bank'), ['action' => 'index'], ['class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
        </div>
    </div>

    <div class="goal-pill">
        <?= __('Current withdrawal fee: {0}', $this->silver($withdrawFee)) ?>
    </div>

    <?php if (!$hasMembers): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            <?= __('You do not have members available for withdrawals.') ?>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <div class="card ranking-card mb-4">
        <div class="ranking-header">
            <h3 class="card-title"><?= __('Current Balances Overview') ?></h3>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table ranking-table table-hover">
                <thead>
                    <tr>
                        <th class="text-left"><?= __('Member') ?></th>
                        <th class="text-center"><?= __('Current balance ($)') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allMembers as $memberId => $memberName): ?>
                        <?php $bal = $memberBalances[$memberId] ?? 0; ?>
                        <tr>
                            <td class="text-left font-weight-bold"><?= h($memberName) ?></td>
                            <td class="text-center font-weight-bold" style="color: <?= $bal > 0 ? 'var(--text-dark)' : 'var(--danger)' ?>;">
                                <?= $this->silver($bal) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!$hasPositiveBalance): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle mr-1"></i>
            <?= __('All balances are zero or negative. Withdraw buttons are hidden until there is enough Silver.') ?>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <div class="card ranking-card">
        <div class="ranking-header">
            <h3 class="card-title"><?= __('Withdrawal Form') ?></h3>
        </div>
        <div class="card-body">
            <?= $this->Form->create(null) ?>
            <div class="form-group">
                <?= $this->Form->control('member_id', [
                    'label' => __('Member'),
                    'options' => $allMembers,
                    'required' => true,
                    'class' => 'form-control',
                ]) ?>
            </div>
            <div class="form-group">
                <?= $this->Form->control('amount', [
                    'label' => __('Amount (millions of Silver)'),
                    'type' => 'number',
                    'min' => 1,
                    'step' => 1,
                    'class' => 'form-control',
                    'id' => 'withdraw-amount',
                    'required' => true,
                ]) ?>
                <small class="form-text text-muted">
                    <?= __('Only positive integers are accepted.') ?>
                </small>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <?= $this->Form->control('fee_preview', [
                        'label' => __('Withdrawal fee ($)'),
                        'type' => 'text',
                        'readonly' => true,
                        'class' => 'form-control font-weight-bold',
                        'id' => 'withdraw-fee-preview',
                        'value' => $this->Number->format($withdrawFee, ['places' => 0]),
                    ]) ?>
                </div>

                <div class="col-md-6 form-group">
                    <?= $this->Form->control('final_preview', [
                        'label' => __('Total deduction ($)'),
                        'type' => 'text',
                        'readonly' => true,
                        'class' => 'form-control font-weight-bold text-danger',
                        'id' => 'withdraw-total-preview',
                        'value' => $this->Number->format($withdrawFee, ['places' => 0]),
                    ]) ?>
                </div>
            </div>

            <div class="form-group">
                <?= $this->Form->control('description', [
                    'label' => __('Comment (English only, optional)'),
                    'type' => 'text',
                    'maxlength' => 512,
                    'class' => 'form-control',
                    'placeholder' => __('Short English comment (max 512 characters)'),
                ]) ?>
            </div>

            <div class="alert alert-info mt-3 mb-4">
                <i class="fas fa-info-circle mr-1"></i>
                <?= $isAdmin
                    ? __('Administrator withdrawals are executed immediately.')
                    : __('Withdrawals stay pending until an administrator approves them.') ?>
            </div>

            <div class="d-flex justify-content-end">
                <?= $this->Html->link(__('Cancel'), ['action' => 'index'], ['class' => 'btn btn-default mr-2']) ?>
                <?= $this->Form->button('<i class="fas fa-arrow-circle-up mr-1"></i> ' . __('Submit withdrawal'), ['class' => 'btn btn-warning', 'escapeTitle' => false]) ?>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<?php $withdrawFeeValue = (int)$withdrawFee; ?>
<script>
(function () {
    const amountInput = document.getElementById('withdraw-amount');
    const feeField = document.getElementById('withdraw-fee-preview');
    const totalField = document.getElementById('withdraw-total-preview');
    const fee = <?= json_encode($withdrawFeeValue) ?>;

    const recalc = () => {
        const amount = parseInt(amountInput.value, 10) || 0;
        const total = amount + fee;
        feeField.value = String(fee);
        totalField.value = String(total);
    };

    amountInput.addEventListener('input', recalc);
    recalc();
})();
</script>
