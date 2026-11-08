<?php
/**
 * Edit one part of a tournament of the catalogue (`$section`), or the name,
 * duration, image and goal together when no section is given. Saving goes back
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
        GameTournament::SECTION_DURATION,
        GameTournament::SECTION_IMAGE,
    ], true)
    : $section === $part;
$titles = [
    GameTournament::SECTION_NAME => __('Name'),
    GameTournament::SECTION_DESCRIPTION => __('Description'),
    GameTournament::SECTION_DURATION => __('Duration'),
    GameTournament::SECTION_IMAGE => __('Image'),
    GameTournament::SECTION_GOAL => __('Goal'),
    GameTournament::SECTION_PRIZES => __('Prizes'),
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
                <?= __('Game id {0}', $tournament->game_type) ?>
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
                                    <td class="text-right"><?= $prize['last_amount'] ? $this->Number->format($prize['last_amount']) : '&ndash;' ?></td>
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

    <div class="event-form-actions">
        <?= $this->Html->link(__('Cancel'), ['action' => 'view', $tournament->id], ['class' => 'btn btn-default spacer']) ?>
        <?= $this->Form->button('<i class="fas fa-save mr-1"></i>' . __('Save changes'), ['class' => 'btn btn-primary', 'escapeTitle' => false]) ?>
    </div>
    <?= $this->Form->end() ?>
</div>
