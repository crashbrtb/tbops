<?php
/**
 * Event dashboard: what the event is, and where everybody stands in it.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Event $event
 * @var array $results
 * @var \App\Model\Entity\Event|null $runningEvent
 */

use App\Model\Entity\Event;
use App\Model\Entity\EventAsset;

$this->assign('title', __('Event #{0} - {1}', $event->event_number, $event->name));

$state = $event->state;
$rows = $results['rows'];
$leader = $results['leader'];
// Goal and rewards of every row: live while the event runs, frozen with the result.
$prizes = $results['prizes'] ?? ['rewards' => [], 'totals' => [], 'goal' => $event->goal(), 'goal_met' => 0];
$rewards = $prizes['rewards'];
$goal = $prizes['goal'];
$showGoal = $goal->isActive();
$isOfficial = ($results['source'] ?? 'live') === 'snapshot';
$stateLabels = [
    Event::STATE_RUNNING => __('Running'),
    Event::STATE_SCHEDULED => __('Starts soon'),
    Event::STATE_FINISHED => __('Finished'),
    Event::STATE_CANCELLED => __('Cancelled'),
    Event::STATE_AWAITING => __('Awaiting result'),
];
$stateIcons = [
    Event::STATE_RUNNING => 'fa-play-circle',
    Event::STATE_SCHEDULED => 'fa-hourglass-start',
    Event::STATE_FINISHED => 'fa-flag-checkered',
    Event::STATE_CANCELLED => 'fa-ban',
    Event::STATE_AWAITING => 'fa-hourglass-half',
];

$bannerUrl = $event->has_custom_banner
    ? $this->Url->build(['action' => 'banner', $event->id])
    : $this->Url->build(['action' => 'asset', $state === Event::STATE_RUNNING
        ? EventAsset::SLUG_EVENT_LIVE
        : EventAsset::SLUG_NO_EVENT]);

// The largest share in the table sets the scale of the little bars, so the
// spread is visible even when one player is far ahead of everyone.
$topShare = 0.0;
foreach ($rows as $row) {
    $topShare = max($topShare, (float)$row['participation']);
}
$topShare = $topShare > 0 ? $topShare : 1.0;

