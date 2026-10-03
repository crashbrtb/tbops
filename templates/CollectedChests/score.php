<?php
/**
 * @var \App\View\AppView $this
 * @var array $playerChestCounts
 * @var array $playerTotalChests
 * @var array $playerFinalScores
 * @var array $cycleOptions
 * @var string $selectedCycleOffset
 * @var int $minimumChestScore
 * @var int $minimumEpicChestScore
 * @var \Cake\I18n\FrozenTime|null $lastUpdate
 * @var array $currentCycleFormatted
 * @var string[] $sourcesWithNonZeroScore
 * @var array $chestScores
 * @var array $chestDisplayNames
 * @var array $scoreColorsConfig
 * @var array $epicMonsterDetails
 * @var bool $goalsByGuard Whether goals follow the guard level
 * @var array<int, array<string, int>> $guardGoalTable Guard level => target => goal (only with goals by guard level)
 * @var array<string, array<string, int>> $playerGoals Player => target => goal before any penalty
 * @var array<string, int> $playerGuardLevels Player => guard level (0 = unknown)
 */
?>
<?php
// Nome exibido para um bau: o alias cadastrado quando existir, senao o proprio source
$chestDisplayNames = $chestDisplayNames ?? [];
$chestName = function ($source) use ($chestDisplayNames) {
    $name = $chestDisplayNames[$source] ?? '';

    return $name !== '' ? (string)$name : (string)$source;
};

$sortColumn = $this->request->getQuery('sort', 'final_score');
$sortDirection = $this->request->getQuery('direction', 'desc');

$createSortLink = function ($column, $title) use ($sortColumn, $sortDirection) {
    $currentParams = $this->request->getQuery();
    $direction = ($sortColumn === $column && $sortDirection === 'asc') ? 'desc' : 'asc';
    $arrow = '';
    if ($sortColumn === $column) {
        $arrow = $sortDirection === 'asc' ? ' ▲' : ' ▼';
    }
    $url = $this->Url->build(['?' => array_merge($currentParams, ['sort' => $column, 'direction' => $direction])]);

    return '<a href="' . $url . '">' . h($title) . $arrow . '</a>';
};

// 1. Identify all monster sources
$monsterSources = [];
if (!empty($chestScores)) {
    foreach ($chestScores as $src => $chestObj) {
        if (!empty($chestObj->monster)) {
            $monsterSources[] = $src;
        }
    }
    usort($monsterSources, function ($a, $b) use ($chestScores, $chestName) {
        $scoreA = isset($chestScores[$a]) ? (int)$chestScores[$a]->score : 0;
        $scoreB = isset($chestScores[$b]) ? (int)$chestScores[$b]->score : 0;
        if ($scoreA === $scoreB) {
            return strcasecmp($chestName($a), $chestName($b));
        }
        return $scoreB <=> $scoreA;
    });
}

// 2. Process player data
$playersData = [];
$sourcesWithNonZeroScore = $sourcesWithNonZeroScore ?? [];
usort($sourcesWithNonZeroScore, function ($a, $b) use ($chestScores, $chestName) {
    $scoreA = isset($chestScores[$a]) ? (int)$chestScores[$a]->score : 0;
    $scoreB = isset($chestScores[$b]) ? (int)$chestScores[$b]->score : 0;
    if ($scoreA === $scoreB) {
        return strcasecmp($chestName($a), $chestName($b));
    }
    return $scoreB <=> $scoreA;
});

if (!empty($playerChestCounts)) {
    foreach (array_keys($playerChestCounts) as $player) {
        $counts = $playerChestCounts[$player] ?? [];
        $epicCryptScore = 0;
        $epicMonsterChestCount = 0;
        $monsterScore = 0;
        $playerMonsterCounts = [];

        foreach ($counts as $source => $count) {
            $cnt = (int)$count;
            if (stripos((string)$source, 'epic') !== false && isset($chestScores[$source])) {
                $epicCryptScore += (int)$chestScores[$source]->score * $cnt;
            }
            if (isset($chestScores[$source]) && !empty($chestScores[$source]->monster)) {
                $epicMonsterChestCount += $cnt;
                $monsterScore += (int)$chestScores[$source]->score * $cnt;
                $playerMonsterCounts[$source] = $cnt;
            }
        }

        $playerData = [
            'player' => $player,
            'total_chests' => $playerTotalChests[$player] ?? 0,
            'final_score' => $playerFinalScores[$player] ?? 0,
            'epic_crypt_score' => $epicCryptScore,
            'epic_monster_chest_count' => $epicMonsterChestCount,
            'monster_score' => $monsterScore,
            'monster_counts' => $playerMonsterCounts,
            'counts' => $counts,
        ];

        foreach ($sourcesWithNonZeroScore as $source) {
            $playerData[$source] = $counts[$source] ?? 0;
        }
        $playersData[] = $playerData;
    }
}

// 3. Score Summary sorting
$summaryPlayers = $playersData;
if (!empty($summaryPlayers)) {
    usort($summaryPlayers, function ($a, $b) use ($sortColumn, $sortDirection) {
        $valA = $a[$sortColumn] ?? 0;
        $valB = $b[$sortColumn] ?? 0;

        if ($sortColumn === 'player') {
            return $sortDirection === 'asc'
                ? strcasecmp((string)$valA, (string)$valB)
                : strcasecmp((string)$valB, (string)$valA);
        }

        if ($valA == $valB) {
            return 0;
        }

        return ($sortDirection === 'asc' ? $valA < $valB : $valA > $valB) ? -1 : 1;
    });
}

// 4. Monster Table sorting (by monster count DESC, then monster score DESC)
$monsterPlayers = $playersData;
usort($monsterPlayers, function ($a, $b) {
    if ($b['epic_monster_chest_count'] === $a['epic_monster_chest_count']) {
        return $b['monster_score'] <=> $a['monster_score'];
    }
    return $b['epic_monster_chest_count'] <=> $a['epic_monster_chest_count'];
});

// Filter active monster sources (at least 1 chest collected across players)
$activeMonsterSources = [];
foreach ($monsterSources as $mSrc) {
    foreach ($playersData as $p) {
        if (!empty($p['counts'][$mSrc])) {
            $activeMonsterSources[] = $mSrc;
            break;
        }
    }
}
if (empty($activeMonsterSources)) {
    $activeMonsterSources = $monsterSources;
}

// 5. Prepare monster date/time details for popup
$epicMonsterPopupData = [];
$epicMonsterDetails = $epicMonsterDetails ?? [];
foreach ($playersData as $p) {
    $pName = $p['player'];
    $details = $epicMonsterDetails[$pName] ?? [];
    $rows = [];
    foreach ($details as $src => $dateTimes) {
        $dateCounts = [];
        foreach ($dateTimes as $dt) {
            $dateOnly = substr($dt, 0, 10);
            $dateCounts[$dateOnly] = ($dateCounts[$dateOnly] ?? 0) + 1;
        }
        foreach ($dateCounts as $date => $count) {
            $rows[] = [
                'source' => $chestName($src),
                'date' => $date,
                'count' => $count,
            ];
        }
    }
    if (!empty($rows)) {
        $epicMonsterPopupData[$pName] = $rows;
    }
}

