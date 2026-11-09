<?php
/**
 * @var \App\View\AppView $this
 * @var array $ownMembers
 * @var array $allMembers
 * @var bool $isAdmin
 * @var float $depositFee
 * @var float $caravanFee
 */

$selectOptions = $ownMembers;
if ($isAdmin) {
    $otherMembers = array_diff_key($allMembers, $ownMembers);
    $selectOptions = [
        __('My members') => $ownMembers,
    ];
    if (!empty($otherMembers)) {
        $selectOptions[__('All members')] = $otherMembers;
    }
}
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div>
            <h1 class="score-title"><?= __('Register Deposit') ?></h1>
            <p class="cycle-subtitle"><?= __('Request a Silver deposit. Amounts represent millions of Silver ($)') ?></p>
        </div>
        <div class="actions">
            <?= $this->Html->link('<i class="fas fa-arrow-left mr-1"></i> ' . __('Back to Bank'), ['action' => 'index'], ['class' => 'btn btn-default btn-sm', 'escape' => false]) ?>
        </div>
    </div>

    <div class="goal-pill">
        <?= __('Flat deposit fee: {0} | Caravan fee: {1}%', $this->silver($depositFee), $this->Number->format($caravanFee, ['places' => 0])) ?>
    </div>

    <div class="card ranking-card">
        <div class="ranking-header">
            <h3 class="card-title"><?= __('Deposit Details') ?></h3>
        </div>
        <div class="card-body">
            <?= $this->Form->create(null) ?>
            <div class="form-group">
                <?= $this->Form->control('member_id', [
                    'label' => __('Member'),
                    'options' => $selectOptions,
                    'class' => 'form-control',
                    'required' => true,
                ]) ?>
            </div>
            <div class="form-group">
                <?= $this->Form->control('amount', [
                    'label' => __('Amount (millions of Silver)'),
                    'type' => 'number',
                    'min' => 1,
                    'step' => 1,
                    'id' => 'deposit-amount',
                    'class' => 'form-control',
                    'required' => true,
                ]) ?>
                <small class="form-text text-muted">
                    <?= __('Example: 10 means 10 million Silver ($). Only integers are accepted.') ?>
                </small>
            </div>

            <div class="form-check mb-3 mt-3">
                <?= $this->Form->checkbox('caravan', ['class' => 'form-check-input', 'id' => 'deposit-caravan']) ?>
                <?= $this->Form->label('deposit-caravan', __('Caravan (apply caravan fee and zero flat fee)'), ['class' => 'form-check-label font-weight-bold']) ?>
            </div>

            <div class="row">
                <div class="col-md-4 form-group">
                    <?= $this->Form->control('fee_preview', [
                        'label' => __('Estimated total fee ($)'),
                        'type' => 'text',
                        'readonly' => true,
                        'class' => 'form-control font-weight-bold',
                        'id' => 'deposit-fee-preview',
                        'value' => $this->Number->format(0, ['places' => 0]),
                    ]) ?>
                </div>

                <div class="col-md-4 form-group caravan-field d-none">
                    <?= $this->Form->control('caravan_preview', [
                        'label' => __('Amount after caravan fee ($)'),
                        'type' => 'text',
                        'readonly' => true,
                        'class' => 'form-control font-weight-bold',
                        'id' => 'deposit-caravan-preview',
                        'value' => $this->Number->format(0, ['places' => 0]),
                    ]) ?>
                </div>

                <div class="col-md-4 form-group">
                    <?= $this->Form->control('final_preview', [
                        'label' => __('Net amount to credit ($)'),
                        'type' => 'text',
                        'readonly' => true,
                        'class' => 'form-control font-weight-bold text-success',
                        'id' => 'deposit-final-preview',
                        'value' => $this->Number->format(0, ['places' => 0]),
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
                    ? __('Administrator deposits are approved instantly.')
                    : __('Deposits created by players stay pending until an administrator approves them.') ?>
            </div>

            <div class="d-flex justify-content-end">
                <?= $this->Html->link(__('Cancel'), ['action' => 'index'], ['class' => 'btn btn-default mr-2']) ?>
                <?= $this->Form->button('<i class="fas fa-check-circle mr-1"></i> ' . __('Submit deposit'), ['class' => 'btn btn-success', 'escapeTitle' => false]) ?>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<?php
$depositFeeValue = (int)$depositFee;
$caravanFeeValue = (int)$caravanFee;
?>

<script>
(function () {
    const amountInput = document.getElementById('deposit-amount');
    const caravanCheckbox = document.getElementById('deposit-caravan');
    const feeField = document.getElementById('deposit-fee-preview');
    const finalField = document.getElementById('deposit-final-preview');
    const caravanFieldWrapper = document.querySelector('.caravan-field');
    const caravanPreview = document.getElementById('deposit-caravan-preview');
    const depositFee = <?= json_encode($depositFeeValue) ?>;
    const caravanPercent = <?= json_encode($caravanFeeValue) ?>;

    const recalc = () => {
        const amount = parseInt(amountInput.value, 10) || 0;
        const actualDepositFee = caravanCheckbox.checked ? 0 : depositFee;
        const caravanFeeValue = caravanCheckbox.checked ? Math.floor(amount * (caravanPercent / 100)) : 0;
        const totalFee = actualDepositFee + caravanFeeValue;
        let caravanNet = amount - caravanFeeValue;
        if (caravanNet < 0) {
            caravanNet = 0;
        }
        let finalAmount = amount - totalFee;
        if (finalAmount < 0) {
            finalAmount = 0;
        }

        feeField.value = String(totalFee);
        finalField.value = String(finalAmount);

        if (caravanCheckbox.checked) {
            caravanFieldWrapper.classList.remove('d-none');
            caravanPreview.value = String(caravanNet);
        } else {
            caravanFieldWrapper.classList.add('d-none');
        }
    };

    amountInput.addEventListener('input', recalc);
    caravanCheckbox.addEventListener('change', recalc);
    recalc();
})();
</script>
