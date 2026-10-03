<?php
/**
 * Review of a game tournament's uploaded ranking.
 *
 * The administrator checks who is who, who takes part in the prize and each
 * player's share of every reward, corrects what is wrong, and publishes.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Event $event
 * @var \App\Model\Entity\EventImport|null $import
 * @var array|null $preview
 * @var list<\App\Model\Entity\EventImport> $history
 * @var array<int, string> $members
 */

use App\Model\Entity\EventImport;
use App\Model\Entity\EventImportRow;
use App\Model\Entity\EventReward;

$this->assign('title', __('Review result - Event #{0}', $event->event_number));

$isDraft = $import !== null && $import->status === EventImport::STATUS_DRAFT;
$isPublished = $event->published_at !== null;
$showGoal = $event->goal()->isActive();

$matchLabels = [
    EventImportRow::MATCH_PLAYER_ID => [__('game id'), 'is-top', 'fa-link'],
    EventImportRow::MATCH_NAME => [__('by name'), 'is-third', 'fa-font'],
    EventImportRow::MATCH_MAPPING => [__('name correction'), 'is-third', 'fa-spell-check'],
    EventImportRow::MATCH_MANUAL => [__('by hand'), 'is-top', 'fa-hand-pointer'],
    EventImportRow::MATCH_NONE => [__('not linked'), '', 'fa-unlink'],
];
$methodLabels = [
    EventImport::METHOD_PACKET => __('EventUploader (game data)'),
    EventImport::METHOD_OCR => __('EventUploader (screen reading)'),
    EventImport::METHOD_CSV => __('CSV file'),
];
$statusLabels = [
    EventImport::STATUS_DRAFT => __('Draft'),
    EventImport::STATUS_PUBLISHED => __('Published'),
    EventImport::STATUS_SUPERSEDED => __('Replaced'),
];
$fmt = fn ($n) => $this->Number->format((int)$n);
?>
<div class="content-page-wrap">

    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-clipboard-check text-primary"></i>
                <?= __('Review result') ?>
            </h1>
            <p class="cycle-subtitle">
                #<?= h($event->event_number) ?> &middot; <?= h($event->name) ?>
                &middot; <?= h($event->starts_at->format('d/m/Y')) ?>
            </p>
        </div>
        <div class="toolbar-actions d-flex flex-wrap" style="gap: 8px;">
            <?php if ($isPublished): ?>
                <?= $this->Html->link(
                    '<i class="fas fa-trophy mr-1"></i>' . __('Published page'),
                    ['action' => 'view', $event->id],
                    ['class' => 'btn btn-success btn-sm', 'escape' => false]
                ) ?>
                <?= $this->Form->postLink(
                    '<i class="fas fa-undo mr-1"></i>' . __('Unpublish'),
                    ['action' => 'unpublish', $event->id],
                    [
                        'class' => 'btn btn-warning btn-sm',
                        'escape' => false,
                        'confirm' => __('Take the result of event #{0} down? Players will stop seeing it until it is published again.', $event->event_number),
                    ]
                ) ?>
            <?php endif; ?>
            <?= $this->Html->link(
                '<i class="fas fa-pen mr-1"></i>' . __('Edit event'),
                ['action' => 'edit', $event->id],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-list mr-1"></i>' . __('Manage Events'),
                ['action' => 'manage'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <!-- Rewards -->
    <div class="event-info-grid">
        <div class="event-info-card is-prize">
            <h3><i class="fas fa-coins"></i> <?= __('Rewards to split') ?></h3>
            <?php if ($event->event_rewards): ?>
                <ul class="review-reward-list">
                    <?php foreach ($event->event_rewards as $reward): ?>
                        <?php $split = $preview['distribution']['rewards'][$reward->id] ?? null; ?>
                        <li>
                            <strong><?= $reward->rule === EventReward::RULE_POSITION ? h($reward->summary()) : $fmt($reward->quantity) . ' &times; ' . h($reward->item_name) ?></strong>
                            <span class="text-muted">
                                &middot; <?= h(EventReward::ruleOptions()[$reward->rule] ?? $reward->rule) ?>
                                &middot; <?= __('min. {0} pts', $fmt($reward->min_points)) ?>
                            </span>
                            <?php if ($split !== null): ?>
                                <br>
                                <small>
                                    <?= __('{0} handed out to {1} player(s)', $fmt($split['distributed']), $fmt($split['recipients'])) ?>
                                    <?php if ($split['leftover'] > 0): ?>
                                        &middot; <span class="text-warning font-weight-bold"><?= __('{0} left over', $fmt($split['leftover'])) ?></span>
                                    <?php endif; ?>
                                </small>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted"><?= __('No rewards: the result is published with the ranking only. Edit the event to add rewards, even after it is published; the points do not change.') ?></p>
            <?php endif; ?>
        </div>

        <div class="event-info-card is-rules">
            <h3><i class="fas fa-file-import"></i> <?= __('Ranking') ?></h3>
            <?php if ($import === null): ?>
                <p><?= __('No ranking has been received yet.') ?></p>
            <?php else: ?>
                <p>
                    <span class="event-state state-<?= $isDraft ? 'awaiting' : 'finished' ?>">
                        <?= h($statusLabels[$import->status] ?? $import->status) ?>
                    </span>
                </p>
                <p class="mb-1" style="font-size: 0.85rem;">
                    <?= __('Received {0} UTC from {1}', $import->created?->format('d/m/Y H:i'), h($import->user->name ?? '-')) ?><br>
                    <?= h($methodLabels[$import->capture_method] ?? $import->capture_method) ?>
                    <?php if ($import->client_version): ?>
                        &middot; v<?= h($import->client_version) ?>
                    <?php endif; ?>
                    <?php if ($import->game_event_name): ?>
                        <br><?= __('In the game: {0}', h($import->game_event_name)) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- When it was played -->
    <div class="event-form-card">
        <h2>
            <i class="fas fa-calendar-alt text-primary"></i> <?= __('When it was played') ?>
            <span class="utc-note"><i class="fas fa-globe"></i> UTC</span>
        </h2>
        <div class="review-dates">
            <?php if ($event->game_tournament !== null): ?>
                <div class="review-tournament">
                    <?php if ($event->game_tournament->has_image): ?>
                        <img src="<?= $this->Url->build(['controller' => 'GameTournaments', 'action' => 'image', $event->game_tournament->id, '?' => ['v' => $event->game_tournament->modified?->getTimestamp()]]) ?>"
                             alt="" class="tournament-thumb">
                    <?php endif; ?>
                    <div>
                        <strong><?= h($event->game_tournament->displayName()) ?></strong>
                        <small class="d-block text-muted">
                            <?= $event->game_tournament->duration_days
                                ? __('Lasts {0} day(s) in the catalogue', $event->game_tournament->duration_days)
                                : __('No duration in the catalogue: one day assumed') ?>
                            &middot;
                            <?= $this->Html->link(__('Edit in the catalogue'), ['controller' => 'GameTournaments', 'action' => 'edit', $event->game_tournament->id]) ?>
                        </small>
                    </div>
                </div>
            <?php endif; ?>

            <?= $this->Form->create(null, ['url' => ['action' => 'review', $event->id], 'class' => 'review-dates-form']) ?>
            <input type="hidden" name="intent" value="dates">
            <div class="form-group">
                <label for="review-starts-at"><?= __('Starts at (UTC)') ?></label>
                <input type="datetime-local" class="form-control" id="review-starts-at" name="starts_at" step="60" required
                       value="<?= h($event->starts_at->format('Y-m-d\TH:i')) ?>">
            </div>
            <div class="form-group">
                <label for="review-ends-at"><?= __('Ends at (UTC)') ?></label>
                <input type="datetime-local" class="form-control" id="review-ends-at" name="ends_at" step="60" required
                       value="<?= h($event->ends_at->format('Y-m-d\TH:i')) ?>">
            </div>
            <?= $this->Form->button('<i class="fas fa-save mr-1"></i>' . __('Save dates'), ['class' => 'btn btn-outline-primary', 'escapeTitle' => false]) ?>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <?php if ($preview !== null): ?>

        <!-- Numbers -->
        <div class="event-stats">
            <div class="event-stat">
                <div class="event-stat-label"><i class="fas fa-users"></i> <?= __('Players') ?></div>
                <div class="event-stat-value"><?= $fmt($preview['totals']['players']) ?></div>
                <div class="event-stat-note"><?= __('{0} taking part in the prize', $fmt($preview['totals']['eligible'])) ?></div>
            </div>
            <div class="event-stat">
                <div class="event-stat-label"><i class="fas fa-user-shield"></i> <?= __('Administrative accounts') ?></div>
                <div class="event-stat-value"><?= $fmt($preview['totals']['administrative']) ?></div>
                <div class="event-stat-note"><?= __('never receive a prize') ?></div>
            </div>
            <div class="event-stat">
                <div class="event-stat-label"><i class="fas fa-unlink"></i> <?= __('Not linked') ?></div>
                <div class="event-stat-value"><?= $fmt($preview['totals']['unmatched']) ?></div>
                <div class="event-stat-note"><?= __('players without a member') ?></div>
            </div>
            <?php if ($event->goal()->isActive()): ?>
                <div class="event-stat">
                    <div class="event-stat-label"><i class="fas fa-bullseye"></i> <?= __('Goal reached') ?></div>
                    <div class="event-stat-value"><?= $fmt($preview['totals']['goal_met']) ?></div>
                    <div class="event-stat-note"><?= $event->goal()->isRequired() ? __('only they share the rewards') : __('the goal does not decide the rewards') ?></div>
                </div>
            <?php endif; ?>
            <div class="event-stat">
                <div class="event-stat-label"><i class="fas fa-chart-bar"></i> <?= __('Points in the split') ?></div>
                <div class="event-stat-value"><?= $fmt($preview['distribution']['eligible_points']) ?></div>
                <div class="event-stat-note"><?= __('of {0} in the ranking', $fmt($preview['totals']['points'])) ?></div>
            </div>
        </div>

        <?php if ($preview['warnings']): ?>
            <div class="review-warnings">
                <?php foreach ($preview['warnings'] as $warning): ?>
                    <div class="alert alert-<?= $warning['level'] === 'warning' ? 'warning' : 'info' ?> mb-2">
                        <i class="fas fa-<?= $warning['level'] === 'warning' ? 'exclamation-triangle' : 'info-circle' ?> mr-1"></i>
                        <?= h($warning['text']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Ranking -->
        <div class="event-standings-card">
            <div class="event-standings-header">
                <h2 class="event-standings-title">
                    <i class="fas fa-list-ol text-primary"></i> <?= __('Ranking and shares') ?>
                </h2>
                <div class="event-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="reviewSearch" placeholder="<?= __('Search player...') ?>" onkeyup="filterReview(this.value)">
                </div>
            </div>

            <?= $this->Form->create(null, ['url' => ['action' => 'review', $event->id], 'id' => 'reviewForm']) ?>
            <div class="event-table-wrap">
                <table class="event-table review-table" id="reviewTable">
                    <thead>
                        <tr>
                            <th style="width: 60px;">#</th>
                            <th><?= __('Player in the game') ?></th>
                            <th style="width: 230px;"><?= __('Member') ?></th>
                            <th style="width: 90px;" title="<?= h(__('Takes part in the prize')) ?>"><?= __('Prize') ?></th>
                            <th style="width: 160px;"><?= __('Points') ?></th>
                            <th style="width: 80px;"><?= __('Share') ?></th>
                            <?php if ($showGoal): ?>
                                <th style="width: 140px;"><?= __('Goal') ?></th>
                            <?php endif; ?>
                            <?php foreach ($event->event_rewards as $reward): ?>
                                <th style="width: 110px;" title="<?= h($reward->summary()) ?>"><?= h($reward->item_name) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview['rows'] as $row): ?>
                            <?php
                            [$matchText, $matchClass, $matchIcon] = $matchLabels[$row->match_type] ?? $matchLabels[EventImportRow::MATCH_NONE];
                            $isAdministrative = $row->member !== null && $row->member->administrative_account;
                            $field = fn (string $name) => 'rows[' . (int)$row->id . '][' . $name . ']';
                            ?>
                            <tr class="<?= $row->eligible ? '' : 'is-excluded' ?>" data-player="<?= h(mb_strtolower($row->raw_name . ' ' . ($row->member->player ?? ''))) ?>">
                                <td><span class="event-position <?= $row->position <= 3 ? 'rank-' . (int)$row->position : '' ?>"><?= (int)$row->position ?></span></td>
                                <td>
                                    <strong><?= h($row->raw_name) ?></strong>
                                    <?php if ($row->game_player_id): ?>
                                        <small class="d-block text-muted">id <?= h($row->game_player_id) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isDraft): ?>
                                        <select name="<?= $field('member_id') ?>" class="form-control form-control-sm">
                                            <option value=""><?= __('- not linked -') ?></option>
                                            <?php foreach ($members as $memberId => $label): ?>
                                                <option value="<?= (int)$memberId ?>" <?= (int)$row->member_id === (int)$memberId ? 'selected' : '' ?>><?= h($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <?= h($row->member->player ?? '-') ?>
                                    <?php endif; ?>
                                    <span class="event-highlight <?= $matchClass ?>" style="margin-top: 4px;">
                                        <i class="fas <?= $matchIcon ?>"></i> <?= h($matchText) ?>
                                    </span>
                                    <?php if ($isAdministrative): ?>
                                        <span class="event-highlight is-second" style="margin-top: 4px;">
                                            <i class="fas fa-user-shield"></i> <?= __('administrative') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($isDraft): ?>
                                        <input type="hidden" name="<?= $field('eligible') ?>" value="0">
                                        <input type="checkbox" name="<?= $field('eligible') ?>" value="1" <?= $row->eligible ? 'checked' : '' ?>
                                               title="<?= h(__('Takes part in the prize')) ?>" style="transform: scale(1.3);">
                                    <?php else: ?>
                                        <i class="fas <?= $row->eligible ? 'fa-check text-success' : 'fa-times text-muted' ?>"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="event-points">
                                    <?php /* Points come from the game and are never edited here. */ ?>
                                    <span title="<?= h(__('Value received from the game')) ?>"><?= $fmt($row->points) ?></span>
                                    <?php if ($row->original_points !== null): ?>
                                        <small class="d-block text-warning" title="<?= h(__('Value received from the game')) ?>">
                                            <i class="fas fa-pen"></i> <?= __('was {0}', $fmt($row->original_points)) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td><?= $this->Number->format($preview['distribution']['participation'][$row->id] ?? 0, ['places' => 2]) ?>%</td>
                                <?php if ($showGoal): ?>
                                    <?php $goalRow = $preview['distribution']['goals'][$row->id] ?? ['goal' => null, 'met' => null, 'level' => 0]; ?>
                                    <td><?= $this->element('event_goal_cell', [
                                        'value' => $goalRow['goal'],
                                        'met' => $goalRow['met'],
                                        'level' => (int)$goalRow['level'],
                                        'byGuard' => $event->goal()->isByGuard(),
                                    ]) ?></td>
                                <?php endif; ?>
                                <?php foreach ($event->event_rewards as $reward): ?>
                                    <?php $amount = $preview['distribution']['rewards'][$reward->id]['amounts'][$row->id] ?? 0; ?>
                                    <td class="review-amount <?= $amount > 0 ? '' : 'is-zero' ?>"><?= $fmt($amount) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="<?= $showGoal ? 7 : 6 ?>" class="text-right"><strong><?= __('Total handed out') ?></strong></td>
                            <?php foreach ($event->event_rewards as $reward): ?>
                                <?php $split = $preview['distribution']['rewards'][$reward->id]; ?>
                                <td class="review-amount">
                                    <strong><?= $fmt($split['distributed']) ?></strong>
                                    <small class="d-block text-muted"><?= __('of {0}', $fmt($reward->quantity)) ?></small>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if ($isDraft): ?>
                <div class="event-form-actions review-actions">
                    <span class="text-muted" style="font-size: 0.85rem;">
                        <?= __('Change the member or the prize checkbox, then recalculate to see the new shares. The points come from the game and cannot be changed.') ?>
                    </span>
                    <button type="submit" name="intent" value="save" class="btn btn-outline-primary">
                        <i class="fas fa-calculator mr-1"></i><?= __('Save and recalculate') ?>
                    </button>
                    <button type="submit" name="intent" value="publish" class="btn btn-success"
                            onclick="return confirm(<?= h(json_encode(__('Publish this result? Players will see the ranking and what each one receives.'))) ?>);">
                        <i class="fas fa-bullhorn mr-1"></i><?= __('Publish result') ?>
                    </button>
                </div>
            <?php endif; ?>
            <?= $this->Form->end() ?>
        </div>
    <?php endif; ?>

    <?php if (count($history) > 1): ?>
        <div class="event-standings-card">
            <div class="event-standings-header">
                <h2 class="event-standings-title"><i class="fas fa-history text-primary"></i> <?= __('Rankings received') ?></h2>
            </div>
            <div class="event-table-wrap">
                <table class="event-table">
                    <thead>
                        <tr>
                            <th><?= __('When (UTC)') ?></th>
                            <th><?= __('Sent by') ?></th>
                            <th><?= __('Source') ?></th>
                            <th><?= __('Players') ?></th>
                            <th><?= __('State') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                            <tr>
                                <td><?= h($entry->created?->format('d/m/Y H:i')) ?></td>
                                <td><?= h($entry->user->name ?? '-') ?></td>
                                <td><?= h($methodLabels[$entry->capture_method] ?? $entry->capture_method) ?></td>
                                <td><?= $fmt($entry->row_count) ?></td>
                                <td><?= h($statusLabels[$entry->status] ?? $entry->status) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php $this->start('script'); ?>
<script>
    function filterReview(term) {
        var needle = (term || '').toLowerCase().trim();
        document.querySelectorAll('#reviewTable tbody tr').forEach(function (row) {
            var player = row.getAttribute('data-player') || '';
            row.style.display = needle === '' || player.indexOf(needle) !== -1 ? '' : 'none';
        });
    }
</script>
<?php $this->end(); ?>
