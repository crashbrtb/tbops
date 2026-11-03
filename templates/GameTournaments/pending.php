<?php
/**
 * The tournaments waiting for a name, each with a picture of the Journal.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\GameTournament> $tournaments
 */

$this->assign('title', __('Pending Tournaments'));
?>
<div class="content-page-wrap">

    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title"><i class="fas fa-question-circle text-primary"></i> <?= __('Pending Tournaments') ?></h1>
            <p class="cycle-subtitle">
                <?= __('The game only sends a number for a tournament. Read its name in the picture: its rankings are not sent until it has one, and the search only looks back as far as the uploader\'s "Days to look back".') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-book mr-1"></i>' . __('Tournament Catalogue'),
                ['action' => 'index'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <?php foreach ($tournaments as $tournament): ?>
        <div class="card card-primary card-outline">
            <div class="card-header">
                <h2 class="card-title"><?= h($tournament->displayName()) ?></h2>
            </div>
            <div class="row no-gutters">
                <div class="col-md-8 p-3 text-center">
                    <?php if ((int)$tournament->get('picture_bytes') > 0): ?>
                        <img src="<?= $this->Url->build(['action' => 'picture', $tournament->id]) ?>"
                             alt="<?= h(__('The Journal page of this tournament')) ?>" class="img-fluid border rounded">
                        <p class="text-muted mt-2 mb-0">
                            <?= __('The card of this tournament is outlined. Check that its date is the one beside the picture, and that it is a results card.') ?>
                        </p>
                    <?php else: ?>
                        <div class="p-5 text-muted">
                            <i class="fas fa-image fa-2x mb-2"></i><br>
                            <?= __('The uploader could not take a picture of it yet: it tries again on the next search.') ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 p-3">
                    <dl class="mb-3">
                        <dt><?= __('Game id') ?></dt>
                        <dd><?= h($tournament->game_type) ?></dd>
                        <?php if ($tournament->ranking): ?>
                            <dt><?= __('Ranking') ?></dt>
                            <dd><?= h($tournament->rankingLabel()) ?></dd>
                        <?php endif; ?>
                        <dt><?= __('Last seen (UTC)') ?></dt>
                        <dd><?= $tournament->last_seen_at ? h($tournament->last_seen_at->format('Y-m-d H:i')) : '—' ?></dd>
                    </dl>
                    <?= $this->Form->create(null, ['url' => ['action' => 'assignName', $tournament->id]]) ?>
                    <?= $this->Form->control('name', [
                        'label' => __('Tournament'),
                        'class' => 'form-control',
                        'maxlength' => 120,
                        'required' => true,
                    ]) ?>
                    <p class="text-muted">
                        <?= __('This name is used for every tournament of this code from now on.') ?>
                    </p>
                    <?= $this->Form->button(__('Save the name'), ['class' => 'btn btn-success']) ?>
                    <?= $this->Form->end() ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (count($tournaments) === 0): ?>
        <div class="event-standings-card">
            <p class="text-muted p-4 mb-0"><?= __('No tournament is waiting for a name.') ?></p>
        </div>
    <?php endif; ?>
</div>
