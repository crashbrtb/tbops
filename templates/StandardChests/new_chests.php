<?php
/**
 * @var \App\View\AppView $this
 * @var array $lostChests
 */
?>

<?php
$this->assign('title', __('Lost Chests'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('Standard Chests'), 'url' => ['action' => 'index']],
    ['title' => __('Lost Chests')],
]);

$totalLostTypes = count($lostChests);
$totalLostChestsCount = array_sum(array_column($lostChests, 'total_count'));
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-question-circle text-warning mr-2"></i><?= __('Lost Chests') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('Review unregistered chests found in collection records and promote them to Standard Chests') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-arrow-left mr-1"></i> ' . __('Back to Standard Chests'),
                ['action' => 'index'],
                ['class' => 'btn btn-default btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

<div class="row mb-3">
    <div class="col-12 col-sm-6 col-md-4">
        <div class="info-box shadow-sm">
            <span class="info-box-icon bg-warning text-dark"><i class="fas fa-question-circle"></i></span>
            <div class="info-box-content">
                <span class="info-box-text"><?= __('Unregistered Chest Types') ?></span>
                <span class="info-box-number"><?= number_format($totalLostTypes) ?></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <div class="info-box shadow-sm">
            <span class="info-box-icon bg-danger"><i class="fas fa-boxes"></i></span>
            <div class="info-box-content">
                <span class="info-box-text"><?= __('Total Unregistered Collections') ?></span>
                <span class="info-box-number"><?= number_format($totalLostChestsCount) ?></span>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-12 col-md-4">
        <div class="info-box shadow-sm">
            <span class="info-box-icon bg-primary"><i class="fas fa-list-check"></i></span>
            <div class="info-box-content">
                <span class="info-box-text"><?= __('Quick Action') ?></span>
                <span class="info-box-text text-muted" style="font-size: 0.85rem;">
                    <?= __('Configure score & monster status, select and add to Standard Chests.') ?>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="card card-outline card-warning shadow-sm">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
        <div class="d-flex align-items-center my-1">
            <h3 class="card-title font-weight-bold mb-0">
                <i class="fas fa-search-location text-warning mr-2"></i>
                <?= __('Lost Chests Detected in Collected History') ?>
            </h3>
            <span class="badge badge-warning ml-2 font-weight-normal"><?= $totalLostTypes ?> <?= __('types') ?></span>
        </div>

        <div class="d-flex align-items-center my-1">
            <div class="input-group input-group-sm mr-2" style="width: 220px;">
                <input type="text" id="tableFilterInput" class="form-control" placeholder="<?= __('Filter chest names...') ?>" onkeyup="filterLostTable(this.value)">
                <div class="input-group-append">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                </div>
            </div>
            <?= $this->Html->link('<i class="fas fa-arrow-left mr-1"></i> ' . __('Back to Standard Chests'), ['controller' => 'StandardChests', 'action' => 'index'], ['class' => 'btn btn-secondary btn-sm', 'escape' => false]) ?>
        </div>
    </div>

    <?php if (empty($lostChests)): ?>
        <div class="card-body py-5 text-center">
            <div class="text-success mb-3">
                <i class="fas fa-check-circle fa-4x"></i>
            </div>
            <h4 class="text-success font-weight-bold"><?= __('All Chests are Registered!') ?></h4>
            <p class="text-muted mb-4">
                <?= __('There are no collected chests missing from your Standard Chests catalog. Everything is up to date.') ?>
            </p>
            <?= $this->Html->link('<i class="fas fa-th-list mr-1"></i> ' . __('View Standard Chests'), ['controller' => 'StandardChests', 'action' => 'index'], ['class' => 'btn btn-primary', 'escape' => false]) ?>
        </div>
    <?php else: ?>
        <?= $this->Form->create(null, ['id' => 'bulkAddForm', 'url' => ['controller' => 'StandardChests', 'action' => 'lostChests']]) ?>
        
        <div class="bg-light p-3 border-bottom d-flex flex-wrap align-items-center justify-content-between">
            <div class="d-flex align-items-center my-1 flex-wrap">
                <button type="button" class="btn btn-outline-secondary btn-sm mr-2 mb-1" onclick="toggleSelectAll(true)">
                    <i class="far fa-check-square mr-1"></i> <?= __('Select All') ?>
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm mr-3 mb-1" onclick="toggleSelectAll(false)">
                    <i class="far fa-square mr-1"></i> <?= __('Deselect All') ?>
                </button>
                
                <span class="text-muted small mr-3 mb-1">
                    <span id="selectedCountBadge" class="badge badge-primary">0</span> <?= __('selected') ?>
                </span>
            </div>

            <div class="d-flex align-items-center my-1">
                <button type="submit" id="btnSubmitSelected" class="btn btn-success btn-sm px-3 font-weight-bold" disabled>
                    <i class="fas fa-plus-circle mr-1"></i> <?= __('Add Selected to Standard Chests') ?>
                </button>
            </div>
        </div>

        <div class="card-body table-responsive p-0">
            <table class="table table-hover table-striped mb-0" id="lostChestsTable">
                <thead class="thead-light">
                    <tr>
                        <th style="width: 40px; text-align: center;">
                            <input type="checkbox" id="masterCheckbox" title="<?= __('Select all') ?>" onchange="toggleSelectAll(this.checked)">
                        </th>
                        <th><?= __('Chest Source (Name)') ?></th>
                        <th style="width: 170px;"><?= __('Alias (optional)') ?></th>
                        <th style="text-align: center; width: 110px;"><?= __('Times Seen') ?></th>
                        <th style="text-align: center; width: 90px;"><?= __('Players') ?></th>
                        <th style="width: 150px;"><?= __('Activity Window') ?></th>
                        <th style="width: 120px;"><?= __('Points (Score)') ?></th>
                        <th style="width: 140px; text-align: center;"><?= __('Epic Monster?') ?></th>
                        <th style="width: 110px;"><?= __('Qty / Kill') ?></th>
                        <th style="width: 90px; text-align: center;"><?= __('Action') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lostChests as $i => $row): ?>
                        <?php
                        $source = (string)$row['source'];
                        $totalCount = (int)$row['total_count'];
                        $playersCount = (int)$row['players_count'];
                        $firstSeen = !empty($row['first_seen']) ? (new \Cake\I18n\DateTime($row['first_seen']))->format('d/m/Y') : '-';
                        $lastSeen = !empty($row['last_seen']) ? (new \Cake\I18n\DateTime($row['last_seen']))->format('d/m/Y') : '-';

                        // Auto-detect if name suggests an epic monster / squad / raid
                        $isMonsterSuggested = (bool)preg_match('/(monster|squad|boss|dread|omens|ancient|raid)/i', $source);
                        ?>
                        <tr id="row-<?= $i ?>">
                            <td style="text-align: center; vertical-align: middle;">
                                <input type="checkbox" name="chests[<?= $i ?>][selected]" value="1" class="chest-select-box" onchange="updateSelectedCount()">
                                <input type="hidden" name="chests[<?= $i ?>][source]" value="<?= h($source) ?>">
                            </td>
                            <td style="vertical-align: middle;">
                                <div class="font-weight-bold text-dark">
                                    <i class="fas fa-box-open text-muted mr-1"></i>
                                    <?= h($source) ?>
                                </div>
                                <?php if ($isMonsterSuggested): ?>
                                    <small class="badge badge-danger-light text-danger" style="background: #fee2e2; border: 1px solid #fecaca; border-radius: 4px; padding: 2px 6px;">
                                        <i class="fas fa-dragon mr-1"></i> <?= __('Possible Monster Chest') ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td style="vertical-align: middle;">
                                <input type="text" name="chests[<?= $i ?>][alias]" maxlength="50" placeholder="<?= __('Friendly name') ?>" class="form-control form-control-sm chest-alias-input" title="<?= __('Optional friendly name shown in reports instead of the source') ?>">
                            </td>
                            <td style="text-align: center; vertical-align: middle;">
                                <span class="badge badge-info px-2 py-1 font-weight-bold" style="font-size: 0.9rem;">
                                    <?= number_format($totalCount) ?>
                                </span>
                            </td>
                            <td style="text-align: center; vertical-align: middle;">
                                <span class="badge badge-secondary px-2 py-1 font-weight-normal">
                                    <i class="fas fa-users mr-1"></i><?= number_format($playersCount) ?>
                                </span>
                            </td>
                            <td style="vertical-align: middle; font-size: 0.85rem;" class="text-muted">
                                <div><i class="far fa-calendar-alt mr-1"></i> <?= __('First:') ?> <?= h($firstSeen) ?></div>
                                <div><i class="far fa-clock mr-1"></i> <?= __('Last:') ?> <?= h($lastSeen) ?></div>
                            </td>
                            <td style="vertical-align: middle;">
                                <input type="number" name="chests[<?= $i ?>][score]" value="0" min="0" step="1" class="form-control form-control-sm chest-score-input" required>
                            </td>
                            <td style="text-align: center; vertical-align: middle;">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" name="chests[<?= $i ?>][monster]" value="1" class="custom-control-input" id="monsterCheck<?= $i ?>" <?= $isMonsterSuggested ? 'checked' : '' ?>>
                                    <label class="custom-control-label" for="monsterCheck<?= $i ?>"><?= __('Yes') ?></label>
                                </div>
                            </td>
                            <td style="vertical-align: middle;">
                                <input type="number" name="chests[<?= $i ?>][qty_chest]" placeholder="<?= __('Opt.') ?>" min="1" step="1" class="form-control form-control-sm">
                            </td>
                            <td style="text-align: center; vertical-align: middle;">
                                <button type="button" class="btn btn-outline-success btn-sm" title="<?= __('Add only this chest') ?>" onclick="submitSingleRow(<?= $i ?>)">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card-footer d-flex align-items-center justify-content-between bg-light">
            <div class="text-muted small">
                <?= __('Tip: Fill in the points and select which chests you want to register. Unselected chests will remain in the lost list.') ?>
            </div>
            <button type="submit" class="btn btn-success font-weight-bold" id="btnSubmitSelectedBottom" disabled>
                <i class="fas fa-plus-circle mr-1"></i> <?= __('Add Selected to Standard Chests') ?>
            </button>
        </div>

        <?= $this->Form->end() ?>

        <!-- Hidden form for quick single-row submission -->
        <?= $this->Form->create(null, ['id' => 'singleAddForm', 'url' => ['controller' => 'StandardChests', 'action' => 'lostChests'], 'style' => 'display:none;']) ?>
            <input type="hidden" name="single_source" id="singleSourceInput">
            <input type="hidden" name="alias" id="singleAliasInput">
            <input type="hidden" name="score" id="singleScoreInput">
            <input type="hidden" name="monster" id="singleMonsterInput">
            <input type="hidden" name="qty_chest" id="singleQtyInput">
        <?= $this->Form->end() ?>

    <?php endif; ?>
