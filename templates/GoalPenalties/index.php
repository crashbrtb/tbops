<?php
/**
 * Who carries a raised chest goal in a cycle, and releasing players from it.
 *
 * @var \App\View\AppView $this
 * @var array{enabled: bool, mode: string, targets: list<string>, percent: float, base_goals: array<string, int>, raised_goals: array<string, int>, cycle_days: int, reference_day: ?\Cake\I18n\FrozenTime} $settings
 * @var int $cyclesAgo
 * @var \Cake\I18n\FrozenTime|null $cycleStart
 * @var array<int, string> $cycleOptions
 * @var array<string, array{status: string, previous: array<string, array{score: int, goal: int, missed: bool}>, raised: array<string, int>, goals: array<string, int>}> $evaluation
 * @var array<string, array<string, int>> $currentScores Player => target => score so far in the cycle
 * @var array<string, \App\Model\Entity\GoalPenaltyWaiver> $waivers
 * @var array<string, array{goals: array<string, int>, percent: float, automatic_reason: ?string, manual_reason: ?string}> $penalties Player => raised goals in this cycle, automatic and manual together
 * @var array<string, \App\Model\Entity\ManualGoalPenalty> $manualPenalties
 * @var list<string> $manualTargets Goals a manual penalty can raise
 * @var list<string> $playerOptions
 */

use App\Service\GoalPenaltyService;

$this->assign('title', __('Goal Penalties'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('Goal Penalties')],
]);

