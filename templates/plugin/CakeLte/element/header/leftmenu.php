<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="chestsDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <?= __('Chests') ?>
    </a>
    <div class="dropdown-menu" aria-labelledby="chestsDropdown">
        <?= $this->Html->link(__('Scoreboard'), '/score', ['class' => 'dropdown-item']) ?>
        <?= $this->Html->link(__('Goals'), ['controller' => 'StandardChests', 'action' => 'weights'], ['class' => 'dropdown-item']) ?>
    </div>
</li>

<?php
// A live event gets a marker on the menu itself: players should not have to open
// the menu to find out that something is running.
try {
    $runningEventCount = \Cake\ORM\TableRegistry::getTableLocator()->get('Events')->find('running')->count();
} catch (\Throwable $e) {
    $runningEventCount = 0;
}
?>
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="eventsDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <?= __('Events') ?>
        <?php if ($runningEventCount > 0): ?>
            <span class="badge badge-success"><?= $runningEventCount ?></span>
        <?php endif; ?>
    </a>
    <div class="dropdown-menu" aria-labelledby="eventsDropdown">
        <?= $this->Html->link(
            '<i class="fas fa-trophy mr-2 text-warning"></i>' . __('Current Event'),
            ['controller' => 'Events', 'action' => 'index'],
            ['class' => 'dropdown-item', 'escape' => false]
        ) ?>
        <?= $this->Html->link(
            '<i class="fas fa-history mr-2 text-muted"></i>' . __('Event History'),
            ['controller' => 'Events', 'action' => 'history'],
            ['class' => 'dropdown-item', 'escape' => false]
        ) ?>
    </div>
</li>

<?php
$configTable = \Cake\ORM\TableRegistry::getTableLocator()->get('Config');
$calculatorFunctionConfig = $configTable->find()
    ->where(['param' => 'calculator_function'])
    ->first();
$calculatorFunctionEnabled = !$calculatorFunctionConfig || (int)$calculatorFunctionConfig->value === 1;
?>

<?php if ($calculatorFunctionEnabled): ?>
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="toolsDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <?= __('Tools') ?>
    </a>
    <div class="dropdown-menu" aria-labelledby="toolsDropdown">
        <?= $this->Html->link(
            __('Troop Calculator'),
            ['controller' => 'TroopCalculator', 'action' => 'index'],
            ['class' => 'dropdown-item']
        ) ?>
        <div class="dropdown-submenu dropdown-hover">
            <a id="automationsDropdownMenuLink" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="dropdown-item dropdown-toggle">
                <?= __('Automations') ?>
            </a>
            <ul aria-labelledby="automationsDropdownMenuLink" class="dropdown-menu border-0 shadow">
                <li>
                    <?= $this->Html->link(
                        __('Crypt Invader'),
                        'https://github.com/crashbrtb/crypt_collector',
                        ['class' => 'dropdown-item', 'target' => '_blank', 'rel' => 'noopener']
                    ) ?>
                </li>
                <li>
                    <?= $this->Html->link(
                        __('Citadel Attack'),
                        'https://github.com/crashbrtb/citadels_attack',
                        ['class' => 'dropdown-item', 'target' => '_blank', 'rel' => 'noopener']
                    ) ?>
                </li>
            </ul>
        </div>
    </div>
</li>
<?php endif; ?>

<?php
$bankFunctionConfig = $configTable->find()
    ->where(['param' => 'bank_function'])
    ->first();
$bankFunctionEnabled = $bankFunctionConfig && (int)$bankFunctionConfig->value === 1;
?>

<?php if ($bankFunctionEnabled): ?>
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="bankDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <?= __('Bank') ?>
    </a>
    <div class="dropdown-menu" aria-labelledby="bankDropdown">
        <?= $this->Html->link(__('Bank'), ['controller' => 'Bank', 'action' => 'index'], ['class' => 'dropdown-item']) ?>
        <?= $this->Html->link(__('Deposit'), ['controller' => 'Bank', 'action' => 'deposit'], ['class' => 'dropdown-item']) ?>
        <?= $this->Html->link(__('Withdraw'), ['controller' => 'Bank', 'action' => 'withdraw'], ['class' => 'dropdown-item']) ?>
        <?= $this->Html->link(__('Transfer'), ['controller' => 'Bank', 'action' => 'transfer'], ['class' => 'dropdown-item']) ?>
        <?= $this->Html->link(__('Statement'), ['controller' => 'Bank', 'action' => 'statement'], ['class' => 'dropdown-item']) ?>
    </div>
</li>
<?php endif; ?>

