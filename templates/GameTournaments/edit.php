<?php
/**
 * Edit one part of a tournament of the catalogue (`$section`), or the name
 * and goal together when no section is given. Saving goes back
 * to the tournament's page.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\GameTournament $tournament
 * @var string $section One of GameTournament::SECTIONS, or '' for the whole form.
 * @var list<array{item_id: int, name: string, times: int, by_position: array<int, int>, last_amount: int}> $prizes
 * @var array<string, string> $ruleOptions
 * @var array<string, string> $remainderOptions
 */

use App\Model\Entity\GameTournament;

$this->assign('title', __('Edit Tournament'));

$shows = fn (string $part): bool => $section === ''
    ? !in_array($part, [
        GameTournament::SECTION_PRIZES,
        GameTournament::SECTION_DESCRIPTION,
        GameTournament::SECTION_FINE,
    ], true)
    : $section === $part;
$titles = [
    GameTournament::SECTION_NAME => __('Name'),
    GameTournament::SECTION_DESCRIPTION => __('Description'),
    GameTournament::SECTION_GOAL => __('Goal'),
    GameTournament::SECTION_PRIZES => __('Prizes'),
    GameTournament::SECTION_FINE => __('Fine'),
];
$selected = [];
foreach ($tournament->prizeSelection() as $prize) {
    $selected[$prize['item_id']] = $prize;
}
// A prize chosen earlier that the clan's results no longer list is still offered.
foreach ($selected as $itemId => $prize) {
    if (!in_array($itemId, array_column($prizes, 'item_id'), true)) {
        $prizes[] = ['item_id' => $itemId, 'name' => $prize['name'], 'times' => 0, 'by_position' => [], 'last_amount' => 0];
    }
}
?>
<div class="content-page-wrap">

    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-pencil-alt text-primary"></i> <?= h($tournament->displayName()) ?>
                <?php if ($section !== ''): ?>
                    <small class="text-muted">&middot; <?= h($titles[$section]) ?></small>
                <?php endif; ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('Game id {0}', $tournament->gameKey()) ?>
                <?php if ($tournament->ranking !== ''): ?>
                    &middot; <?= h(__('Ranking {0}', $tournament->rankingLabel())) ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-arrow-left mr-1"></i>' . __('Back to the tournament'),
                ['action' => 'view', $tournament->id],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <?= $this->Form->create($tournament, ['type' => 'file']) ?>
    <?= $this->Form->hidden('section', ['value' => $section]) ?>

    <?php if ($shows(GameTournament::SECTION_NAME) || $shows(GameTournament::SECTION_DESCRIPTION)): ?>
    <div class="event-form-card">
        <div class="event-form-grid">
            <?php if ($shows(GameTournament::SECTION_NAME)): ?>
                <div class="form-group">
                    <?php if ($tournament->isNamedByTheGame()): ?>
                        <label><?= __('Name in the game') ?></label>
                        <input type="text" class="form-control" value="<?= h($tournament->name) ?>" readonly>
                        <small class="form-text text-muted"><?= __('Read from the game\'s Journal. It is kept as the game names it, in English.') ?></small>
                    <?php else: ?>
                        <?= $this->Form->control('name', [
                            'label' => __('Name in the game'),
                            'class' => 'form-control',
                            'maxlength' => 120,
                            'placeholder' => __('As it appears in the game'),
                        ]) ?>
                        <small class="form-text text-muted">
                            <?= __('The game has not said it yet. A name typed here is kept: the collector will not overwrite it.') ?>
                        </small>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <?= $this->Form->control('display_name', [
                        'label' => __('Display name'),
                        'class' => 'form-control',
                        'maxlength' => 120,
                        'placeholder' => $tournament->gameName(),
                    ]) ?>
                    <small class="form-text text-muted">
                        <?= __('The name the site shows for this tournament and its new events. Empty: the game\'s name.') ?>
                    </small>
                </div>
            <?php endif; ?>

            <?php if ($shows(GameTournament::SECTION_DESCRIPTION)): ?>
                <div class="form-group full-width">
                    <?= $this->Form->control('description', [
                        'label' => __('Description'),
                        'type' => 'textarea',
                        'rows' => 5,
                        'maxlength' => 2000,
                        'class' => 'form-control',
                    ]) ?>
                    <small class="form-text text-muted"><?= __('Shown on every new event of this tournament.') ?></small>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($shows(GameTournament::SECTION_GOAL)): ?>
        <div class="event-form-card">
            <h2><i class="fas fa-bullseye text-primary"></i> <?= __('Default goal') ?></h2>
            <p class="section-hint">
                <?= __('Every new event of this tournament sent by the EventUploader starts with this goal. It can still be changed in each event before the result is published.') ?>
            </p>
            <?= $this->element('event_goal_fields', [
                'goal' => \App\Service\EventGoal::fromEntity($tournament),
                'error' => implode(' ', (array)$tournament->getError('goal_points')) ?: null,
            ]) ?>
        </div>
    <?php endif; ?>

    <?php if ($shows(GameTournament::SECTION_FINE)): ?>
        <?php $fineMode = (string)($tournament->get('fine_mode') ?: \App\Service\TournamentFineService::MODE_NONE); ?>
        <div class="event-form-card">
            <h2><i class="fas fa-gavel text-danger"></i> <?= __('Fine for missing the goal') ?></h2>
            <p class="section-hint">
                <?= __('When the result of an event of this tournament is published, every player who did not reach the goal is listed with the fine below, debited from their account in the clan bank once an administrator charges it. Players can be waived on the event page before charging.') ?>
                <?= __('The fine is collected as a bank fee: the balance may go below zero, the player pays it by depositing, and the amount stays with the clan.') ?>
            </p>
            <?= $this->Form->control('fine_mode', [
                'type' => 'radio',
                'label' => false,
                'options' => \App\Service\TournamentFineService::modeOptions(),
                'value' => $fineMode,
            ]) ?>
            <div id="fineAmountBox" <?= $fineMode === \App\Service\TournamentFineService::MODE_TIERS ? 'hidden' : '' ?>>
                <?= $this->Form->control('fine_amount', [
                    'type' => 'number',
                    'min' => 1,
                    'step' => 1,
                    'label' => __('Amount (millions of Silver)'),
                    'value' => $tournament->get('fine_amount'),
                    'error' => implode(' ', (array)$tournament->getError('fine_amount')) ?: null,
                ]) ?>
                <p class="section-hint mb-0">
                    <?= __('The fine is Silver in whole millions, as in the clan bank: 1 is 1 million, 1000 is 1 billion. It is never less than 1 million.') ?>
                    <?= __('Fixed: every player who missed the goal owes the amount. In proportion: the amount times the part of the goal that was missing; a player at 60% of the goal owes 40% of the amount.') ?>
                </p>
            </div>
            <?php
            $postedTiers = $this->getRequest()->getData('fine_tiers');
            $tierRows = is_array($postedTiers) ? array_values($postedTiers) : $tournament->fineTiers();
            if ($tierRows === []) {
                $tierRows = [['from' => '', 'to' => '', 'amount' => '']];
            }
            $tierError = implode(' ', (array)$tournament->getError('fine_tiers'));
            ?>
            <div id="fineTiersBox" <?= $fineMode === \App\Service\TournamentFineService::MODE_TIERS ? '' : 'hidden' ?>>
                <p class="section-hint">
                    <?= __('Each range is a share of the goal the player reached and the fine for it. A range holds its start but not its end: with 0% to 10% and 10% to 50%, a player at exactly 10% falls in the second. A share no range holds is not fined; a player who reached the goal never is.') ?>
                    <?= __('The fine is Silver in whole millions, as in the clan bank: 1 is 1 million, 1000 is 1 billion. It is never less than 1 million.') ?>
                </p>
                <?php if ($tierError !== ''): ?>
                    <div class="alert alert-danger py-2"><?= h($tierError) ?></div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-2" id="fineTiersTable">
                        <thead>
                            <tr>
                                <th style="width: 130px;"><?= __('From (%)') ?></th>
                                <th style="width: 130px;"><?= __('To (%)') ?></th>
                                <th><?= __('Fine (millions of Silver)') ?></th>
                                <th style="width: 50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tierRows as $i => $row): ?>
                                <tr>
                                    <td><input type="number" min="0" max="100" step="1" class="form-control form-control-sm" name="fine_tiers[<?= (int)$i ?>][from]" value="<?= h((string)($row['from'] ?? '')) ?>"></td>
                                    <td><input type="number" min="1" max="100" step="1" class="form-control form-control-sm" name="fine_tiers[<?= (int)$i ?>][to]" value="<?= h((string)($row['to'] ?? '')) ?>"></td>
                                    <td><input type="number" min="1" step="1" class="form-control form-control-sm" name="fine_tiers[<?= (int)$i ?>][amount]" value="<?= h((string)($row['amount'] ?? '')) ?>"></td>
                                    <td class="text-right">
                                        <button type="button" class="btn btn-outline-danger btn-sm js-remove-tier" title="<?= h(__('Remove range')) ?>"><i class="fas fa-times"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm" id="addFineTier">
                    <i class="fas fa-plus mr-1"></i><?= __('Add range') ?>
                </button>
            </div>
            <script>
                (function () {
                    var amountBox = document.getElementById('fineAmountBox');
                    var tiersBox = document.getElementById('fineTiersBox');
                    var body = document.querySelector('#fineTiersTable tbody');
                    var tiersMode = <?= json_encode(\App\Service\TournamentFineService::MODE_TIERS) ?>;
                    var noneMode = <?= json_encode(\App\Service\TournamentFineService::MODE_NONE) ?>;
                    function show() {
                        var picked = document.querySelector('input[name="fine_mode"]:checked');
                        var mode = picked ? picked.value : noneMode;
                        amountBox.hidden = mode === tiersMode || mode === noneMode;
                        tiersBox.hidden = mode !== tiersMode;
                    }
                    document.querySelectorAll('input[name="fine_mode"]').forEach(function (radio) {
                        radio.addEventListener('change', show);
                    });
                    show();
                    var next = body.rows.length;
                    document.getElementById('addFineTier').addEventListener('click', function () {
                        var row = body.rows[0].cloneNode(true);
                        var last = body.rows[body.rows.length - 1];
                        var lastTo = last ? last.querySelector('input[name$="[to]"]').value : '';
                        row.querySelectorAll('input').forEach(function (input) {
                            input.name = input.name.replace(/fine_tiers\[\d+\]/, 'fine_tiers[' + next + ']');
                            input.value = '';
                        });
                        // The new range starts where the last one ends.
                        row.querySelector('input[name$="[from]"]').value = lastTo;
                        next++;
                        body.appendChild(row);
                        row.querySelector('input[name$="[' + (lastTo === '' ? 'from' : 'to') + ']"]').focus();
                    });
                    body.addEventListener('click', function (e) {
                        var button = e.target.closest('.js-remove-tier');
                        if (!button) {
                            return;
                        }
                        var row = button.closest('tr');
                        if (body.rows.length > 1) {
                            row.remove();
                        } else {
                            row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
                        }
                    });
                })();
            </script>
        </div>
    <?php endif; ?>

    <?php if ($shows(GameTournament::SECTION_PRIZES)): ?>
        <div class="event-form-card">
            <h2><i class="fas fa-gift text-primary"></i> <?= __('Clan prizes to split among the players') ?></h2>
            <p class="section-hint">
                <?= __('These are the prizes the clan has received in this tournament. Tick the ones to split among the players: every new event of the tournament gets one reward per prize ticked, with the amount the clan received in it, split by the rule chosen. Rewards added by hand to an event are kept beside them.') ?>
            </p>
            <?php if ($prizes === []): ?>
                <p class="text-muted"><?= __('The clan has not received any prize in this tournament yet, or the collector has not sent the clan\'s results.') ?></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th style="width: 40px;"></th>
                                <th><?= __('Prize') ?></th>
                                <th class="text-right"><?= __('Last received') ?></th>
                                <th style="width: 220px;"><?= __('Split') ?></th>
                                <th style="width: 130px;"><?= __('Minimum points') ?></th>
                                <th style="width: 260px;"><?= __('Leftover units') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($prizes as $prize): ?>
                                <?php $id = $prize['item_id']; $chosen = $selected[$id] ?? null; $field = fn (string $f): string => "prizes[{$id}][{$f}]"; ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="<?= $field('selected') ?>" value="0">
                                        <input type="checkbox" name="<?= $field('selected') ?>" value="1" id="prize-<?= $id ?>" <?= $chosen ? 'checked' : '' ?>>
                                        <input type="hidden" name="<?= $field('name') ?>" value="<?= h($prize['name']) ?>">
                                    </td>
                                    <td><label for="prize-<?= $id ?>" class="mb-0"><?= h($prize['name'] ?: __('Item {0}', $id)) ?></label></td>
                                    <td class="text-right"><?= $prize['last_amount'] ? $this->gameNumber($prize['last_amount']) : '&ndash;' ?></td>
                                    <td>
                                        <select name="<?= $field('rule') ?>" class="form-control form-control-sm">
                                            <?php foreach ($ruleOptions as $value => $label): ?>
                                                <option value="<?= h($value) ?>" <?= ($chosen['rule'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" min="0" name="<?= $field('min_points') ?>" class="form-control form-control-sm"
                                               value="<?= h((string)($chosen['min_points'] ?? 1)) ?>">
                                    </td>
                                    <td>
                                        <select name="<?= $field('remainder') ?>" class="form-control form-control-sm">
                                            <?php foreach ($remainderOptions as $value => $label): ?>
                                                <option value="<?= h($value) ?>" <?= ($chosen['remainder'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($shows(GameTournament::SECTION_PRIZES)): ?>
        <?php
        $postedExtras = $this->getRequest()->getData('extra_prizes');
        $extraRows = is_array($postedExtras)
            ? array_values(array_map(fn ($r): array => (array)$r + ['kind' => 'item'], $postedExtras))
            : array_map(fn (array $p): array => $p + ['kind' => $p['silver'] ? 'silver' : 'item'], $tournament->extraPrizes());
        if ($extraRows === []) {
            $extraRows = [['kind' => 'silver', 'name' => '', 'quantity' => '', 'rule' => \App\Model\Entity\EventReward::RULE_PROPORTIONAL,
                'min_points' => 1, 'remainder' => \App\Model\Entity\EventReward::REMAINDER_TOP_RANKED]];
        }
        $extraError = implode(' ', (array)$tournament->getError('extra_prizes'));
        $kindOptions = ['silver' => __('Silver (millions)'), 'item' => __('Item')];
        ?>
        <div class="event-form-card">
            <h2><i class="fas fa-coins text-warning"></i> <?= __('Extra prizes: Silver and items') ?></h2>
            <p class="section-hint">
                <?= __('Prizes the clan gives besides the ones it receives, added to every new event of this tournament and split by the rule chosen. Add as many as you like.') ?>
                <?= __('Silver is in whole millions, as in the clan bank (1000 is 1B): when the result is published, each player\'s share waits on the event page for an administrator to credit it to their account in the clan bank.') ?>
            </p>
            <input type="hidden" name="extra_prizes_sent" value="1">
            <?php if ($extraError !== ''): ?>
                <div class="alert alert-danger py-2"><?= h($extraError) ?></div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-sm mb-2" id="extraPrizesTable">
                    <thead>
                        <tr>
                            <th style="width: 160px;"><?= __('Prize') ?></th>
                            <th><?= __('Item name') ?></th>
                            <th style="width: 130px;"><?= __('Amount') ?></th>
                            <th style="width: 200px;"><?= __('Split') ?></th>
                            <th style="width: 120px;"><?= __('Minimum points') ?></th>
                            <th style="width: 220px;"><?= __('Leftover units') ?></th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($extraRows as $i => $row): ?>
                            <?php $field = fn (string $f): string => 'extra_prizes[' . (int)$i . '][' . $f . ']'; ?>
                            <tr>
                                <td>
                                    <select name="<?= $field('kind') ?>" class="form-control form-control-sm js-extra-kind">
                                        <?php foreach ($kindOptions as $value => $label): ?>
                                            <option value="<?= h($value) ?>" <?= ($row['kind'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="text" maxlength="120" name="<?= $field('name') ?>" class="form-control form-control-sm js-extra-name"
                                           value="<?= h((string)($row['name'] ?? '')) ?>" placeholder="<?= h(__('Item name')) ?>"></td>
                                <td><input type="number" min="1" step="1" name="<?= $field('quantity') ?>" class="form-control form-control-sm"
                                           value="<?= h((string)($row['quantity'] ?? '')) ?>"></td>
                                <td>
                                    <select name="<?= $field('rule') ?>" class="form-control form-control-sm">
                                        <?php foreach ($ruleOptions as $value => $label): ?>
                                            <option value="<?= h($value) ?>" <?= ($row['rule'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" min="0" name="<?= $field('min_points') ?>" class="form-control form-control-sm"
                                           value="<?= h((string)($row['min_points'] ?? 1)) ?>"></td>
                                <td>
                                    <select name="<?= $field('remainder') ?>" class="form-control form-control-sm">
                                        <?php foreach ($remainderOptions as $value => $label): ?>
                                            <option value="<?= h($value) ?>" <?= ($row['remainder'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="text-right">
                                    <button type="button" class="btn btn-outline-danger btn-sm js-remove-extra" title="<?= h(__('Remove prize')) ?>"><i class="fas fa-times"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" id="addExtraPrize">
                <i class="fas fa-plus mr-1"></i><?= __('Add prize') ?>
            </button>
            <script>
                (function () {
                    var body = document.querySelector('#extraPrizesTable tbody');
                    function sync(row) {
                        var kind = row.querySelector('.js-extra-kind');
                        var name = row.querySelector('.js-extra-name');
                        name.disabled = kind.value === 'silver';
                        name.placeholder = kind.value === 'silver' ? <?= json_encode(__('Silver'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?> : <?= json_encode(__('Item name'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
                    }
                    Array.prototype.forEach.call(body.rows, sync);
                    body.addEventListener('change', function (e) {
                        if (e.target.classList.contains('js-extra-kind')) {
                            sync(e.target.closest('tr'));
                        }
                    });
                    var next = body.rows.length;
                    document.getElementById('addExtraPrize').addEventListener('click', function () {
                        var row = body.rows[0].cloneNode(true);
                        row.querySelectorAll('input, select').forEach(function (input) {
                            input.name = input.name.replace(/extra_prizes\[\d+\]/, 'extra_prizes[' + next + ']');
                            if (input.tagName === 'INPUT') {
                                input.value = input.name.indexOf('[min_points]') !== -1 ? '1' : '';
                            }
                        });
                        next++;
                        body.appendChild(row);
                        sync(row);
                        row.querySelector('input[name$="[quantity]"]').focus();
                    });
                    body.addEventListener('click', function (e) {
                        var button = e.target.closest('.js-remove-extra');
                        if (!button) {
                            return;
                        }
                        var row = button.closest('tr');
                        if (body.rows.length > 1) {
                            row.remove();
                        } else {
                            row.querySelectorAll('input[type="text"], input[type="number"]').forEach(function (input) {
                                input.value = input.name.indexOf('[min_points]') !== -1 ? '1' : '';
                            });
                        }
                    });
                })();
            </script>
        </div>
    <?php endif; ?>

    <div class="event-form-actions">
        <?= $this->Html->link(__('Cancel'), ['action' => 'view', $tournament->id], ['class' => 'btn btn-default spacer']) ?>
        <?= $this->Form->button('<i class="fas fa-save mr-1"></i>' . __('Save changes'), ['class' => 'btn btn-primary', 'escapeTitle' => false]) ?>
    </div>
    <?= $this->Form->end() ?>
</div>
