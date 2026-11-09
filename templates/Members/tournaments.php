<?php
/**
 * The clan in the game's tournaments: its place and points in each one, and
 * what it received, with the evolution of one tournament drawn.
 *
 * @var \App\View\AppView $this
 * @var list<\App\Model\Entity\ClanTournamentResult> $results Newest first.
 * @var array<int, string> $names Game type => name to show.
 * @var array<int, array{type: int, name: string, count: int, ranked: int}> $types
 * @var int $selected Game type drawn.
 * @var list<\App\Model\Entity\ClanTournamentResult> $series Results of the type drawn, oldest first.
 * @var bool $isAdmin
 */

$this->assign('title', __('Clan Tournament History'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('Clan Data')],
    ['title' => __('Tournament History')],
]);

$nameOf = fn (int $type): string => $names[$type] ?? __('Game tournament {0}', $type);
$chartData = [
    'locale' => str_replace('_', '-', \Cake\I18n\I18n::getLocale()),
    'points' => array_map(fn ($r): array => [
        'date' => $r->ended_at->format('Y-m-d\TH:i:s\Z'),
        'position' => $r->position,
        'points' => $r->points,
        'listed' => $r->clans_listed,
    ], $series),
    'labels' => [
        'position' => __('Place'),
        'points' => __('Clan points'),
        'outside' => __('Not in the top {0}', '%n%'),
    ],
];
$ranked = array_filter($series, fn ($r): bool => $r->isRanked());
$best = $ranked === [] ? null : min(array_map(fn ($r): int => (int)$r->position, $ranked));
?>

<style>
    .clan-history-chart {
        position: relative;
        height: 280px;
    }
    .clan-history-table td.num,
    .clan-history-table th.num {
        text-align: right;
        font-variant-numeric: tabular-nums;
    }
    @media (max-width: 575.98px) {
        .clan-history-chart {
            height: 220px;
        }
    }
