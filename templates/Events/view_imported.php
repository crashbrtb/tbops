<?php
/**
 * Published result of a game tournament: the ranking and what each player
 * receives of every reward.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Event $event
 * @var array $result
 * @var bool $isAdmin
 */

use App\Model\Entity\EventAsset;
use App\Model\Entity\EventReward;

$this->assign('title', __('Event #{0} - {1}', $event->event_number, $event->name));

$rows = $result['rows'];
$rewards = $result['rewards'];
$champion = null;
foreach ($rows as $row) {
    if ($row['standing']->eligible) {
        $champion = $row['standing'];
        break;
    }
}
// The event's own banner first, then the tournament's image from the catalogue.
$bannerUrl = match (true) {
    $event->has_custom_banner => $this->Url->build(['action' => 'banner', $event->id]),
    $event->game_tournament !== null && $event->game_tournament->has_image => $this->Url->build([
        'controller' => 'GameTournaments', 'action' => 'image', $event->game_tournament->id,
        '?' => ['v' => $event->game_tournament->modified?->getTimestamp()],
    ]),
    default => $this->Url->build(['action' => 'asset', EventAsset::SLUG_NO_EVENT]),
};
$fmt = fn ($n) => $this->Number->format((int)$n);
$goal = $event->goal();
$showGoal = $goal->isActive();
$goalMet = 0;
foreach ($rows as $row) {
    $goalMet += $row['standing']->goal_met ? 1 : 0;
}
?>
<div class="content-page-wrap">

    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-gamepad text-warning"></i> <?= __('Tournament Result') ?>
            </h1>
            <p class="cycle-subtitle"><?= __('Ranking read from the game and the rewards each player receives') ?></p>
        </div>
        <div class="toolbar-actions d-flex flex-wrap" style="gap: 8px;">
            <?= $this->Html->link(
                '<i class="fas fa-history mr-1"></i>' . __('Event History'),
                ['action' => 'history'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
            <?php if ($isAdmin): ?>
                <?= $this->Html->link(
                    '<i class="fas fa-clipboard-check mr-1"></i>' . __('Review'),
                    ['action' => 'review', $event->id],
                    ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
                ) ?>
                <?= $this->Html->link(
                    '<i class="fas fa-coins mr-1"></i>' . __('Edit rewards'),
                    ['action' => 'edit', $event->id],
                    ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
                ) ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="event-hero is-finished">
        <div class="event-hero-inner">
            <div class="event-hero-main">
                <span class="event-hero-eyebrow">
                    <i class="fas fa-flag-checkered"></i> <?= __('Finished') ?>
                    <span>&middot;</span>
                    <?= __('Event #{0}', $event->event_number) ?>
                </span>
                <h1><?= h($event->name) ?></h1>
                <?php if (!empty($event->description)): ?>
                    <p class="event-hero-description"><?= h($event->description) ?></p>
                <?php endif; ?>
                <div class="event-hero-meta">
                    <span class="event-chip">
                        <i class="fas fa-calendar"></i>
                        <?= h($event->starts_at->format('d/m/Y H:i')) ?> &rarr; <?= h($event->ends_at->format('d/m/Y H:i')) ?> UTC
                    </span>
                    <span class="event-chip"><i class="fas fa-certificate"></i>
                        <?= __('Published {0} UTC', $event->published_at->format('d/m/Y H:i')) ?>
                    </span>
                </div>
            </div>
            <div class="event-hero-banner">
                <img src="<?= h($bannerUrl) ?>" alt="<?= h($event->name) ?>">
            </div>
        </div>
    </div>

    <div class="event-stats">
        <div class="event-stat is-champion">
            <div class="event-stat-label"><i class="fas fa-crown"></i> <?= __('Champion') ?></div>
            <div class="event-stat-value"><?= $champion ? h($champion->player) : '—' ?></div>
            <div class="event-stat-note"><?= $champion ? __('{0} points', $fmt($champion->points)) : '' ?></div>
        </div>
        <div class="event-stat">
            <div class="event-stat-label"><i class="fas fa-users"></i> <?= __('Participants') ?></div>
            <div class="event-stat-value"><?= $fmt($result['players']) ?></div>
            <div class="event-stat-note"><?= __('{0} receive a reward', $fmt($result['recipients'])) ?></div>
        </div>
        <div class="event-stat">
            <div class="event-stat-label"><i class="fas fa-chart-bar"></i> <?= __('Clan Total') ?></div>
            <div class="event-stat-value"><?= $fmt($result['points']) ?></div>
            <div class="event-stat-note"><?= __('points in the tournament') ?></div>
        </div>
    </div>

    <div class="event-info-grid">
        <div class="event-info-card is-prize">
            <h3><i class="fas fa-coins"></i> <?= __('Rewards') ?></h3>
            <?php if ($rewards): ?>
                <ul class="review-reward-list">
                    <?php foreach ($rewards as $reward): ?>
                        <li>
                            <strong><?= $reward->rule === EventReward::RULE_POSITION ? h($reward->summary()) : $fmt($result['totals'][$reward->id] ?? 0) . ' &times; ' . h($reward->item_name) ?></strong>
                            <span class="text-muted">&middot; <?= h(EventReward::ruleOptions()[$reward->rule] ?? $reward->rule) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted"><?= __('This tournament has no rewards.') ?></p>
            <?php endif; ?>
        </div>
        <?php if ($showGoal): ?>
            <?= $this->element('event_goal_summary', [
                'goal' => $goal,
                'metCount' => $goalMet,
                'playerCount' => count($rows),
            ]) ?>
        <?php endif; ?>
        <div class="event-info-card is-contact">
            <h3><i class="fas fa-user-check"></i> <?= __('Who hands out the rewards') ?></h3>
            <p><?= h($event->contact_player) ?></p>
            <p class="text-muted mt-2" style="font-size: 0.82rem;">
                <?= __('Rewards are delivered in the game. Look for this player if yours has not arrived.') ?>
            </p>
        </div>
    </div>

    <div class="event-standings-card">
        <div class="event-standings-header">
            <h2 class="event-standings-title">
                <i class="fas fa-list-ol text-primary"></i> <?= __('Player Ranking') ?>
                <span class="event-highlight is-top" style="margin-left: 6px;">
                    <i class="fas fa-certificate"></i> <?= __('Official result') ?>
                </span>
            </h2>
            <div class="event-search">
                <i class="fas fa-search"></i>
                <input type="text" id="eventSearch" placeholder="<?= __('Search player...') ?>" onkeyup="filterEventStandings(this.value)">
            </div>
        </div>

        <div class="event-table-wrap">
            <table class="event-table" id="eventStandingsTable">
                <thead>
                    <tr>
                        <th style="width: 80px;"><?= __('Position') ?></th>
                        <th><?= __('Player') ?></th>
                        <th style="width: 160px;"><?= __('Points') ?></th>
                        <th style="width: 90px;"><?= __('Share') ?></th>
                        <?php if ($showGoal): ?>
                            <th style="width: 150px;"><?= __('Goal') ?></th>
                        <?php endif; ?>
                        <?php foreach ($rewards as $reward): ?>
                            <th style="width: 130px;" title="<?= h($reward->summary()) ?>"><?= h($reward->item_name) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $standing = $row['standing'];
                        $position = (int)$standing->position;
                        $rowClass = match ($position) {
                            1 => 'is-first',
                            2 => 'is-second',
                            3 => 'is-third',
                            default => '',
                        };
                        ?>
                        <tr class="<?= $rowClass ?> <?= $standing->eligible ? '' : 'is-excluded' ?>" data-player="<?= h(mb_strtolower($standing->player)) ?>">
                            <td><span class="event-position <?= $position <= 3 ? 'rank-' . $position : '' ?>"><?= $position ?></span></td>
                            <td>
                                <?= h($standing->player) ?>
                                <?php if (!$standing->eligible): ?>
                                    <span class="event-highlight" style="margin-left: 6px;">
                                        <i class="fas fa-user-shield"></i> <?= __('No reward') ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="event-points"><?= $fmt($standing->points) ?></td>
                            <td><?= $standing->eligible ? $this->Number->format($standing->participation, ['places' => 2]) . '%' : '—' ?></td>
                            <?php if ($showGoal): ?>
                                <td><?= $this->element('event_goal_cell', [
                                    'value' => $standing->goal,
                                    'met' => $standing->goal_met,
                                    'level' => (int)$standing->guard_level,
                                    'byGuard' => $goal->isByGuard(),
                                ]) ?></td>
                            <?php endif; ?>
                            <?php foreach ($rewards as $reward): ?>
                                <?php $amount = $row['amounts'][$reward->id] ?? 0; ?>
                                <td class="review-amount <?= $amount > 0 ? '' : 'is-zero' ?>"><?= $fmt($amount) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php $this->start('script'); ?>
<script>
    function filterEventStandings(term) {
        var needle = (term || '').toLowerCase().trim();
        document.querySelectorAll('#eventStandingsTable tbody tr').forEach(function (row) {
            var player = row.getAttribute('data-player') || '';
            row.style.display = needle === '' || player.indexOf(needle) !== -1 ? '' : 'none';
        });
    }
</script>
<?php $this->end(); ?>
