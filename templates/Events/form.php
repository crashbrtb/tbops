<?php
/**
 * Create or edit an event.
 *
 * Times are entered and shown as UTC throughout, matching how the rest of the
 * application stores and compares them; the browser's own zone is never used,
 * and the page says so next to every date field.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Event $event
 * @var array<\App\Model\Entity\StandardChest> $scoredChests
 * @var list<int> $selectedChestIds
 * @var array<string, string> $criteriaOptions
 * @var array<string, string> $criteriaHints
 * @var array<string, string> $ruleOptions
 * @var array<string, string> $remainderOptions
 * @var int $nextNumber
 */

use App\Model\Entity\Event;
use App\Model\Entity\EventAsset;
use App\Model\Entity\EventReward;
use Cake\I18n\DateTime;
use Cake\Utility\Hash;

$isNew = $event->isNew();
$this->assign('title', $isNew ? __('New Event') : __('Edit Event'));

// datetime-local wants "Y-m-dTH:i". A freshly opened form starts an hour out,
// which is a sane default for "soon" and safely clear of the not-in-the-past rule.
$formatForInput = function ($value): string {
    return $value instanceof \DateTimeInterface ? $value->format('Y-m-d\TH:i') : '';
};

$startsValue = $formatForInput($event->starts_at) ?: DateTime::now()->addHours(1)->format('Y-m-d\TH:i');
$endsValue = $formatForInput($event->ends_at) ?: DateTime::now()->addDays(7)->format('Y-m-d\TH:i');

$nowForMin = DateTime::now()->format('Y-m-d\TH:i');

$criteriaIcons = [
    Event::CRITERIA_CHEST_COUNT => 'fa-boxes',
    Event::CRITERIA_CHEST_SCORE => 'fa-star',
    Event::CRITERIA_EPIC_MONSTER => 'fa-dragon',
    Event::CRITERIA_CUSTOM_CHESTS => 'fa-sliders-h',
    Event::CRITERIA_IMPORTED => 'fa-gamepad',
];

// Reward lines as entered, including the ones a failed save sent back with
// their errors. A new tournament starts with one empty line to fill in.
$rewardRows = [];
foreach ((array)$event->event_rewards as $reward) {
    if ($reward instanceof EventReward) {
        $rewardRows[] = [
            'id' => $reward->id,
            'item_name' => $reward->item_name,
            'quantity' => $reward->quantity,
            'positions' => implode('; ', $reward->positionList()),
            'rule' => $reward->rule,
            'min_points' => $reward->min_points ?? 1,
            'remainder' => $reward->remainder ?: EventReward::REMAINDER_TOP_RANKED,
            'errors' => array_values(Hash::flatten($reward->getErrors())),
        ];
    }
}
if (!$rewardRows) {
    $rewardRows[] = [
        'id' => null, 'item_name' => '', 'quantity' => '', 'positions' => '', 'rule' => EventReward::RULE_PROPORTIONAL,
        'min_points' => 1, 'remainder' => EventReward::REMAINDER_TOP_RANKED, 'errors' => [],
    ];
}

