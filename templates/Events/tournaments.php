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
 * @var array{active: list<\App\Model\Entity\LiveTournament>, upcoming: list<\App\Model\Entity\LiveTournament>, read_at: \Cake\I18n\DateTime|null} $live
 * @var array<int, array{tournament: \App\Model\Entity\GameTournament|null, last_event: \App\Model\Entity\Event|null}> $site
 * @var array<int, string> $itemIcons
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
            <?php if ($isAdmin): ?>
                <?= $this->Html->link(
                    '<i class="fas fa-book mr-1"></i>' . __('Tournament Catalogue'),
                    ['controller' => 'GameTournaments', 'action' => 'index'],
                    ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
                ) ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($live['active'] !== [] || $live['upcoming'] !== []): ?>
        <h2 class="score-title live-section-title"><i class="fas fa-play-circle text-success"></i> <?= __('Active') ?></h2>
        <?php if ($live['read_at'] !== null): ?>
            <p class="cycle-subtitle"><?= h(__('Last updated on {0} UTC.', $live['read_at']->format('d/m/Y H:i'))) ?></p>
        <?php endif; ?>
        <div class="live-tournament-list">
            <?php foreach ($live['active'] as $entry): ?>
                <?= $this->element('live_tournament_card', ['live' => $entry, 'site' => $site[$entry->game_key], 'itemIcons' => $itemIcons, 'isAdmin' => $isAdmin]) ?>
            <?php endforeach; ?>
        </div>
        <?php if ($live['upcoming'] !== []): ?>
            <h2 class="score-title live-section-title"><i class="fas fa-hourglass-start text-muted"></i> <?= __('Coming up') ?></h2>
            <div class="live-tournament-list">
                <?php foreach ($live['upcoming'] as $entry): ?>
                    <?= $this->element('live_tournament_card', ['live' => $entry, 'site' => $site[$entry->game_key], 'itemIcons' => $itemIcons, 'isAdmin' => $isAdmin]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($live['active'] === [] && $live['upcoming'] === []): ?>
        <div class="event-standings-card">
            <div class="event-empty">
                <i class="fas fa-book-open"></i>
                <p><?= __('No tournament is registered yet.') ?></p>
            </div>
        </div>
    <?php endif; ?>
</div>
