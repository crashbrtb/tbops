<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\StandardChest[]|\Cake\Collection\CollectionInterface $standardChests
 * @var \App\Model\Entity\Config $referencegoalConfig
 * @var \App\Model\Entity\Config $epicGoalConfig
 * @var string $showAll
 */

$showAllParam = $this->request->getQuery('show_all', '0');

$buttonLinkParams = $this->request->getQueryParams();
$buttonText = '';

if ($showAllParam === '1') {
    $buttonText = __('Show Only Scored');
    unset($buttonLinkParams['show_all']);
} else {
    $buttonText = __('Show All Chests');
    $buttonLinkParams['show_all'] = '1';
}

if (isset($buttonLinkParams['page'])) {
    unset($buttonLinkParams['page']);
}

$toggleShowAllLink = $this->Url->build(['prefix' => false, 'controller' => 'StandardChests', 'action' => 'weights', '?' => $buttonLinkParams]);
?>

<div class="content-page-wrap">
    <div class="score-toolbar">
        <div>
            <h1 class="score-title"><?= __('Chest Weights & Goals') ?></h1>
            <p class="cycle-subtitle"><?= __('Score values and points assigned to each chest source') ?></p>
        </div>
        <div class="d-flex align-items-center">
            <?= $this->Html->link('<i class="fas fa-filter mr-1"></i> ' . $buttonText, $toggleShowAllLink, ['class' => 'btn btn-outline-primary btn-sm mr-2', 'escape' => false]) ?>
            <?= $this->Paginator->limitControl([], null, [
                'label' => false,
                'class' => 'form-control form-control-sm',
                'templates' => ['inputContainer' => '{{content}}']
            ]); ?>
        </div>
    </div>

    <?php if (!empty($guardGoalTable)): ?>
    <div class="goal-pill">
        <?= __('Goals by guard level') ?>:
        <?php foreach ($guardGoalTable as $level => $goals): ?>
            <?php if ($level === 0) { continue; } ?>
            <span class="text-nowrap ml-2">G<?= $level ?> <?= $this->Number->format($goals['total']) ?> / <?= $this->Number->format($goals['epic']) ?></span>
        <?php endforeach; ?>
        <span class="text-nowrap ml-2"><?= __('G0 (unknown): highest goal') ?></span>
    </div>
    <?php else: ?>
    <div class="goal-pill">
        <?= __('Current Goal: {0} chest points and {1} Epic chest points', $this->Number->format($referencegoalConfig->value ?? 0), $this->Number->format($epicGoalConfig->value ?? 0)) ?>
    </div>
    <?php endif; ?>

    <div class="card ranking-card">
        <div class="ranking-header d-flex justify-content-between align-items-center">
            <h3 class="card-title"><?= __('Chest Scoring Table') ?></h3>
        </div>
        <div class="card-body table-responsive p-0">
            <table class="table ranking-table table-hover">
                <thead>
                    <tr>
                        <th class="text-left"><?= $this->Paginator->sort('source', __('Chest Source')) ?></th>
                        <th class="text-center"><?= $this->Paginator->sort('score', __('Score Value')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($standardChests as $standardChest) : ?>
                        <tr>
                            <td class="text-left font-weight-bold">
                                <?= h($standardChest->display_name) ?>
                                <?php if (!empty($standardChest->alias)) : ?>
                                    <small class="d-block text-muted font-weight-normal"><?= h($standardChest->source) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center font-weight-bold" style="color: <?= (int)$standardChest->score > 0 ? 'var(--accent)' : 'var(--muted)' ?>;">
                                <?= $this->Number->format($standardChest->score) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-column flex-md-row align-items-center justify-content-between">
            <div class="text-muted">
                <?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?>
            </div>
            <ul class="pagination pagination-sm mb-0 ml-auto">
                <?= $this->Paginator->first('<i class="fas fa-angle-double-left"></i>', ['escape' => false]) ?>
                <?= $this->Paginator->prev('<i class="fas fa-angle-left"></i>', ['escape' => false]) ?>
                <?= $this->Paginator->numbers() ?>
                <?= $this->Paginator->next('<i class="fas fa-angle-right"></i>', ['escape' => false]) ?>
                <?= $this->Paginator->last('<i class="fas fa-angle-double-right"></i>', ['escape' => false]) ?>
            </ul>
        </div>
    </div>
</div>