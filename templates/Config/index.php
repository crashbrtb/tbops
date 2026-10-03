<?php
/**
 * @var \App\View\AppView $this
 * @var array<string, list<\App\Model\Entity\Config>> $sections Section => parameters
 * @var string $section The section open on arrival
 * @var array{mode: string, global: array<string, int>, by_guard: array<string, array<int, int>>} $goalSettings
 * @var array<int, array<string, int>> $guardGoalTable Guard level => target => goal, as resolved now
 * @var list<int> $guardLevels Guard levels that can carry a goal of their own
 */

use App\Model\Table\ConfigTable;
use App\Service\ChestGoalService;

$this->assign('title', __('System Configurations'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('List Config')],
]);

$sectionInfo = [
    ConfigTable::SECTION_GENERAL => [
        'label' => __('General settings'),
        'icon' => 'fa-sliders-h',
        'hint' => __('Clan identity, appearance, backups and every parameter that has no section of its own.'),
    ],
    ConfigTable::SECTION_BANK => [
        'label' => __('Bank settings'),
        'icon' => 'fa-university',
        'hint' => __('Whether the bank is on, and the fee charged on each kind of transaction.'),
    ],
    ConfigTable::SECTION_CHESTS => [
        'label' => __('Chest settings'),
        'icon' => 'fa-box-open',
        'hint' => __('Cycles, chest goals, the goal penalty and how scores are coloured.'),
    ],
];

$byGuard = $goalSettings['mode'] === ChestGoalService::MODE_GUARD;
$targets = [
    ChestGoalService::TARGET_TOTAL => __('Chest Score Goal'),
    ChestGoalService::TARGET_EPIC => __('Epic Chest Goal'),
];

