<?php
/**
 * One tournament of the catalogue: a card per part, each with a pencil to edit
 * it, the clan's results, publishing without review, and the latest events.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\GameTournament $tournament
 * @var list<array{item_id: int, name: string, times: int, by_position: array<int, int>, last_amount: int}> $prizes
 * @var list<\App\Model\Entity\ClanTournamentResult> $clanResults
 * @var iterable<\App\Model\Entity\Event> $events
 * @var array<string, string> $missing
 */

use App\Model\Entity\EventReward;
use App\Model\Entity\GameTournament;
use App\Service\EventGoal;

$this->assign('title', $tournament->displayName());

$goal = EventGoal::fromEntity($tournament);
$ruleLabels = EventReward::ruleOptions();
$notSet = '<span class="text-muted font-italic">' . h(__('Not defined')) . '</span>';
$imageUrl = $this->Url->build(['action' => 'image', $tournament->id, '?' => ['v' => $tournament->modified?->getTimestamp()]]);

/** The pencil of a card. */
$pencil = fn (string $section): string => $this->Html->link(
    '<i class="fas fa-pencil-alt"></i>',
    ['action' => 'edit', $tournament->id, '?' => ['section' => $section]],
    ['class' => 'tournament-card-edit', 'escape' => false, 'title' => __('Edit')]
);
$state = fn (string $section): string => isset($missing[$section]) ? 'is-missing' : 'is-filled';
?>
<style>
    .tournament-cards {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 14px;
        margin-bottom: 18px;
    }
    .tournament-card {
        position: relative;
        background: var(--card-bg, #fff);
        border: 1px solid var(--line-subtle, rgba(128, 128, 128, .2));
        border-left: 4px solid var(--success, #28a745);
        border-radius: 8px;
        padding: 14px 16px;
        min-height: 110px;
    }
    .tournament-card.is-missing {
        border-left-color: var(--warning, #ffc107);
    }
    .tournament-card.is-wide {
        grid-column: 1 / -1;
    }
    .tournament-card h3 {
        font-size: .95rem;
        font-weight: 600;
        margin: 0 28px 8px 0;
    }
    .tournament-card-edit {
        position: absolute;
        top: 10px;
        right: 12px;
        color: var(--text-muted-strong, #6c757d);
    }
    .tournament-card-edit:hover {
        color: var(--accent, #007bff);
    }
    .tournament-card .card-state {
        font-size: .75rem;
        margin-left: 6px;
    }
    .tournament-card .is-filled-icon { color: var(--success, #28a745); }
    .tournament-card .is-missing-icon { color: var(--warning, #ffc107); }
    .tournament-prize-table td,
    .tournament-prize-table th {
        padding: 4px 8px;
        font-size: .85rem;
    }
    .tournament-prize-table .num {
        text-align: right;
        font-variant-numeric: tabular-nums;
    }
    .auto-publish-card {
        border-left-color: var(--accent, #007bff);
    }
</style>

<div class="content-page-wrap">

    <div class="score-toolbar">
        <div class="score-title-group d-flex align-items-center" style="gap: 14px;">
            <?php if ($tournament->has_image): ?>
                <img src="<?= $imageUrl ?>" alt="" class="tournament-thumb is-large">
            <?php endif; ?>
            <div>
                <h1 class="score-title"><i class="fas fa-trophy text-primary"></i> <?= h($tournament->displayName()) ?></h1>
                <p class="cycle-subtitle">
                    <?= __('Game id {0}', $tournament->gameKey()) ?>
                    <?php if ($tournament->ranking !== ''): ?>
                        &middot; <?= h(__('Ranking {0}', $tournament->rankingLabel())) ?>
                    <?php endif; ?>
                    <?php if ($tournament->auto_publish): ?>
                        &middot; <span class="text-success"><i class="fas fa-bolt"></i> <?= __('Published without review') ?></span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-book mr-1"></i>' . __('Tournament Catalogue'),
                ['action' => 'index'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-chart-line mr-1"></i>' . __('Clan Tournament History'),
                ['controller' => 'Members', 'action' => 'tournaments', '?' => ['type' => $tournament->game_type]],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <?php $icon = fn (string $section): string => isset($missing[$section])
        ? '<i class="fas fa-exclamation-circle is-missing-icon card-state" title="' . h(__('Not defined')) . '"></i>'
        : '<i class="fas fa-check-circle is-filled-icon card-state"></i>'; ?>

    <div class="tournament-cards">
        <div class="tournament-card <?= $state(GameTournament::SECTION_NAME) ?>">
            <?= $pencil(GameTournament::SECTION_NAME) ?>
            <h3><?= __('Name') ?><?= $icon(GameTournament::SECTION_NAME) ?></h3>
            <div><small class="text-muted"><?= __('In the game') ?></small><br>
                <?= $tournament->hasGameName() ? '<strong>' . h($tournament->name) . '</strong>' : $notSet ?></div>
            <div class="mt-2"><small class="text-muted"><?= __('Display name') ?></small><br>
                <?= trim((string)$tournament->display_name) !== ''
                    ? h($tournament->display_name)
                    : '<span class="text-muted">' . h(__('The game\'s name')) . '</span>' ?></div>
        </div>

        <div class="tournament-card <?= $state(GameTournament::SECTION_DESCRIPTION) ?>">
            <?= $pencil(GameTournament::SECTION_DESCRIPTION) ?>
            <h3><?= __('Description') ?><?= $icon(GameTournament::SECTION_DESCRIPTION) ?></h3>
            <?= trim((string)$tournament->description) !== '' ? nl2br(h($tournament->description)) : $notSet ?>
        </div>

        <div class="tournament-card <?= $state(GameTournament::SECTION_GOAL) ?>">
            <?= $pencil(GameTournament::SECTION_GOAL) ?>
            <h3><?= __('Goal') ?><?= $icon(GameTournament::SECTION_GOAL) ?></h3>
            <?php if (!$goal->isActive()): ?>
                <?= $notSet ?>
            <?php elseif ($goal->isByGuard()): ?>
                <?= __('By guard level') ?>:
                <?php foreach ($goal->shownLevels() as $level => $points): ?>
                    <span class="badge badge-light"><?= \App\Service\EventGoal::levelLabel((int)$level) ?> <?= $this->Number->format($points) ?></span>
                <?php endforeach; ?>
                <div class="text-muted mt-1" style="font-size: .8rem;"><?= __('Players without an identified level are evaluated by the highest goal.') ?></div>
            <?php else: ?>
                <?= __('{0} points for every player.', $this->Number->format($goal->points)) ?>
            <?php endif; ?>
            <?php if ($goal->isActive()): ?>
                <div class="text-muted mt-1" style="font-size: .8rem;">
                    <?= $goal->isRequired() ? __('Required to receive a reward.') : __('Not required to receive a reward.') ?>
                </div>
            <?php endif; ?>
        </div>

        <?php $fineRule = \App\Service\TournamentFineService::ruleOf($tournament); ?>
        <?php $fineMode = $fineRule[0]; ?>
        <div class="tournament-card is-filled">
            <?= $pencil(GameTournament::SECTION_FINE) ?>
            <h3><?= __('Fine') ?></h3>
            <?= $this->element('fine_rule', ['rule' => $fineRule]) ?>
            <?php if ($fineMode !== \App\Service\TournamentFineService::MODE_NONE && !$goal->isActive()): ?>
                <div class="text-warning mt-1" style="font-size: .8rem;"><?= __('Without a goal nobody is fined.') ?></div>
            <?php endif; ?>
        </div>

        <div class="tournament-card is-wide <?= $state(GameTournament::SECTION_PRIZES) ?>">
            <?= $pencil(GameTournament::SECTION_PRIZES) ?>
            <h3><?= __('Prizes') ?><?= $icon(GameTournament::SECTION_PRIZES) ?></h3>
            <?php $selection = $tournament->prizeSelection(); ?>
            <?php if ($selection !== []): ?>
                <p class="mb-2"><?= __('Split among the players of every new event, with the amount the clan received in it:') ?></p>
                <ul class="mb-2">
                    <?php foreach ($selection as $prize): ?>
                        <li>
                            <strong><?= h($prize['name'] ?: __('Item {0}', $prize['item_id'])) ?></strong>
                            &middot; <?= h(mb_strtolower($ruleLabels[$prize['rule']] ?? $prize['rule'])) ?>
                            &middot; <?= h(__('minimum {0} point(s)', $this->Number->format($prize['min_points']))) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php $extras = $tournament->extraPrizes(); ?>
            <?php if ($extras !== []): ?>
                <p class="mb-2"><?= __('Given by the clan in every new event:') ?></p>
                <ul class="mb-2">
                    <?php foreach ($extras as $prize): ?>
                        <li>
                            <?php if ($prize['silver']): ?>
                                <i class="fas fa-coins text-warning"></i> <strong><?= h(__('Silver')) ?> <?= $this->silver($prize['quantity']) ?></strong>
                            <?php else: ?>
                                <strong><?= h($prize['name']) ?> x<?= $this->gameNumber($prize['quantity']) ?></strong>
                            <?php endif; ?>
                            &middot; <?= h(mb_strtolower($ruleLabels[$prize['rule']] ?? $prize['rule'])) ?>
                            &middot; <?= h(__('minimum {0} point(s)', $this->Number->format($prize['min_points']))) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($selection === [] && $extras === []): ?>
                <p class="mb-2"><?= $notSet ?></p>
            <?php endif; ?>
            <?php if ($prizes !== []): ?>
                <?php $places = []; foreach ($prizes as $p) { $places += array_flip(array_keys($p['by_position'])); } ksort($places); ?>
                <p class="text-muted mb-1" style="font-size: .82rem;"><?= __('What the clan has received in this tournament, by place:') ?></p>
                <div class="table-responsive">
                    <table class="table table-sm tournament-prize-table mb-0">
                        <thead>
                            <tr>
                                <th><?= __('Prize') ?></th>
                                <?php foreach (array_keys($places) as $place): ?>
                                    <th class="num"><?= h(__('#{0}', $place)) ?></th>
                                <?php endforeach; ?>
                                <th class="num"><?= __('Last received') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($prizes as $p): ?>
                                <tr>
                                    <td><?= h($p['name'] ?: __('Item {0}', $p['item_id'])) ?></td>
                                    <?php foreach (array_keys($places) as $place): ?>
                                        <td class="num"><?= isset($p['by_position'][$place]) ? $this->gameNumber($p['by_position'][$place]) : '&ndash;' ?></td>
                                    <?php endforeach; ?>
                                    <td class="num"><?= $this->gameNumber($p['last_amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0" style="font-size: .82rem;">
                    <?= __('The clan has not received any prize in this tournament yet, or the collector has not sent the clan\'s results.') ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($clanResults !== []): ?>
        <div class="event-standings-card">
            <div class="event-standings-header">
                <h2 class="event-standings-title"><i class="fas fa-flag text-primary"></i> <?= __('The clan in this tournament') ?></h2>
            </div>
            <div class="event-table-wrap">
                <table class="event-table">
                    <thead>
                        <tr>
                            <th style="width: 150px;"><?= __('Ended (UTC)') ?></th>
                            <th style="width: 110px;"><?= __('Place') ?></th>
                            <th style="width: 160px;"><?= __('Clan points') ?></th>
                            <th><?= __('Received') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clanResults as $result): ?>
                            <tr>
                                <td style="font-size: .8rem;"><?= h($result->ended_at->format('d/m/Y H:i')) ?></td>
                                <td>
                                    <?= $result->isRanked()
                                        ? '<strong>' . h(__('#{0}', $result->position)) . '</strong>'
                                        : '<span class="text-muted">' . h(__('Not in the top {0}', $result->clans_listed)) . '</span>' ?>
                                </td>
                                <td><?= $result->points !== null ? $this->Number->format($result->points) : '&ndash;' ?></td>
                                <td style="font-size: .85rem;">
                                    <?php $received = $result->rewardList(); ?>
                                    <?= $received === [] ? '<span class="text-muted">' . h(__('Nothing')) . '</span>' : h(implode(', ', array_map(
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

    <div class="tournament-card is-wide auto-publish-card mb-4">
        <h3><i class="fas fa-bolt text-primary mr-1"></i> <?= __('Publish events automatically') ?></h3>
        <p class="mb-2" style="font-size: .88rem;">
            <?= __('When this is on, as soon as the collector sends the ranking of a finished event of this tournament, the goals are worked out and the event is published at once, without review: every player\'s share of the prizes is recorded. A ranking with players the site cannot identify still waits for a review. When this is off, every ranking waits as a draft on the review page.') ?>
        </p>
        <?= $this->Form->create(null, [
            'url' => ['action' => 'autoPublish', $tournament->id],
            'id' => 'autoPublishForm',
        ]) ?>
        <input type="hidden" name="auto_publish" value="0">
        <div class="custom-control custom-switch">
            <input type="checkbox" class="custom-control-input" id="autoPublishSwitch" name="auto_publish" value="1"
                <?= $tournament->auto_publish ? 'checked' : '' ?>
                data-missing="<?= h(implode(', ', $missing)) ?>">
            <label class="custom-control-label" for="autoPublishSwitch"><?= __('Publish this tournament\'s events without review') ?></label>
        </div>
        <?= $this->Form->end() ?>
    </div>

    <?php if (count($events) > 0): ?>
        <div class="event-standings-card">
            <div class="event-standings-header">
                <h2 class="event-standings-title"><i class="fas fa-history text-primary"></i> <?= __('Latest events of this tournament') ?></h2>
            </div>
            <div class="event-table-wrap">
                <table class="event-table">
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td style="width: 70px;"><span class="event-number">#<?= h($event->event_number) ?></span></td>
                                <td><?= $this->Html->link($event->name, ['controller' => 'Events', 'action' => 'review', $event->id]) ?></td>
                                <td style="width: 260px; font-size: 0.8rem;">
                                    <?= h($event->starts_at->format('d/m/Y H:i')) ?> &rarr; <?= h($event->ends_at->format('d/m/Y H:i')) ?> UTC
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    (function () {
        var box = document.getElementById('autoPublishSwitch');
        var form = document.getElementById('autoPublishForm');
        if (!box || !form) {
            return;
        }
        var question = <?= json_encode(__('Missing: {0}.', '%missing%') . "\n\n" . __('Publish the events of this tournament without review all the same?'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>;
        box.addEventListener('change', function () {
            var missing = box.getAttribute('data-missing');
            if (box.checked && missing && !window.confirm(question.replace('%missing%', missing))) {
                box.checked = false;
                return;
            }
            form.submit();
        });
    })();
</script>
