<?php
/**
 * The game's tournaments and the goal of each one, as the players read them.
 *
 * The banner is the game's own artwork for the tournament, kept in
 * webroot/img/tournaments under the game's type id; a tournament without one
 * shows its catalogue icon over a plain background instead.
 *
 * @var \App\View\AppView $this
 * @var array<\App\Model\Entity\GameTournament> $tournaments
 * @var array<int, \App\Model\Entity\Event> $lastResults
 * @var bool $isAdmin
 */

use App\Service\EventGoal;

$this->assign('title', __('Tournaments and Goals'));
?>
<div class="content-page-wrap">

    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title"><i class="fas fa-bullseye text-primary"></i> <?= __('Tournaments and Goals') ?></h1>
            <p class="cycle-subtitle"><?= __('The game\'s tournaments and the goal each player has in them') ?></p>
        </div>
        <div class="toolbar-actions d-flex flex-wrap" style="gap: 8px;">
            <?= $this->Html->link(
                '<i class="fas fa-history mr-1"></i>' . __('Event History'),
                ['action' => 'history'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
            <?php if ($isAdmin): ?>
                <?= $this->Html->link(
                    '<i class="fas fa-book mr-1"></i>' . __('Tournament Catalogue'),
                    ['controller' => 'GameTournaments', 'action' => 'index'],
                    ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
                ) ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($tournaments): ?>
        <div class="tournament-grid">
            <?php foreach ($tournaments as $tournament): ?>
                <?php
                $goal = EventGoal::fromEntity($tournament);
                $banner = 'tournaments/' . (int)$tournament->game_type . '.png';
                $hasBanner = is_file(WWW_ROOT . 'img' . DS . str_replace('/', DS, $banner));
                $iconUrl = $tournament->has_image
                    ? $this->Url->build([
                        'controller' => 'GameTournaments', 'action' => 'image', $tournament->id,
                        '?' => ['v' => $tournament->modified?->getTimestamp()],
                    ])
                    : null;
                $lastResult = $lastResults[$tournament->id] ?? null;
                ?>
                <article class="tournament-card">
                    <div class="tournament-card-banner<?= $hasBanner ? '' : ' is-plain' ?>">
                        <?php if ($hasBanner): ?>
                            <?= $this->Html->image($banner, ['alt' => '', 'loading' => 'lazy']) ?>
                        <?php endif; ?>
                        <?php if ($iconUrl !== null): ?>
                            <img src="<?= h($iconUrl) ?>" alt="" class="tournament-card-icon">
                        <?php endif; ?>
                    </div>

                    <div class="tournament-card-body">
                        <h2 class="tournament-card-title"><?= h($tournament->displayName()) ?></h2>
                        <div class="event-history-meta">
                            <span><i class="fas fa-clock"></i> <?= __('{0} day(s)', $tournament->effectiveDurationDays()) ?></span>
                            <?php if ($goal->isActive()): ?>
                                <span class="tournament-goal-flag<?= $goal->isRequired() ? ' is-required' : '' ?>">
                                    <i class="fas <?= $goal->isRequired() ? 'fa-lock' : 'fa-lock-open' ?>"></i>
                                    <?= $goal->isRequired() ? __('Required for rewards') : __('Does not decide rewards') ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!$goal->isActive()): ?>
                            <p class="tournament-goal-none"><i class="fas fa-minus-circle"></i> <?= __('No goal set for this tournament.') ?></p>
                        <?php elseif ($goal->isByGuard()): ?>
                            <div class="tournament-goal-label"><?= __('Goal by guard level') ?></div>
                            <div class="tournament-goal-levels">
                                <?php foreach ($goal->levelTable() as $level => $points): ?>
                                    <div class="tournament-goal-level" title="<?= $level === 0 ? h(__('Guard level not identified')) : '' ?>">
                                        <span class="level-name">G<?= $level ?></span>
                                        <span class="level-points"><?= $this->Number->format($points) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="tournament-goal-label"><?= __('Goal for every player') ?></div>
                            <div class="tournament-goal-single"><?= __('{0} points', $this->Number->format($goal->points)) ?></div>
                        <?php endif; ?>
                    </div>

                    <?php if ($lastResult !== null || $isAdmin): ?>
                        <div class="tournament-card-footer">
                            <?php if ($lastResult !== null): ?>
                                <?= $this->Html->link(
                                    '<i class="fas fa-trophy mr-1"></i>' . __('Last result') . ' &middot; ' . h($lastResult->ends_at->format('d/m/Y')),
                                    ['action' => 'view', $lastResult->id],
                                    ['escape' => false]
                                ) ?>
                            <?php endif; ?>
                            <?php if ($isAdmin): ?>
                                <?= $this->Html->link(
                                    '<i class="fas fa-pen"></i>',
                                    ['controller' => 'GameTournaments', 'action' => 'edit', $tournament->id],
                                    ['class' => 'btn btn-outline-primary btn-xs ml-auto', 'escape' => false, 'title' => __('Edit')]
                                ) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="event-standings-card">
            <div class="event-empty">
                <i class="fas fa-book-open"></i>
                <p><?= __('No tournament is registered yet.') ?></p>
            </div>
        </div>
    <?php endif; ?>
</div>
