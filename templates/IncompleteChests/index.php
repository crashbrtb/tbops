<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\IncompleteChest> $incompleteChests
 * @var string $status
 * @var array<string, int> $counts
 * @var list<array<string, mixed>> $typesToName
 * @var list<string> $knownChests
 * @var list<string> $knownSources
 */
?>

<?php
$this->assign('title', __('Incomplete Chests'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('Incomplete Chests')],
]);

$labels = [
    'pending' => ['text' => __('Pending'), 'class' => 'badge badge-warning'],
    'corrected' => ['text' => __('Corrected'), 'class' => 'badge badge-success'],
    'unresolved' => ['text' => __('Not recoverable'), 'class' => 'badge badge-secondary'],
];
$filters = [
    'pending' => __('Pending ({0})', $counts['pending'] ?? 0),
    'corrected' => __('Corrected ({0})', $counts['corrected'] ?? 0),
    'unresolved' => __('Not recoverable ({0})', $counts['unresolved'] ?? 0),
    'all' => __('All'),
];
$pendingCount = (int)($counts['pending'] ?? 0);
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-box-open text-warning mr-2"></i><?= __('Incomplete Chests') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('Review and manually transcribe unopened or unread chests captured by collectors') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-boxes mr-1"></i> ' . __('Standard Chests'),
                ['controller' => 'StandardChests', 'action' => 'index'],
                ['class' => 'btn btn-default btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

<?php if ($typesToName) : ?>
<div class="card card-warning card-outline shadow-sm">
    <div class="card-header">
        <h3 class="card-title font-weight-bold mb-0">
            <i class="fas fa-tag text-warning mr-2"></i><?= __('New chest types') ?>
        </h3>
    </div>
    <div class="card-body pb-2 pt-3">
        <p class="text-muted mb-0 small">
            <?= __('The game has chests the system does not know yet. Copy the name and the source from the picture and save: all the chests of that type are recorded, each with its own player, and the next ones are recognised on their own.') ?>
        </p>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table mb-0" id="typesToName">
            <thead class="thead-light">
                <tr>
                    <th><?= __('Picture') ?></th>
                    <th style="width: 130px; text-align: center;"><?= __('Chests waiting') ?></th>
                    <th style="min-width: 280px;"><?= __('Name it') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($typesToName as $type) : ?>
                    <tr>
                        <td style="vertical-align: middle;">
                            <?php if ($type['picture_id']) : ?>
                                <?= $this->Html->image(
                                    ['action' => 'image', $type['picture_id']],
                                    ['alt' => __('Picture of the chest'), 'class' => 'img-fluid border rounded', 'style' => 'max-width: 420px;']
                                ) ?>
                                <?php if ($type['picture_player']) : ?>
                                    <div class="text-muted small mt-1">
                                        <?= __('Check: the top line of the picture must be from {0}.', h($type['picture_player'])) ?>
                                    </div>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="text-muted font-italic"><?= __('No picture. Name it only if you know which chest it is.') ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: middle;">
                            <span class="badge badge-warning px-2 py-1" style="font-size: 1rem;"><?= $this->Number->format($type['waiting']) ?></span>
                            <div class="text-muted small mt-1"><?= __('Code {0}', h($type['game_type_id'])) ?></div>
                        </td>
                        <td style="vertical-align: middle;">
                            <?= $this->Form->create(null, ['url' => ['action' => 'nameCode']]) ?>
                            <?= $this->Form->hidden('game_type_id', ['value' => $type['game_type_id']]) ?>
                            <?= $this->Form->control('name', [
                                'label' => __('Chest'), 'class' => 'form-control form-control-sm', 'list' => 'known-chests',
                                'maxlength' => 50, 'required' => true, 'id' => 'name-' . $type['game_type_id'],
                            ]) ?>
                            <?= $this->Form->control('source', [
                                'label' => __('Source'), 'class' => 'form-control form-control-sm', 'list' => 'known-sources',
                                'maxlength' => 50, 'required' => true, 'id' => 'source-' . $type['game_type_id'],
                            ]) ?>
                            <?= $this->Form->button(__('Save and record the chests'), ['class' => 'btn btn-success btn-sm']) ?>
                            <?= $this->Form->end() ?>
                            <?= $this->Form->postLink(
                                __('Cannot be identified'),
                                ['action' => 'dropCode'],
                                [
                                    'data' => ['game_type_id' => $type['game_type_id']],
                                    'class' => 'btn btn-link btn-sm text-muted p-0 mt-2',
                                    'confirm' => __('Mark these {0} chest(s) as not recoverable? They will not count for anyone.', $type['waiting']),
                                ]
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <datalist id="known-chests">
        <?php foreach ($knownChests as $option) : ?>
            <option value="<?= h($option) ?>"></option>
        <?php endforeach; ?>
    </datalist>
    <datalist id="known-sources">
        <?php foreach ($knownSources as $option) : ?>
            <option value="<?= h($option) ?>"></option>
        <?php endforeach; ?>
    </datalist>
</div>
<?php endif; ?>

<div class="card card-primary card-outline shadow-sm">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
        <h3 class="card-title font-weight-bold mb-0">
            <i class="fas fa-filter text-primary mr-2"></i>
            <?= __('Status Filters') ?>
        </h3>
        <div class="d-flex align-items-center my-1 flex-wrap">
            <div class="btn-group btn-group-sm">
                <?php foreach ($filters as $key => $text) : ?>
                    <?= $this->Html->link($text, ['action' => 'index', '?' => ['status' => $key]], [
                        'class' => 'btn ' . ($status === $key ? 'btn-primary font-weight-bold' : 'btn-outline-primary'),
                    ]) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <!-- /.card-header -->

    <div class="card-body pb-2 pt-3">
        <p class="text-muted mb-0 small">
            <?= __('Chests the collector opened but could not read. The screenshot of each one was kept so it can be filled in by hand — the chest itself is no longer in the game.') ?>
        </p>
    </div>

    <?= $this->Form->create(null, ['url' => ['action' => 'bulkUnresolved'], 'id' => 'bulkChestsForm']) ?>
    <?= $this->Form->hidden('status', ['value' => $status]) ?>

    <!-- Bulk Action Toolbar -->
    <div class="bg-light px-3 py-2 border-top border-bottom d-flex flex-wrap align-items-center justify-content-between">
        <div class="d-flex align-items-center my-1 flex-wrap">
            <button type="button" class="btn btn-outline-secondary btn-xs mr-2 mb-1" id="btnSelectAll" onclick="toggleAllChests(true)">
                <i class="far fa-check-square mr-1"></i> <?= __('Select all') ?>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-xs mr-3 mb-1" id="btnDeselectAll" onclick="toggleAllChests(false)">
                <i class="far fa-square mr-1"></i> <?= __('Deselect all') ?>
            </button>
            <span class="text-muted small mb-1 mr-3">
                <span id="selectedCountBadge" class="badge badge-secondary">0</span> <?= __('chests selected') ?>
            </span>
        </div>

        <div class="d-flex align-items-center my-1 flex-wrap">
            <button type="submit" id="btnSubmitBulk" class="btn btn-secondary btn-sm mr-2 mb-1" disabled onclick="return confirmBulkAction();">
                <i class="fas fa-ban mr-1"></i> <?= __('Mark selected as not recoverable') ?>
            </button>
            <?php if ($pendingCount > 0 && $status === 'pending') : ?>
                <button type="button" class="btn btn-outline-secondary btn-sm mb-1" onclick="markAllPending();">
                    <i class="fas fa-times-circle mr-1"></i> <?= __('Mark all {0} pending as not recoverable', $pendingCount) ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body table-responsive p-0">
        <table class="table table-hover mb-0" style="table-layout: auto; width: 100%;">
            <thead class="thead-light">
                <tr>
                    <th style="width: 40px; text-align: center; vertical-align: middle;">
                        <input type="checkbox" id="masterCheckbox" title="<?= __('Select all') ?>" onchange="toggleAllChests(this.checked)">
                    </th>
                    <th style="width: 65px; vertical-align: middle;"><?= $this->Paginator->sort('id', '#') ?></th>
                    <th style="width: 115px; vertical-align: middle;"><?= $this->Paginator->sort('collected_at', __('Collected at')) ?></th>
                    <th style="min-width: 140px; vertical-align: middle;"><?= $this->Paginator->sort('name', __('Chest')) ?></th>
                    <th style="min-width: 120px; vertical-align: middle;"><?= $this->Paginator->sort('player', __('Player')) ?></th>
                    <th style="min-width: 130px; vertical-align: middle;"><?= $this->Paginator->sort('source', __('Source')) ?></th>
                    <th style="width: 110px; text-align: center; vertical-align: middle;"><?= $this->Paginator->sort('status', __('Status')) ?></th>
                    <?php if ($status !== 'pending') : ?>
                        <th style="width: 115px; vertical-align: middle;"><?= $this->Paginator->sort('reviewed_at', __('Reviewed at')) ?></th>
                    <?php endif; ?>
                    <th style="width: 85px; text-align: center; vertical-align: middle;" class="actions"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $shown = 0; ?>
                <?php foreach ($incompleteChests as $incompleteChest) : ?>
                    <?php $shown++; ?>
                    <?php $label = $labels[$incompleteChest->status] ?? $labels['pending']; ?>
                    <tr>
                        <td style="text-align: center; vertical-align: middle;">
                            <?php if ($incompleteChest->is_pending) : ?>
                                <input type="checkbox" name="ids[]" value="<?= $incompleteChest->id ?>" class="chest-select-box" onchange="updateSelectedCount()">
                            <?php else : ?>
                                <input type="checkbox" disabled class="text-muted" style="opacity: 0.35;" title="<?= __('Already reviewed') ?>">
                            <?php endif; ?>
                        </td>
                        <td style="vertical-align: middle; font-weight: 600; color: #495057;">
                            <?= $this->Number->format($incompleteChest->id) ?>
                        </td>
                        <td style="white-space: nowrap; vertical-align: middle;">
                            <?php if ($incompleteChest->collected_at) : ?>
                                <span class="text-dark font-weight-500"><?= $incompleteChest->collected_at->format('d/m/Y') ?></span><br>
                                <small class="text-muted"><?= $incompleteChest->collected_at->format('H:i:s') ?></small>
                            <?php else : ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="vertical-align: middle; word-break: break-word;">
                            <?php if ($incompleteChest->name) : ?>
                                <strong class="text-dark"><?= h($incompleteChest->name) ?></strong>
                            <?php else : ?>
                                <span class="text-muted font-italic"><?= __('not read') ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="vertical-align: middle; word-break: break-word;">
                            <?= $incompleteChest->player ? h($incompleteChest->player) : '<span class="text-muted font-italic">' . __('not read') . '</span>' ?>
                        </td>
                        <td style="vertical-align: middle; word-break: break-word;">
                            <?= $incompleteChest->source ? h($incompleteChest->source) : '<span class="text-muted font-italic">' . __('not read') . '</span>' ?>
                        </td>
                        <td style="white-space: nowrap; text-align: center; vertical-align: middle;">
                            <span class="<?= $label['class'] ?> px-2 py-1"><?= $label['text'] ?></span>
                        </td>
                        <?php if ($status !== 'pending') : ?>
                            <td style="white-space: nowrap; vertical-align: middle;">
                                <?php if ($incompleteChest->reviewed_at) : ?>
                                    <span class="text-dark font-weight-500"><?= $incompleteChest->reviewed_at->format('d/m/Y') ?></span><br>
                                    <small class="text-muted"><?= $incompleteChest->reviewed_at->format('H:i:s') ?></small>
                                <?php else : ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td style="white-space: nowrap; text-align: center; vertical-align: middle;" class="actions">
                            <?= $this->Html->link(
                                $incompleteChest->is_pending ? '<i class="fas fa-edit mr-1"></i>' . __('Review') : '<i class="fas fa-eye mr-1"></i>' . __('View'),
                                ['action' => 'view', $incompleteChest->id],
                                [
                                    'class' => 'btn btn-xs ' . ($incompleteChest->is_pending ? 'btn-primary' : 'btn-outline-primary'),
                                    'escape' => false,
                                ]
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($shown === 0) : ?>
                    <tr>
                        <td colspan="<?= $status !== 'pending' ? '9' : '8' ?>" class="text-center text-muted p-5">
                            <i class="fas fa-check-circle fa-2x text-success mb-2"></i><br>
                            <?= __('Nothing here — every chest was read.') ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- /.card-body -->

    <?= $this->Form->end() ?>

    <div class="card-footer d-flex flex-column flex-md-row align-items-center">
        <div class="text-muted small">
            <?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?>
        </div>
        <ul class="pagination pagination-sm mb-0 ml-auto mt-2 mt-md-0">
            <?= $this->Paginator->first('<i class="fas fa-angle-double-left"></i>', ['escape' => false]) ?>
            <?= $this->Paginator->prev('<i class="fas fa-angle-left"></i>', ['escape' => false]) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next('<i class="fas fa-angle-right"></i>', ['escape' => false]) ?>
            <?= $this->Paginator->last('<i class="fas fa-angle-double-right"></i>', ['escape' => false]) ?>
        </ul>
    </div>
    <!-- /.card-footer -->
</div>

<script>
function updateSelectedCount() {
    var selectable = document.querySelectorAll('.chest-select-box:not(:disabled)');
    var checked = document.querySelectorAll('.chest-select-box:checked');
    var count = checked.length;

    var badge = document.getElementById('selectedCountBadge');
    if (badge) {
        badge.textContent = count;
        if (count > 0) {
            badge.className = 'badge badge-primary';
        } else {
            badge.className = 'badge badge-secondary';
        }
    }

    var submitBtn = document.getElementById('btnSubmitBulk');
    if (submitBtn) {
        submitBtn.disabled = count === 0;
        if (count > 0) {
            submitBtn.className = 'btn btn-danger btn-sm mr-2 mb-1';
        } else {
            submitBtn.className = 'btn btn-secondary btn-sm mr-2 mb-1';
        }
    }

    var master = document.getElementById('masterCheckbox');
    if (master && selectable.length > 0) {
        master.checked = count === selectable.length;
        master.indeterminate = count > 0 && count < selectable.length;
    }
}

function toggleAllChests(checked) {
    var selectable = document.querySelectorAll('.chest-select-box:not(:disabled)');
    selectable.forEach(function(cb) {
        cb.checked = checked;
    });
    updateSelectedCount();
}

function confirmBulkAction() {
    var checked = document.querySelectorAll('.chest-select-box:checked');
    if (checked.length === 0) {
        alert('<?= __('Please select at least one chest.') ?>');
        return false;
    }
    return confirm('<?= __('Are you sure you want to mark the selected chests as not recoverable?') ?>');
}

function markAllPending() {
    if (confirm('<?= __('Are you sure you want to mark ALL {0} pending chests as not recoverable? This action will update all pending chests across all pages.', $pendingCount) ?>')) {
        var form = document.getElementById('bulkChestsForm');
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'mark_all_pending';
        input.value = '1';
        form.appendChild(input);
        form.submit();
    }
}
</script>
</div>