// 6. Prepare player full chest breakdown for popup
$playerAllDetailsData = [];
foreach ($playersData as $p) {
    $pName = $p['player'];
    $nonZeroChests = [];
    foreach ($sourcesWithNonZeroScore as $src) {
        $cnt = (int)($p['counts'][$src] ?? 0);
        if ($cnt > 0) {
            $singleScore = isset($chestScores[$src]) ? (int)$chestScores[$src]->score : 0;
            $isMonster = !empty($chestScores[$src]->monster);
            $nonZeroChests[] = [
                'source' => $chestName($src),
                'count' => $cnt,
                'score_each' => $singleScore,
                'total_points' => $cnt * $singleScore,
                'is_monster' => $isMonster,
            ];
        }
    }
    $playerAllDetailsData[$pName] = [
        'player' => $pName,
        'final_score' => (int)$p['final_score'],
        'total_chests' => (int)$p['total_chests'],
        'epic_crypt_score' => (int)$p['epic_crypt_score'],
        'monster_chests' => (int)$p['epic_monster_chest_count'],
        'chests' => $nonZeroChests,
    ];
}

// Dynamic score gradient calculation
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

// Goal penalty: players who missed the goal last cycle carry a raised goal in this one,
// and their score only turns green once it reaches that raised goal.
// $penaltyGoals: player => target ('total' / 'epic') => raised goal, only for the goals that were raised.
$goalPenaltySettings = $goalPenaltySettings ?? ['enabled' => false, 'mode' => 'total', 'percent' => 0];
$penaltyGoals = $penaltyGoals ?? [];
$penaltyMode = $goalPenaltySettings['mode'] ?? 'total';
$penaltyPercent = $this->Number->format((float)($goalPenaltySettings['percent'] ?? 0));
// $playerGoals: each player's goal before the penalty (by guard level when so configured)
$goalsByGuard = $goalsByGuard ?? false;
$guardGoalTable = $guardGoalTable ?? [];
$playerGoals = $playerGoals ?? [];
$playerGuardLevels = $playerGuardLevels ?? [];
$totalGoalFor = function (string $player) use ($penaltyGoals, $playerGoals, $minimumChestScore): int {
    return $penaltyGoals[$player]['total'] ?? $playerGoals[$player]['total'] ?? (int)$minimumChestScore;
};
$epicGoalFor = function (string $player) use ($penaltyGoals, $playerGoals, $minimumEpicChestScore): int {
    return $penaltyGoals[$player]['epic'] ?? $playerGoals[$player]['epic'] ?? (int)$minimumEpicChestScore;
};
$guardTooltip = function (string $player) use ($playerGuardLevels, $totalGoalFor, $epicGoalFor): string {
    $level = (int)($playerGuardLevels[$player] ?? 0);
    $goal = __('Goal: {0} chest points, {1} Epic chest points', $this->Number->format($totalGoalFor($player)), $this->Number->format($epicGoalFor($player)));

    return $level > 0
        ? __('Guards G{0}', $level) . ' — ' . $goal
        : __('Guard level unknown (G0): highest goal') . ' — ' . $goal;
};
$penaltyTooltip = function (string $player) use ($penaltyGoals, $penaltyPercent): string {
    $parts = [];
    if (isset($penaltyGoals[$player]['total'])) {
        $parts[] = __('{0} chest points', $this->Number->format($penaltyGoals[$player]['total']));
    }
    if (isset($penaltyGoals[$player]['epic'])) {
        $parts[] = __('{0} Epic chest points', $this->Number->format($penaltyGoals[$player]['epic']));
    }

    return __('Raised goal this cycle: {0} (+{1}%), goal missed last cycle', implode(' + ', $parts), $penaltyPercent);
};
$penaltyTooltipsData = [];
foreach (array_keys($penaltyGoals) as $penalizedPlayer) {
    $penaltyTooltipsData[$penalizedPlayer] = $penaltyTooltip((string)$penalizedPlayer);
}
// Player name linking to the history page, flagged when the player carries a raised goal
$playerNameLink = function (string $player) use ($penaltyGoals, $penaltyTooltip, $penaltyPercent, $goalsByGuard, $playerGuardLevels, $guardTooltip): string {
    $penalized = isset($penaltyGoals[$player]);
    $title = $penalized ? $player . ' — ' . $penaltyTooltip($player) : $player;
    $html = $this->Html->link(
        $player,
        ['controller' => 'PlayerCycleSummaries', 'action' => 'playerHistory', urlencode($player)],
        ['class' => 'player-link' . ($penalized ? ' penalized' : ''), 'title' => $title]
    );
    $badges = '';
    if ($goalsByGuard) {
        $level = (int)($playerGuardLevels[$player] ?? 0);
        $badges .= '<span class="guard-badge' . ($level === 0 ? ' unknown' : '') . '" title="' . h($guardTooltip($player)) . '">'
            . 'G' . $level . '</span>';
    }
    if ($penalized) {
        $badges .= '<span class="penalty-badge" title="' . h($penaltyTooltip($player)) . '">'
            . '<i class="fas fa-arrow-up"></i> ' . h($penaltyPercent) . '%</span>';
    }
    if ($badges !== '') {
        $html = '<span class="player-name-line">' . $html . $badges . '</span>';
    }

    return $html;
};
?>

