<?php
/**
 * The catalogue of the game's tournaments.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\GameTournament> $tournaments
 * @var array<int, int> $counts
 */

use App\Model\Entity\GameTournament;

$this->assign('title', __('Tournament Catalogue'));

$sourceLabels = [
    GameTournament::SOURCE_JOURNAL => __('read from the Journal'),
    GameTournament::SOURCE_UPLOADER => __('typed in the uploader'),
    GameTournament::SOURCE_MANUAL => __('set by hand'),
];
?>
<div class="content-page-wrap">

    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title"><i class="fas fa-book text-primary"></i> <?= __('Tournament Catalogue') ?></h1>
            <p class="cycle-subtitle">
                <?= __('The game\'s tournaments. Names and ids are filled in by the tournament mapper; the image and the duration are set here.') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-list mr-1"></i>' . __('Manage Events'),
                ['controller' => 'Events', 'action' => 'manage'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <div class="event-standings-card">
        <?php if (count($tournaments) > 0): ?>
            <div class="event-table-wrap">
                <table class="event-table">
                    <thead>
                        <tr>
                            <th style="width: 72px;"><?= __('Image') ?></th>
                            <th><?= __('Tournament') ?></th>
                            <th style="width: 110px;"><?= __('Game id') ?></th>
                            <th style="width: 120px;"><?= __('Duration') ?></th>
                            <th style="width: 150px;"><?= __('Last seen (UTC)') ?></th>
                            <th style="width: 90px;"><?= __('Events') ?></th>
                            <th style="width: 80px;"><?= __('Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tournaments as $tournament): ?>
                            <tr>
                                <td>
                                    <?php if ($tournament->has_image): ?>
                                        <img src="<?= $this->Url->build(['action' => 'image', $tournament->id, '?' => ['v' => $tournament->modified?->getTimestamp()]]) ?>"
                                             alt="" class="tournament-thumb">
                                    <?php else: ?>
                                        <span class="tournament-thumb is-empty"><i class="fas fa-image"></i></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($tournament->name): ?>
                                        <strong><?= h($tournament->name) ?></strong>
                                        <small class="d-block text-muted"><?= h($sourceLabels[$tournament->name_source] ?? '') ?></small>
                                    <?php else: ?>
                                        <span class="text-warning"><i class="fas fa-question-circle"></i> <?= __('Name not mapped yet') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code><?= h($tournament->game_type) ?></code>
                                    <?php if ($tournament->ranking !== ''): ?>
                                        <small class="d-block text-muted text-break" title="<?= h($tournament->ranking) ?>"><?= h($tournament->rankingLabel()) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($tournament->duration_days): ?>
                                        <?= __('{0} day(s)', $tournament->duration_days) ?>
                                    <?php else: ?>
                                        <span class="text-muted"><?= __('not set (1 day)') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.8rem;"><?= h($tournament->last_seen_at?->format('d/m/Y H:i')) ?></td>
                                <td><?= $this->Number->format($counts[$tournament->id] ?? 0) ?></td>
                                <td>
                                    <?= $this->Html->link(
                                        '<i class="fas fa-pen"></i>',
                                        ['action' => 'edit', $tournament->id],
                                        ['class' => 'btn btn-outline-primary btn-xs', 'escape' => false, 'title' => __('Edit')]
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="event-empty">
                <i class="fas fa-book-open"></i>
                <p><?= __('The catalogue is empty. Run the tournament mapper, or send a tournament with the EventUploader.') ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>
