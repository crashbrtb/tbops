<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\IncompleteChest $incompleteChest
 * @var list<string> $knownPlayers
 * @var list<string> $knownChests
 * @var list<string> $knownSources
 * @var int|null $pictureId
 */
?>

<?php
$this->assign('title', __('Review Incomplete Chest'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('Incomplete Chests'), 'url' => ['action' => 'index']],
    ['title' => __('#{0}', $incompleteChest->id)],
]);

$labels = [
    'pending' => ['text' => __('Pending'), 'class' => 'badge badge-warning'],
    'corrected' => ['text' => __('Corrected'), 'class' => 'badge badge-success'],
    'unresolved' => ['text' => __('Not recoverable'), 'class' => 'badge badge-secondary'],
];
$label = $labels[$incompleteChest->status] ?? $labels['pending'];
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-search-plus text-primary mr-2"></i><?= __('Review Incomplete Chest') ?>
                <span class="text-muted small ml-1">#<?= $this->Number->format($incompleteChest->id) ?></span>
            </h1>
            <p class="cycle-subtitle">
                <?= __('Manually identify and register collected chest from screenshot') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-arrow-left mr-1"></i> ' . __('Back to Queue'),
                ['action' => 'index'],
                ['class' => 'btn btn-default btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

<div class="row">
    <div class="col-md-7">
        <div class="card card-primary card-outline">
            <div class="card-header d-flex flex-column flex-md-row">
                <h2 class="card-title">
                    <?= __('Chest as it was on screen') ?>
                </h2>
                <div class="d-flex ml-auto">
                    <span class="<?= $label['class'] ?>"><?= $label['text'] ?></span>
                </div>
            </div>
            <div class="card-body text-center">
                <?php if ($pictureId) : ?>
                    <?= $this->Html->image(
                        ['action' => 'image', $pictureId],
                        [
                            'alt' => __('Screenshot of the chest'),
                            'class' => 'img-fluid border rounded',
                            'style' => 'background:#12161c;max-width:100%',
                        ]
                    ) ?>
                    <p class="text-muted mt-2 mb-0">
                        <?= __('Read the text in the picture and fill in the fields beside it.') ?>
                    </p>
                <?php else : ?>
                    <div class="p-5 text-muted">
                        <i class="fas fa-image fa-2x mb-2"></i><br>
                        <?= __('This record has no screenshot — it was collected before the screenshot was kept.') ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <dl class="row mb-0">
                    <dt class="col-sm-4"><?= __('Collected at') ?></dt>
                    <dd class="col-sm-8"><?= h($incompleteChest->collected_at) ?></dd>
                    <?php if ($incompleteChest->game_type_id !== null) : ?>
                        <dt class="col-sm-4"><?= __('Chest code') ?></dt>
                        <dd class="col-sm-8"><?= h($incompleteChest->game_type_id) ?></dd>
                    <?php endif; ?>
                    <dt class="col-sm-4"><?= __('Read as chest') ?></dt>
                    <dd class="col-sm-8"><?= $incompleteChest->name ? h($incompleteChest->name) : '<em class="text-muted">' . __('nothing') . '</em>' ?></dd>
                    <dt class="col-sm-4"><?= __('Read as player') ?></dt>
                    <dd class="col-sm-8"><?= $incompleteChest->player ? h($incompleteChest->player) : '<em class="text-muted">' . __('nothing') . '</em>' ?></dd>
                    <dt class="col-sm-4"><?= __('Read as source') ?></dt>
                    <dd class="col-sm-8"><?= $incompleteChest->source ? h($incompleteChest->source) : '<em class="text-muted">' . __('nothing') . '</em>' ?></dd>
                    <?php if (!$incompleteChest->is_pending) : ?>
                        <dt class="col-sm-4"><?= __('Reviewed at') ?></dt>
                        <dd class="col-sm-8"><?= h($incompleteChest->reviewed_at) ?></dd>
                        <?php if ($incompleteChest->collected_chest_id) : ?>
                            <dt class="col-sm-4"><?= __('Collected chest') ?></dt>
                            <dd class="col-sm-8">#<?= $this->Number->format($incompleteChest->collected_chest_id) ?></dd>
                        <?php endif; ?>
                        <?php if ($incompleteChest->review_notes) : ?>
                            <dt class="col-sm-4"><?= __('Notes') ?></dt>
                            <dd class="col-sm-8"><?= h($incompleteChest->review_notes) ?></dd>
                        <?php endif; ?>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <?php if ($incompleteChest->is_pending) : ?>
            <div class="card card-success card-outline">
                <div class="card-header">
                    <h2 class="card-title"><?= __('Correct and record') ?></h2>
                </div>
                <?= $this->Form->create(null, ['url' => ['action' => 'correct', $incompleteChest->id]]) ?>
                <div class="card-body">
                    <?= $this->Form->control('name', [
                        'label' => __('Chest'),
                        'value' => $incompleteChest->name,
                        'class' => 'form-control',
                        'list' => 'known-chests',
                        'maxlength' => 50,
                        'required' => true,
                    ]) ?>
                    <datalist id="known-chests">
                        <?php foreach ($knownChests as $option) : ?>
                            <option value="<?= h($option) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>

                    <?= $this->Form->control('player', [
                        'label' => __('Player'),
                        'value' => $incompleteChest->player,
                        'class' => 'form-control',
                        'list' => 'known-players',
                        'maxlength' => 50,
                        'required' => true,
                    ]) ?>
                    <datalist id="known-players">
                        <?php foreach ($knownPlayers as $option) : ?>
                            <option value="<?= h($option) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>

                    <?= $this->Form->control('source', [
                        'label' => __('Source'),
                        'value' => $incompleteChest->source,
                        'class' => 'form-control',
                        'list' => 'known-sources',
                        'maxlength' => 50,
                        'required' => true,
                    ]) ?>
                    <datalist id="known-sources">
                        <?php foreach ($knownSources as $option) : ?>
                            <option value="<?= h($option) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>

                    <p class="text-muted mt-3 mb-0">
                        <?= __('The chest is recorded with the date it was collected, not today, so it counts in the right cycle.') ?>
                    </p>
                </div>
                <div class="card-footer">
                    <?= $this->Form->button(__('Record in collected chests'), ['class' => 'btn btn-success']) ?>
                    <?= $this->Html->link(__('Back to the queue'), ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
                </div>
                <?= $this->Form->end() ?>
            </div>

            <div class="card card-outline card-secondary">
                <div class="card-header">
                    <h2 class="card-title"><?= __('Cannot be recovered') ?></h2>
                </div>
                <?= $this->Form->create(null, ['url' => ['action' => 'unresolved', $incompleteChest->id]]) ?>
                <div class="card-body">
                    <p class="text-muted">
                        <?= __('Use this when the picture does not show enough to fill the fields in. The record stays, marked as reviewed, and leaves the queue.') ?>
                    </p>
                    <?= $this->Form->control('review_notes', [
                        'label' => __('Note (optional)'),
                        'class' => 'form-control',
                        'maxlength' => 255,
                    ]) ?>
                </div>
                <div class="card-footer">
                    <?= $this->Form->button(__('Mark as reviewed, not recovered'), ['class' => 'btn btn-outline-secondary']) ?>
                </div>
                <?= $this->Form->end() ?>
            </div>
        <?php else : ?>
            <div class="card card-outline card-secondary">
                <div class="card-header">
                    <h2 class="card-title"><?= __('Already reviewed') ?></h2>
                </div>
                <div class="card-body">
                    <p>
                        <?= $incompleteChest->status === 'corrected'
                            ? __('This chest was corrected and recorded in collected chests.')
                            : __('This chest was examined and could not be recovered.') ?>
                    </p>
                </div>
                <div class="card-footer">
                    <?= $this->Form->postLink(
                        __('Put back in the queue'),
                        ['action' => 'reopen', $incompleteChest->id],
                        [
                            'class' => 'btn btn-outline-warning',
                            'confirm' => __('Review this chest again? Any collected chest already recorded from it stays as it is.'),
                        ]
                    ) ?>
                    <?= $this->Html->link(__('Back to the queue'), ['action' => 'index'], ['class' => 'btn btn-outline-secondary']) ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