$byStatus = [
    GoalPenaltyService::STATUS_PENALIZED => [],
    GoalPenaltyService::STATUS_WAIVED => [],
    GoalPenaltyService::STATUS_FIRST_CYCLE => [],
];
foreach ($evaluation as $player => $row) {
    $byStatus[$row['status']][(string)$player] = $row;
}
$goalNames = [
    GoalPenaltyService::TARGET_TOTAL => __('Chest Score Goal'),
    GoalPenaltyService::TARGET_EPIC => __('Epic Chest Goal'),
];
$shortNames = [
    GoalPenaltyService::TARGET_TOTAL => __('Total'),
    GoalPenaltyService::TARGET_EPIC => __('Epic'),
];
// With both goals watched every score is labelled, otherwise the label is noise
$labelled = count($settings['targets']) > 1;
$number = fn (int $value): string => $this->Number->format($value);
$scoreAgainst = function (string $target, int $score, int $goal, bool $raised = false, ?bool $label = null) use ($number, $shortNames, $labelled): string {
    $class = $score >= $goal ? 'text-success' : 'text-danger';

    return '<div class="text-nowrap">'
        . ($label ?? $labelled ? '<small class="text-muted mr-1">' . h($shortNames[$target]) . ':</small>' : '')
        . '<span class="font-weight-bold ' . $class . '">' . $number($score) . '</span>'
        . ' <span class="text-muted">/ ' . $number($goal) . '</span>'
        . ($raised ? ' <i class="fas fa-arrow-up text-warning" title="' . h(__('Raised goal')) . '"></i>' : '')
        . '</div>';
};
// Score and goal of the previous cycle, per watched goal
$previousCell = function (array $row) use ($scoreAgainst): string {
    $html = '';
    foreach ($row['previous'] as $target => $previous) {
        $html .= $scoreAgainst($target, $previous['score'], $previous['goal']);
    }

    return $html;
};
// Score so far in this cycle against the goal the player has in it
$currentCell = function (string $player, array $row) use ($scoreAgainst, $currentScores): string {
    $html = '';
    foreach ($row['goals'] as $target => $goal) {
        $html .= $scoreAgainst($target, $currentScores[$player][$target] ?? 0, $goal, isset($row['raised'][$target]));
    }

    return $html;
};
$targetOptions = [];
foreach ($manualTargets as $target) {
    $targetOptions[$target] = $goalNames[$target];
}
if (count($manualTargets) > 1) {
    $targetOptions[GoalPenaltyService::TARGET_BOTH] = __('Both goals');
}
$reasonMaxLength = GoalPenaltyService::REASON_MAX_LENGTH;
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-arrow-up text-warning mr-2"></i><?= __('Goal Penalties') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('Players who missed the goal in the previous cycle carry a raised goal in this one. Release a player here when there is a good reason.') ?>
            </p>
        </div>
        <?php if ($cycleOptions): ?>
            <div class="toolbar-actions">
                <?= $this->Form->create(null, ['type' => 'get', 'class' => 'form-inline']) ?>
                <?= $this->Form->select('cycle', $cycleOptions, [
                    'default' => $cyclesAgo,
                    'class' => 'form-control form-control-sm mr-2',
                ]) ?>
                <?= $this->Form->button('<i class="fas fa-filter mr-1"></i> ' . __('Filter'), [
                    'escapeTitle' => false,
                    'class' => 'btn btn-sm btn-outline-secondary',
                ]) ?>
                <?= $this->Form->end() ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$settings['enabled']): ?>
        <div class="alert alert-info">
            <i class="fas fa-eye mr-1"></i>
            <strong><?= __('Preview') ?>:</strong>
            <?= __('the goal penalty is off, so nobody is affected yet. The lists below show who would be if it were turned on now.') ?>
            <?= __('Set goal_penalty_enabled to 1 (and a percentage above 0) to turn it on.') ?>
            <?= $this->Html->link(__('Configs'), ['controller' => 'Config', 'action' => 'index', '?' => ['section' => 'chests']], ['class' => 'alert-link ml-1']) ?>
        </div>
    <?php endif; ?>
    <div class="card card-outline card-warning">
        <div class="card-body py-2">
            <i class="fas fa-info-circle text-warning mr-1"></i>
            <?php foreach ($settings['targets'] as $target): ?>
                <div>
                    <?php if (!empty($settings['by_guard'])): ?>
                        <?= __('{0}: the goal of the player\'s guard level, raised by {1}% for players who missed it.', $goalNames[$target], $this->Number->format($settings['percent'])) ?>
                    <?php else: ?>
                        <?= __('{0}: {1} points, raised by {2}% to {3} points for players who missed it.', $goalNames[$target], $number($settings['base_goals'][$target]), $this->Number->format($settings['percent']), $number($settings['raised_goals'][$target])) ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$settings['targets']): ?>
                <div><?= __('The goal the penalty watches is 0 in the configs, so nobody can miss it.') ?></div>
            <?php endif; ?>
            <?php if ($labelled): ?>
                <div><?= __('Missing either goal is a miss, and only the goal that was missed is raised.') ?></div>
            <?php endif; ?>
            <div><?= __('The first cycle of a player in the clan never counts as a miss.') ?></div>
        </div>
    </div>

    <?php if ($cycleStart === null): ?>
        <div class="alert alert-danger"><?= __('reference_day or every_how_many_days is missing in the configs.') ?></div>
    <?php else: ?>

        <!-- Penalized players -->
        <div class="card card-warning card-outline">
            <div class="card-header">
                <h3 class="card-title font-weight-bold">
                    <i class="fas fa-arrow-up text-warning mr-2"></i><?= $settings['enabled'] ? __('Players with a raised goal') : __('Players who would get a raised goal') ?>
                    <span class="badge badge-warning ml-1"><?= count($byStatus[GoalPenaltyService::STATUS_PENALIZED]) ?></span>
                </h3>
            </div>
            <div class="card-body table-responsive p-0">
                <?php if (!$byStatus[GoalPenaltyService::STATUS_PENALIZED]): ?>
                    <p class="text-muted p-3 mb-0"><?= $settings['enabled'] ? __('Nobody carries a raised goal in this cycle.') : __('Nobody would carry a raised goal in this cycle.') ?></p>
                <?php else: ?>
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?= __('Player') ?></th>
                                <th><?= __('Previous cycle (score / goal)') ?></th>
                                <th><?= __('This cycle (score / goal)') ?></th>
                                <th class="text-right"><?= __('Release') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byStatus[GoalPenaltyService::STATUS_PENALIZED] as $player => $row): ?>
                                <tr>
                                    <td class="align-middle">
                                        <?= $this->Html->link($player, ['controller' => 'PlayerCycleSummaries', 'action' => 'playerHistory', urlencode($player)]) ?>
                                    </td>
                                    <td class="align-middle">
                                        <?= $previousCell($row) ?>
                                        <small class="text-muted"><?= h($row['reason']) ?></small>
                                    </td>
                                    <td class="align-middle"><?= $currentCell((string)$player, $row) ?></td>
                                    <td class="align-middle text-right">
                                        <?= $this->Form->create(null, ['url' => ['action' => 'release'], 'class' => 'form-inline justify-content-end']) ?>
                                        <?= $this->Form->hidden('player_name', ['value' => $player]) ?>
                                        <?= $this->Form->hidden('cycle', ['value' => $cyclesAgo]) ?>
                                        <?= $this->Form->text('reason', [
                                            'class' => 'form-control form-control-sm mr-2',
                                            'placeholder' => __('Reason (optional)'),
                                            'maxlength' => 255,
                                        ]) ?>
                                        <?= $this->Form->button('<i class="fas fa-unlock mr-1"></i> ' . __('Release'), [
                                            'escapeTitle' => false,
                                            'class' => 'btn btn-sm btn-success',
                                        ]) ?>
                                        <?= $this->Form->end() ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Manual penalties -->
        <div class="card card-danger card-outline">
            <div class="card-header">
                <h3 class="card-title font-weight-bold">
                    <i class="fas fa-gavel text-danger mr-2"></i><?= __('Manual penalties') ?>
                    <span class="badge badge-danger ml-1"><?= count($manualPenalties) ?></span>
                </h3>
            </div>
            <div class="card-body border-bottom">
                <p class="text-muted mb-2">
                    <?= __('A manual penalty raises a player\'s goal in this cycle by the percentage you set. It works even while the automatic penalty is off, and adds to it when the player already has one.') ?>
                </p>
                <?php if (!$manualTargets): ?>
                    <div class="text-muted"><?= __('The goal the penalty watches is 0 in the configs, so nobody can miss it.') ?></div>
                <?php else: ?>
                    <?= $this->Form->create(null, ['url' => ['action' => 'addManual'], 'class' => 'form-inline']) ?>
                    <?= $this->Form->hidden('cycle', ['value' => $cyclesAgo]) ?>
                    <?= $this->Form->text('player_name', [
                        'class' => 'form-control form-control-sm mr-2 mb-2',
                        'placeholder' => __('Player'),
                        'list' => 'manual-penalty-players',
                        'autocomplete' => 'off',
                        'required' => true,
                        'maxlength' => 255,
                    ]) ?>
                    <datalist id="manual-penalty-players">
                        <?php foreach ($playerOptions as $option): ?>
                            <option value="<?= h($option) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <?php if (count($targetOptions) > 1): ?>
                        <?= $this->Form->select('target', $targetOptions, [
                            'class' => 'form-control form-control-sm mr-2 mb-2',
                            'empty' => false,
                        ]) ?>
                    <?php else: ?>
                        <?= $this->Form->hidden('target', ['value' => $manualTargets[0]]) ?>
                    <?php endif; ?>
                    <div class="input-group input-group-sm mr-2 mb-2" style="width: 110px;">
                        <div class="input-group-prepend"><span class="input-group-text">+</span></div>
                        <?= $this->Form->number('percent', [
                            'class' => 'form-control',
                            'value' => $settings['percent'] > 0 ? $settings['percent'] : 10,
                            'min' => 0.01,
                            'max' => 1000,
                            'step' => 'any',
                            'required' => true,
                            'title' => __('Increase (%)'),
                        ]) ?>
                        <div class="input-group-append"><span class="input-group-text">%</span></div>
                    </div>
                    <?= $this->Form->text('reason', [
                        'class' => 'form-control form-control-sm mr-2 mb-2 flex-grow-1',
                        'placeholder' => __('Reason (required, up to {0} characters)', $reasonMaxLength),
                        'required' => true,
                        'maxlength' => $reasonMaxLength,
                    ]) ?>
                    <?= $this->Form->button('<i class="fas fa-plus mr-1"></i> ' . __('Add'), [
                        'escapeTitle' => false,
                        'class' => 'btn btn-sm btn-danger mb-2',
                    ]) ?>
                    <?= $this->Form->end() ?>
                <?php endif; ?>
            </div>
            <div class="card-body table-responsive p-0">
                <?php if (!$manualPenalties): ?>
                    <p class="text-muted p-3 mb-0"><?= __('Nobody has a manual penalty in this cycle.') ?></p>
                <?php else: ?>
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?= __('Player') ?></th>
                                <th><?= __('Goal') ?></th>
                                <th><?= __('This cycle (score / goal)') ?></th>
                                <th><?= __('Reason') ?></th>
                                <th><?= __('Added by') ?></th>
                                <th class="text-right"><?= __('Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manualPenalties as $player => $manualPenalty): ?>
                                <tr>
                                    <td class="align-middle">
                                        <?= $this->Html->link($manualPenalty->player_name, ['controller' => 'PlayerCycleSummaries', 'action' => 'playerHistory', urlencode($manualPenalty->player_name)]) ?>
                                    </td>
                                    <td class="align-middle text-nowrap">
                                        <?= h($manualPenalty->target === GoalPenaltyService::TARGET_BOTH ? __('Both goals') : $goalNames[$manualPenalty->target]) ?>
                                        <span class="badge badge-warning ml-1">+<?= $this->Number->format((float)$manualPenalty->percent) ?>%</span>
                                    </td>
                                    <td class="align-middle">
                                        <?php foreach ($manualPenalty->targets() as $target): ?>
                                            <?php if (isset($penalties[$player]['goals'][$target])): ?>
                                                <?= $scoreAgainst($target, $currentScores[$player][$target] ?? 0, $penalties[$player]['goals'][$target], true, count($manualTargets) > 1) ?>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="align-middle"><?= h($manualPenalty->reason) ?></td>
                                    <td class="align-middle">
                                        <?= h($manualPenalty->user->name ?? '—') ?>
                                        <small class="d-block text-muted"><?= h($manualPenalty->created?->i18nFormat('dd/MM/yyyy HH:mm')) ?></small>
                                    </td>
                                    <td class="align-middle text-right">
                                        <?= $this->Form->postLink(
                                            '<i class="fas fa-trash mr-1"></i> ' . __('Remove'),
                                            ['action' => 'removeManual', $manualPenalty->id],
                                            [
                                                'escape' => false,
                                                'class' => 'btn btn-sm btn-outline-danger',
                                                'confirm' => __('Remove the manual penalty of {0}?', $manualPenalty->player_name),
                                            ]
                                        ) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Released players -->
        <div class="card card-success card-outline">
            <div class="card-header">
                <h3 class="card-title font-weight-bold">
                    <i class="fas fa-unlock text-success mr-2"></i><?= __('Released by an administrator') ?>
                    <span class="badge badge-success ml-1"><?= count($waivers) ?></span>
                </h3>
            </div>
            <div class="card-body table-responsive p-0">
                <?php if (!$waivers): ?>
                    <p class="text-muted p-3 mb-0"><?= __('Nobody was released in this cycle.') ?></p>
                <?php else: ?>
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?= __('Player') ?></th>
                                <th><?= __('Previous cycle (score / goal)') ?></th>
                                <th><?= __('Reason') ?></th>
                                <th><?= __('Released by') ?></th>
                                <th class="text-right"><?= __('Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($waivers as $player => $waiver): ?>
                                <?php $row = $evaluation[$player] ?? null; ?>
                                <tr>
                                    <td class="align-middle">
                                        <?= $this->Html->link($waiver->player_name, ['controller' => 'PlayerCycleSummaries', 'action' => 'playerHistory', urlencode($waiver->player_name)]) ?>
                                    </td>
                                    <td class="align-middle">
                                        <?php if ($row !== null): ?>
                                            <?= $previousCell($row) ?>
                                        <?php else: ?>
                                            <span class="text-muted"><?= __('Not penalized anyway') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle"><?= $waiver->reason !== null ? h($waiver->reason) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="align-middle">
                                        <?= h($waiver->user->name ?? '—') ?>
                                        <small class="d-block text-muted"><?= h($waiver->created?->i18nFormat('dd/MM/yyyy HH:mm')) ?></small>
                                    </td>
                                    <td class="align-middle text-right">
                                        <?= $this->Form->postLink(
                                            '<i class="fas fa-undo mr-1"></i> ' . __('Undo'),
                                            ['action' => 'revoke', $waiver->id],
                                            [
                                                'escape' => false,
                                                'class' => 'btn btn-sm btn-outline-danger',
                                                'confirm' => __('Undo the release of {0}? The raised goal applies again if the player missed the goal.', $waiver->player_name),
                                            ]
                                        ) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- First cycle in the clan -->
        <div class="card card-info card-outline">
            <div class="card-header">
                <h3 class="card-title font-weight-bold">
                    <i class="fas fa-user-plus text-info mr-2"></i><?= __('Spared: first cycle in the clan') ?>
                    <span class="badge badge-info ml-1"><?= count($byStatus[GoalPenaltyService::STATUS_FIRST_CYCLE]) ?></span>
                </h3>
            </div>
            <div class="card-body table-responsive p-0">
                <?php if (!$byStatus[GoalPenaltyService::STATUS_FIRST_CYCLE]): ?>
                    <p class="text-muted p-3 mb-0"><?= __('No newcomer missed the goal in the previous cycle.') ?></p>
                <?php else: ?>
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th><?= __('Player') ?></th>
                                <th><?= __('Previous cycle (score / goal)') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($byStatus[GoalPenaltyService::STATUS_FIRST_CYCLE] as $player => $row): ?>
                                <tr>
                                    <td><?= $this->Html->link($player, ['controller' => 'PlayerCycleSummaries', 'action' => 'playerHistory', urlencode($player)]) ?></td>
                                    <td><?= $previousCell($row) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