</div>

<script>
function updateSelectedCount() {
    var checkboxes = document.querySelectorAll('.chest-select-box');
    var count = 0;
    var allChecked = true;

    checkboxes.forEach(function(cb) {
        var tr = cb.closest('tr');
        if (tr.style.display !== 'none') {
            if (cb.checked) {
                count++;
                tr.classList.add('table-primary');
            } else {
                allChecked = false;
                tr.classList.remove('table-primary');
            }
        }
    });

    var badge = document.getElementById('selectedCountBadge');
    if (badge) badge.textContent = count;

    var btnTop = document.getElementById('btnSubmitSelected');
    var btnBottom = document.getElementById('btnSubmitSelectedBottom');
    var master = document.getElementById('masterCheckbox');

    var disabled = (count === 0);
    if (btnTop) {
        btnTop.disabled = disabled;
        btnTop.innerHTML = '<i class="fas fa-plus-circle mr-1"></i> <?= __('Add') ?> (' + count + ') <?= __('Selected to Standard Chests') ?>';
    }
    if (btnBottom) {
        btnBottom.disabled = disabled;
        btnBottom.innerHTML = '<i class="fas fa-plus-circle mr-1"></i> <?= __('Add') ?> (' + count + ') <?= __('Selected to Standard Chests') ?>';
    }
    if (master) master.checked = (count > 0 && allChecked);
}