/** One reward line; `__INDEX__` stays literal in the template the page clones. */
$rewardLine = function (string $index, array $row) use ($ruleOptions, $remainderOptions): string {
    $name = fn (string $field): string => 'event_rewards[' . $index . '][' . $field . ']';
    $options = function (array $choices, $selected): string {
        $html = '';
        foreach ($choices as $value => $label) {
            $html .= '<option value="' . h($value) . '"' . ((string)$selected === (string)$value ? ' selected' : '') . '>' . h($label) . '</option>';
        }

        return $html;
    };

    $byPosition = (string)$row['rule'] === EventReward::RULE_POSITION;
    $hidden = fn (bool $hide): string => $hide ? ' style="display: none;"' : '';
    $html = '<div class="reward-line">';
    if (!empty($row['id'])) {
        $html .= '<input type="hidden" name="' . $name('id') . '" value="' . (int)$row['id'] . '">';
    }
    $html .= '<div class="form-group reward-item"><label>' . __('Item') . '</label>'
        . '<input type="text" class="form-control" maxlength="120" name="' . $name('item_name') . '" value="' . h($row['item_name']) . '" placeholder="' . h(__('e.g. Artifact pieces')) . '"></div>';
    $html .= '<div class="form-group reward-rule"><label>' . __('Split') . '</label>'
        . '<select class="form-control" name="' . $name('rule') . '" onchange="onRewardRuleChange(this)">' . $options($ruleOptions, $row['rule']) . '</select></div>';
    $html .= '<div class="form-group reward-quantity js-not-position"' . $hidden($byPosition) . '><label>' . __('Quantity') . '</label>'
        . '<input type="text" inputmode="numeric" class="form-control" name="' . $name('quantity') . '" value="' . h((string)$row['quantity']) . '" placeholder="500"></div>';
    $html .= '<div class="form-group reward-positions js-position"' . $hidden(!$byPosition) . '><label>' . __('Amount per place') . '</label>'
        . '<input type="text" class="form-control" name="' . $name('positions') . '" value="' . h((string)($row['positions'] ?? '')) . '" placeholder="' . h(__('1st; 2nd; 3rd... e.g. 500; 250; 100')) . '"></div>';
    $html .= '<div class="form-group reward-min"><label>' . __('Minimum points') . '</label>'
        . '<input type="text" inputmode="numeric" class="form-control" name="' . $name('min_points') . '" value="' . h((string)$row['min_points']) . '"></div>';
    $html .= '<div class="form-group reward-remainder js-not-position"' . $hidden($byPosition) . '><label>' . __('Leftover units') . '</label>'
        . '<select class="form-control" name="' . $name('remainder') . '">' . $options($remainderOptions, $row['remainder']) . '</select></div>';
    $html .= '<button type="button" class="btn btn-outline-danger btn-sm reward-remove" onclick="removeRewardLine(this)" title="' . h(__('Remove')) . '"><i class="fas fa-trash"></i></button>';
    if (!empty($row['errors'])) {
        $html .= '<span class="field-error reward-errors"><i class="fas fa-exclamation-circle mr-1"></i>' . h(implode(' ', $row['errors'])) . '</span>';
    }

    return $html . '</div>';
};

// Two kinds of event share this form, but only one can be created here. A
// clan event is an internal challenge counted from collected chests; a game
// event is a tournament played in the game, which only the EventUploader
// registers (with its ranking) and which is only edited here.
$isGameEvent = !$isNew && $event->criteria === Event::CRITERIA_IMPORTED;
// Once the result is recorded the points are frozen; rewards and goal (and
// the texts) can still change, and are applied again to the recorded result.
$isRecordedResult = !$isNew && $event->hasRecordedResult();
// "No rewards" starts ticked for an event saved without any, so editing it
// does not suddenly ask for one; a new event starts with a line to fill.
$noRewards = !$isNew && !array_filter($rewardRows, fn (array $r): bool => !empty($r['id']) || $r['item_name'] !== '');
$currentCriteria = $isGameEvent || !$event->criteria ? Event::CRITERIA_CHEST_SCORE : $event->criteria;

