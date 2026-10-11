<?php
/**
 * Every alert sent to the administrators, read or not.
 *
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\AdminNotification> $notifications
 */

$this->assign('title', __('Notifications'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('Config'), 'url' => ['controller' => 'Config', 'action' => 'index']],
    ['title' => __('Notifications')],
]);
?>

<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center">
        <span class="text-muted mr-auto">
            <?= __('Every alert sent to the administrators. Player names the chest counter cannot tell apart are checked whenever the members change.') ?>
        </span>
        <span class="mt-2 mt-md-0">
            <?= $this->Form->postLink(
                '<i class="fas fa-sync-alt"></i> ' . __('Check now'),
                ['action' => 'check'],
                ['class' => 'btn btn-sm btn-primary', 'escapeTitle' => false]
            ) ?>
            <?= $this->Form->postLink(
                '<i class="fas fa-check-double"></i> ' . __('Mark all as read'),
                ['action' => 'markAllRead'],
                ['class' => 'btn btn-sm btn-default', 'escapeTitle' => false]
            ) ?>
        </span>
    </div>
    <div class="card-body table-responsive p-0">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th><?= __('Date') ?></th>
                    <th><?= __('Notification') ?></th>
                    <th><?= __('Status') ?></th>
                    <th class="text-right"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $empty = true; ?>
                <?php foreach ($notifications as $notification): ?>
                    <?php $empty = false; ?>
                    <tr>
                        <td class="text-nowrap"><?= h($notification->created?->nice()) ?></td>
                        <td>
                            <?php if ($notification->read_at === null): ?>
                                <i class="fas fa-exclamation-circle text-danger"></i>
                            <?php endif; ?>
                            <?= h($notification->message()) ?>
                            <div class="small">
                                <?php foreach ($notification->members() as $member): ?>
                                    <?= $this->Html->link(
                                        $member['player'] . ' (#' . $member['id'] . ')',
                                        ['controller' => 'Members', 'action' => 'view', $member['id']],
                                        ['class' => 'mr-2']
                                    ) ?>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($notification->read_at === null): ?>
                                <span class="badge badge-danger"><?= __('Unread') ?></span>
                            <?php elseif ($notification->read_by === null): ?>
                                <span class="text-muted"><?= __('No longer applies since {0}', $notification->read_at->nice()) ?></span>
                            <?php else: ?>
                                <span class="text-muted"><?= __(
                                    'Read by {0} on {1}',
                                    h($notification->reader?->username ?? '#' . $notification->read_by),
                                    $notification->read_at->nice()
                                ) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <?php if ($notification->read_at === null): ?>
                                <?= $this->Form->postLink(
                                    '<i class="fas fa-check"></i> ' . __('Mark as read'),
                                    ['action' => 'markRead', $notification->id],
                                    ['class' => 'btn btn-xs btn-outline-secondary', 'escapeTitle' => false]
                                ) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($empty): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted"><?= __('No notifications yet.') ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex flex-column flex-md-row align-items-center justify-content-between">
        <div class="text-muted small mb-2 mb-md-0">
            <?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?>
        </div>
        <ul class="pagination pagination-sm mb-0">
            <?= $this->Paginator->first('<i class="fas fa-angle-double-left"></i>', ['escape' => false]) ?>
            <?= $this->Paginator->prev('<i class="fas fa-angle-left"></i>', ['escape' => false]) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next('<i class="fas fa-angle-right"></i>', ['escape' => false]) ?>
            <?= $this->Paginator->last('<i class="fas fa-angle-double-right"></i>', ['escape' => false]) ?>
        </ul>
    </div>
</div>
