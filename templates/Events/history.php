<?php
/**
 * Event history: what is running, what is next, and everything already done.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Event> $events
 * @var array<\App\Model\Entity\Event> $upcoming
 * @var array<\App\Model\Entity\Event> $running
 */

$this->assign('title', __('Event History'));
?>
<div class="content-page-wrap">

    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title"><i class="fas fa-history text-primary"></i> <?= __('Event History') ?></h1>
            <p class="cycle-subtitle">
                <?php if (!empty($tournamentName)): ?>
                    <?= h(__('Every event of {0} that has been held, newest first', $tournamentName)) ?>
                    &middot; <?= $this->Html->link(__('All events'), ['action' => 'history']) ?>
                <?php else: ?>
                    <?= __('Every event that has been held, newest first') ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-trophy mr-1"></i>' . __('Current Event'),
                ['action' => 'index'],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <?php if (!empty($running)): ?>
        <h2 class="score-title" style="font-size: 1.1rem; margin-bottom: 12px;">
            <i class="fas fa-play-circle text-success"></i> <?= __('Running now') ?>
        </h2>
        <div class="event-history-list mb-4">
            <?php foreach ($running as $event): ?>
                <?= $this->element('event_row', ['event' => $event]) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($upcoming)): ?>
        <h2 class="score-title" style="font-size: 1.1rem; margin-bottom: 12px;">
            <i class="fas fa-hourglass-start text-info"></i> <?= __('Coming up') ?>
        </h2>
        <div class="event-history-list mb-4">
            <?php foreach ($upcoming as $event): ?>
                <?= $this->element('event_row', ['event' => $event]) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="score-title" style="font-size: 1.1rem; margin-bottom: 12px;">
        <i class="fas fa-flag-checkered text-muted"></i> <?= __('Past events') ?>
    </h2>

    <?php if (count($events) > 0): ?>
        <div class="event-history-list">
            <?php foreach ($events as $event): ?>
                <?= $this->element('event_row', ['event' => $event]) ?>
            <?php endforeach; ?>
        </div>

        <div class="d-flex flex-column flex-md-row align-items-center justify-content-between mt-4">
            <div class="text-muted">
                <?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} event(s) out of {{count}} total')) ?>
            </div>
            <ul class="pagination pagination-sm mb-0 ml-auto">
                <?= $this->Paginator->first('<i class="fas fa-angle-double-left"></i>', ['escape' => false]) ?>
                <?= $this->Paginator->prev('<i class="fas fa-angle-left"></i>', ['escape' => false]) ?>
                <?= $this->Paginator->numbers() ?>
                <?= $this->Paginator->next('<i class="fas fa-angle-right"></i>', ['escape' => false]) ?>
                <?= $this->Paginator->last('<i class="fas fa-angle-double-right"></i>', ['escape' => false]) ?>
            </ul>
        </div>
    <?php else: ?>
        <div class="event-standings-card">
            <div class="event-empty">
                <i class="fas fa-calendar-day"></i>
                <p><?= __('No event has finished yet.') ?></p>
            </div>
        </div>
    <?php endif; ?>
</div>
