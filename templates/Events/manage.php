<?php
/**
 * Administration list: every event in one place, with the actions that apply to
 * each one given its state.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Event> $events
 * @var int $nextNumber
 */

use App\Model\Entity\Event;

$this->assign('title', __('Manage Events'));

$stateLabels = [
    Event::STATE_RUNNING => __('Running'),
    Event::STATE_SCHEDULED => __('Scheduled'),
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
?>
<div class="content-page-wrap">

    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title"><i class="fas fa-calendar-check text-primary"></i> <?= __('Manage Events') ?></h1>
            <p class="cycle-subtitle"><?= __('The next event created will be #{0}', $nextNumber) ?></p>
        </div>
        <div class="toolbar-actions d-flex flex-wrap" style="gap: 8px;">
            <?= $this->Html->link(
                '<i class="fas fa-image mr-1"></i>' . __('Event Banners'),
                ['action' => 'settings'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-book mr-1"></i>' . __('Tournament Catalogue'),
                ['controller' => 'GameTournaments', 'action' => 'index'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-key mr-1"></i>' . __('API Tokens'),
                ['controller' => 'ApiTokens', 'action' => 'index'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
            <?= $this->Html->link(
                '<i class="fas fa-plus mr-1"></i>' . __('New Event'),
                ['action' => 'add'],
                ['class' => 'btn btn-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <div class="event-standings-card">
        <div class="event-standings-header">
            <h2 class="event-standings-title">
                <i class="fas fa-list text-primary"></i> <?= __('All events') ?>
            </h2>
        </div>

        <?php if (count($events) > 0): ?>
            <div class="event-table-wrap">
                <table class="event-table">
                    <thead>
                        <tr>
                            <th style="width: 70px;"><?= $this->Paginator->sort('event_number', '#') ?></th>
                            <th><?= $this->Paginator->sort('name', __('Event')) ?></th>
                            <th style="width: 180px;"><?= __('Criteria') ?></th>
                            <th style="width: 210px;"><?= $this->Paginator->sort('starts_at', __('Window (UTC)')) ?></th>
                            <th style="width: 130px;"><?= __('State') ?></th>
                            <th style="width: 210px;"><?= __('Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <?php $state = $event->state; ?>
                            <tr>
                                <td><span class="event-number">#<?= h($event->event_number) ?></span></td>
                                <td>
                                    <?= $this->Html->link($event->name, ['action' => 'view', $event->id], ['class' => 'player-link']) ?>
                                    <?php if ($event->finalized_at !== null): ?>
                                        <small class="d-block text-muted">
                                            <i class="fas fa-certificate"></i> <?= __('Result recorded') ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.82rem;"><?= h($event->criteriaLabel()) ?></td>
                                <td style="font-size: 0.8rem;">
                                    <?php if ($event->is_imported): ?>
                                        <?= h($event->starts_at->format('d/m/Y')) ?>
                                    <?php else: ?>
                                        <?= h($event->starts_at->format('d/m/Y H:i')) ?><br>
                                        <span class="text-muted"><?= h($event->ends_at->format('d/m/Y H:i')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="event-state state-<?= h($state) ?>">
                                        <i class="fas <?= h($stateIcons[$state]) ?>"></i> <?= h($stateLabels[$state]) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-center flex-wrap" style="gap: 5px;">
                                        <?= $this->Html->link(
                                            '<i class="fas fa-pen"></i>',
                                            ['action' => 'edit', $event->id],
                                            ['class' => 'btn btn-outline-primary btn-xs', 'escape' => false, 'title' => __('Edit')]
                                        ) ?>

                                        <?php if ($event->is_imported): ?>
                                            <?= $this->Html->link(
                                                '<i class="fas fa-clipboard-check"></i>',
                                                ['action' => 'review', $event->id],
                                                [
                                                    'class' => 'btn btn-xs ' . ($state === Event::STATE_AWAITING ? 'btn-success' : 'btn-outline-primary'),
                                                    'escape' => false,
                                                    'title' => __('Review result'),
                                                ]
                                            ) ?>
                                        <?php endif; ?>

                                        <?php if (!$event->is_imported): ?>
                                            <?= $this->Form->postLink(
                                                '<i class="fas fa-copy"></i>',
                                                ['action' => 'duplicate', $event->id],
                                                [
                                                    'class' => 'btn btn-outline-primary btn-xs',
                                                    'escape' => false,
                                                    'title' => __('Duplicate'),
                                                ]
                                            ) ?>
                                        <?php endif; ?>
                                        <?php if (!$event->is_imported && ($state === Event::STATE_FINISHED || $state === Event::STATE_CANCELLED)): ?>
                                            <?= $this->Form->postLink(
                                                '<i class="fas fa-lock"></i>',
                                                ['action' => 'finalize', $event->id],
                                                [
                                                    'class' => 'btn btn-warning btn-xs',
                                                    'escape' => false,
                                                    'title' => $event->finalized_at
                                                        ? __('Recalculate the recorded result')
                                                        : __('Close and record the result'),
                                                    'confirm' => __('Record the current standings as the official result of event #{0}?', $event->event_number),
                                                ]
                                            ) ?>
                                        <?php endif; ?>

                                        <?= $this->Form->postLink(
                                            $event->status === Event::STATUS_CANCELLED
                                                ? '<i class="fas fa-undo"></i>'
                                                : '<i class="fas fa-ban"></i>',
                                            ['action' => 'cancel', $event->id],
                                            [
                                                'class' => 'btn btn-outline-primary btn-xs',
                                                'escape' => false,
                                                'title' => $event->status === Event::STATUS_CANCELLED
                                                    ? __('Reactivate')
                                                    : __('Cancel'),
                                                'confirm' => $event->status === Event::STATUS_CANCELLED
                                                    ? __('Make event #{0} active again?', $event->event_number)
                                                    : __('Cancel event #{0}?', $event->event_number),
                                            ]
                                        ) ?>

                                        <?= $this->Form->postLink(
                                            '<i class="fas fa-trash"></i>',
                                            ['action' => 'delete', $event->id],
                                            [
                                                'class' => 'btn btn-danger btn-xs',
                                                'escape' => false,
                                                'title' => __('Delete'),
                                                'confirm' => __('Delete event #{0} and its recorded results for good?', $event->event_number),
                                            ]
                                        ) ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-footer d-flex flex-column flex-md-row align-items-center justify-content-between">
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
            <div class="event-empty">
                <i class="fas fa-calendar-plus"></i>
                <p><?= __('No event has been created yet.') ?></p>
                <?= $this->Html->link(
                    '<i class="fas fa-plus mr-1"></i>' . __('Create the first event'),
                    ['action' => 'add'],
                    ['class' => 'btn btn-primary btn-sm mt-2', 'escape' => false]
                ) ?>
            </div>
        <?php endif; ?>
    </div>
</div>
