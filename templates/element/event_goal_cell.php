<?php
/**
 * One player's goal in a ranking: the goal, ticked when reached, crossed when
 * missed, with the guard level it came from when the goal follows it.
 *
 * @var \App\View\AppView $this
 * @var int|null $value The player's goal; null when the event has none.
 * @var bool|null $met Whether they reached it.
 * @var int $level Guard level (0 = unknown).
 * @var bool $byGuard Whether the goal follows the guard level.
 */

if ($value === null):
    ?><span class="text-muted">&mdash;</span><?php
    return;
endif;
?>
<span class="font-weight-bold text-nowrap <?= $met ? 'text-success' : 'text-danger' ?>"
      title="<?= h($met ? __('Goal reached') : __('Goal not reached')) ?>">
    <i class="fas <?= $met ? 'fa-check' : 'fa-times' ?> mr-1"></i><?= $this->Number->format((int)$value) ?>
</span>
<?php if (!empty($byGuard)): ?>
    <span class="badge badge-light border" title="<?= h($level === \App\Service\EventGoal::LEVEL_T9 ? __('All troops level 9 (T9)') : ($level > 0 ? __('Guards G{0}', $level) : __('Guard level unknown (G0): highest goal'))) ?>"><?= \App\Service\EventGoal::levelLabel((int)$level) ?></span>
<?php endif; ?>
