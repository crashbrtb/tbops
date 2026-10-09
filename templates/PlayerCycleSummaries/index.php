<?php
/**
 * @var \App\View\AppView $this
 * @var array $summariesByCycle Array of player cycle summaries grouped by cycle start date string.
 * @var array $formattedCycleDates Array of formatted cycle start and end dates, keyed by cycle start date string.
 * @var int $minimumChestScore
 * @var int $minimumEpicChestScore
 * @var array $scoreColorsConfig
 */

// Obter identidade do usuário e verificar se é admin
$identity = $this->request->getAttribute('identity');
$isLoggedIn = $identity !== null;
$isAdmin = false;

if ($isLoggedIn) {
    $userAssociatedRoles = $identity->get('roles'); 

    if (!empty($userAssociatedRoles) && (is_array($userAssociatedRoles) || $userAssociatedRoles instanceof \Traversable)) {
        foreach ($userAssociatedRoles as $roleEntity) {
            if (is_object($roleEntity) && isset($roleEntity->name) && $roleEntity->name === 'admin') {
                $isAdmin = true;
                break; 
            }
        }
    }
}

$minimumChestScore = $minimumChestScore ?? 0;
$minimumEpicChestScore = $minimumEpicChestScore ?? 0;
$scoreColorsConfig = $scoreColorsConfig ?? [];

$transitionStart = (float)($scoreColorsConfig['score_color_transition_start'] ?? 0.0);
$startR = (int)($scoreColorsConfig['score_color_start_r'] ?? 255);
$startG = (int)($scoreColorsConfig['score_color_start_g'] ?? 0);
$startB = (int)($scoreColorsConfig['score_color_start_b'] ?? 0);
$endR = (int)($scoreColorsConfig['score_color_end_r'] ?? 0);
$endG = (int)($scoreColorsConfig['score_color_end_g'] ?? 255);
$endB = (int)($scoreColorsConfig['score_color_end_b'] ?? 0);

$scoreColor = function ($scoreValue, $targetValue) use ($transitionStart, $startR, $startG, $startB, $endR, $endG, $endB) {
    $percentage = $targetValue > 0
        ? min(max($scoreValue / $targetValue, 0), 1)
        : ($scoreValue > 0 ? 1 : 0);

    $adjusted = 0;
    if ($percentage >= $transitionStart) {
        $adjusted = (1.0 - $transitionStart > 0)
            ? ($percentage - $transitionStart) / (1.0 - $transitionStart)
            : 1.0;
    }

    $r = (int)($startR + ($endR - $startR) * $adjusted);
    $g = (int)($startG + ($endG - $startG) * $adjusted);
    $b = (int)($startB + ($endB - $startB) * $adjusted);

    return sprintf('rgb(%d, %d, %d)', $r, $g, $b);
};
?>