<style>
    /* No token declarations here. This element sits below <html data-theme>,
       so redeclaring --bg and friends on it would override whichever theme is
       active, for the whole page. The tokens come from theme-score.css and are
       overridden only by themes.css. */
    .score-new-page {
        background: var(--bg);
        padding: 20px;
        border-radius: 16px;
    }

    /* Toolbar & Headers */
    .score-toolbar {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        margin-bottom: 20px;
    }

    .score-title-group {
        display: flex;
        flex-direction: column;
    }

    .score-title {
        margin: 0;
        color: var(--text);
        font-weight: 800;
        font-size: 1.75rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .cycle-subtitle {
        color: var(--muted);
        margin: 4px 0 0;
        font-size: 0.95rem;
    }

    .toolbar-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px;
    }

    .filter-form {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .filter-form select {
        padding: 7px 12px;
        border-radius: 8px;
        border: 1px solid var(--line);
        background: var(--input-bg);
        font-size: 0.9rem;
        color: var(--text);
        font-weight: 500;
    }

    .filter-form button {
        padding: 7px 16px;
        border-radius: 8px;
        background: var(--input-bg);
        border: 1px solid var(--line);
        color: var(--text);
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.2s;
    }

    .filter-form button:hover {
        background: var(--surface-sunken);
    }

    /* Action Toggle: View All */
    .btn-view-all-toggle {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
        color: #fff !important;
        border: none;
        border-radius: 10px;
        padding: 8px 18px;
        font-weight: 600;
        font-size: 0.92rem;
        cursor: pointer;
        box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);
        transition: all 0.2s ease;
        text-decoration: none !important;
    }

    .btn-view-all-toggle:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);
        background: linear-gradient(135deg, #4338ca 0%, #2563eb 100%);
    }

    .btn-view-all-toggle.active {
        background: var(--text-dark);
        box-shadow: 0 4px 14px rgba(31, 41, 55, 0.3);
    }

    /* Goals Pill */
    .goals-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: center;
        margin-bottom: 22px;
    }

    .goal-pill {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: var(--surface);
        border: 1px solid var(--accent-border);
        color: var(--accent-dark);
        border-radius: 999px;
        padding: 0 16px;
        height: 38px;
        box-sizing: border-box;
        line-height: 1;
        font-weight: 600;
        font-size: 0.9rem;
        box-shadow: 0 2px 8px rgba(99, 102, 241, 0.08);
        margin: 0;
    }

    .goals-bar .event-badge {
        margin: 0;
        height: 38px;
        display: inline-flex;
        align-items: center;
    }

    .goal-pill i {
        color: var(--accent);
    }

    .goal-pill.epic {
        border-color: var(--epic-border);
        color: var(--epic-text);
        box-shadow: 0 2px 8px rgba(244, 63, 94, 0.08);
    }

    .goal-pill.epic i {
        color: var(--epic);
    }

    /* Tables Container */
    .tables-container {
        display: flex;
        flex-direction: column;
        gap: 24px;
    }

    .score-card {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(17, 24, 39, 0.05);
        overflow: hidden;
        transition: box-shadow 0.2s;
    }

    .score-card-header {
        padding: 16px 22px;
        border-bottom: 1px solid var(--line);
        background: var(--surface-raised);
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }

    .score-card-title {
        margin: 0;
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .table-search-box {
        position: relative;
        min-width: 220px;
    }

    .table-search-box input {
        width: 100%;
        padding: 6px 12px 6px 32px;
        border-radius: 8px;
        border: 1px solid var(--line);
        font-size: 0.88rem;
        outline: none;
        transition: border-color 0.2s;
    }

    .table-search-box input:focus {
        border-color: var(--accent);
    }

    .table-search-box i {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-faint);
        font-size: 0.85rem;
    }

    /* Tables */
    .table-wrap {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        position: relative;
    }

    .custom-score-table {
        width: 100%;
        border-collapse: collapse;
    }

    .custom-score-table th,
    .custom-score-table td {
        padding: 12px 14px;
        border-bottom: 1px solid var(--line-subtle);
        text-align: center;
        white-space: nowrap;
        font-size: 0.94rem;
    }

    .custom-score-table th {
        background: var(--surface-sunken);
        color: var(--text-soft);
        font-size: 0.88rem;
        font-weight: 700;
        position: sticky;
        top: 0;
        z-index: 2;
        border-bottom: 2px solid var(--line);
    }

    .custom-score-table th a {
        color: inherit;
        text-decoration: none;
    }

    .custom-score-table th a:hover {
        color: var(--accent);
    }

    .custom-score-table tbody tr:hover {
        background: var(--surface-hover);
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
    }

    .top-rank.rank-1 { background: #fef3c7; color: #b45309; }
    .top-rank.rank-2 { background: #e5e7eb; color: #374151; }
    .top-rank.rank-3 { background: #ffedd5; color: #c2410c; }

    /* Player cell & link with truncation support */
    .player-cell-box {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        min-width: 0;
        max-width: 100%;
    }

    .player-link {
        color: var(--link);
        text-decoration: none;
        font-weight: 600;
        transition: color 0.15s;
        display: inline-block;
        max-width: 100%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        vertical-align: middle;
    }

    .player-link:hover {
        color: var(--link);
        text-decoration: underline;
    }

    /* Goal penalty: player carrying a raised goal this cycle */
    .player-name-line {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        max-width: 100%;
        min-width: 0;
    }

    .player-link.penalized,
    .player-link.penalized:hover {
        color: #d97706;
    }

    .penalty-badge {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        gap: 3px;
        padding: 1px 7px;
        border-radius: 999px;
        background: rgba(245, 158, 11, 0.15);
        border: 1px solid rgba(217, 119, 6, 0.45);
        color: #d97706;
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1.4;
        cursor: help;
    }

    tr.penalized-row td:first-child {
        box-shadow: inset 3px 0 0 #f59e0b;
    }

    /* Goals by guard level: the player's guard level next to their name */
    .guard-badge {
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        padding: 1px 6px;
        border-radius: 6px;
        background: var(--surface-sunken);
        border: 1px solid var(--accent-border);
        color: var(--accent-dark);
        font-size: 0.7rem;
        font-weight: 700;
        line-height: 1.4;
        cursor: help;
    }

    .guard-badge.unknown {
        border-style: dashed;
        color: var(--text-soft);
    }

    .goal-pill.guard {
        cursor: help;
    }

    .goal-pill.penalty {
        border-color: rgba(217, 119, 6, 0.45);
        color: #d97706;
    }

    .goal-pill.penalty i {
        color: #f59e0b;
    }

    /* Monster hunted chips under player name */
    .player-monster-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        margin-top: 4px;
    }

    .m-chip {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        padding: 2px 7px;
        border-radius: 6px;
        background: var(--surface-sunken);
        color: var(--text-soft);
        font-size: 0.72rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.15s;
        white-space: nowrap;
        text-decoration: none;
        border: 1px solid var(--line);
    }

    .m-chip i {
        color: var(--danger);
        font-size: 0.68rem;
    }

    .m-chip strong {
        color: var(--danger-dark);
        font-weight: 700;
    }

    .m-chip:hover {
        background: var(--danger-light);
        border-color: var(--danger-border);
        color: var(--danger-dark);
        transform: translateY(-1px);
    }

    /* Monster Table Switcher */
    .monster-view-switcher {
        display: inline-flex;
        align-items: center;
        background: var(--surface-sunken);
        padding: 3px;
        border-radius: 8px;
        gap: 3px;
    }

    .btn-m-switch {
        border: none;
        background: transparent;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--muted);
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all 0.15s;
    }

    .btn-m-switch.active {
        background: var(--surface);
        color: var(--text-dark);
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
    }

    /* Scroll hint */
    .mobile-scroll-hint {
        display: none;
        padding: 6px 12px;
        background: var(--surface-sunken);
        border-bottom: 1px solid var(--line);
        color: var(--muted);
        font-size: 0.76rem;
        text-align: center;
    }

    /* Interactive Data Links for Details */
    .interactive-score-link {
        cursor: pointer;
        font-weight: 800;
        font-size: 1.02rem;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        border-radius: 6px;
        transition: background 0.15s, transform 0.15s;
    }

    .interactive-score-link:hover {
        background: rgba(79, 70, 229, 0.08);
        transform: translateY(-1px);
        text-decoration: underline;
    }

    .interactive-monster-link {
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 999px;
        background: var(--danger-light);
        color: var(--danger-dark) !important;
        font-weight: 700;
        font-size: 0.88rem;
        text-decoration: none;
        transition: background 0.15s, transform 0.15s;
    }

    .interactive-monster-link:hover {
        background: var(--danger-border);
        transform: translateY(-1px);
        text-decoration: underline;
    }

    /* Detailed View Section */
    #detailedViewSection {
        display: none;
    }

    #detailedViewSection.show {
        display: block;
        animation: fadeIn 0.25s ease-in-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Modals */
    .em-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(4px);
        z-index: 9998;
        animation: emFadeIn 0.2s ease;
    }

    .em-modal {
        display: none;
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: var(--card);
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(17, 24, 39, 0.2);
        z-index: 9999;
        width: 90%;
        max-width: 600px;
        max-height: 85vh;
        overflow: hidden;
        animation: emSlideIn 0.25s ease;
    }

    .em-modal-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 20px;
        border-bottom: 1px solid var(--line);
        background: var(--surface-sunken);
    }

    .em-modal-head h3 {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--text-dark);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .em-modal-close {
        background: none;
        border: none;
        font-size: 1.4rem;
        color: var(--muted);
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 8px;
        transition: background 0.15s, color 0.15s;
    }

    .em-modal-close:hover {
        background: var(--danger-light);
        color: var(--danger-dark);
    }

    .em-modal-body {
        padding: 18px;
        overflow-y: auto;
        max-height: calc(85vh - 65px);
    }

    .em-detail-table {
        width: 100%;
        border-collapse: collapse;
    }

    .em-detail-table th {
        background: var(--accent-light);
        color: var(--accent-dark);
        font-weight: 700;
        font-size: 0.86rem;
        padding: 8px 10px;
        text-align: left;
        border-bottom: 2px solid var(--accent-border);
    }

    .em-detail-table th:last-child {
        text-align: center;
    }

    .em-detail-table td {
        padding: 8px 10px;
        font-size: 0.86rem;
        color: var(--text-soft);
        border-bottom: 1px solid var(--line-subtle);
    }

    .em-detail-table td:last-child {
        text-align: center;
        font-weight: 700;
        color: var(--accent);
    }

    .em-detail-table tbody tr:hover {
        background: var(--surface-hover);
    }

    .em-no-data {
        color: var(--text-faint);
        font-style: italic;
        text-align: center;
        padding: 28px;
    }

    /* Footer Notes */
    .footer-note {
        margin-top: 20px;
        text-align: center;
        color: var(--muted);
        font-size: 0.88rem;
    }

    @keyframes emFadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes emSlideIn {
        from { opacity: 0; transform: translate(-50%, -48%); }
        to { opacity: 1; transform: translate(-50%, -50%); }
    }

    /* Default Desktop column rules for tables */
    @media (min-width: 769px) {
        #monsterTable {
            min-width: 600px;
        }
        #monsterTable .monster-source-col {
            display: table-cell !important;
        }
        .player-monster-chips {
            display: none !important;
        }
        .monster-view-switcher {
            display: none !important;
        }
        .mobile-scroll-hint {
            display: none !important;
        }
    }

    /* Mobile and Tablet Responsive Design */
    @media (max-width: 768px) {
        .score-new-page {
            padding: 10px 8px;
            border-radius: 12px;
        }

        .score-toolbar {
            flex-direction: column;
            align-items: stretch;
            gap: 12px;
            margin-bottom: 14px;
        }

        .score-title {
            font-size: 1.32rem;
            gap: 8px;
        }

        .cycle-subtitle {
            font-size: 0.82rem;
        }

        .toolbar-actions {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .btn-view-all-toggle {
            width: 100%;
            justify-content: center;
            padding: 8px 12px;
            font-size: 0.86rem;
        }

        .filter-form form {
            width: 100%;
            display: flex;
            gap: 6px;
        }

        .filter-form select {
            flex: 1;
            min-width: 0;
            font-size: 0.82rem;
            padding: 6px 8px;
        }

        .filter-form button {
            padding: 6px 14px;
            font-size: 0.82rem;
            white-space: nowrap;
        }

        .goals-bar {
            gap: 8px;
            margin-bottom: 14px;
        }

        .goal-pill {
            flex: 1 1 calc(50% - 4px);
            justify-content: center;
            padding: 0 10px;
            font-size: 0.78rem;
            white-space: nowrap;
            height: 31px;
            margin: 0;
        }

        .goals-bar .event-badge {
            margin: 0;
            height: 31px;
        }

        .score-card {
            border-radius: 12px;
        }

        .score-card-header {
            padding: 12px 14px;
            flex-direction: column;
            align-items: stretch;
            gap: 10px;
        }

        .score-card-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            gap: 8px;
        }

        .score-card-title {
            font-size: 1.05rem;
        }

        .table-search-box {
            width: 100%;
            min-width: 100%;
        }

        .table-search-box input {
            font-size: 0.84rem;
            padding: 7px 10px 7px 30px;
        }

        /* 1. SCORE SUMMARY TABLE: ZERO HORIZONTAL SCROLL */
        #summaryTable {
            min-width: 0 !important;
            width: 100% !important;
            table-layout: fixed;
        }

        #summaryTable th, 
        #summaryTable td {
            padding: 8px 4px;
            font-size: 0.84rem;
        }

        #summaryTable .col-pos,
        #summaryTable th:nth-child(1),
        #summaryTable td:nth-child(1) {
            width: 36px !important;
            max-width: 36px !important;
            padding-left: 4px;
            padding-right: 2px;
            text-align: center;
        }

        .top-rank {
            min-width: 22px;
            width: 22px;
            height: 22px;
            font-size: 0.74rem;
        }

        #summaryTable .col-player,
        #summaryTable th:nth-child(2),
        #summaryTable td:nth-child(2) {
            width: auto !important;
            overflow: hidden;
            padding-left: 4px;
            padding-right: 4px;
            text-align: left;
        }

        #summaryTable td:nth-child(2) .player-link {
            width: 100%;
            font-size: 0.85rem;
        }

        #summaryTable .col-score,
        #summaryTable th:nth-child(3),
        #summaryTable td:nth-child(3) {
            width: 80px !important;
            max-width: 80px !important;
            padding-left: 2px;
            padding-right: 4px;
            text-align: right;
        }

        .interactive-score-link {
            font-size: 0.86rem;
            padding: 2px 2px;
            gap: 2px;
            display: inline-flex;
            justify-content: flex-end;
            width: 100%;
        }

        .interactive-score-link i {
            display: none;
        }

        #summaryTable .col-monsters,
        #summaryTable th:nth-child(4),
        #summaryTable td:nth-child(4) {
            width: 62px !important;
            max-width: 62px !important;
            padding-left: 2px;
            padding-right: 4px;
            text-align: center;
        }

        .interactive-monster-link {
            font-size: 0.76rem;
            padding: 2px 5px;
            gap: 3px;
        }

        /* 2. MONSTER TABLE COMPACT MODE: ZERO HORIZONTAL SCROLL */
        #monsterTable.compact-mode {
            min-width: 0 !important;
            width: 100% !important;
            table-layout: fixed;
        }

        #monsterTable.compact-mode .monster-source-col {
            display: none !important;
        }

        #monsterTable.compact-mode th,
        #monsterTable.compact-mode td {
            padding: 8px 4px;
            font-size: 0.84rem;
        }

        #monsterTable.compact-mode th:nth-child(1),
        #monsterTable.compact-mode td:nth-child(1) {
            width: 36px !important;
            max-width: 36px !important;
            padding-left: 4px;
            padding-right: 2px;
            text-align: center;
        }

        #monsterTable.compact-mode th:nth-child(2),
        #monsterTable.compact-mode td:nth-child(2) {
            width: auto !important;
            overflow: hidden;
            padding-left: 4px;
            padding-right: 4px;
            text-align: left;
        }

        #monsterTable.compact-mode td:nth-child(2) .player-link {
            width: 100%;
            font-size: 0.85rem;
        }

        #monsterTable.compact-mode th:nth-child(3),
        #monsterTable.compact-mode td:nth-child(3) {
            width: 58px !important;
            max-width: 58px !important;
            padding-left: 2px;
            padding-right: 2px;
            text-align: center;
        }

        #monsterTable.compact-mode th:nth-child(4),
        #monsterTable.compact-mode td:nth-child(4) {
            width: 64px !important;
            max-width: 64px !important;
            padding-left: 2px;
            padding-right: 4px;
            text-align: right;
            font-size: 0.84rem;
        }

        /* Monster Table in Full Matrix Mode on mobile */
        #monsterTable.full-mode {
            min-width: 650px;
        }

        #monsterTable.full-mode .player-monster-chips {
            display: none !important;
        }

        .mobile-scroll-hint {
            display: block;
        }

        /* 3. DETAILED FULL DATA TABLE (STICKY COLUMNS) */
        #detailedTable {
            min-width: 700px;
        }

        #detailedTable th:nth-child(1),
        #detailedTable td:nth-child(1) {
            position: sticky;
            left: 0;
            z-index: 3;
            background: var(--surface-sunken);
        }
        #detailedTable tbody td:nth-child(1) {
            background: var(--card);
        }

        #detailedTable th:nth-child(2),
        #detailedTable td:nth-child(2) {
            position: sticky;
            left: 42px;
            z-index: 3;
            background: var(--surface-sunken);
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.06);
        }
        #detailedTable tbody td:nth-child(2) {
            background: var(--card);
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.06);
        }

        /* Mobile Modal */
        .em-modal {
            width: 95%;
            max-height: 90vh;
            border-radius: 12px;
        }
        .em-modal-head {
            padding: 12px 14px;
        }
        .em-modal-body {
            padding: 14px 10px;
        }
    }

    @media (max-width: 380px) {
        #summaryTable .col-score,
        #summaryTable th:nth-child(3),
        #summaryTable td:nth-child(3) {
            width: 72px !important;
            max-width: 72px !important;
        }
        .interactive-score-link {
            font-size: 0.82rem;
        }
        #summaryTable .col-monsters,
        #summaryTable th:nth-child(4),
        #summaryTable td:nth-child(4) {
            width: 56px !important;
            max-width: 56px !important;
        }
        .interactive-monster-link {
            font-size: 0.74rem;
            padding: 2px 4px;
        }
        #monsterTable.compact-mode th:nth-child(4),
        #monsterTable.compact-mode td:nth-child(4) {
            width: 58px !important;
            max-width: 58px !important;
        }
    }
