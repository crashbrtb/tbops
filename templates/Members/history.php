<?php
/**
 * @var \App\View\AppView $this
 * @var int $period
 * @var list<int> $periods
 * @var list<array{date: string, power: int, players: int}> $clanSeries
 * @var list<array{member_id: int, player: string, first_date: string, first: int, last_date: string, last: int, change: int, percent: float|null, readings: int}> $growth
 * @var \App\Model\Entity\Member|null $player
 * @var list<array{date: string, power: int}> $playerSeries
 * @var bool $isAdmin Members' pages are linked for administrators only.
 */

$this->assign('title', __('Clan Power History'));
$this->Breadcrumbs->add(array_merge(
    [['title' => __('Home'), 'url' => '/']],
    $isAdmin ? [['title' => __('List Members'), 'url' => ['action' => 'index']]] : [['title' => __('Clan Data')]],
    [['title' => __('Power History')]],
));

$periodLabel = fn (int $days): string => $days > 0 ? __('{0} days', $days) : __('All');
$signed = fn (int|float $n, ?int $precision = null): string => ($n > 0 ? '+' : '')
    . ($precision === null ? $this->Number->format($n) : $this->Number->format($n, ['precision' => $precision]));
$changeClass = fn (int|float $n): string => $n > 0 ? 'text-success' : ($n < 0 ? 'text-danger' : 'text-muted');

$first = $clanSeries[0] ?? null;
$last = $clanSeries !== [] ? $clanSeries[count($clanSeries) - 1] : null;
$clanChange = $first !== null && $last !== null ? $last['power'] - $first['power'] : 0;
$clanPercent = $first !== null && $first['power'] > 0 ? $clanChange * 100 / $first['power'] : null;

$chartData = [
    'locale' => str_replace('_', '-', \Cake\I18n\I18n::getLocale()),
    'clan' => $clanSeries,
    'player' => $player !== null ? ['name' => $player->player, 'series' => $playerSeries] : null,
    'labels' => [
        'clan' => __('Clan power'),
        'players' => __('Players'),
        'power' => __('Power'),
    ],
];
?>

<style>
    .power-history-chart {
        position: relative;
        height: 320px;
    }
    .power-history-table th[data-sort] {
        cursor: pointer;
        white-space: nowrap;
    }
    .power-history-table th[data-sort]::after {
        content: "\f0dc";
        font-family: "Font Awesome 5 Free";
        font-weight: 900;
        margin-left: .35rem;
        opacity: .35;
    }
    .power-history-table tr.is-selected {
        background: var(--accent-light, rgba(0, 123, 255, .08));
    }
    .power-history-table td.num,
    .power-history-table th.num {
        text-align: right;
        font-variant-numeric: tabular-nums;
    }
    @media (max-width: 575.98px) {
        .power-history-chart {
            height: 240px;
        }
    }
