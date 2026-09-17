<?php
/**
 * Edit one tournament of the catalogue.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\GameTournament $tournament
 * @var iterable<\App\Model\Entity\Event> $events
 */

$this->assign('title', __('Edit Tournament'));
?>
<div class="content-page-wrap">

    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title"><i class="fas fa-pen text-primary"></i> <?= h($tournament->displayName()) ?></h1>
            <p class="cycle-subtitle">
                <?= __('Game id {0}', $tournament->game_type) ?>
                <?php if ($tournament->ranking !== ''): ?>
                    &middot; <?= h(__('Ranking {0}', $tournament->rankingLabel())) ?>
                <?php endif; ?>
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

    <?= $this->Form->create($tournament, ['type' => 'file']) ?>
    <div class="event-form-card">
        <div class="event-form-grid">
            <div class="form-group">
                <?= $this->Form->control('name', [
                    'label' => __('Name'),
                    'class' => 'form-control',
                    'maxlength' => 120,
                    'placeholder' => __('As it appears in the game'),
                ]) ?>
                <small class="form-text text-muted">
                    <?= __('Filled in by the tournament mapper. A name changed here is kept: the mapper will not overwrite it.') ?>
                </small>
            </div>

            <div class="form-group">
                <?= $this->Form->control('duration_days', [
                    'label' => __('Duration (days)'),
                    'type' => 'number',
                    'min' => 1,
                    'max' => 60,
                    'class' => 'form-control',
                    'placeholder' => '1',
                ]) ?>
                <small class="form-text text-muted">
                    <?= __('How long the tournament runs. A new event starts this many days before the end the game reports; one day when empty.') ?>
                </small>
            </div>

            <div class="form-group full-width">
                <label><?= __('Image') ?></label>
                <div class="d-flex align-items-center flex-wrap" style="gap: 16px;">
                    <?php if ($tournament->has_image): ?>
                        <img src="<?= $this->Url->build(['action' => 'image', $tournament->id, '?' => ['v' => $tournament->modified?->getTimestamp()]]) ?>"
                             alt="" class="tournament-thumb is-large">
                    <?php endif; ?>
                    <div>
                        <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control-file">
                        <small class="form-text text-muted"><?= __('PNG, JPEG, WebP or GIF, up to 1 MB.') ?></small>
                        <?php if ($tournament->has_image): ?>
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="remove-image" name="remove_image" value="1">
                                <label class="form-check-label" for="remove-image"><?= __('Remove the image') ?></label>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="event-form-actions">
        <?= $this->Html->link(__('Cancel'), ['action' => 'index'], ['class' => 'btn btn-default spacer']) ?>
        <?= $this->Form->button('<i class="fas fa-save mr-1"></i>' . __('Save changes'), ['class' => 'btn btn-primary', 'escapeTitle' => false]) ?>
    </div>
    <?= $this->Form->end() ?>

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