<style>
    /* No token declarations here: this element sits below <html data-theme>,
       so redeclaring --bg and friends would override the active theme for
       everything inside it. */
    .score-new-page {
        background: var(--bg);
        padding: 16px;
        border-radius: 14px;
    }

    .score-toolbar {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }

    .score-title {
        margin: 0;
        color: var(--text);
        font-weight: 700;
    }

    .cycle-subtitle {
        color: var(--muted);
        margin: 4px 0 0;
        font-size: 0.95rem;
    }

    .goal-pill {
        background: var(--accent-light);
        border: 1px solid var(--accent-border);
        color: var(--accent-dark);
        border-radius: 999px;
        padding: 8px 14px;
        font-weight: 600;
        font-size: 0.9rem;
        display: inline-block;
        margin-bottom: 16px;
    }

    .ranking-card {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 14px;
        box-shadow: 0 10px 30px rgba(17, 24, 39, 0.06);
        overflow: hidden;
        margin-bottom: 16px;
    }

    .ranking-header {
        padding: 14px 16px;
        border-bottom: 1px solid var(--line);
        font-weight: 700;
        color: var(--text);
        background: var(--surface-raised);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .ranking-header .card-title {
        font-size: 1.05rem;
        font-weight: 700;
        margin: 0;
    }

    .ranking-table-wrap {
        overflow-x: auto;
    }

    .ranking-table {
        width: 100%;
        border-collapse: collapse;
    }

    .ranking-table th,
    .ranking-table td {
        padding: 11px 12px;
        border-bottom: 1px solid var(--line-subtle);
        text-align: center;
        white-space: nowrap;
    }

    .ranking-table th {
        background: var(--surface-sunken);
        color: var(--text-soft);
        font-size: 0.9rem;
        font-weight: 700;
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .ranking-table tbody tr:hover {
        background: var(--surface-hover);
    }

    .player-link {
        color: var(--link);
        text-decoration: none;
        font-weight: 600;
    }

    .player-link:hover {
        text-decoration: underline;
    }

    .top-rank {
        min-width: 28px;
        height: 28px;
        border-radius: 999px;
        background: var(--accent-light);
        color: var(--accent-dark);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.85rem;
        padding: 0 6px;
    }
</style>

<div class="score-new-page">
    <div class="score-toolbar">
        <div>
            <h1 class="score-title"><?= __('Player Cycle Summaries') ?></h1>
            <p class="cycle-subtitle"><?= __('Summary of player performance by cycle') ?></p>
        </div>
        <?php if ($isAdmin): ?>
        <div class="card-tools">
            <?= $this->Html->link('<i class="fas fa-sync-alt"></i> ' . __('Process Cycle (offset=1)'), [
                'action' => 'processCycleSummaries', '?' => ['cycle_offset' => 1]
            ], ['class' => 'btn btn-sm btn-outline-secondary ml-1', 'escape' => false]) ?>                 
            <?= $this->Html->link('<i class="fas fa-sync-alt"></i> ' . __('Process Previous Cycle (offset=0)'), [
                'action' => 'processCycleSummaries', '?' => ['cycle_offset' => 0]
            ], ['class' => 'btn btn-sm btn-outline-primary ml-1', 'escape' => false]) ?>
            <?= $this->Html->link('<i class="fas fa-cogs"></i> ' . __('Process Newest Completed Cycle'), [
                'action' => 'processCycleSummaries'
            ], ['class' => 'btn btn-sm btn-primary ml-1', 'escape' => false]) ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($goalsByGuard)): ?>
    <div class="goal-pill">
        <?= __('Goals by guard level: each score is judged by the goal the player had in that cycle.') ?>
    </div>
    <?php elseif ($minimumChestScore > 0 || $minimumEpicChestScore > 0): ?>
    <div class="goal-pill">
        <?= __('Current Goal: {0} chest points and {1} Epic chest points', $this->Number->format($minimumChestScore), $this->Number->format($minimumEpicChestScore)) ?>
    </div>
    <?php endif; ?>

    <?php if (empty($summariesByCycle)): ?>
        <div class="ranking-card p-4 text-center text-muted">
            <h5><?= __('No Summaries Found') ?></h5>
            <p><?= __('There are no player cycle summaries to display. You can try processing a cycle using the buttons above.') ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($formattedCycleDates as $cycleStartDateString => $cycleDates): ?>
            <?php if (isset($summariesByCycle[$cycleStartDateString])): ?>
                <div class="ranking-card card card-outline card-info collapsed-card">
                    <div class="ranking-header card-header">
                        <h4 class="card-title mb-0">
                            <button type="button" class="btn btn-tool mr-2" data-card-widget="collapse">
                                <i class="fas fa-plus"></i> 
                            </button>
                            <?= __('Cycle: {0} to {1}', $cycleDates['start']->i18nFormat('yyyy-MM-dd'), $cycleDates['end']->i18nFormat('yyyy-MM-dd')) ?>
                        </h4>
                        <span class="badge badge-light" style="font-size: 0.85rem;">
                            <?= count($summariesByCycle[$cycleStartDateString]) ?> <?= __('Players') ?>
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="ranking-table-wrap">
                            <table class="ranking-table">
                                <thead>
                                    <tr>
                                        <th><?= __('Pos.') ?></th>
                                        <th><?= __('Player Name') ?></th>
                                        <th><?= __('Total Chests') ?></th>
                                        <th><?= __('Total Score') ?></th>
                                        <th><?= __('Epic Crypt Score') ?></th>
                                        <th><?= __('Goal Achieved') ?></th>
                                        <th><?= __('Fine Status') ?></th> 
                                        <?php if ($isAdmin): ?>
                                        <th class="actions text-right"><?= __('Actions') ?></th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($summariesByCycle[$cycleStartDateString] as $idx => $summary): ?>
                                    <?php
                                    $score = $summary->total_score;
                                    $scoreCellColor = $scoreColor($score, $summary->goalFor('total', (int)$minimumChestScore));
                                    $epicScore = $summary->epic_crypt_score ?? 0;
                                    $epicCellColor = $scoreColor($epicScore, $summary->goalFor('epic', (int)$minimumEpicChestScore));
                                    $raisedParts = [];
                                    if ($summary->penalty_goal !== null) {
                                        $raisedParts[] = __('{0} chest points', $this->Number->format($summary->penalty_goal));
                                    }
                                    if ($summary->penalty_epic_goal !== null) {
                                        $raisedParts[] = __('{0} Epic chest points', $this->Number->format($summary->penalty_epic_goal));
                                    }
                                    $penaltyTitle = $raisedParts ? __('Raised goal in this cycle: {0}', implode(' + ', $raisedParts)) : null;
                                    $penaltyReasons = array_filter([
                                        $summary->penalty_reason,
                                        $manualPenaltyReasons[$cycleStartDateString][$summary->player_name] ?? null,
                                    ]);
                                    if ($penaltyTitle !== null && $penaltyReasons) {
                                        $penaltyTitle .= ' — ' . implode(' · ', $penaltyReasons);
                                    }
                                    ?>
                                    <tr>
                                        <td><span class="top-rank"><?= $idx + 1 ?></span></td>
                                        <td>
                                            <?= $this->Html->link(
                                                $summary->player_name,
                                                ['controller' => 'PlayerCycleSummaries', 'action' => 'playerHistory', urlencode($summary->player_name)],
                                                ['class' => 'player-link', 'title' => $penaltyTitle ?? $summary->player_name]
                                            ) ?>
                                            <?php if ($summary->guard_level !== null): ?>
                                                <span class="badge badge-light border ml-1" title="<?= h(__('Goal in this cycle: {0} chest points, {1} Epic chest points', $this->Number->format($summary->goalFor('total', (int)$minimumChestScore)), $this->Number->format($summary->goalFor('epic', (int)$minimumEpicChestScore)))) ?>">G<?= (int)$summary->guard_level ?></span>
                                            <?php endif; ?>
                                            <?php if ($penaltyTitle !== null): ?>
                                                <i class="fas fa-arrow-up" style="color: #d97706; cursor: help;" title="<?= h($penaltyTitle) ?>"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $this->Number->format($summary->total_chests) ?></td>
                                        <td style="color: <?= h($scoreCellColor) ?>; font-weight: 700;">
                                            <?= $this->Number->format($summary->total_score) ?>
                                        </td>
                                        <td style="color: <?= h($epicCellColor) ?>; font-weight: 700;">
                                            <?= $this->Number->format($summary->epic_crypt_score ?? 0) ?>
                                        </td>
                                        <td>
                                            <?= $summary->goal_achieved ? '<span class="badge badge-success">'.__('Yes').'</span>' : '<span class="badge badge-danger">'.__('No').'</span>' ?>
                                        </td>
                                        <td>
                                            <?php if (!$summary->fine_due): ?>
                                                <span class="badge badge-light"><?= __('N/A') ?></span>
                                            <?php elseif ($summary->fine_paid): ?>
                                                <span class="badge badge-success"><?= __('Paid') ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-warning"><?= __('Due') ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($isAdmin): ?>
                                        <td class="actions text-right">
                                            <?= $this->Html->link('<i class="fas fa-eye"></i> ' . __('View'), ['action' => 'view', $summary->id], ['class' => 'btn btn-xs btn-info', 'escape' => false]) ?>
                                            <?php if ($summary->fine_due && !$summary->fine_paid): ?>
                                                <?= $this->Form->postLink('<i class="fas fa-check-circle"></i> ' . __('Mark Paid'), 
                                                    ['action' => 'markFinePaid', $summary->id], 
                                                    ['confirm' => __('Are you sure you want to mark the fine as paid for {0}?', $summary->player_name), 'class' => 'btn btn-xs btn-success ml-1', 'escape' => false]
                                                ) ?>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