</style>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-flag text-primary mr-2"></i><?= __('Clan Tournament History') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('The clan\'s place among the clans and its points in each of the game\'s tournaments, and what the clan received') ?>
            </p>
        </div>
        <?php if ($types !== []): ?>
            <div class="toolbar-actions">
                <form method="get" class="form-inline">
                    <label for="tournamentType" class="mr-2"><?= __('Tournament') ?></label>
                    <select id="tournamentType" name="type" class="form-control form-control-sm" onchange="this.form.submit()">
                        <?php foreach ($types as $type): ?>
                            <option value="<?= $type['type'] ?>" <?= $type['type'] === $selected ? 'selected' : '' ?>>
                                <?= h($type['name']) ?> (<?= $type['count'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($results === []): ?>
        <div class="event-standings-card">
            <div class="event-empty">
                <i class="fas fa-flag"></i>
                <p><?= __('No clan result yet. The collector sends them with the tournaments it reads in the Journal.') ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="row">
            <div class="col-md-4 mb-3">
                <div class="event-standings-card h-100" style="padding: 16px;">
                    <small class="text-muted"><?= h($nameOf($selected)) ?></small>
                    <div style="font-size: 1.6rem; font-weight: 700;"><?= $best !== null ? h(__('#{0}', $best)) : '&ndash;' ?></div>
                    <small class="text-muted"><?= __('Best place') ?> &middot; <?= h(__('{0} of {1} time(s) ranked', count($ranked), count($series))) ?></small>
                </div>
            </div>
            <div class="col-md-8 mb-3">
                <div class="event-standings-card h-100" style="padding: 16px;">
                    <h2 class="event-standings-title" style="font-size: 1rem;"><?= __('Place') ?></h2>
                    <?php if ($ranked === []): ?>
                        <p class="text-muted mb-0"><?= __('The clan was never among the clans listed in this tournament.') ?></p>
                    <?php else: ?>
                        <div class="clan-history-chart"><canvas id="clanPlaceChart" role="img" aria-label="<?= h(__('Place')) ?>"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($ranked !== []): ?>
            <div class="event-standings-card mb-3" style="padding: 16px;">
                <h2 class="event-standings-title" style="font-size: 1rem;"><?= __('Clan points') ?></h2>
                <div class="clan-history-chart"><canvas id="clanPointsChart" role="img" aria-label="<?= h(__('Clan points')) ?>"></canvas></div>
            </div>
        <?php endif; ?>

        <div class="event-standings-card">
            <div class="event-standings-header">
                <h2 class="event-standings-title"><i class="fas fa-list text-primary"></i> <?= __('Every tournament') ?></h2>
            </div>
            <div class="event-table-wrap">
                <table class="event-table clan-history-table">
                    <thead>
                        <tr>
                            <th style="width: 140px;"><?= __('Ended (UTC)') ?></th>
                            <th><?= __('Tournament') ?></th>
                            <th class="num" style="width: 110px;"><?= __('Place') ?></th>
                            <th class="num" style="width: 150px;"><?= __('Clan points') ?></th>
                            <th><?= __('Received') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <tr class="<?= (int)$result->game_type === $selected ? 'is-selected' : '' ?>">
                                <td style="font-size: .8rem;"><?= h($result->ended_at->format('d/m/Y H:i')) ?></td>
                                <td>
                                    <?= $this->Html->link($nameOf((int)$result->game_type), ['?' => ['type' => $result->game_type]]) ?>
                                </td>
                                <td class="num">
                                    <?= $result->isRanked()
                                        ? '<strong>' . h(__('#{0}', $result->position)) . '</strong>'
                                        : '<span class="text-muted" style="font-size: .8rem;">' . h(__('Not in the top {0}', $result->clans_listed)) . '</span>' ?>
                                </td>
                                <td class="num"><?= $result->points !== null ? $this->Number->format($result->points) : '&ndash;' ?></td>
                                <td style="font-size: .85rem;">
                                    <?php $received = $result->rewardList(); ?>
                                    <?= $received === [] ? '<span class="text-muted">&ndash;</span>' : h(implode(', ', array_map(
                                        fn (array $r): string => $r['name'] . ' x' . $this->gameNumber($r['amount']),
                                        $received
                                    ))) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($ranked !== []): ?>
    <script type="application/json" id="clanHistoryData"><?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
    <?php $this->Html->script('/adminlte/plugins/chart.js/Chart.bundle.min.js', ['block' => true]); ?>
    <?php $this->Html->scriptStart(['block' => true]); ?>
    (function () {
        var data = JSON.parse(document.getElementById('clanHistoryData').textContent);
        var css = getComputedStyle(document.documentElement);
        var token = function (name, fallback) { return (css.getPropertyValue(name) || '').trim() || fallback; };
        var text = token('--text-muted-strong', '#6c757d');
        var grid = token('--line-subtle', 'rgba(128, 128, 128, .15)');
        var number = new Intl.NumberFormat(data.locale);
        var compact = new Intl.NumberFormat(data.locale, { notation: 'compact', maximumFractionDigits: 1 });
        var day = new Intl.DateTimeFormat(data.locale, { dateStyle: 'medium', timeZone: 'UTC' });
        var series = data.points;

        function draw(id, field, label, color, reverse) {
            var canvas = document.getElementById(id);
            if (!canvas || typeof Chart === 'undefined') {
                return;
            }
            new Chart(canvas, {
                type: 'line',
                data: {
                    datasets: [{
                        label: label,
                        data: series.map(function (p) { return { x: p.date, y: p[field] }; }),
                        borderColor: color,
                        backgroundColor: /^#[0-9a-f]{6}$/i.test(color) ? color + '22' : 'rgba(128, 128, 128, .08)',
                        pointBackgroundColor: color,
                        pointRadius: 4,
                        pointHoverRadius: 5,
                        borderWidth: 2,
                        lineTension: 0,
                        spanGaps: true,
                        fill: !reverse
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    legend: { display: false },
                    scales: {
                        xAxes: [{
                            type: 'time',
                            time: { unit: series.length > 30 ? 'month' : 'day', tooltipFormat: 'll' },
                            ticks: { fontColor: text, maxTicksLimit: 8, autoSkip: true },
                            gridLines: { color: grid, zeroLineColor: grid }
                        }],
                        yAxes: [{
                            ticks: reverse
                                ? { fontColor: text, reverse: true, precision: 0, suggestedMin: 1, callback: function (v) { return Number.isInteger(v) ? '#' + v : ''; } }
                                : { fontColor: text, callback: function (v) { return compact.format(v); } },
                            gridLines: { color: grid, zeroLineColor: grid }
                        }]
                    },
                    tooltips: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            title: function (items) { return items.length ? day.format(new Date(series[items[0].index].date)) : ''; },
                            label: function (item) {
                                var p = series[item.index];
                                if (p[field] === null) {
                                    return data.labels.outside.replace('%n%', p.listed);
                                }
                                return label + ': ' + (reverse ? '#' + p[field] : number.format(p[field]));
                            }
                        }
                    },
                    hover: { mode: 'index', intersect: false }
                }
            });
        }

        draw('clanPlaceChart', 'position', data.labels.position, token('--accent', '#007bff'), true);
        draw('clanPointsChart', 'points', data.labels.points, token('--success', '#28a745'), false);
    })();
    <?php $this->Html->scriptEnd(); ?>
<?php endif; ?>