function toggleSelectAll(checked) {
    var checkboxes = document.querySelectorAll('.chest-select-box');
    checkboxes.forEach(function(cb) {
        var tr = cb.closest('tr');
        if (tr.style.display !== 'none') {
            cb.checked = checked;
        }
    });
    updateSelectedCount();
}

function filterLostTable(query) {
    var filter = (query || '').toLowerCase().trim();
    var table = document.getElementById('lostChestsTable');
    if (!table) return;

    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    for (var i = 0; i < rows.length; i++) {
        var text = rows[i].textContent.toLowerCase();
        if (text.indexOf(filter) > -1) {
            rows[i].style.display = '';
        } else {
            rows[i].style.display = 'none';
        }
    }
    updateSelectedCount();
}

function submitSingleRow(index) {
    var row = document.getElementById('row-' + index);
    if (!row) return;

    var sourceInput = row.querySelector('input[name="chests[' + index + '][source]"]');
    var aliasInput = row.querySelector('input[name="chests[' + index + '][alias]"]');
    var scoreInput = row.querySelector('input[name="chests[' + index + '][score]"]');
    var monsterInput = row.querySelector('input[name="chests[' + index + '][monster]"]');
    var qtyInput = row.querySelector('input[name="chests[' + index + '][qty_chest]"]');

    if (!sourceInput) return;

    document.getElementById('singleSourceInput').value = sourceInput.value;
    document.getElementById('singleAliasInput').value = aliasInput ? aliasInput.value : '';
    document.getElementById('singleScoreInput').value = scoreInput ? scoreInput.value : 0;
    document.getElementById('singleMonsterInput').value = (monsterInput && monsterInput.checked) ? '1' : '0';
    document.getElementById('singleQtyInput').value = qtyInput ? qtyInput.value : '';

    if (confirm('<?= __('Add "{0}" to Standard Chests?', '') ?>' + sourceInput.value + '"?')) {
        document.getElementById('singleAddForm').submit();
    }
}
</script>
</div>