</style>

<div class="score-new-page">
    <!-- Header / Toolbar -->
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-chart-line text-primary"></i> <?= __('Players Score & Monsters') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= h($cycleOptions[$selectedCycleOffset] ?? __('Current Cycle')) ?>
            </p>
        </div>

        <div class="toolbar-actions">
            <!-- View All Button (Detailed Data) -->
            <button type="button" class="btn-view-all-toggle" id="btnToggleDetailed" onclick="toggleDetailedView()" title="<?= __('Toggle between clean view and full detailed data') ?>">
                <i class="fas fa-table" id="toggleIcon"></i>
                <span id="toggleText"><?= __('View All (Detailed Data)') ?></span>
            </button>

            <!-- Cycle Filter Form -->
            <div class="filter-form">
                <?= $this->Form->create(null, ['type' => 'get']) ?>
                <?= $this->Form->select('cycle', $cycleOptions, ['default' => $selectedCycleOffset]) ?>
                <?= $this->Form->button('<i class="fas fa-filter mr-1"></i> ' . __('Filter'), ['escapeTitle' => false]) ?>
                <?= $this->Form->end() ?>
            </div>
        </div>
    </div>

    <!-- Goals Bar (Without Total Players) -->
    <div class="goals-bar">
        <?php if ($goalsByGuard): ?>
            <?php
            $guardGoalLines = [];
            foreach ($guardGoalTable as $level => $goals) {
                if ($level === 0) {
                    continue;
                }
                $guardGoalLines[] = 'G' . $level . ': ' . $this->Number->format($goals['total']) . ' / ' . $this->Number->format($goals['epic']);
            }
            $guardGoalLines[] = __('G0 (unknown): highest goal');
            ?>
            <div class="goal-pill guard" title="<?= h(__('Chest Score Goal / Epic Chest Goal') . "\n" . implode("\n", $guardGoalLines)) ?>">
                <i class="fas fa-shield-alt"></i>
                <span><?= __('Goals by guard level') ?></span>
            </div>
        <?php else: ?>
            <div class="goal-pill">
                <i class="fas fa-bullseye"></i>
                <span><?= __('Chest Score Goal: {0} points', $this->Number->format($minimumChestScore ?? 0)) ?></span>
            </div>
            <div class="goal-pill epic">
                <i class="fas fa-gem"></i>
                <span><?= __('Epic Chest Goal: {0} points', $this->Number->format($minimumEpicChestScore ?? 0)) ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($goalPenaltySettings['enabled'])): ?>
            <div class="goal-pill penalty" title="<?= h(__('Players who missed the goal in the previous cycle have it raised by {0}% in this cycle (rounded up).', $penaltyPercent)) ?>">
                <i class="fas fa-arrow-up"></i>
                <span>
                    <?php if ($penaltyMode === 'both'): ?>
                        <?= __('Goal penalty: +{0}% on the Chest Score Goal and the Epic Chest Goal', $penaltyPercent) ?>
                    <?php elseif ($penaltyMode === 'epic'): ?>
                        <?= __('Goal penalty: +{0}% on the Epic Chest Goal', $penaltyPercent) ?>
                    <?php else: ?>
                        <?= __('Goal penalty: +{0}% on the Chest Score Goal', $penaltyPercent) ?>
                    <?php endif; ?>
                    <?php if (!empty($penaltyGoals)): ?>
                        · <?= __('Players with a raised goal: {0}', count($penaltyGoals)) ?>
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
        <?= $this->element('event_banner') ?>
    </div>

    <!-- SECTION 1: TWO SEPARATE TABLES (SCORE SUMMARY & MONSTER CHESTS) -->
    <div class="tables-container" id="splitViewSection">
        
        <!-- TABLE 1: SCORE SUMMARY (Columns in order: Pos, Player, Final Score, Monster Chests) -->
        <section class="score-card">
            <div class="score-card-header">
                <div class="score-card-header-top">
                    <div class="score-card-title">
                        <i class="fas fa-award text-warning"></i>
                        <span><?= __('Score Summary') ?></span>
                    </div>
                </div>
                <div class="table-search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchSummaryTable" placeholder="<?= __('Search player...') ?>" onkeyup="filterTable('summaryTable', this.value)">
                </div>
            </div>

            <?php if (!empty($summaryPlayers)): ?>
                <div class="table-wrap">
                    <table class="custom-score-table" id="summaryTable">
                        <thead>
                            <tr>
                                <th class="col-pos" style="width: 70px;"><?= __('Pos.') ?></th>
                                <th class="col-player" style="text-align: left;"><?= $createSortLink('player', __('Player')) ?></th>
                                <th class="col-score"><?= $createSortLink('final_score', __('Final Score')) ?></th>
                                <th class="col-monsters"><?= $createSortLink('epic_monster_chest_count', __('Monster Chests')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summaryPlayers as $index => $p): ?>
                                <?php
                                $finalScore = (int)$p['final_score'];
                                $scoreCellColor = $scoreColor($finalScore, $totalGoalFor((string)$p['player']));
                                $monsterCount = (int)$p['epic_monster_chest_count'];
                                ?>
                                <tr<?= isset($penaltyGoals[$p['player']]) ? ' class="penalized-row"' : '' ?>>
                                    <td class="col-pos">
                                        <span class="top-rank <?= $index === 0 ? 'rank-1' : ($index === 1 ? 'rank-2' : ($index === 2 ? 'rank-3' : '')) ?>">
                                            <?= $index + 1 ?>
                                        </span>
                                    </td>
                                    <td class="col-player" style="text-align: left;">
                                        <div class="player-cell-box">
                                            <?= $playerNameLink((string)$p['player']) ?>
                                        </div>
                                    </td>
                                    <!-- Interactive Final Score Link -> opens detailed score popup -->
                                    <td class="col-score">
                                        <a href="#" class="interactive-score-link" style="color: <?= h($scoreCellColor) ?>;" onclick="showPlayerSummaryModal('<?= h(addslashes($p['player'])) ?>'); return false;" title="<?= __('Click to view detailed chest breakdown for {0}', h($p['player'])) ?>">
                                            <?= $this->Number->format($finalScore) ?>
                                            <i class="fas fa-external-link-alt ml-1" style="font-size: 0.72rem; opacity: 0.6;"></i>
                                        </a>
                                    </td>
                                    <!-- Interactive Monster Chests Link -> opens monster dates/history popup -->
                                    <td class="col-monsters">
                                        <?php if ($monsterCount > 0): ?>
                                            <a href="#" class="interactive-monster-link" onclick="showEpicMonsterDetails('<?= h(addslashes($p['player'])) ?>'); return false;" title="<?= __('Click to view monster chest dates and details for {0}', h($p['player'])) ?>">
                                                <i class="fas fa-dragon mr-1"></i><?= $this->Number->format($monsterCount) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted" style="color: #9ca3af !important;">0</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-4 text-center text-muted"><?= __('No chests collected in this cycle.') ?></div>
            <?php endif; ?>
        </section>

    </div>

    <!-- SECTION 2: FULL DETAILED DATA (VIEW ALL) -->
    <div id="detailedViewSection">
        <div class="tables-container">

            <!-- TABLE 2: MONSTER CHESTS -->
            <section class="score-card">
                <div class="score-card-header">
                    <div class="score-card-header-top">
                        <div class="score-card-title">
                            <i class="fas fa-dragon text-danger"></i>
                            <span><?= __('Monster Chests') ?></span>
                        </div>
                        <div class="monster-view-switcher">
                            <button type="button" class="btn-m-switch active" id="btnMonsterCompact" onclick="setMonsterView('compact')" title="<?= __('Compact View (Fit to screen)') ?>">
                                <i class="fas fa-list"></i> <?= __('Summary') ?>
                            </button>
                            <button type="button" class="btn-m-switch" id="btnMonsterFull" onclick="setMonsterView('full')" title="<?= __('Full matrix with all monster columns') ?>">
                                <i class="fas fa-table"></i> <?= __('Matrix') ?>
                            </button>
                        </div>
                    </div>
                    <div class="table-search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchMonsterTable" placeholder="<?= __('Search monster or player...') ?>" onkeyup="filterTable('monsterTable', this.value)">
                    </div>
                </div>

                <?php if (!empty($monsterPlayers)): ?>
                    <div class="table-wrap">
                        <table class="custom-score-table compact-mode" id="monsterTable">
                            <thead>
                                <tr>
                                    <th style="width: 70px;" class="col-pos"><?= __('Pos.') ?></th>
                                    <th style="text-align: left;" class="col-player"><?= __('Player') ?></th>
                                    <th class="col-monsters"><?= __('Monster Chests') ?></th>
                                    <th class="col-points" style="text-align: right;"><?= __('Monster Points') ?></th>
                                    <?php foreach ($activeMonsterSources as $mSource): ?>
                                        <th class="monster-source-col" title="<?= h($chestName($mSource)) ?>">
                                            <?= h($chestName($mSource)) ?>
                                            <?php if (isset($chestScores[$mSource])): ?>
                                                <small class="d-block text-muted" style="font-size: 0.72rem; font-weight: normal;">(<?= (int)$chestScores[$mSource]->score ?> pts)</small>
                                            <?php endif; ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $mPos = 1; ?>
                                <?php foreach ($monsterPlayers as $p): ?>
                                    <?php $mCount = (int)$p['epic_monster_chest_count']; ?>
                                    <tr<?= isset($penaltyGoals[$p['player']]) ? ' class="penalized-row"' : '' ?>>
                                        <td class="col-pos">
                                            <span class="top-rank <?= $mPos === 1 ? 'rank-1' : ($mPos === 2 ? 'rank-2' : ($mPos === 3 ? 'rank-3' : '')) ?>">
                                                <?= $mPos++ ?>
                                            </span>
                                        </td>
                                        <td class="col-player" style="text-align: left;">
                                            <div class="player-cell-box">
                                                <?= $playerNameLink((string)$p['player']) ?>
                                                <?php
                                                $playerHunted = [];
                                                foreach ($activeMonsterSources as $mSource) {
                                                    $smc = (int)($p['counts'][$mSource] ?? 0);
                                                    if ($smc > 0) {
                                                        $playerHunted[] = ['name' => $chestName($mSource), 'count' => $smc];
                                                    }
                                                }
                                                ?>
                                                <?php if (!empty($playerHunted)): ?>
                                                    <div class="player-monster-chips">
                                                        <?php foreach ($playerHunted as $ph): ?>
                                                            <span class="m-chip" onclick="showEpicMonsterDetails('<?= h(addslashes($p['player'])) ?>'); event.stopPropagation();" title="<?= h($ph['name']) ?>: <?= $ph['count'] ?> chests">
                                                                <i class="fas fa-paw"></i> <?= h($ph['name']) ?>: <strong><?= $ph['count'] ?></strong>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <!-- Interactive Monster Chest Link -->
                                        <td class="col-monsters">
                                            <?php if ($mCount > 0): ?>
                                                <a href="#" class="interactive-monster-link" onclick="showEpicMonsterDetails('<?= h(addslashes($p['player'])) ?>'); return false;" title="<?= __('Click to view dates and details for {0}', h($p['player'])) ?>">
                                                    <i class="fas fa-dragon mr-1"></i><?= $this->Number->format($mCount) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted" style="color: #9ca3af !important;">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-points" style="font-weight: 700; color: #dc2626; text-align: right;">
                                            <?= $this->Number->format($p['monster_score']) ?>
                                        </td>
                                        <?php foreach ($activeMonsterSources as $mSource): ?>
                                            <?php $singleMCount = (int)($p['counts'][$mSource] ?? 0); ?>
                                            <td class="monster-source-col">
                                                <?php if ($singleMCount > 0): ?>
                                                    <a href="#" style="font-weight: 700; color: #1f2937; text-decoration: none;" onclick="showEpicMonsterDetails('<?= h(addslashes($p['player'])) ?>'); return false;" title="<?= __('View details') ?>">
                                                        <?= $this->Number->format($singleMCount) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted" style="color: #cbd5e1 !important;">-</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="p-4 text-center text-muted"><?= __('No monster data available for this cycle.') ?></div>
                <?php endif; ?>
            </section>

            <!-- TABLE 3: ALL DETAILED DATA BY CHEST SOURCE -->
            <section class="score-card">
                <div class="score-card-header">
                    <div class="score-card-header-top">
                        <div class="score-card-title">
                            <i class="fas fa-th-list text-indigo"></i>
                            <span><?= __('All Detailed Data by Chest Source') ?></span>
                        </div>
                    </div>
                    <div class="table-search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchDetailedTable" placeholder="<?= __('Filter player...') ?>" onkeyup="filterTable('detailedTable', this.value)">
                    </div>
                </div>

                <div class="mobile-scroll-hint">
                    <i class="fas fa-arrows-alt-h mr-1"></i> <?= __('Scroll horizontally to view all chest columns') ?>
                </div>

                <?php if (!empty($playersData)): ?>
                    <div class="table-wrap">
                        <table class="custom-score-table" id="detailedTable">
                            <thead>
                                <tr>
                                    <th style="width: 60px;"><?= __('Pos.') ?></th>
                                    <th style="text-align: left;"><?= $createSortLink('player', __('Player')) ?></th>
                                    <th><?= $createSortLink('final_score', __('Final Score')) ?></th>
                                    <th><?= $createSortLink('total_chests', __('Total Chests')) ?></th>
                                    <th><?= $createSortLink('epic_crypt_score', __('Epic Crypt Score')) ?></th>
                                    <?php foreach ($sourcesWithNonZeroScore as $source): ?>
                                        <th><?= $createSortLink($source, $chestName($source)) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($playersData as $index => $playerData): ?>
                                    <?php
                                    $score = $playerData['final_score'];
                                    $scoreCellColor = $scoreColor($score, $totalGoalFor((string)$playerData['player']));
                                    $epicScore = $playerData['epic_crypt_score'];
                                    $epicCellColor = $scoreColor($epicScore, $epicGoalFor((string)$playerData['player']));
                                    ?>
                                    <tr<?= isset($penaltyGoals[$playerData['player']]) ? ' class="penalized-row"' : '' ?>>
                                        <td><span class="top-rank"><?= $index + 1 ?></span></td>
                                        <td style="text-align: left;">
                                            <div class="player-cell-box">
                                                <?= $playerNameLink((string)$playerData['player']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="#" class="interactive-score-link" style="color: <?= h($scoreCellColor) ?>;" onclick="showPlayerSummaryModal('<?= h(addslashes($playerData['player'])) ?>'); return false;">
                                                <?= (int)$score ?>
                                            </a>
                                        </td>
                                        <td><?= (int)$playerData['total_chests'] ?></td>
                                        <td style="color: <?= h($epicCellColor) ?>; font-weight: 700;"><?= (int)$epicScore ?></td>
                                        <?php foreach ($sourcesWithNonZeroScore as $source): ?>
                                            <td><?= (int)($playerData['counts'][$source] ?? 0) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="p-4 text-center text-muted"><?= __('No detailed data available for this cycle.') ?></div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <!-- Footer Notes -->
    <div class="footer-note">
        <?php
        $now = \Cake\I18n\FrozenTime::now();
        $startOfCurrentCycle = \Cake\I18n\FrozenTime::parse($currentCycleFormatted['start'], 'UTC')->setTimezone('America/Sao_Paulo');
        $endOfCurrentCycle = \Cake\I18n\FrozenTime::parse($currentCycleFormatted['end'], 'UTC')->setTimezone('America/Sao_Paulo');

        if ($now >= $startOfCurrentCycle && $now <= $endOfCurrentCycle) {
            $diff = $now->diff($endOfCurrentCycle);
            echo __('Time remaining until cycle ends: ');
            if ($diff->days > 0) {
                echo __("{0} day{1}, ", $diff->days, $diff->days > 1 ? 's' : '');
            }
            echo __("{0} hour{1}, ", $diff->h, $diff->h > 1 ? 's' : '');
            echo __("{0} minute{1}, ", $diff->i, $diff->i > 1 ? 's' : '');
            echo __("{0} second{1}", $diff->s, $diff->s > 1 ? 's' : '');
        } else {
            echo __('The Current Cycle has already ended.');
        }
        ?>
    </div>

    <?php if (isset($lastUpdate)): ?>
        <div class="footer-note">
            <?= __('Last chest update: {0} UTC', $lastUpdate->collected_at->i18nFormat('dd/MM/yyyy HH:mm:ss')) ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal for Monster Details & Player Chest Breakdown -->
<div class="em-modal-overlay" id="appOverlay" onclick="closeModal()"></div>
<div class="em-modal" id="appModal">
    <div class="em-modal-head">
        <h3 id="appModalTitle"></h3>
        <button class="em-modal-close" onclick="closeModal()" title="<?= __('Close') ?>">&times;</button>
    </div>
    <div class="em-modal-body" id="appModalBody"></div>
</div>

<script>
// Data serialized for client interactivity
var epicMonsterData = <?= json_encode($epicMonsterPopupData, JSON_UNESCAPED_UNICODE) ?>;
var playerAllDetails = <?= json_encode($playerAllDetailsData, JSON_UNESCAPED_UNICODE) ?>;
var penaltyTooltips = <?= json_encode((object)$penaltyTooltipsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

var currentMode = 'split';

function switchView(mode) {
    var splitSec = document.getElementById('splitViewSection');
    var detailSec = document.getElementById('detailedViewSection');
    var toggleBtn = document.getElementById('btnToggleDetailed');
    var toggleText = document.getElementById('toggleText');
    var toggleIcon = document.getElementById('toggleIcon');

    if (mode === 'full') {
        currentMode = 'full';
        if (splitSec) splitSec.style.display = 'none';
        if (detailSec) detailSec.className = 'show';
        if (toggleBtn) toggleBtn.classList.add('active');
        if (toggleText) toggleText.textContent = '<?= __('Back to Organized View') ?>';
        if (toggleIcon) toggleIcon.className = 'fas fa-columns';
    } else {
        currentMode = 'split';
        if (splitSec) splitSec.style.display = 'flex';
        if (detailSec) detailSec.className = '';
        if (toggleBtn) toggleBtn.classList.remove('active');
        if (toggleText) toggleText.textContent = '<?= __('View All (Detailed Data)') ?>';
        if (toggleIcon) toggleIcon.className = 'fas fa-table';
    }
}

function toggleDetailedView() {
    if (currentMode === 'split') {
        switchView('full');
    } else {
        switchView('split');
    }
}

// Switch between compact view and full matrix for Monster table on mobile
function setMonsterView(view) {
    var table = document.getElementById('monsterTable');
    var btnCompact = document.getElementById('btnMonsterCompact');
    var btnFull = document.getElementById('btnMonsterFull');
    if (!table) return;

    if (view === 'full') {
        table.classList.remove('compact-mode');
        table.classList.add('full-mode');
        if (btnCompact) btnCompact.classList.remove('active');
        if (btnFull) btnFull.classList.add('active');
    } else {
        table.classList.remove('full-mode');
        table.classList.add('compact-mode');
        if (btnCompact) btnCompact.classList.add('active');
        if (btnFull) btnFull.classList.remove('active');
    }
}

// Instant table search filter
function filterTable(tableId, query) {
    var table = document.getElementById(tableId);
    if (!table) return;
    var rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
    var filter = query.toLowerCase().trim();

    for (var i = 0; i < rows.length; i++) {
        var text = rows[i].textContent.toLowerCase();
        if (text.indexOf(filter) > -1) {
            rows[i].style.display = '';
        } else {
            rows[i].style.display = 'none';
        }
    }
}

// Monster Details Modal with Dates
function showEpicMonsterDetails(playerName) {
    var title = document.getElementById('appModalTitle');
    var body = document.getElementById('appModalBody');

    title.innerHTML = '<i class="fas fa-dragon text-danger mr-2"></i>' + escapeHtml(playerName) + ' — Monster Chests History';

    var data = epicMonsterData[playerName];
    if (!data || data.length === 0) {
        body.innerHTML = '<div class="em-no-data"><?= __('No individual monster chest records found for this player in this cycle.') ?></div>';
    } else {
        var html = '<table class="em-detail-table">';
        html += '<thead><tr>';
        html += '<th><?= __('Monster Chest') ?></th>';
        html += '<th><?= __('Date') ?></th>';
        html += '<th><?= __('Quantity') ?></th>';
        html += '</tr></thead><tbody>';
        for (var i = 0; i < data.length; i++) {
            var row = data[i];
            html += '<tr>';
            html += '<td><i class="fas fa-paw text-muted mr-1"></i> ' + escapeHtml(row.source) + '</td>';
            html += '<td>' + escapeHtml(row.date) + '</td>';
            html += '<td>' + row.count + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        body.innerHTML = html;
    }

    openModal();
}

// Player Full Chest Breakdown Modal
function showPlayerSummaryModal(playerName) {
    var title = document.getElementById('appModalTitle');
    var body = document.getElementById('appModalBody');

    var p = playerAllDetails[playerName];
    if (!p) {
        body.innerHTML = '<div class="em-no-data"><?= __('Player data not found.') ?></div>';
        openModal();
        return;
    }

    title.innerHTML = '<i class="fas fa-user text-primary mr-2"></i>' + escapeHtml(playerName) + ' — Score & Chest Breakdown';

    var html = '<div style="margin-bottom: 16px; display: flex; gap: 10px; flex-wrap: wrap;">';
    html += '<div class="goal-pill"><i class="fas fa-star text-warning"></i> Final Score: <strong>' + p.final_score.toLocaleString() + '</strong></div>';
    html += '<div class="goal-pill"><i class="fas fa-box text-primary"></i> Total Chests: <strong>' + p.total_chests.toLocaleString() + '</strong></div>';
    html += '<div class="goal-pill epic"><i class="fas fa-gem"></i> Epic Crypts: <strong>' + p.epic_crypt_score.toLocaleString() + '</strong></div>';
    html += '<div class="goal-pill" style="border-color:#fecaca; color:#991b1b;"><i class="fas fa-dragon text-danger"></i> Monsters: <strong>' + p.monster_chests.toLocaleString() + '</strong></div>';
    if (penaltyTooltips[playerName]) {
        html += '<div class="goal-pill penalty"><i class="fas fa-arrow-up"></i> ' + escapeHtml(penaltyTooltips[playerName]) + '</div>';
    }
    html += '</div>';

    if (!p.chests || p.chests.length === 0) {
        html += '<div class="em-no-data"><?= __('No scored chests recorded for this player in this cycle.') ?></div>';
    } else {
        html += '<table class="em-detail-table">';
        html += '<thead><tr>';
        html += '<th><?= __('Chest Source') ?></th>';
        html += '<th style="text-align:center;"><?= __('Qty') ?></th>';
        html += '<th style="text-align:center;"><?= __('Points Each') ?></th>';
        html += '<th style="text-align:right;"><?= __('Total Points') ?></th>';
        html += '</tr></thead><tbody>';

        for (var i = 0; i < p.chests.length; i++) {
            var c = p.chests[i];
            var icon = c.is_monster ? '<i class="fas fa-dragon text-danger mr-1"></i> ' : '<i class="fas fa-box text-muted mr-1"></i> ';
            html += '<tr>';
            html += '<td>' + icon + escapeHtml(c.source) + '</td>';
            html += '<td style="text-align:center; font-weight: 600;">' + c.count + '</td>';
            html += '<td style="text-align:center; color: #6b7280;">' + c.score_each + '</td>';
            html += '<td style="text-align:right; font-weight: 700; color: #4f46e5;">' + c.total_points.toLocaleString() + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
    }

    body.innerHTML = html;
    openModal();
}

function openModal() {
    document.getElementById('appOverlay').style.display = 'block';
    document.getElementById('appModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('appModal').style.display = 'none';
    document.getElementById('appOverlay').style.display = 'none';
}

function escapeHtml(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>
