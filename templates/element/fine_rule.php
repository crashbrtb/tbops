<?php
/**
 * A tournament's fine rule in a few words, with its ranges in the tiers mode.
 *
 * @var \App\View\AppView $this
 * @var array{0: string, 1: int, 2?: list<array{from: int, to: int, amount: int}>} $rule [mode, amount, ranges]
 * @var bool $inline One line (the ranges side by side) instead of a list.
 */

use App\Service\TournamentFineService;

[$mode, $amount] = $rule;
$tiers = $rule[2] ?? [];
$inline = $inline ?? false;
?>
<?php if ($mode === TournamentFineService::MODE_FIXED): ?>
    <?= h(__('{0} for every player who misses the goal.', $this->silver($amount))) ?>
<?php elseif ($mode === TournamentFineService::MODE_PROPORTIONAL): ?>
    <?= h(__('Up to {0}, in proportion to what was missing of the goal.', $this->silver($amount))) ?>
<?php elseif ($mode === TournamentFineService::MODE_TIERS): ?>
    <?php
    $lines = array_map(
        fn (array $t): string => __('{0}% to {1}% of the goal: {2}', $t['from'], $t['to'], $this->silver($t['amount'])),
        $tiers
    );
    ?>
    <?php if ($inline): ?>
        <?= h(__('By the goal reached')) ?>: <?= h(implode(' · ', $lines)) ?>
    <?php else: ?>
        <?= h(__('By the share of the goal reached:')) ?>
        <ul class="mb-0 pl-3">
            <?php foreach ($lines as $line): ?>
                <li><?= h($line) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php else: ?>
    <span class="text-muted"><?= $inline ? __('This tournament has no fine.') : __('No fine') ?></span>
<?php endif; ?>