/** Renders the validation message for a field, if the save left one. */
$fieldError = function (string $field) use ($event) {
    $errors = $event->getError($field);
    if (!$errors) {
        return '';
    }

    return '<span class="field-error"><i class="fas fa-exclamation-circle mr-1"></i>'
        . h(implode(' ', (array)$errors)) . '</span>';
};
?>
<div class="content-page-wrap">

    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-<?= $isNew ? 'plus' : 'pen' ?> text-primary"></i>
                <?= $isNew ? __('New Event') : __('Edit Event #{0}', $event->event_number) ?>
            </h1>
            <p class="cycle-subtitle">
                <?= $isNew
                    ? __('It will be created as event #{0}', $nextNumber)
                    : __('Created {0}', $event->created ? $event->created->format('d/m/Y H:i') . ' UTC' : '-') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-list mr-1"></i>' . __('Manage Events'),
                ['action' => 'manage'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <?= $this->Form->create($event, ['type' => 'file', 'id' => 'eventForm']) ?>

    <?php if ($isRecordedResult): ?>
        <div class="alert alert-info">
            <i class="fas fa-lock mr-1"></i>
            <?= $isGameEvent
                ? __('The result of this tournament is published. You can add or change its rewards here: they are split again over the published ranking, and nobody\'s points change.')
                : __('The result of this event is recorded. You can change its rewards and its goal here: they are applied again to the recorded ranking, and nobody\'s points change.') ?>
        </div>
    <?php endif; ?>

    <!-- Kind: fixed. Game events only come from the EventUploader. -->
    <div class="event-form-card">
        <h2><i class="fas fa-layer-group text-primary"></i> <?= __('Event type') ?></h2>
        <?php if ($isGameEvent): ?>
            <p class="mb-0">
                <strong><i class="fas fa-gamepad"></i> <?= __('Game event') ?></strong> &mdash;
                <?= __('A tournament played in the game, registered by the EventUploader together with its ranking. Its name, dates, rewards and goal can be adjusted here.') ?>
            </p>
            <input type="hidden" name="criteria" value="<?= Event::CRITERIA_IMPORTED ?>" id="gameCriteria">
        <?php else: ?>
            <p class="mb-0">
                <strong><i class="fas fa-flag"></i> <?= __('Clan event') ?></strong> &mdash;
                <?= __('An internal challenge to push crypts and epic monsters. It is counted from the chests collected inside its window, which can start in the past but must end in the future.') ?>
            </p>
            <small class="form-text text-muted">
                <?= __('Game events are not created here: the EventUploader registers each tournament together with its ranking.') ?>
            </small>
        <?php endif; ?>
    </div>

    <!-- Identity -->
    <div class="event-form-card">
        <h2><i class="fas fa-tag text-primary"></i> <?= __('Identification') ?></h2>
        <p class="section-hint"><?= __('The name players will see, and an optional explanation of the rules.') ?></p>

        <div class="event-form-grid">
            <div class="form-group">
                <label for="event-number-display"><?= __('Event number') ?></label>
                <input type="text" id="event-number-display" class="form-control"
                       value="#<?= h($isNew ? $nextNumber : $event->event_number) ?>" disabled>
                <small class="form-text text-muted">
                    <?= __('Assigned automatically and never reused.') ?>
                </small>
            </div>

            <div class="form-group">
                <?= $this->Form->control('name', [
                    'label' => __('Event name'),
                    'class' => 'form-control',
                    'required' => true,
                    'maxlength' => 120,
                    'placeholder' => __('e.g. September Chest Marathon'),
                    'templates' => ['inputContainer' => '{{content}}'],
                    'error' => false,
                ]) ?>
                <?= $fieldError('name') ?>
            </div>

            <div class="form-group full-width">
                <?= $this->Form->control('description', [
                    'label' => __('Description (optional)'),
                    'type' => 'textarea',
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => __('Anything the players should know about this event.'),
                    'templates' => ['inputContainer' => '{{content}}'],
                    'error' => false,
                ]) ?>
            </div>
        </div>
    </div>

    <!-- Window -->
    <div class="event-form-card">
        <h2>
            <i class="fas fa-clock text-primary"></i>
            <span data-kind="clan"<?= $isGameEvent ? ' style="display: none;"' : '' ?>><?= __('When it runs') ?></span>
            <span data-kind="game"<?= $isGameEvent ? '' : ' style="display: none;"' ?>><?= __('When it was played') ?></span>
            <span class="utc-note"><i class="fas fa-globe"></i> UTC</span>
        </h2>
        <p class="section-hint" data-kind="clan"<?= $isGameEvent ? ' style="display: none;"' : '' ?>>
            <?= __('Both moments are UTC, the same clock the scoreboard uses. Only chests collected inside this window count; the start may be in the past, but the end must be in the future.') ?>
        </p>
        <p class="section-hint" data-kind="game"<?= $isGameEvent ? '' : ' style="display: none;"' ?>>
            <?= __('The day and time the tournament ended in the game, in UTC. Past dates are accepted and nothing is counted from chests.') ?>
        </p>

        <div class="event-form-grid">
            <div class="form-group">
                <label for="starts-at"><?= __('Starts at (UTC)') ?></label>
                <input type="datetime-local" class="form-control" id="starts-at" name="starts_at"
                       value="<?= h($startsValue) ?>" step="60" required>
                <?= $fieldError('starts_at') ?>
            </div>

            <div class="form-group">
                <label for="ends-at"><?= __('Ends at (UTC)') ?></label>
                <input type="datetime-local" class="form-control" id="ends-at" name="ends_at"
                       value="<?= h($endsValue) ?>" <?= $isGameEvent ? '' : 'min="' . h($nowForMin) . '"' ?> step="60" required>
                <?= $fieldError('ends_at') ?>
            </div>

            <div class="form-group full-width">
                <div class="alert alert-info mb-0" style="font-size: 0.85rem;">
                    <i class="fas fa-info-circle mr-1"></i>
                    <?= __('Right now it is {0} UTC.', DateTime::now()->format('d/m/Y H:i')) ?>
                    <span id="durationHint" class="ml-2 font-weight-bold"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Criteria of a clan event -->
    <div class="event-form-card" data-kind="clan"<?= $isGameEvent ? ' style="display: none;"' : '' ?>>
        <h2><i class="fas fa-bullseye text-primary"></i> <?= __('What counts') ?></h2>
        <p class="section-hint"><?= __('How players are ranked in this event.') ?></p>

        <div class="criteria-options">
            <?php foreach ($criteriaOptions as $value => $label): ?>
                <?php if ($value === Event::CRITERIA_IMPORTED) {
                    continue;
                } ?>
                <label class="criteria-option">
                    <input type="radio" name="criteria" value="<?= h($value) ?>" class="clan-criteria"
                           <?= $currentCriteria === $value ? 'checked' : '' ?>
                           <?= $isGameEvent ? 'disabled' : '' ?>
                           onchange="onCriteriaChange()">
                    <span class="criteria-option-body">
                        <strong>
                            <i class="fas <?= h($criteriaIcons[$value] ?? 'fa-circle') ?>"></i>
                            <?= h($label) ?>
                        </strong>
                        <span><?= h($criteriaHints[$value] ?? '') ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <?= $fieldError('criteria') ?>

        <!-- Custom chest picker -->
        <div id="customChestSection" class="mt-4" style="display: none;">
            <hr>
            <h2 class="mt-3"><i class="fas fa-box-open text-primary"></i> <?= __('Chests in this event') ?></h2>
            <p class="section-hint">
                <?= __('Only chests that carry a score can be picked, because a chest worth nothing cannot decide a ranking. Choose as many as you like.') ?>
            </p>

            <div class="form-group">
                <label><?= __('Rank players by') ?></label>
                <div class="d-flex flex-wrap" style="gap: 18px;">
                    <label class="form-check-label" style="cursor: pointer;">
                        <input type="radio" name="custom_metric" value="<?= Event::METRIC_SCORE ?>"
                               <?= ($event->custom_metric ?? Event::METRIC_SCORE) !== Event::METRIC_COUNT ? 'checked' : '' ?>>
                        <?= __('Total score of the chosen chests') ?>
                    </label>
                    <label class="form-check-label" style="cursor: pointer;">
                        <input type="radio" name="custom_metric" value="<?= Event::METRIC_COUNT ?>"
                               <?= ($event->custom_metric ?? '') === Event::METRIC_COUNT ? 'checked' : '' ?>>
                        <?= __('Number of the chosen chests collected') ?>
                    </label>
                </div>
            </div>

            <div class="chest-picker-toolbar">
                <div class="event-search" style="flex: 1 1 240px;">
                    <i class="fas fa-search"></i>
                    <input type="text" id="chestSearch" placeholder="<?= __('Filter chests...') ?>"
                           onkeyup="filterChests(this.value)">
                </div>
                <div class="d-flex align-items-center" style="gap: 8px;">
                    <span class="chest-picker-count" id="chestCount">0</span>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="setAllChests(true)">
                        <?= __('Select all shown') ?>
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="setAllChests(false)">
                        <?= __('Clear') ?>
                    </button>
                </div>
            </div>

            <?php if (empty($scoredChests)): ?>
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    <?= __('No chest has a score yet. Set chest scores before creating a custom chest event.') ?>
                </div>
            <?php else: ?>
                <div class="chest-picker" id="chestPicker">
                    <?php foreach ($scoredChests as $chest): ?>
                        <label class="chest-option" data-name="<?= h(mb_strtolower($chest->display_name . ' ' . $chest->source)) ?>">
                            <input type="checkbox" name="chest_ids[]" value="<?= (int)$chest->id ?>"
                                   <?= in_array((int)$chest->id, $selectedChestIds, true) ? 'checked' : '' ?>
                                   onchange="updateChestCount()">
                            <?php if (!empty($chest->monster)): ?>
                                <i class="fas fa-dragon chest-option-monster" title="<?= __('Epic monster chest') ?>"></i>
                            <?php endif; ?>
                            <span class="chest-option-name" title="<?= h($chest->source) ?>">
                                <?= h($chest->display_name) ?>
                            </span>
                            <span class="chest-option-score"><?= $this->Number->format($chest->score) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?= $fieldError('event_chests') ?>
        </div>
    </div>

    <!-- Rewards -->
    <div class="event-form-card">
        <div id="rewardsSection">
            <h2><i class="fas fa-coins text-primary"></i> <?= __('Rewards to split') ?></h2>
            <p class="section-hint">
                <?= __('What is handed out and how it is divided. Proportional gives each player a part matching their share of the points; equal gives everybody the same; by position gives a fixed amount to each place (1st, 2nd, 3rd...). Administrative accounts never take part, and players below the minimum points are left out.') ?>
            </p>
            <div class="form-check mb-3">
                <input type="checkbox" class="form-check-input" id="no-rewards" name="no_rewards" value="1"
                       <?= $noRewards ? 'checked' : '' ?> onchange="onNoRewardsChange()">
                <label class="form-check-label" for="no-rewards">
                    <?= __('This event has no rewards') ?>
                </label>
                <small class="form-text text-muted">
                    <?= $isGameEvent
                        ? __('The result is published with the ranking only. Rewards can still be added later, even after it is published.')
                        : __('Describe the prize in words below instead. Rewards can still be added later, even after the result is recorded.') ?>
                </small>
            </div>

            <div id="rewardEditor"<?= $noRewards ? ' style="display: none;"' : '' ?>>
                <div id="rewardLines">
                    <?php foreach ($rewardRows as $index => $row): ?>
                        <?= $rewardLine((string)$index, $row) ?>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addRewardLine()">
                    <i class="fas fa-plus mr-1"></i><?= __('Add another reward') ?>
                </button>
            </div>
            <?= $fieldError('event_rewards') ?>

            <template id="rewardLineTemplate">
                <?= $rewardLine('__INDEX__', [
                    'id' => null, 'item_name' => '', 'quantity' => '', 'positions' => '', 'rule' => EventReward::RULE_PROPORTIONAL,
                    'min_points' => 1, 'remainder' => EventReward::REMAINDER_TOP_RANKED, 'errors' => [],
                ]) ?>
            </template>
        </div>
    </div>

    <!-- Goal -->
    <div class="event-form-card">
        <h2><i class="fas fa-bullseye text-primary"></i> <?= __('Event goal') ?></h2>
        <p class="section-hint">
            <?= $isGameEvent
                ? __('What each player has to reach in this tournament. New tournaments start with the default goal set in the Tournament Catalogue.')
                : __('What each player has to reach in this event, in the same unit as the ranking. It can be the same for everybody or follow the guard level shown in the member profile.') ?>
        </p>
        <?= $this->element('event_goal_fields', [
            'goal' => $event->goal(),
            'error' => implode(' ', (array)$event->getError('goal_points')) ?: null,
            'pointsLabel' => $isGameEvent ? __('Points') : __('points of the ranking'),
        ]) ?>
    </div>
    <!-- Prize and contact -->
    <div class="event-form-card">
        <h2><i class="fas fa-gift text-primary"></i> <?= __('Prize and contact') ?></h2>
        <p class="section-hint"><?= __('What the winner gets, and who they should look for to claim it.') ?></p>

        <div class="event-form-grid">
            <div class="form-group full-width">
                <?= $this->Form->control('prize', [
                    'label' => __('Prize'),
                    'id' => 'prize',
                    'type' => 'textarea',
                    'class' => 'form-control',
                    'rows' => 3,
                    // Filled in from the reward lines when left empty.
                    'required' => false,
                    'placeholder' => __('Optional: filled in from the rewards when left empty.'),
                    'templates' => ['inputContainer' => '{{content}}'],
                    'error' => false,
                ]) ?>
                <?= $fieldError('prize') ?>
            </div>

            <div class="form-group">
                <?= $this->Form->control('contact_player', [
                    'label' => __('Player to look for'),
                    'class' => 'form-control',
                    'required' => true,
                    'maxlength' => 120,
                    'placeholder' => __('e.g. Ventura'),
                    'templates' => ['inputContainer' => '{{content}}'],
                    'error' => false,
                ]) ?>
                <?= $fieldError('contact_player') ?>
            </div>
        </div>
    </div>

    <!-- Banner -->
    <div class="event-form-card">
        <h2><i class="fas fa-image text-primary"></i> <?= __('Event banner') ?></h2>
        <p class="section-hint">
            <?= __('Shown on the scoreboard next to the goals while this event is running. Leave it empty to use the default banner.') ?>
        </p>

        <img class="banner-preview" id="bannerPreview"
             src="<?= $event->has_custom_banner
                 ? $this->Url->build(['action' => 'banner', $event->id])
                 : $this->Url->build(['action' => 'asset', EventAsset::SLUG_EVENT_LIVE]) ?>"
             alt="<?= __('Banner preview') ?>">

        <div class="form-group">
            <label for="banner"><?= __('Replace the banner') ?></label>
            <input type="file" name="banner" id="banner" class="form-control-file"
                   accept="image/png,image/jpeg,image/gif,image/webp" onchange="previewBanner(this)">
            <small class="form-text text-muted">
                <?= __('PNG, JPEG, GIF or WebP, up to 3 MB. A wide strip works best, around 640 x 160 pixels.') ?>
            </small>
        </div>

        <?php if (!$isNew && $event->has_custom_banner): ?>
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="remove-banner" name="remove_banner" value="1">
                <label class="form-check-label" for="remove-banner">
                    <?= __('Drop this banner and go back to the default') ?>
                </label>
            </div>
        <?php endif; ?>
    </div>

    <div class="event-form-actions">
        <?= $this->Html->link(__('Cancel'), ['action' => 'manage'], ['class' => 'btn btn-default spacer']) ?>
        <?= $this->Form->button(
            '<i class="fas fa-save mr-1"></i>' . ($isNew ? __('Create event') : __('Save changes')),
            ['class' => 'btn btn-primary', 'escapeTitle' => false]
        ) ?>
    </div>

    <?= $this->Form->end() ?>
</div>

<?php $this->start('script'); ?>
<script>
    var CUSTOM_CRITERIA = '<?= Event::CRITERIA_CUSTOM_CHESTS ?>';

    var IS_GAME_EVENT = <?= $isGameEvent ? 'true' : 'false' ?>;
    // The chest list only means anything for the custom criteria of a clan event.
    function onCriteriaChange() {
        var selected = document.querySelector('.clan-criteria:checked');
        var value = selected ? selected.value : '';
        document.getElementById('customChestSection').style.display = !IS_GAME_EVENT && value === CUSTOM_CRITERIA ? 'block' : 'none';
    }
    // A reward by position is described by its places, not by one quantity.
    function onRewardRuleChange(select) {
        var line = select.closest('.reward-line');
        var byPosition = select.value === '<?= EventReward::RULE_POSITION ?>';
        line.querySelectorAll('.js-position').forEach(function (el) {
            el.style.display = byPosition ? '' : 'none';
        });
        line.querySelectorAll('.js-not-position').forEach(function (el) {
            el.style.display = byPosition ? 'none' : '';
        });
    }
    // The lines stay in the page while hidden; the server drops them when the
    // box is ticked, so unticking it brings back whatever was typed.
    function onNoRewardsChange() {
        var none = document.getElementById('no-rewards').checked;
        document.getElementById('rewardEditor').style.display = none ? 'none' : '';
    }

    function addRewardLine() {
        var container = document.getElementById('rewardLines');
        var index = 'n' + Date.now();
        var html = document.getElementById('rewardLineTemplate').innerHTML.split('__INDEX__').join(index);
        container.insertAdjacentHTML('beforeend', html);
    }

    // The last line is emptied rather than removed, so there is always one to fill.
    function removeRewardLine(button) {
        var lines = document.querySelectorAll('#rewardLines .reward-line');
        var line = button.closest('.reward-line');
        if (lines.length > 1) {
            line.remove();
            return;
        }
        line.querySelectorAll('input[type="text"]').forEach(function (input) {
            input.value = input.name.indexOf('[min_points]') !== -1 ? '1' : '';
        });
        var hidden = line.querySelector('input[type="hidden"]');
        if (hidden) {
            hidden.remove();
        }
    }

    function updateChestCount() {
        var checked = document.querySelectorAll('#chestPicker input[type="checkbox"]:checked').length;
        var badge = document.getElementById('chestCount');
        if (badge) {
            badge.textContent = checked === 1
                ? '<?= __('1 chest selected') ?>'
                : '<?= __('{0} chests selected') ?>'.replace('{0}', checked);
        }
    }

    function filterChests(term) {
        var needle = (term || '').toLowerCase().trim();
        document.querySelectorAll('#chestPicker .chest-option').forEach(function (option) {
            var name = option.getAttribute('data-name') || '';
            option.style.display = needle === '' || name.indexOf(needle) !== -1 ? '' : 'none';
        });
    }

    // Acts on what is visible: with a filter applied, "select all shown" should
    // not quietly tick the hundreds of rows the filter hid.
    function setAllChests(checked) {
        document.querySelectorAll('#chestPicker .chest-option').forEach(function (option) {
            if (option.style.display === 'none') {
                return;
            }
            var box = option.querySelector('input[type="checkbox"]');
            if (box) {
                box.checked = checked;
            }
        });
        updateChestCount();
    }

    function previewBanner(input) {
        if (!input.files || !input.files[0]) {
            return;
        }
        var reader = new FileReader();
        reader.onload = function (e) {
            document.getElementById('bannerPreview').src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }

    // Says how long the event will last, so a wrong date is obvious before saving
    // rather than after the event has been announced.
    function updateDuration() {
        var start = document.getElementById('starts-at').value;
        var end = document.getElementById('ends-at').value;
        var hint = document.getElementById('durationHint');
        if (!start || !end) {
            hint.textContent = '';
            return;
        }

        var ms = new Date(end + 'Z') - new Date(start + 'Z');
        if (ms <= 0) {
            hint.className = 'ml-2 font-weight-bold text-danger';
            hint.textContent = '<?= __('The end must come after the start.') ?>';
            return;
        }

        var hours = Math.floor(ms / 3600000);
        var days = Math.floor(hours / 24);
        hint.className = 'ml-2 font-weight-bold';
        hint.textContent = days >= 1
            ? '<?= __('Duration: {0} day(s) and {1} hour(s).') ?>'.replace('{0}', days).replace('{1}', hours % 24)
            : '<?= __('Duration: {0} hour(s).') ?>'.replace('{0}', hours);
    }

    document.addEventListener('DOMContentLoaded', function () {
        onCriteriaChange();
        updateChestCount();
        updateDuration();
        document.getElementById('starts-at').addEventListener('change', updateDuration);
        document.getElementById('ends-at').addEventListener('change', updateDuration);
    });
</script>
<?php $this->end(); ?>
