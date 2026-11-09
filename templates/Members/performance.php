<?php
/**
 * Member Performance Dashboard
 *
 * @var \App\View\AppView $this
 * @var array<string, mixed> $data
 * @var int $period
 * @var array<int, string> $periods
 * @var string $selectedPlayer
 */

$this->assign('title', __('Member Performance'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('Members'), 'url' => ['action' => 'index']],
    ['title' => __('Performance')],
]);

$stats = $data['stats'];
$ranking = $data['ranking'];
$eventChart = $data['eventChart'];
$chestChart = $data['chestChart'];
$topPerformer = $stats['top_performer'] ?? null;

$locale = str_replace('_', '-', \Cake\I18n\I18n::getLocale());
$chartData = [
    'locale' => $locale,
    'events' => $eventChart,
    'chests' => $chestChart,
    'labels' => [
        'met' => __('Goals Met'),
        'missed' => __('Goals Missed'),
        'rate' => __('Success Rate (%)'),
        'participants' => __('Participants'),
    ],
];
?>

<style>
    /* Prevent horizontal scroll anywhere on page */
    .content-page-wrap {
        max-width: 100%;
        overflow-x: hidden;
    }
    
    .performance-stat-card {
        background: var(--surface, #ffffff);
        border: 1px solid var(--line, #e5e7eb);
        border-radius: var(--radius-md, 10px);
        padding: 1.15rem 1.25rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        position: relative;
        overflow: hidden;
        height: 100%;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .performance-stat-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
    }
    .stat-card-accent {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
    }
    .stat-accent-warning { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
    .stat-accent-info { background: linear-gradient(90deg, #0ea5e9, #38bdf8); }
    .stat-accent-success { background: linear-gradient(90deg, #10b981, #34d399); }
    .stat-accent-primary { background: linear-gradient(90deg, #4f46e5, #6366f1); }

    .stat-title {
        font-size: 0.82rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted-strong, #6b7280);
        margin-bottom: 0.35rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .stat-number {
        font-size: 1.75rem;
        font-weight: 700;
        color: var(--text-dark, #111827);
        line-height: 1.2;
    }
    .stat-subtext {
        font-size: 0.85rem;
        color: var(--text-muted-strong, #6b7280);
        margin-top: 0.35rem;
    }

    .performance-chart-card {
        background: var(--surface, #ffffff);
        border: 1px solid var(--line, #e5e7eb);
        border-radius: var(--radius-md, 10px);
        padding: 1.25rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        margin-bottom: 1.5rem;
    }
    .chart-container {
        position: relative;
        height: 270px;
        width: 100%;
    }

    .ranking-card {
        background: var(--surface, #ffffff);
        border: 1px solid var(--line, #e5e7eb);
        border-radius: var(--radius-md, 10px);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        overflow: hidden;
        margin-bottom: 2rem;
        width: 100%;
    }
    .ranking-header {
        padding: 1.15rem 1.25rem;
        border-bottom: 1px solid var(--line, #e5e7eb);
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
    }
    .ranking-table-wrap {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .ranking-table {
        width: 100%;
        min-width: 760px;
        margin-bottom: 0;
        border-collapse: collapse;
    }
    .ranking-table th {
        background: var(--surface-sunken, #f8fafc);
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted-strong, #4b5563);
        padding: 0.8rem 0.65rem;
        border-bottom: 1px solid var(--line, #e5e7eb);
        border-top: none;
    }
    .ranking-table td {
        padding: 0.8rem 0.65rem;
        vertical-align: middle;
        border-bottom: 1px solid var(--line-subtle, #f1f5f9);
        color: var(--text, #1f2937);
        font-size: 0.9rem;
    }
    .ranking-row {
        cursor: pointer;
        transition: background-color 0.15s ease;
    }
    .ranking-row:hover {
        background-color: var(--surface-hover, #f8fbff);
    }
    .rank-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        font-weight: 700;
        font-size: 0.85rem;
    }
    .rank-1 { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; box-shadow: 0 0 6px rgba(245, 158, 11, 0.3); }
    .rank-2 { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .rank-3 { background: #ffedd5; color: #c2410c; border: 1px solid #fed7aa; }
    .rank-other { color: var(--text-muted-strong, #64748b); font-size: 0.82rem; font-weight: 600; }

    .rate-badge {
        font-size: 1.05rem;
        font-weight: 700;
        display: inline-block;
    }

    .guard-badge {
        font-size: 0.72rem;
        font-weight: 700;
        padding: 0.15rem 0.45rem;
        border-radius: var(--radius-sm, 4px);
        background: #e2e8f0;
        color: #334155;
    }

    .progress-bar-custom {
        height: 5px;
        border-radius: 3px;
        background: #e2e8f0;
        overflow: hidden;
        margin-top: 4px;
        width: 100%;
    }
    .progress-fill {
        height: 100%;
        border-radius: 3px;
    }

    /* Modal styling */
    #playerHistoryModal {
        z-index: 1060 !important;
    }
    .modal-backdrop {
        z-index: 1050 !important;
    }
    .player-modal-header {
        background: var(--surface-sunken, #f8fafc);
        border-bottom: 1px solid var(--line, #e5e7eb);
        padding: 1.15rem 1.25rem;
    }
    .player-avatar {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        font-weight: 700;
        margin-right: 0.85rem;
        flex-shrink: 0;
    }
    .detail-history-table th {
        font-size: 0.78rem;
        text-transform: uppercase;
        background: var(--surface-sunken, #f8fafc);
        color: var(--text-muted-strong, #6b7280);
    }
    .detail-history-table td {
        font-size: 0.88rem;
        vertical-align: middle;
    }
</style>

<div class="content-page-wrap">
    <!-- Header & Period Toolbar -->
    <div class="score-toolbar mb-4">
        <div class="score-title-group">
            <h1 class="score-title" style="font-size: 1.6rem; font-weight: 700; margin-bottom: 0.35rem;">
                <i class="fas fa-medal text-warning mr-2"></i><?= __('Member Performance') ?>
            </h1>
            <p class="cycle-subtitle text-muted mb-0" style="font-size: 0.95rem;">
                <?= __('Goal achievement metrics for chests and events, ranking by success rate, and complete player histories.') ?>
            </p>
        </div>
        <div class="toolbar-actions d-flex align-items-center">
            <form method="get" class="form-inline">
                <label for="periodSelect" class="mr-2 font-weight-bold text-muted small"><?= __('Period') ?>:</label>
                <div class="input-group input-group-sm">
                    <select id="periodSelect" name="period" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($periods as $pVal => $pLabel): ?>
                            <option value="<?= $pVal ?>" <?= $pVal === $period ? 'selected' : '' ?>>
                                <?= h(__($pLabel)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Statistics KPI Cards -->
    <div class="row mb-4">
        <!-- Event Goals Met -->
        <div class="col-lg-3 col-sm-6 mb-3">
            <div class="performance-stat-card">
                <div class="stat-card-accent stat-accent-warning"></div>
                <div class="stat-title">
                    <span><?= __('Event Goals Met') ?></span>
                    <i class="fas fa-trophy text-warning"></i>
                </div>
                <div class="stat-number text-warning">
                    <?= $stats['events_goals_met'] ?>
                    <small class="text-muted" style="font-size: 1rem; font-weight: normal;">/ <?= $stats['events_goals_evaluated'] ?></small>
                </div>
                <div class="stat-subtext">
                    <span class="badge badge-warning"><?= $stats['events_success_rate'] ?>% <?= __('success rate') ?></span>
                    <span class="text-muted ml-1">&bull; <?= $stats['events_count'] ?> <?= __('events') ?></span>
                </div>
            </div>
        </div>

        <!-- Chest Goals Met -->
        <div class="col-lg-3 col-sm-6 mb-3">
            <div class="performance-stat-card">
                <div class="stat-card-accent stat-accent-info"></div>
                <div class="stat-title">
                    <span><?= __('Chest Goals Met') ?></span>
                    <i class="fas fa-box-open text-info"></i>
                </div>
                <div class="stat-number text-info">
                    <?= $stats['chests_goals_met'] ?>
                    <small class="text-muted" style="font-size: 1rem; font-weight: normal;">/ <?= $stats['chests_goals_evaluated'] ?></small>
                </div>
                <div class="stat-subtext">
                    <span class="badge badge-info"><?= $stats['chests_success_rate'] ?>% <?= __('success rate') ?></span>
                    <span class="text-muted ml-1">&bull; <?= $stats['chests_cycles_count'] ?> <?= __('cycles') ?></span>
                </div>
            </div>
        </div>

        <!-- Overall Goals Combined -->
        <div class="col-lg-3 col-sm-6 mb-3">
            <div class="performance-stat-card">
                <div class="stat-card-accent stat-accent-success"></div>
                <div class="stat-title">
                    <span><?= __('Overall Goals Met') ?></span>
                    <i class="fas fa-bullseye text-success"></i>
                </div>
                <div class="stat-number text-success">
                    <?= $stats['total_goals_met'] ?>
                    <small class="text-muted" style="font-size: 1rem; font-weight: normal;">/ <?= $stats['total_goals_evaluated'] ?></small>
                </div>
                <div class="stat-subtext">
                    <span class="badge badge-success"><?= $stats['overall_success_rate'] ?>% <?= __('global rate') ?></span>
                    <span class="text-muted ml-1">&bull; <?= __('Events + Chests') ?></span>
                </div>
            </div>
        </div>

        <!-- Top Performer -->
        <div class="col-lg-3 col-sm-6 mb-3">
            <div class="performance-stat-card">
                <div class="stat-card-accent stat-accent-primary"></div>
                <div class="stat-title">
                    <span><?= __('Top Performer') ?></span>
                    <i class="fas fa-crown text-warning"></i>
                </div>
                <?php if ($topPerformer && $topPerformer['total_evaluated'] > 0): ?>
                    <div class="stat-number text-truncate" style="font-size: 1.45rem; color: #10b981;" title="<?= h($topPerformer['player']) ?>">
                        <?= $topPerformer['success_rate'] ?>%
                    </div>
                    <div class="stat-subtext text-truncate">
                        <strong><?= h($topPerformer['player']) ?></strong>
                        <span class="text-muted ml-1">&bull; <?= $topPerformer['total_goals_met'] ?> / <?= $topPerformer['total_evaluated'] ?> <?= __('goals beaten') ?></span>
                    </div>
                <?php elseif ($topPerformer): ?>
                    <div class="stat-number text-truncate" style="font-size: 1.45rem;">
                        <?= h($topPerformer['player']) ?>
                    </div>
                    <div class="stat-subtext text-muted">
                        <?= __('Awaiting goal evaluations') ?>
                    </div>
                <?php else: ?>
                    <div class="stat-number">&ndash;</div>
                    <div class="stat-subtext text-muted"><?= __('No active members ranked') ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="row">
        <!-- Event Goals Chart -->
        <div class="col-lg-6 mb-4">
            <div class="performance-chart-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h6 font-weight-bold text-dark mb-0">
                        <i class="fas fa-chart-bar text-warning mr-2"></i><?= __('Event Goals Met per Tournament') ?>
                    </h2>
                    <span class="badge badge-light border text-muted"><?= count($eventChart) ?> <?= __('events') ?></span>
                </div>
                <?php if (empty($eventChart)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-calendar-times fa-2x mb-2 text-gray-300"></i>
                        <p class="mb-0"><?= __('No events with standings found in this period.') ?></p>
                    </div>
                <?php else: ?>
                    <div class="chart-container">
                        <canvas id="eventPerformanceChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chest Goals Chart -->
        <div class="col-lg-6 mb-4">
            <div class="performance-chart-card h-100">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <h2 class="h6 font-weight-bold text-dark mb-0">
                        <i class="fas fa-box text-info mr-2"></i><?= __('Chest Goals Met per Cycle') ?>
                    </h2>
                    <?php if ($stats['is_fallback_cycles']): ?>
                        <span class="badge badge-warning" title="<?= __('Displaying the most recent completed cycles') ?>">
                            <i class="fas fa-info-circle mr-1"></i><?= __('Recent cycles') ?>
                        </span>
                    <?php else: ?>
                        <span class="badge badge-light border text-muted"><?= count($chestChart) ?> <?= __('cycles') ?></span>
                    <?php endif; ?>
                </div>
                <?php if (empty($chestChart)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-box-open fa-2x mb-2 text-gray-300"></i>
                        <p class="mb-0"><?= __('No chest cycle summaries recorded.') ?></p>
                    </div>
                <?php else: ?>
                    <div class="chart-container">
                        <canvas id="chestPerformanceChart"></canvas>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Players Ranking Table -->
    <div class="ranking-card">
        <div class="ranking-header">
            <div>
                <h2 class="h5 font-weight-bold text-dark mb-1">
                    <i class="fas fa-trophy text-warning mr-2"></i><?= __('Player Performance Ranking') ?>
                </h2>
                <small class="text-muted">
                    <?= __('Classified by success rate (aproveitamento %). Only active members are listed. Click on any player to inspect their full event & chest history.') ?>
                </small>
            </div>
            <div class="d-flex align-items-center">
                <div class="input-group input-group-sm" style="width: 220px;">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                    </div>
                    <input type="text" id="rankingSearchInput" class="form-control" placeholder="<?= __('Search player...') ?>">
                </div>
            </div>
        </div>

        <div class="ranking-table-wrap">
            <table class="ranking-table" id="performanceRankingTable">
                <thead>
                    <tr>
                        <th style="width: 55px; text-align: center;"><?= __('Rank') ?></th>
                        <th><?= __('Player') ?></th>
                        <th style="width: 170px; text-align: center;" title="<?= __('Percentage of goals met out of valid evaluated goals') ?>">
                            <i class="fas fa-percentage text-success mr-1"></i><?= __('Success Rate') ?>
                        </th>
                        <th style="width: 125px; text-align: center;"><?= __('Event Goals') ?></th>
                        <th style="width: 125px; text-align: center;"><?= __('Chest Goals') ?></th>
                        <th style="width: 105px; text-align: center;"><?= __('Total Met') ?></th>
                        <th style="width: 65px; text-align: center;"><?= __('Action') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ranking)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <?= __('No active members found.') ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ranking as $player): ?>
                            <?php
                            $rateColor = $player['success_rate'] >= 75 ? '#10b981' : ($player['success_rate'] >= 40 ? '#0ea5e9' : ($player['success_rate'] > 0 ? '#f59e0b' : '#9ca3af'));
                            ?>
                            <tr class="ranking-row" data-player-key="<?= h($player['player']) ?>" data-rate="<?= $player['success_rate'] ?>" data-rank="<?= $player['rank'] ?>">
                                <td style="text-align: center;">
                                    <?php if ($player['rank'] === 1): ?>
                                        <span class="rank-badge rank-1" title="<?= __('1st Place') ?>">🥇</span>
                                    <?php elseif ($player['rank'] === 2): ?>
                                        <span class="rank-badge rank-2" title="<?= __('2nd Place') ?>">🥈</span>
                                    <?php elseif ($player['rank'] === 3): ?>
                                        <span class="rank-badge rank-3" title="<?= __('3rd Place') ?>">🥉</span>
                                    <?php else: ?>
                                        <span class="rank-other">#<?= $player['rank'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="font-weight-bold text-dark player-name-cell">
                                            <?= h($player['player']) ?>
                                        </span>
                                        <?php if ($player['guards'] > 0): ?>
                                            <span class="guard-badge ml-2" title="<?= __('Guards level') ?>">G<?= $player['guards'] ?></span>
                                        <?php endif; ?>
                                        <?php if ($player['administrative']): ?>
                                            <span class="badge badge-secondary ml-1" title="<?= __('Administrative account') ?>"><?= __('Admin') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="rate-badge" style="color: <?= $rateColor ?>;"><?= $player['success_rate'] ?>%</span>
                                        <span class="small text-muted font-weight-bold"><?= $player['total_goals_met'] ?> / <?= $player['total_evaluated'] ?></span>
                                    </div>
                                    <div class="progress-bar-custom">
                                        <div class="progress-fill" style="width: <?= min(100, $player['success_rate']) ?>%; background: <?= $rateColor ?>;"></div>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($player['events_evaluated'] > 0): ?>
                                        <span class="badge <?= $player['events_met'] === $player['events_evaluated'] ? 'badge-success' : ($player['events_met'] > 0 ? 'badge-warning text-dark' : 'badge-light border text-muted') ?> py-1 px-2 font-weight-normal">
                                            <?php if ($player['events_met'] > 0): ?><i class="fas fa-check mr-1"></i><?php endif; ?><?= $player['events_met'] ?> / <?= $player['events_evaluated'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">&ndash;</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($player['chests_evaluated'] > 0): ?>
                                        <span class="badge <?= $player['chests_met'] === $player['chests_evaluated'] ? 'badge-info' : ($player['chests_met'] > 0 ? 'badge-warning text-dark' : 'badge-light border text-muted') ?> py-1 px-2 font-weight-normal">
                                            <?php if ($player['chests_met'] > 0): ?><i class="fas fa-check mr-1"></i><?php endif; ?><?= $player['chests_met'] ?> / <?= $player['chests_evaluated'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">&ndash;</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; font-weight: 700; font-size: 0.95rem;">
                                    <?= $player['total_goals_met'] ?>
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" class="btn btn-outline-primary btn-sm view-player-btn" data-player-name="<?= h($player['player']) ?>" title="<?= __('View player history') ?>">
                                        <i class="fas fa-history"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Player History Modal -->
<div class="modal fade" id="playerHistoryModal" tabindex="-1" role="dialog" aria-labelledby="playerHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">
        <div class="modal-content border-0 shadow-lg" style="border-radius: var(--radius-md, 10px);">
            <div class="player-modal-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <div class="player-avatar" id="modalPlayerAvatar">
                        PL
                    </div>
                    <div>
                        <div class="d-flex align-items-center">
                            <h3 class="modal-title font-weight-bold h5 mb-0" id="modalPlayerName">Player Name</h3>
                            <span id="modalPlayerGuard" class="guard-badge ml-2">G1</span>
                            <span id="modalPlayerRank" class="badge badge-warning ml-2 font-weight-bold">#1</span>
                        </div>
                        <div class="mt-1 d-flex align-items-center text-muted small flex-wrap">
                            <span><i class="fas fa-percentage text-success mr-1"></i><?= __('Success Rate') ?>: <strong id="modalPlayerRate" class="text-success">0%</strong></span>
                            <span class="mx-2">&bull;</span>
                            <span><?= __('Goals Met') ?>: <strong id="modalPlayerGoalsCount">0</strong></span>
                            <span id="modalPlayerEntryDate" class="ml-2 text-muted"></span>
                        </div>
                    </div>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="<?= __('Close') ?>">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body p-0">
                <!-- Tabs -->
                <ul class="nav nav-tabs px-3 pt-3 bg-light" id="playerModalTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active font-weight-bold" id="tab-events-link" data-toggle="tab" href="#tab-events" role="tab">
                            <i class="fas fa-trophy text-warning mr-1"></i><?= __('Event History') ?> (<span id="modalEventHistoryCount">0</span>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link font-weight-bold" id="tab-chests-link" data-toggle="tab" href="#tab-chests" role="tab">
                            <i class="fas fa-box-open text-info mr-1"></i><?= __('Chest History') ?> (<span id="modalChestHistoryCount">0</span>)
                        </a>
                    </li>
                </ul>

                <div class="tab-content p-3">
                    <!-- Events Tab -->
                    <div class="tab-pane fade show active" id="tab-events" role="tabpanel">
                        <div class="alert alert-light border py-2 px-3 small mb-3 text-muted">
                            <i class="fas fa-info-circle mr-1"></i>
                            <?= __('Tournaments with active goals that started after the player joined the clan are evaluated for success rate. Tournaments that started before join date or with no goal are marked accordingly.') ?>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped detail-history-table mb-0">
                                <thead>
                                    <tr>
                                        <th><?= __('Tournament / Event') ?></th>
                                        <th><?= __('Date') ?></th>
                                        <th style="text-align: right;"><?= __('Points') ?></th>
                                        <th style="text-align: right;"><?= __('Goal') ?></th>
                                        <th style="text-align: center;"><?= __('Status') ?></th>
                                    </tr>
                                </thead>
                                <tbody id="modalEventsTableBody">
                                    <!-- Populated dynamically via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Chests Tab -->
                    <div class="tab-pane fade" id="tab-chests" role="tabpanel">
                        <div class="alert alert-light border py-2 px-3 small mb-3 text-muted">
                            <i class="fas fa-info-circle mr-1"></i>
                            <?= __('Weekly chest cycles that started after the player joined the clan are evaluated. Cycles that started before joining are disregarded.') ?>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped detail-history-table mb-0">
                                <thead>
                                    <tr>
                                        <th><?= __('Cycle') ?></th>
                                        <th style="text-align: right;"><?= __('Chests') ?></th>
                                        <th style="text-align: right;"><?= __('Score') ?></th>
                                        <th style="text-align: right;"><?= __('Goal') ?></th>
                                        <th style="text-align: center;"><?= __('Status') ?></th>
                                    </tr>
                                </thead>
                                <tbody id="modalChestsTableBody">
                                    <!-- Populated dynamically via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal"><?= __('Close') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Embedded JSON Data for Client Scripts -->
<script type="application/json" id="performanceRankingJson"><?= json_encode($ranking, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script type="application/json" id="performanceChartsJson"><?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<!-- Chart.js and Dashboard Controller Script -->
<?php $this->Html->script('/adminlte/plugins/chart.js/Chart.bundle.min.js', ['block' => true]); ?>
<?php $this->Html->scriptStart(['block' => true]); ?>
(function () {
    var rawRanking = document.getElementById('performanceRankingJson');
    var rawCharts = document.getElementById('performanceChartsJson');
    if (!rawRanking || !rawCharts) return;

    var playersData = JSON.parse(rawRanking.textContent);
    var chartData = JSON.parse(rawCharts.textContent);
    var selectedPlayerInit = <?= json_encode($selectedPlayer) ?>;

    var playerIndex = {};
    playersData.forEach(function (p) {
        playerIndex[p.player.toLowerCase()] = p;
    });

    var numberFmt = new Intl.NumberFormat(chartData.locale || 'pt-BR');

    // 1. Draw Event Performance Chart
    if (chartData.events && chartData.events.length > 0 && typeof Chart !== 'undefined') {
        var eventCanvas = document.getElementById('eventPerformanceChart');
        if (eventCanvas) {
            var eventLabels = chartData.events.map(function (e) {
                return (e.name.length > 25 ? e.name.substring(0, 22) + '...' : e.name) + ' (' + e.short_date + ')';
            });
            var eventMet = chartData.events.map(function (e) { return e.met; });
            var eventMissed = chartData.events.map(function (e) { return e.missed; });

            new Chart(eventCanvas, {
                type: 'bar',
                data: {
                    labels: eventLabels,
                    datasets: [
                        {
                            label: chartData.labels.met,
                            data: eventMet,
                            backgroundColor: '#10b981',
                            borderColor: '#059669',
                            borderWidth: 1
                        },
                        {
                            label: chartData.labels.missed,
                            data: eventMissed,
                            backgroundColor: '#f87171',
                            borderColor: '#ef4444',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    maintainAspectRatio: false,
                    responsive: true,
                    scales: {
                        xAxes: [{
                            stacked: true,
                            ticks: {
                                maxRotation: 45,
                                minRotation: 0,
                                autoSkip: false,
                                fontSize: 11
                            },
                            gridLines: { display: false }
                        }],
                        yAxes: [{
                            stacked: true,
                            ticks: {
                                beginAtZero: true,
                                precision: 0
                            },
                            gridLines: { color: 'rgba(0, 0, 0, 0.05)' }
                        }]
                    },
                    tooltips: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            afterTitle: function (items) {
                                var idx = items[0].index;
                                return chartData.events[idx].name + ' - ' + chartData.events[idx].date;
                            }
                        }
                    }
                }
            });
        }
    }

    // 2. Draw Chest Performance Chart
    if (chartData.chests && chartData.chests.length > 0 && typeof Chart !== 'undefined') {
        var chestCanvas = document.getElementById('chestPerformanceChart');
        if (chestCanvas) {
            var chestLabels = chartData.chests.map(function (c) { return c.label; });
            var chestMet = chartData.chests.map(function (c) { return c.met; });
            var chestMissed = chartData.chests.map(function (c) { return c.missed; });

            new Chart(chestCanvas, {
                type: 'bar',
                data: {
                    labels: chestLabels,
                    datasets: [
                        {
                            label: chartData.labels.met,
                            data: chestMet,
                            backgroundColor: '#0ea5e9',
                            borderColor: '#0284c7',
                            borderWidth: 1
                        },
                        {
                            label: chartData.labels.missed,
                            data: chestMissed,
                            backgroundColor: '#cbd5e1',
                            borderColor: '#94a3b8',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    maintainAspectRatio: false,
                    responsive: true,
                    scales: {
                        xAxes: [{
                            stacked: true,
                            ticks: { fontSize: 11 },
                            gridLines: { display: false }
                        }],
                        yAxes: [{
                            stacked: true,
                            ticks: {
                                beginAtZero: true,
                                precision: 0
                            },
                            gridLines: { color: 'rgba(0, 0, 0, 0.05)' }
                        }]
                    },
                    tooltips: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            afterTitle: function (items) {
                                var idx = items[0].index;
                                return chartData.chests[idx].full_label;
                            }
                        }
                    }
                }
            });
        }
    }

    // 3. Search Filter in Table
    var searchInput = document.getElementById('rankingSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var query = this.value.toLowerCase().trim();
            var rows = document.querySelectorAll('#performanceRankingTable tbody tr.ranking-row');
            rows.forEach(function (row) {
                var playerName = (row.dataset.playerKey || '').toLowerCase();
                if (playerName.indexOf(query) !== -1) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }

    // 4. Modal Display Function
    function showPlayerModal(playerObj) {
        if (!playerObj) return;

        document.getElementById('modalPlayerName').textContent = playerObj.player;
        document.getElementById('modalPlayerAvatar').textContent = playerObj.player.substring(0, 2).toUpperCase();
        document.getElementById('modalPlayerGuard').textContent = 'G' + (playerObj.guards || 0);
        document.getElementById('modalPlayerRank').textContent = '#' + playerObj.rank;
        document.getElementById('modalPlayerRate').textContent = playerObj.success_rate + '%';
        document.getElementById('modalPlayerGoalsCount').textContent = playerObj.total_goals_met + ' / ' + playerObj.total_evaluated;

        var entryElem = document.getElementById('modalPlayerEntryDate');
        if (playerObj.created_at) {
            var entryDate = new Date(playerObj.created_at);
            entryElem.textContent = '• <?= __('Member since') ?>: ' + entryDate.toLocaleDateString(chartData.locale || 'pt-BR');
        } else {
            entryElem.textContent = '';
        }

        // Populate Events History
        var eventsBody = document.getElementById('modalEventsTableBody');
        eventsBody.innerHTML = '';
        var evHistory = playerObj.events_history || [];
        document.getElementById('modalEventHistoryCount').textContent = evHistory.length;

        if (evHistory.length === 0) {
            eventsBody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted"><?= __('No event records in this period.') ?></td></tr>';
        } else {
            evHistory.forEach(function (ev) {
                var tr = document.createElement('tr');
                var statusHtml = '';

                if (ev.disregarded) {
                    statusHtml = '<span class="badge badge-light border text-muted" title="<?= __('Joined clan after tournament started') ?>"><i class="fas fa-ban mr-1"></i><?= __('Disregarded (joined mid-event)') ?></span>';
                } else if (!ev.has_goal) {
                    statusHtml = '<span class="badge badge-secondary"><i class="fas fa-minus mr-1"></i><?= __('No Goal') ?></span>';
                } else if (ev.goal_met === true) {
                    statusHtml = '<span class="badge badge-success"><i class="fas fa-check mr-1"></i><?= __('Goal Met') ?></span>';
                } else {
                    statusHtml = '<span class="badge badge-danger"><i class="fas fa-times mr-1"></i><?= __('Not Met') ?></span>';
                }

                var dateStr = '';
                if (ev.starts_at) {
                    var d = new Date(ev.starts_at);
                    dateStr = d.toLocaleDateString(chartData.locale || 'pt-BR');
                }

                var targetStr = ev.goal ? numberFmt.format(ev.goal) : '<span class="text-muted">&ndash;</span>';
                var pointsStr = numberFmt.format(ev.points);

                tr.innerHTML = '<td><strong>' + escapeHtml(ev.event_name) + '</strong></td>' +
                    '<td class="text-muted">' + dateStr + '</td>' +
                    '<td style="text-align: right; font-variant-numeric: tabular-nums;">' + pointsStr + '</td>' +
                    '<td style="text-align: right; font-variant-numeric: tabular-nums;">' + targetStr + '</td>' +
                    '<td style="text-align: center;">' + statusHtml + '</td>';

                eventsBody.appendChild(tr);
            });
        }

        // Populate Chests History
        var chestsBody = document.getElementById('modalChestsTableBody');
        chestsBody.innerHTML = '';
        var chHistory = playerObj.chests_history || [];
        document.getElementById('modalChestHistoryCount').textContent = chHistory.length;

        if (chHistory.length === 0) {
            chestsBody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted"><?= __('No chest cycle records in this period.') ?></td></tr>';
        } else {
            chHistory.forEach(function (ch) {
                var tr = document.createElement('tr');
                var statusHtml = '';

                if (ch.disregarded) {
                    statusHtml = '<span class="badge badge-light border text-muted" title="<?= __('Joined clan after cycle started') ?>"><i class="fas fa-ban mr-1"></i><?= __('Disregarded (joined mid-cycle)') ?></span>';
                } else if (ch.goal_achieved) {
                    statusHtml = '<span class="badge badge-success"><i class="fas fa-check mr-1"></i><?= __('Goal Met') ?></span>';
                } else {
                    statusHtml = '<span class="badge badge-danger"><i class="fas fa-times mr-1"></i><?= __('Not Met') ?></span>';
                }

                var startStr = ch.cycle_start ? new Date(ch.cycle_start).toLocaleDateString(chartData.locale || 'pt-BR') : '';
                var endStr = ch.cycle_end ? new Date(ch.cycle_end).toLocaleDateString(chartData.locale || 'pt-BR') : '';
                var cycleStr = startStr + ' - ' + endStr;

                var chestsCountStr = numberFmt.format(ch.total_chests || 0);
                var totalScoreStr = numberFmt.format(ch.total_score || 0);
                var goalStr = ch.chest_goal ? numberFmt.format(ch.chest_goal) : '<span class="text-muted">&ndash;</span>';

                tr.innerHTML = '<td>' + cycleStr + '</td>' +
                    '<td style="text-align: right; font-variant-numeric: tabular-nums;">' + chestsCountStr + '</td>' +
                    '<td style="text-align: right; font-variant-numeric: tabular-nums;">' + totalScoreStr + '</td>' +
                    '<td style="text-align: right; font-variant-numeric: tabular-nums;">' + goalStr + '</td>' +
                    '<td style="text-align: center;">' + statusHtml + '</td>';

                chestsBody.appendChild(tr);
            });
        }

        var $modal = $('#playerHistoryModal');
        if ($modal.parent()[0] !== document.body) {
            $modal.appendTo('body');
        }
        $modal.modal('show');
    }

    function escapeHtml(text) {
        if (!text) return '';
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    // Attach click events to rows and buttons
    document.querySelectorAll('.ranking-row').forEach(function (row) {
        row.addEventListener('click', function (e) {
            var playerKey = (this.dataset.playerKey || '').toLowerCase();
            if (playerIndex[playerKey]) {
                showPlayerModal(playerIndex[playerKey]);
            }
        });
    });

    document.querySelectorAll('.view-player-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var playerName = (this.dataset.playerName || '').toLowerCase();
            if (playerIndex[playerName]) {
                showPlayerModal(playerIndex[playerName]);
            }
        });
    });

    // Check if player selected via URL
    if (selectedPlayerInit) {
        var match = playerIndex[selectedPlayerInit.toLowerCase()];
        if (match) {
            showPlayerModal(match);
        }
    }
})();
<?php $this->Html->scriptEnd(); ?>