$isAdmin = false;
$identity = $this->request->getAttribute('identity');
if ($identity !== null) {
    foreach ((array)$identity->get('roles') as $role) {
        if (isset($role->name) && $role->name === 'admin') {
            $isAdmin = true;
            break;
        }
    }
}
?>
<div class="content-page-wrap">

    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-trophy text-warning"></i> <?= __('Event Standings') ?>
            </h1>
            <p class="cycle-subtitle"><?= __('Official ranking and leaders for this event') ?></p>
        </div>
        <div class="toolbar-actions d-flex flex-wrap" style="gap: 8px;">
            <?php if ($runningEvent !== null && $runningEvent->id !== $event->id): ?>
                <?= $this->Html->link(
                    '<i class="fas fa-play-circle mr-1"></i>' . __('Go to running event'),
                    ['action' => 'view', $runningEvent->id],
                    ['class' => 'btn btn-success btn-sm', 'escape' => false]
                ) ?>
            <?php endif; ?>
            <?= $this->Html->link(
                '<i class="fas fa-history mr-1"></i>' . __('Event History'),
                ['action' => 'history'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
            <?php if ($isAdmin): ?>
                <?= $this->Html->link(
                    '<i class="fas fa-pen mr-1"></i>' . __('Edit'),
                    ['action' => 'edit', $event->id],
                    ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
                ) ?>
                <?php if ($state === Event::STATE_FINISHED || $state === Event::STATE_CANCELLED): ?>
                    <?= $this->Form->postLink(
                        '<i class="fas fa-lock mr-1"></i>' . ($event->finalized_at
                            ? __('Recalculate results')
                            : __('Close & record results')),
                        ['action' => 'finalize', $event->id],
                        [
                            'class' => 'btn btn-warning btn-sm',
                            'escape' => false,
                            'confirm' => __('Record the current standings as this event\'s official result?'),
                        ]
                    ) ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Hero: identity of the event and where it is in its life -->
    <div class="event-hero is-<?= h($state) ?>">
        <div class="event-hero-inner">
            <div class="event-hero-main">
                <span class="event-hero-eyebrow">
                    <i class="fas <?= h($stateIcons[$state]) ?>"></i>
                    <?= h($stateLabels[$state]) ?>
                    <span>&middot;</span>
                    <?= __('Event #{0}', $event->event_number) ?>
                </span>

                <h1><?= h($event->name) ?></h1>

                <?php if (!empty($event->description)): ?>
                    <p class="event-hero-description"><?= h($event->description) ?></p>
                <?php endif; ?>

                <div class="event-hero-meta">
                    <span class="event-chip">
                        <i class="fas fa-bullseye"></i>
                        <?= h($event->criteriaLabel()) ?>
                    </span>
                    <span class="event-chip">
                        <i class="fas fa-play"></i>
                        <?= h($event->starts_at->format('d/m/Y H:i')) ?> UTC
                    </span>
                    <span class="event-chip">
                        <i class="fas fa-stop"></i>
                        <?= h($event->ends_at->format('d/m/Y H:i')) ?> UTC
                    </span>
                    <?php if ($event->finalized_at !== null): ?>
                        <span class="event-chip">
                            <i class="fas fa-certificate"></i>
                            <?= __('Official result recorded') ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($state === Event::STATE_RUNNING || $state === Event::STATE_SCHEDULED): ?>
                    <?php
                    $target = $state === Event::STATE_RUNNING ? $event->ends_at : $event->starts_at;
                    ?>
                    <div class="event-countdown"
                         data-countdown-to="<?= h($target->format('Y-m-d\TH:i:s\Z')) ?>">
                        <div class="event-countdown-cell">
                            <span class="event-countdown-value" data-unit="days">--</span>
                            <span class="event-countdown-label"><?= __('Days') ?></span>
                        </div>
                        <div class="event-countdown-cell">
                            <span class="event-countdown-value" data-unit="hours">--</span>
                            <span class="event-countdown-label"><?= __('Hours') ?></span>
                        </div>
                        <div class="event-countdown-cell">
                            <span class="event-countdown-value" data-unit="minutes">--</span>
                            <span class="event-countdown-label"><?= __('Minutes') ?></span>
                        </div>
                        <div class="event-countdown-cell">
                            <span class="event-countdown-value" data-unit="seconds">--</span>
                            <span class="event-countdown-label"><?= __('Seconds') ?></span>
                        </div>
                        <div class="event-countdown-cell" style="min-width: 130px;">
                            <span class="event-countdown-value" style="font-size: 0.95rem;">
                                <?= $state === Event::STATE_RUNNING ? __('until the end') : __('until the start') ?>
                            </span>
                            <span class="event-countdown-label"><?= __('Time left') ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="event-hero-banner">
                <img src="<?= h($bannerUrl) ?>" alt="<?= h($event->name) ?>">
            </div>
        </div>
    </div>

    <!-- Headline numbers -->
    <div class="event-stats">
        <div class="event-stat is-champion">
            <div class="event-stat-label">
                <i class="fas fa-crown"></i> <?= $state === Event::STATE_RUNNING ? __('Leader') : __('Champion') ?>
            </div>
            <div class="event-stat-value">
                <?= $leader ? h($leader['player']) : '—' ?>
            </div>
            <div class="event-stat-note">
                <?php if ($leader): ?>
                    <?= __('{0} chest(s) collected', $this->Number->format($leader['chest_count'])) ?>
                <?php else: ?>
                    <?= __('Nobody has scored yet') ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="event-stat">
            <div class="event-stat-label">
                <i class="fas fa-bullseye"></i> <?= __('Leader {0}', $event->pointsLabel()) ?>
            </div>
            <div class="event-stat-value">
                <?= $leader ? $this->Number->format($leader['points']) : '0' ?>
            </div>
            <div class="event-stat-note">
                <?= __('Share: {0}%', $leader ? $this->Number->format($leader['participation'], ['places' => 1]) : '0') ?>
            </div>
        </div>

        <div class="event-stat">
            <div class="event-stat-label">
                <i class="fas fa-chart-bar"></i> <?= __('Event Total') ?>
            </div>
            <div class="event-stat-value"><?= $this->Number->format($results['total_points']) ?></div>
            <div class="event-stat-note">
                <?= __('Chests counted: {0}', $this->Number->format($results['total_chests'])) ?>
            </div>
        </div>

        <div class="event-stat">
            <div class="event-stat-label">
                <i class="fas fa-users"></i> <?= __('Participants') ?>
            </div>
            <div class="event-stat-value"><?= $this->Number->format($results['participants']) ?></div>
            <div class="event-stat-note">
                <?= __('Average: {0} pts', $this->Number->format($results['average_points'], ['places' => 1])) ?>
            </div>
        </div>
    </div>

    <!-- What the players need to know -->
    <div class="event-info-grid">
        <div class="event-info-card is-prize">
            <h3><i class="fas fa-gift"></i> <?= __('Prize') ?></h3>
            <p><?= nl2br(h($event->prize)) ?></p>
            <?php if ($rewards): ?>
                <p class="text-muted mt-2" style="font-size: 0.82rem;">
                    <?= $isOfficial
                        ? __('What each player receives is in the ranking below.')
                        : __('The ranking below shows what each player would receive if the event ended now. Administrative accounts never receive a reward.') ?>
                </p>
            <?php endif; ?>
        </div>

        <?php if ($showGoal): ?>
            <?= $this->element('event_goal_summary', [
                'goal' => $goal,
                'metCount' => $prizes['goal_met'],
                'playerCount' => count($rows),
            ]) ?>
        <?php endif; ?>
        <div class="event-info-card is-contact">
            <h3><i class="fas fa-user-check"></i> <?= __('Who to talk to') ?></h3>
            <p><?= h($event->contact_player) ?></p>
            <p class="text-muted mt-2" style="font-size: 0.82rem;">
                <?= __('Look for this player when the event ends to claim the prize.') ?>
            </p>
        </div>

        <div class="event-info-card is-rules">
            <h3><i class="fas fa-balance-scale"></i> <?= __('How it is scored') ?></h3>
            <p><?= h(Event::criteriaHints()[$event->criteria] ?? $event->criteriaLabel()) ?></p>

            <?php if ($event->criteria === Event::CRITERIA_CUSTOM_CHESTS): ?>
                <p class="text-muted mt-2" style="font-size: 0.82rem;">
                    <?= $event->custom_metric === Event::METRIC_COUNT
                        ? __('Ranked by how many of these chests each player collected.')
                        : __('Ranked by the total score of these chests.') ?>
                </p>
                <div class="event-chest-tags">
                    <?php foreach ((array)$event->event_chests as $chest): ?>
                        <span class="event-chest-tag">
                            <?php if (!empty($chest->standard_chest) && !empty($chest->standard_chest->monster)): ?>
                                <i class="fas fa-dragon" style="color:#ec4899;"></i>
                            <?php endif; ?>
                            <?= h($chest->standard_chest->display_name ?? $chest->source) ?>
                            <?php if (!empty($chest->standard_chest)): ?>
                                <span class="tag-score"><?= $this->Number->format($chest->standard_chest->score) ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Standings -->
    <div class="event-standings-card">
        <div class="event-standings-header">
            <h2 class="event-standings-title">
                <i class="fas fa-list-ol text-primary"></i>
                <?= __('Player Ranking') ?>
                <?php if (($results['source'] ?? 'live') === 'snapshot'): ?>
                    <span class="event-highlight is-top" style="margin-left: 6px;">
                        <i class="fas fa-certificate"></i> <?= __('Official result') ?>
                    </span>
                <?php elseif ($state === Event::STATE_RUNNING): ?>
                    <span class="event-highlight is-top" style="margin-left: 6px;">
                        <i class="fas fa-bolt"></i> <?= __('Live') ?>
                    </span>
                <?php endif; ?>
            </h2>
            <?php if ($rows): ?>
                <div class="event-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="eventSearch" placeholder="<?= __('Search player...') ?>"
                           onkeyup="filterEventStandings(this.value)">
                </div>
            <?php endif; ?>
        </div>

        <?php if ($rows): ?>
            <div class="event-table-wrap">
                <table class="event-table" id="eventStandingsTable">
                    <thead>
                        <tr>
                            <th style="width: 80px;"><?= __('Position') ?></th>
                            <th><?= __('Player') ?></th>
                            <th style="width: 150px;"><?= h($event->pointsLabel()) ?></th>
                            <th style="width: 130px;"><?= __('Total Chests') ?></th>
                            <th style="width: 160px;"><?= __('Share') ?></th>
                            <?php if ($showGoal): ?>
                                <th style="width: 150px;"><?= __('Goal') ?></th>
                            <?php endif; ?>
                            <?php foreach ($rewards as $reward): ?>
                                <th style="width: 130px;" title="<?= h($reward->summary()) ?>"><?= h($reward->item_name) ?></th>
                            <?php endforeach; ?>
                            <th style="width: 150px;"><?= __('Highlight') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $position = (int)$row['position'];
                            $rowClass = match ($position) {
                                1 => 'is-first',
                                2 => 'is-second',
                                3 => 'is-third',
                                default => '',
                            };
                            [$badgeClass, $badgeIcon, $badgeText] = match (true) {
                                $position === 1 => ['is-winner', 'fa-trophy', __('Winner')],
                                $position === 2 => ['is-second', 'fa-medal', __('2nd Place')],
                                $position === 3 => ['is-third', 'fa-medal', __('3rd Place')],
                                $position <= 10 => ['is-top', 'fa-star', __('Top 10')],
                                default => ['', 'fa-user', __('Participant')],
                            };
                            $share = (float)$row['participation'];
                            ?>
                            <tr class="<?= $rowClass ?>" data-player="<?= h(mb_strtolower($row['player'])) ?>">
                                <td>
                                    <span class="event-position <?= $position <= 3 ? 'rank-' . $position : '' ?>">
                                        <?= $position ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $this->Html->link(
                                        $row['player'],
                                        [
                                            'controller' => 'PlayerCycleSummaries',
                                            'action' => 'playerHistory',
                                            urlencode($row['player']),
                                        ],
                                        ['class' => 'player-link', 'title' => $row['player']]
                                    ) ?>
                                    <?php if (array_key_exists('eligible', $row) && !$row['eligible']): ?>
                                        <span class="badge badge-light border ml-1" title="<?= h(__('Administrative account: ranked, never rewarded')) ?>"><?= __('administrative') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="event-points"><?= $this->Number->format($row['points']) ?></td>
                                <td><?= $this->Number->format($row['chest_count']) ?></td>
                                <td>
                                    <div class="event-share">
                                        <span class="event-share-bar">
                                            <span class="event-share-fill"
                                                  style="width: <?= min(100, round($share / $topShare * 100)) ?>%;"></span>
                                        </span>
                                        <span><?= $this->Number->format($share, ['places' => 1]) ?>%</span>
                                    </div>
                                </td>
                                <?php if ($showGoal): ?>
                                    <td><?= $this->element('event_goal_cell', [
                                        'value' => $row['goal'] ?? null,
                                        'met' => $row['goal_met'] ?? null,
                                        'level' => (int)($row['guard_level'] ?? 0),
                                        'byGuard' => $goal->isByGuard(),
                                    ]) ?></td>
                                <?php endif; ?>
                                <?php foreach ($rewards as $reward): ?>
                                    <?php $amount = (int)($row['amounts'][$reward->id] ?? 0); ?>
                                    <td class="review-amount <?= $amount > 0 ? '' : 'is-zero' ?>"><?= $this->Number->format($amount) ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <span class="event-highlight <?= $badgeClass ?>">
                                        <i class="fas <?= $badgeIcon ?>"></i> <?= h($badgeText) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="event-empty">
                <i class="fas fa-inbox"></i>
                <?php if ($state === Event::STATE_SCHEDULED): ?>
                    <p><?= __('This event has not started yet. Chests collected from {0} UTC will count.', $event->starts_at->format('d/m/Y H:i')) ?></p>
                <?php elseif ($state === Event::STATE_CANCELLED): ?>
                    <p><?= __('This event was cancelled.') ?></p>
                <?php else: ?>
                    <p><?= __('No chest matching this event has been collected yet.') ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php $this->start('script'); ?>
<script>
    // Filters the ranking without a round trip: the table is one event's players,
    // small enough that the browser can do this instantly.
    function filterEventStandings(term) {
        var needle = (term || '').toLowerCase().trim();
        var rows = document.querySelectorAll('#eventStandingsTable tbody tr');
        rows.forEach(function (row) {
            var player = row.getAttribute('data-player') || '';
            row.style.display = needle === '' || player.indexOf(needle) !== -1 ? '' : 'none';
        });
    }

    // Countdown. The target is rendered as a UTC instant, so every viewer sees
    // the same remaining time no matter what their machine's clock is set to.
    (function () {
        var box = document.querySelector('[data-countdown-to]');
        if (!box) {
            return;
        }

        var target = new Date(box.getAttribute('data-countdown-to')).getTime();
        var cells = {
            days: box.querySelector('[data-unit="days"]'),
            hours: box.querySelector('[data-unit="hours"]'),
            minutes: box.querySelector('[data-unit="minutes"]'),
            seconds: box.querySelector('[data-unit="seconds"]')
        };

        function pad(value) {
            return value < 10 ? '0' + value : String(value);
        }

        function tick() {
            var remaining = target - Date.now();
            if (remaining <= 0) {
                // The window just moved on; the page it describes is now stale.
                window.location.reload();
                return;
            }

            var seconds = Math.floor(remaining / 1000);
            cells.days.textContent = Math.floor(seconds / 86400);
            cells.hours.textContent = pad(Math.floor(seconds % 86400 / 3600));
            cells.minutes.textContent = pad(Math.floor(seconds % 3600 / 60));
            cells.seconds.textContent = pad(seconds % 60);
        }

        tick();
        setInterval(tick, 1000);
    })();
</script>
<?php $this->end(); ?>
