<?php
/**
 * The goal of an event, or the default goal of a tournament in the catalogue.
 *
 * Posts `goal[mode]`, `goal[points]`, `goal[by_guard][level]` and
 * `goal[required]`; EventGoal::marshal() turns them into the stored columns.
 *
 * @var \App\View\AppView $this
 * @var \App\Service\EventGoal $goal The goal as it stands.
 * @var string|null $error Validation message for the goal, if the save left one.
 * @var string|null $pointsLabel What the goal counts, e.g. "Points" or "Chests".
 */

use App\Service\ChestGoalService;
use App\Service\EventGoal;

$error = $error ?? null;
$pointsLabel = $pointsLabel ?? __('Points');
$uid = 'goal-' . substr(md5((string)mt_rand()), 0, 6);
$mode = $goal->mode;
?>
<div class="event-goal-fields" id="<?= h($uid) ?>">
    <div class="form-group">
        <?php foreach (EventGoal::modeOptions() as $value => $label): ?>
            <div class="custom-control custom-radio">
                <input type="radio" class="custom-control-input js-goal-mode" id="<?= h($uid . '-' . $value) ?>"
                       name="goal[mode]" value="<?= h($value) ?>"<?= $mode === $value ? ' checked' : '' ?>>
                <label class="custom-control-label font-weight-normal" for="<?= h($uid . '-' . $value) ?>"><?= h($label) ?></label>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="js-goal-active"<?= $mode === EventGoal::MODE_NONE ? ' style="display: none;"' : '' ?>>
        <div class="form-group" style="max-width: 320px;">
            <label for="<?= h($uid) ?>-points">
                <span class="js-goal-global-label"<?= $mode === EventGoal::MODE_GUARD ? ' style="display: none;"' : '' ?>><?= __('Goal ({0})', $pointsLabel) ?></span>
                <span class="js-goal-guard-label"<?= $mode === EventGoal::MODE_GUARD ? '' : ' style="display: none;"' ?>><?= __('Goal for levels left blank ({0})', $pointsLabel) ?></span>
            </label>
            <input type="text" inputmode="numeric" class="form-control" id="<?= h($uid) ?>-points" name="goal[points]"
                   value="<?= $goal->points > 0 ? h((string)$goal->points) : '' ?>" placeholder="10000">
        </div>

        <div class="js-goal-guard"<?= $mode === EventGoal::MODE_GUARD ? '' : ' style="display: none;"' ?>>
            <div class="table-responsive" style="max-width: 420px;">
                <table class="table table-sm table-bordered mb-1">
                    <thead>
                        <tr>
                            <th style="width: 6rem;"><?= __('Guards') ?></th>
                            <th><?= __('Goal ({0})', $pointsLabel) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse(ChestGoalService::LEVELS) as $level): ?>
                            <tr>
                                <td class="align-middle font-weight-bold">G<?= $level ?></td>
                                <td>
                                    <input type="text" inputmode="numeric" class="form-control form-control-sm"
                                           name="goal[by_guard][<?= $level ?>]"
                                           aria-label="<?= h('G' . $level . ' — ' . __('Goal')) ?>"
                                           placeholder="<?= h(__('Same as blank levels')) ?>"
                                           value="<?= isset($goal->byGuard[$level]) ? h((string)$goal->byGuard[$level]) : '' ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-active">
                            <td class="align-middle font-weight-bold" title="<?= h(__('Guard level not identified')) ?>">G0</td>
                            <td class="align-middle small"><?= __('Highest goal') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted">
                <?= __('G0 means the guard level was never identified. Those players, and players with no member profile, get the highest goal of the table.') ?>
            </p>
        </div>

        <div class="form-check">
            <input type="hidden" name="goal[required]" value="0">
            <input type="checkbox" class="form-check-input" id="<?= h($uid) ?>-required" name="goal[required]" value="1"<?= $goal->required ? ' checked' : '' ?>>
            <label class="form-check-label" for="<?= h($uid) ?>-required">
                <?= __('Reaching the goal is required to receive a reward') ?>
            </label>
            <small class="form-text text-muted">
                <?= __('Players who miss the goal are still ranked, but the rewards are split only among those who reached it. A reward by position goes to the best placed players who reached it.') ?>
            </small>
        </div>
    </div>

    <?php if ($error): ?>
        <span class="field-error"><i class="fas fa-exclamation-circle mr-1"></i><?= h($error) ?></span>
    <?php endif; ?>
</div>
<script>
    (function () {
        var root = document.getElementById(<?= json_encode($uid) ?>);
        var sync = function () {
            var checked = root.querySelector('.js-goal-mode:checked');
            var mode = checked ? checked.value : 'none';
            root.querySelector('.js-goal-active').style.display = mode === 'none' ? 'none' : '';
            root.querySelector('.js-goal-guard').style.display = mode === 'guard' ? '' : 'none';
            root.querySelector('.js-goal-guard-label').style.display = mode === 'guard' ? '' : 'none';
            root.querySelector('.js-goal-global-label').style.display = mode === 'guard' ? 'none' : '';
        };
        root.querySelectorAll('.js-goal-mode').forEach(function (radio) {
            radio.addEventListener('change', sync);
        });
    })();
</script>
