<?php
/**
 * The goal of an event, as the players read it.
 *
 * @var \App\View\AppView $this
 * @var \App\Service\EventGoal $goal The event goal (active).
 * @var int|null $metCount How many players reached it, when known.
 * @var int|null $playerCount How many players are ranked.
 */

$metCount = $metCount ?? null;
$playerCount = $playerCount ?? null;
?>
<div class="event-info-card is-rules">
    <h3><i class="fas fa-bullseye"></i> <?= __('Event goal') ?></h3>
    <?php if ($goal->isByGuard()): ?>
        <p><?= __('Each player has the goal of their guard level:') ?></p>
        <div class="event-chest-tags">
            <?php foreach ($goal->levelTable() as $level => $points): ?>
                <span class="event-chest-tag" title="<?= $level === 0 ? h(__('Guard level not identified')) : '' ?>">
                    G<?= $level ?> <span class="tag-score"><?= $this->Number->format($points) ?></span>
                </span>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p><?= __('{0} points for every player.', $this->Number->format($goal->points)) ?></p>
    <?php endif; ?>
    <p class="text-muted mt-2" style="font-size: 0.82rem;">
        <?= $goal->isRequired()
            ? __('Reaching the goal is required to receive a reward: the rewards are split only among the players who reached it.')
            : __('The goal does not decide the rewards.') ?>
        <?php if ($metCount !== null && $playerCount !== null): ?>
            <br><?= __('{0} of {1} player(s) reached it.', $this->Number->format($metCount), $this->Number->format($playerCount)) ?>
        <?php endif; ?>
    </p>
</div>
