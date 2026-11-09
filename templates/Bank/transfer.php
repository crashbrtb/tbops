<?php
/**
 * @var \App\View\AppView $this
 * @var array $sourceMembers
 * @var array $destinationMembers
 * @var array $memberBalances
 * @var float $transferFee
 * @var array|null $confirmationData
 */

$hasSource = !empty($sourceMembers);
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
            <h1 class="score-title"><?= __('Transfer Silver') ?></h1>
            <p class="cycle-subtitle"><?= __('Choose a source and destination member. All values represent millions of Silver ($)') ?></p>
        </div>
        <div class="actions">
            <?= $this->Html->link('<i class="fas fa-arrow-left mr-1"></i> ' . __('Back to Bank'), ['action' => 'index'], ['class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
        </div>
    </div>

    <div class="goal-pill">
        <?= __('Transfer fee: {0} (charged in addition to the transferred amount)', $this->silver($transferFee)) ?>
    </div>

    <div class="card ranking-card mb-4">
        <div class="ranking-header">
            <h3 class="card-title"><?= __('Your Available Source Balances') ?></h3>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table ranking-table table-hover">
                <thead>
                    <tr>
                        <th class="text-left"><?= __('Source member') ?></th>
                        <th class="text-center"><?= __('Balance ($)') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$hasSource): ?>
                        <tr>
                            <td colspan="2" class="text-muted"><?= __('No members available.') ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sourceMembers as $memberId => $memberName): ?>
                            <?php $bal = $memberBalances[$memberId] ?? 0; ?>
                            <tr>
                                <td class="text-left font-weight-bold"><?= h($memberName) ?></td>
                                <td class="text-center font-weight-bold" style="color: <?= $bal > 0 ? 'var(--text-dark)' : 'var(--danger)' ?>;">
                                    <?= $this->silver($bal) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!$hasSource): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            <?= __('You must have at least one member to initiate transfers.') ?>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <?php if (!$hasPositiveBalance): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle mr-1"></i>
            <?= __('There is no balance available for transfers. The form will be hidden until one member has Silver.') ?>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <?php if ($confirmationData): ?>
        <div class="card ranking-card border-danger mb-4">
            <div class="ranking-header text-danger font-weight-bold" style="background: var(--danger-light) !important;">
                <i class="fas fa-exclamation-triangle mr-1"></i> <?= __('Confirm transfer') ?>
            </div>
            <div class="card-body">
                <p class="text-danger font-weight-bold"><?= __('This operation cannot be undone.') ?></p>
                <ul class="list-group mb-3">
                    <li class="list-group-item d-flex justify-content-between"><span><?= __('From') ?>:</span> <strong><?= h($confirmationData['source']->player) ?></strong></li>
                    <li class="list-group-item d-flex justify-content-between"><span><?= __('To') ?>:</span> <strong><?= h($confirmationData['destination']->player) ?></strong></li>
                    <li class="list-group-item d-flex justify-content-between"><span><?= __('Amount') ?>:</span> <strong><?= $this->silver($confirmationData['amount']) ?></strong></li>
                    <li class="list-group-item d-flex justify-content-between"><span><?= __('Fee') ?>:</span> <strong><?= $this->silver($confirmationData['fee']) ?></strong></li>
                    <li class="list-group-item d-flex justify-content-between bg-light"><span><?= __('Total deduction') ?>:</span> <strong class="text-danger"><?= $this->silver($confirmationData['totalDeduction']) ?></strong></li>
                    <?php if (!empty($confirmationData['description'])): ?>
                        <li class="list-group-item"><span><?= __('Comment') ?>:</span> <em><?= h($confirmationData['description']) ?></em></li>
                    <?php endif; ?>
                </ul>

                <?= $this->Form->create(null) ?>
                <?= $this->Form->hidden('source_member_id', ['value' => $confirmationData['source']->id]) ?>
                <?= $this->Form->hidden('destination_member_id', ['value' => $confirmationData['destination']->id]) ?>
                <?= $this->Form->hidden('amount', ['value' => $confirmationData['amount']]) ?>
                <?= $this->Form->hidden('description', ['value' => $confirmationData['description']]) ?>
                <?= $this->Form->hidden('confirm', ['value' => 1]) ?>
                <div class="d-flex justify-content-end">
                    <?= $this->Html->link(__('Cancel'), ['action' => 'transfer'], ['class' => 'btn btn-default mr-2']) ?>
                    <?= $this->Form->button('<i class="fas fa-check mr-1"></i> ' . __('Confirm transfer'), ['class' => 'btn btn-danger', 'escapeTitle' => false]) ?>
                </div>
                <?= $this->Form->end() ?>
            </div>
        </div>
        <?php return; ?>
    <?php endif; ?>

    <div class="card ranking-card">
        <div class="ranking-header">
            <h3 class="card-title"><?= __('Transfer Details') ?></h3>
        </div>
        <div class="card-body">
            <?= $this->Form->create(null) ?>
            <div class="form-group">
                <?= $this->Form->control('source_member_id', [
                    'label' => __('Source member'),
                    'options' => $sourceMembers,
                    'required' => true,
                    'class' => 'form-control',
                ]) ?>
            </div>
            <div class="form-group">
                <?= $this->Form->control('destination_member_id', [
                    'label' => __('Destination member'),
                    'options' => $destinationMembers,
                    'required' => true,
                    'class' => 'form-control',
                ]) ?>
                <small class="form-text text-muted">
                    <?= __('Destination members can belong to you or be unassigned.') ?>
                </small>
            </div>
            <div class="form-group">
                <?= $this->Form->control('amount', [
                    'label' => __('Amount (millions of Silver)'),
                    'type' => 'number',
                    'min' => 1,
                    'step' => 1,
                    'class' => 'form-control',
                    'id' => 'transfer-amount',
                    'required' => true,
                ]) ?>
            </div>

            <div class="row">
                <div class="col-md-6 form-group">
                    <?= $this->Form->control('fee_preview', [
                        'label' => __('Transfer fee ($)'),
                        'type' => 'text',
                        'readonly' => true,
                        'class' => 'form-control font-weight-bold',
                        'id' => 'transfer-fee-preview',
                        'value' => $this->Number->format($transferFee, ['places' => 0]),
                    ]) ?>
                </div>

                <div class="col-md-6 form-group">
                    <?= $this->Form->control('total_preview', [
                        'label' => __('Total deduction ($)'),
                        'type' => 'text',
                        'readonly' => true,
                        'class' => 'form-control font-weight-bold text-danger',
                        'id' => 'transfer-total-preview',
                        'value' => $this->Number->format($transferFee, ['places' => 0]),
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

            <div class="d-flex justify-content-end mt-4">
                <?= $this->Html->link(__('Cancel'), ['action' => 'index'], ['class' => 'btn btn-default mr-2']) ?>
                <?= $this->Form->button('<i class="fas fa-arrow-right mr-1"></i> ' . __('Review transfer'), ['class' => 'btn btn-primary', 'escapeTitle' => false]) ?>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<?php $transferFeeValue = (int)$transferFee; ?>
<script>
(function () {
    const amountInput = document.getElementById('transfer-amount');
    if (!amountInput) {
        return;
    }
    const feeField = document.getElementById('transfer-fee-preview');
    const totalField = document.getElementById('transfer-total-preview');
    const fee = <?= json_encode($transferFeeValue) ?>;

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