</style>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-chart-line text-primary mr-2"></i><?= __('Clan Power History') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('How the power of the clan and of each member changed, one reading a day from the game\'s member list and tournaments') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <div class="btn-group btn-group-sm" role="group" aria-label="<?= h(__('Period')) ?>">
                <?php foreach ($periods as $days): ?>
                    <?= $this->Html->link(
                        h($periodLabel($days)),
                        ['action' => 'history', '?' => array_filter(['period' => $days, 'player' => $player?->id], fn ($v) => $v !== null)],
                        ['class' => 'btn ' . ($days === $period ? 'btn-primary' : 'btn-default'), 'escape' => false]
                    ) ?>
                <?php endforeach; ?>
            </div>
            <?php if ($isAdmin): ?>
                <?= $this->Html->link(
                    '<i class="fas fa-list mr-1"></i> ' . __('List Members'),
                    ['action' => 'index'],
                    ['class' => 'btn btn-default btn-sm', 'escape' => false]
                ) ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($clanSeries === []): ?>
        <div class="card card-outline card-primary">
            <div class="card-body text-center text-muted py-5">
                <i class="fas fa-chart-line fa-2x mb-3 d-block"></i>
                <?= __('There is no power history for this period yet. It starts with the next member list or tournament ranking the site receives.') ?>
            </div>
        </div>
    <?php else: ?>
        <div class="row mb-3">
            <div class="col-sm-4 mb-3 mb-sm-0">
                <div class="card h-100 shadow-sm border mb-0">
                    <div class="card-body py-3">
                        <span class="text-muted text-uppercase small font-weight-bold"><?= __('Clan power') ?></span>
                        <div class="h3 font-weight-bold mb-0 mt-1"><?= $this->Number->format($last['power']) ?></div>
                        <span class="small text-muted"><?= __('{0} players on {1}', $last['players'], h((new \Cake\I18n\Date($last['date']))->i18nFormat(\IntlDateFormatter::MEDIUM))) ?></span>
                    </div>
                </div>
            </div>
            <div class="col-sm-4 mb-3 mb-sm-0">
                <div class="card h-100 shadow-sm border mb-0">
                    <div class="card-body py-3">
                        <span class="text-muted text-uppercase small font-weight-bold"><?= __('Change in the period') ?></span>
                        <div class="h3 font-weight-bold mb-0 mt-1 <?= $changeClass($clanChange) ?>"><?= $signed($clanChange) ?></div>
                        <span class="small text-muted">
                            <?= $clanPercent !== null ? h($signed($clanPercent, 2)) . '%' : '&nbsp;' ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="card h-100 shadow-sm border mb-0">
                    <div class="card-body py-3">
                        <span class="text-muted text-uppercase small font-weight-bold"><?= __('Days with readings') ?></span>
                        <div class="h3 font-weight-bold mb-0 mt-1"><?= $this->Number->format(count($clanSeries)) ?></div>
                        <span class="small text-muted"><?= __('Since {0}', h((new \Cake\I18n\Date($first['date']))->i18nFormat(\IntlDateFormatter::MEDIUM))) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title font-weight-bold">
                    <i class="fas fa-users text-primary mr-2"></i><?= __('Clan power') ?>
                </h3>
            </div>
            <div class="card-body">
                <div class="power-history-chart"><canvas id="clanPowerChart" role="img" aria-label="<?= h(__('Clan power')) ?>"></canvas></div>
            </div>
        </div>

        <?php if ($player !== null): ?>
            <div class="card card-info card-outline" id="player-history">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h3 class="card-title font-weight-bold mb-0">
                        <i class="fas fa-user text-info mr-2"></i><?= h($player->player) ?>
                    </h3>
                    <div class="ml-auto">
                        <?php if ($isAdmin): ?>
                            <?= $this->Html->link(
                                '<i class="fas fa-id-card mr-1"></i>' . __('Profile'),
                                ['action' => 'view', $player->id],
                                ['class' => 'btn btn-default btn-xs', 'escape' => false]
                            ) ?>
                        <?php endif; ?>
                        <?= $this->Html->link(
                            '<i class="fas fa-times"></i>',
                            ['action' => 'history', '?' => ['period' => $period]],
                            ['class' => 'btn btn-default btn-xs', 'escape' => false, 'title' => __('Close')]
                        ) ?>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($playerSeries === []): ?>
                        <p class="text-muted mb-0"><?= __('No readings of this player in the period.') ?></p>
                    <?php else: ?>
                        <div class="power-history-chart"><canvas id="playerPowerChart" role="img" aria-label="<?= h($player->player) ?>"></canvas></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card card-primary card-outline">
            <div class="card-header">
                <h3 class="card-title font-weight-bold">
                    <i class="fas fa-trophy text-primary mr-2"></i><?= __('Growth of each member') ?>
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 power-history-table" id="powerGrowthTable">
                        <thead>
                            <tr>
                                <th data-sort="text"><?= __('Player') ?></th>
                                <th class="num" data-sort="num"><?= __('Start') ?></th>
                                <th class="num" data-sort="num"><?= __('Now') ?></th>
                                <th class="num" data-sort="num"><?= __('Change') ?></th>
                                <th class="num" data-sort="num"><?= __('Change %') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($growth as $row): ?>
                                <tr class="<?= $player !== null && (int)$player->id === $row['member_id'] ? 'is-selected' : '' ?>">
                                    <td data-value="<?= h(mb_strtolower($row['player'])) ?>">
                                        <?= $this->Html->link(
                                            h($row['player']),
                                            ['action' => 'history', '?' => ['period' => $period, 'player' => $row['member_id']], '#' => 'player-history'],
                                            ['escape' => false]
                                        ) ?>
                                    </td>
                                    <td class="num" data-value="<?= $row['first'] ?>" title="<?= h($row['first_date']) ?>"><?= $this->Number->format($row['first']) ?></td>
                                    <td class="num" data-value="<?= $row['last'] ?>" title="<?= h($row['last_date']) ?>"><?= $this->Number->format($row['last']) ?></td>
                                    <td class="num font-weight-bold <?= $changeClass($row['change']) ?>" data-value="<?= $row['change'] ?>"><?= $signed($row['change']) ?></td>
                                    <td class="num <?= $changeClass($row['change']) ?>" data-value="<?= $row['percent'] ?? '' ?>">
                                        <?= $row['percent'] !== null ? h($signed($row['percent'], 2)) . '%' : '&ndash;' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($clanSeries !== []): ?>
    <script type="application/json" id="powerHistoryData"><?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
    <?php $this->Html->script('/adminlte/plugins/chart.js/Chart.bundle.min.js', ['block' => true]); ?>
    <?php $this->Html->scriptStart(['block' => true]); ?>
    (function () {
        var data = JSON.parse(document.getElementById('powerHistoryData').textContent);
        var css = getComputedStyle(document.documentElement);
        var token = function (name, fallback) { return (css.getPropertyValue(name) || '').trim() || fallback; };
        var text = token('--text-muted-strong', '#6c757d');
        var grid = token('--line-subtle', 'rgba(128, 128, 128, .15)');
        var number = new Intl.NumberFormat(data.locale);
        var compact = new Intl.NumberFormat(data.locale, { notation: 'compact', maximumFractionDigits: 1 });
        var day = new Intl.DateTimeFormat(data.locale, { dateStyle: 'medium', timeZone: 'UTC' });

        function draw(id, series, label, color, extra) {
            var canvas = document.getElementById(id);
            if (!canvas || typeof Chart === 'undefined') {
                return;
            }
            new Chart(canvas, {
                type: 'line',
                data: {
                    datasets: [{
                        label: label,
                        data: series.map(function (p) { return { x: p.date + 'T00:00:00Z', y: p.power }; }),
                        borderColor: color,
                        backgroundColor: /^#[0-9a-f]{6}$/i.test(color) ? color + '22' : 'rgba(128, 128, 128, .08)',
                        pointBackgroundColor: color,
                        pointRadius: series.length > 60 ? 0 : 3,
                        pointHoverRadius: 4,
                        borderWidth: 2,
                        lineTension: 0.15,
                        fill: true
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    legend: { display: false },
                    scales: {
                        xAxes: [{
                            type: 'time',
                            time: { unit: series.length > 120 ? 'month' : 'day', tooltipFormat: 'll' },
                            ticks: { fontColor: text, maxTicksLimit: 8, autoSkip: true },
                            gridLines: { color: grid, zeroLineColor: grid }
                        }],
                        yAxes: [{
                            ticks: { fontColor: text, callback: function (v) { return compact.format(v); } },
                            gridLines: { color: grid, zeroLineColor: grid }
                        }]
                    },
                    tooltips: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            title: function (items) { return items.length ? day.format(new Date(series[items[0].index].date + 'T00:00:00Z')) : ''; },
                            label: function (item) { return label + ': ' + number.format(series[item.index].power); },
                            afterLabel: extra ? function (item) { return extra(series[item.index]); } : undefined
                        }
                    },
                    hover: { mode: 'index', intersect: false }
                }
            });
        }

        draw('clanPowerChart', data.clan, data.labels.clan, token('--accent', '#007bff'), function (p) {
            return data.labels.players + ': ' + number.format(p.players);
        });
        if (data.player) {
            draw('playerPowerChart', data.player.series, data.labels.power, token('--info', '#17a2b8'));
        }

        // Sort the growth table by the header clicked.
        var table = document.getElementById('powerGrowthTable');
        if (table) {
            table.querySelectorAll('th[data-sort]').forEach(function (th, col) {
                th.addEventListener('click', function () {
                    var body = table.tBodies[0];
                    var desc = th.dataset.dir !== 'desc';
                    var numeric = th.dataset.sort === 'num';
                    table.querySelectorAll('th[data-sort]').forEach(function (o) { delete o.dataset.dir; });
                    th.dataset.dir = desc ? 'desc' : 'asc';
                    Array.prototype.slice.call(body.rows).sort(function (a, b) {
                        var x = a.cells[col].dataset.value, y = b.cells[col].dataset.value;
                        var r;
                        if (numeric) {
                            var nx = x === '' ? -Infinity : Number(x), ny = y === '' ? -Infinity : Number(y);
                            r = nx === ny ? 0 : (nx < ny ? -1 : 1);
                        } else {
                            r = x.localeCompare(y);
                        }
                        return desc ? -r : r;
                    }).forEach(function (row) { body.appendChild(row); });
                });
            });
        }
    })();
    <?php $this->Html->scriptEnd(); ?>
<?php endif; ?>