$configTable = function (array $rows): string {
    ob_start();
    ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th><?= __('Parameter') ?></th>
                    <th><?= __('Value') ?></th>
                    <th class="text-wrap text-left"><?= __('Description') ?></th>
                    <th class="actions text-right"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4"><?= __('No configurations found.') ?></td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $c): ?>
                    <tr>
                        <td>
                            <code class="font-weight-bold text-primary" style="font-size: 0.95rem;"><?= h($c->param) ?></code>
                        </td>
                        <td>
                            <span class="badge badge-light border px-2 py-1 font-weight-bold text-dark text-wrap text-left" style="font-size: 0.9rem; max-width: 22rem; word-break: break-all;">
                                <?= h($c->value) ?>
                            </span>
                        </td>
                        <td class="text-muted small text-wrap text-left"><?= h($c->description) ?></td>
                        <td class="actions text-right text-nowrap">
                            <?= $this->Html->link(
                                '<i class="fas fa-eye"></i>',
                                ['action' => 'view', $c->id],
                                ['class' => 'btn btn-xs btn-outline-primary', 'escape' => false, 'title' => __('View')]
                            ) ?>
                            <?= $this->Html->link(
                                '<i class="fas fa-edit"></i>',
                                ['action' => 'edit', $c->id],
                                ['class' => 'btn btn-xs btn-outline-primary', 'escape' => false, 'title' => __('Edit')]
                            ) ?>
                            <?= $this->Form->postLink(
                                '<i class="fas fa-trash-alt"></i>',
                                ['action' => 'delete', $c->id],
                                [
                                    'class' => 'btn btn-xs btn-outline-danger',
                                    'escape' => false,
                                    'confirm' => __('Are you sure you want to delete parameter {0}?', $c->param),
                                    'title' => __('Delete'),
                                ]
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php

    return (string)ob_get_clean();
};
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-cogs text-primary mr-2"></i><?= __('System Configurations') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('Global environment settings, feature toggles, and application variables') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-plus mr-1"></i> ' . __('New Config'),
                ['action' => 'add'],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-paint-brush mr-1"></i> ' . __('Branding'),
                ['action' => 'branding'],
                ['class' => 'btn btn-default btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-palette mr-1"></i> ' . __('Theme'),
                ['action' => 'theme'],
                ['class' => 'btn btn-default btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-tools mr-1"></i> ' . __('Maintenance'),
                ['action' => 'maintenance'],
                ['class' => 'btn btn-outline-warning btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <div class="card card-primary card-outline card-outline-tabs">
        <div class="card-header p-0 border-bottom-0">
            <ul class="nav nav-tabs" id="config-sections" role="tablist">
                <?php foreach ($sectionInfo as $key => $info): ?>
                    <li class="nav-item">
                        <a class="nav-link<?= $key === $section ? ' active' : '' ?>"
                           id="config-tab-<?= h($key) ?>"
                           href="<?= h($this->Url->build(['action' => 'index', '?' => ['section' => $key]])) ?>"
                           data-toggle="tab"
                           data-target="#config-section-<?= h($key) ?>"
                           data-section="<?= h($key) ?>"
                           role="tab"
                           aria-controls="config-section-<?= h($key) ?>"
                           aria-selected="<?= $key === $section ? 'true' : 'false' ?>">
                            <i class="fas <?= h($info['icon']) ?> mr-1"></i><?= h($info['label']) ?>
                            <span class="badge badge-light border ml-1"><?= count($sections[$key] ?? []) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="card-body p-0">
            <div class="tab-content">
                <?php foreach ($sectionInfo as $key => $info): ?>
                    <div class="tab-pane fade<?= $key === $section ? ' show active' : '' ?>"
                         id="config-section-<?= h($key) ?>"
                         role="tabpanel"
                         aria-labelledby="config-tab-<?= h($key) ?>">
                        <p class="text-muted small px-3 pt-3 mb-2"><?= h($info['hint']) ?></p>

                        <?php if ($key === ConfigTable::SECTION_CHESTS): ?>
                            <div class="px-3 pb-3">
                                <div class="card card-outline card-info mb-0">
                                    <div class="card-header">
                                        <h3 class="card-title font-weight-bold">
                                            <i class="fas fa-bullseye text-info mr-2"></i><?= __('Chest goals') ?>
                                        </h3>
                                    </div>
                                    <?= $this->Form->create(null, ['url' => ['action' => 'chestGoals'], 'id' => 'chest-goals-form']) ?>
                                    <div class="card-body">
                                        <div class="form-group">
                                            <label class="font-weight-bold d-block"><?= __('How goals are set') ?></label>
                                            <div class="custom-control custom-radio">
                                                <input type="radio" class="custom-control-input" id="goal-mode-global" name="mode"
                                                       value="<?= h(ChestGoalService::MODE_GLOBAL) ?>"<?= $byGuard ? '' : ' checked' ?>>
                                                <label class="custom-control-label font-weight-normal" for="goal-mode-global">
                                                    <?= __('Global goal: the same goal for every player') ?>
                                                </label>
                                            </div>
                                            <div class="custom-control custom-radio">
                                                <input type="radio" class="custom-control-input" id="goal-mode-guard" name="mode"
                                                       value="<?= h(ChestGoalService::MODE_GUARD) ?>"<?= $byGuard ? ' checked' : '' ?>>
                                                <label class="custom-control-label font-weight-normal" for="goal-mode-guard">
                                                    <?= __('Individual goal by guard level: each player gets the goal of their guards (G1 to G9) from the member profile') ?>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <?php foreach ($targets as $target => $label): ?>
                                                <div class="col-md-6">
                                                    <div class="form-group">
                                                        <label for="global-<?= h($target) ?>" class="font-weight-bold">
                                                            <?= __('Global {0}', $label) ?>
                                                        </label>
                                                        <input type="number" min="0" step="1" required class="form-control"
                                                               id="global-<?= h($target) ?>"
                                                               name="global[<?= h($target) ?>]"
                                                               value="<?= h((string)$goalSettings['global'][$target]) ?>">
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="small text-muted js-guard-only">
                                            <?= __('With goals by guard level, the global goal is used for any level left blank below.') ?>
                                        </p>

                                        <div class="table-responsive js-guard-table<?= $byGuard ? '' : ' text-muted' ?>">
                                            <table class="table table-sm table-bordered mb-1">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 6rem;"><?= __('Guards') ?></th>
                                                        <?php foreach ($targets as $label): ?>
                                                            <th><?= h($label) ?></th>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($guardLevels as $level): ?>
                                                        <tr>
                                                            <td class="align-middle font-weight-bold">G<?= $level ?></td>
                                                            <?php foreach ($targets as $target => $label): ?>
                                                                <td>
                                                                    <input type="number" min="0" step="1"
                                                                           class="form-control form-control-sm js-guard-input"
                                                                           name="by_guard[<?= h($target) ?>][<?= $level ?>]"
                                                                           aria-label="<?= h('G' . $level . ' — ' . $label) ?>"
                                                                           placeholder="<?= h(__('Global ({0})', $this->Number->format($goalSettings['global'][$target]))) ?>"
                                                                           value="<?= isset($goalSettings['by_guard'][$target][$level]) ? h((string)$goalSettings['by_guard'][$target][$level]) : '' ?>"
                                                                           <?= $byGuard ? '' : 'readonly' ?>>
                                                                </td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    <tr class="table-active">
                                                        <td class="align-middle font-weight-bold" title="<?= h(__('Guard level not identified')) ?>">G0</td>
                                                        <?php foreach ($targets as $target => $label): ?>
                                                            <td class="align-middle small">
                                                                <?php if ($byGuard): ?>
                                                                    <?= __('Highest goal: {0}', $this->Number->format($guardGoalTable[0][$target] ?? 0)) ?>
                                                                <?php else: ?>
                                                                    <?= __('Highest goal') ?>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <p class="small text-muted mb-0">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            <?= __('G0 means the guard level was never identified. Those players, and players with no member profile, get the highest goal of the table.') ?>
                                            <?= __('Each closed cycle keeps the goal the player had in it, so changing a level later does not rewrite history. The goal penalty raises the player\'s own goal.') ?>
                                        </p>
                                    </div>
                                    <div class="card-footer text-right">
                                        <?= $this->Form->button(
                                            '<i class="fas fa-save mr-1"></i> ' . __('Save Chest Goals'),
                                            ['class' => 'btn btn-primary', 'escapeTitle' => false]
                                        ) ?>
                                    </div>
                                    <?= $this->Form->end() ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?= $configTable($sections[$key] ?? []) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        // Remember the open section in the address, so a reload or a shared link opens it again
        document.querySelectorAll('#config-sections [data-section]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var url = new URL(window.location.href);
                url.searchParams.set('section', tab.getAttribute('data-section'));
                window.history.replaceState(null, '', url.toString());
            });
        });

        // The level table only matters with goals by guard level
        var form = document.getElementById('chest-goals-form');
        if (!form) {
            return;
        }
        var sync = function () {
            var byGuard = document.getElementById('goal-mode-guard').checked;
            form.querySelectorAll('.js-guard-input').forEach(function (input) {
                input.readOnly = !byGuard;
            });
            form.querySelectorAll('.js-guard-table').forEach(function (el) {
                el.classList.toggle('text-muted', !byGuard);
            });
            form.querySelectorAll('.js-guard-only').forEach(function (el) {
                el.style.display = byGuard ? '' : 'none';
            });
        };
        form.querySelectorAll('input[name="mode"]').forEach(function (radio) {
            radio.addEventListener('change', sync);
        });
        sync();
    })();
</script>
