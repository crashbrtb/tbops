<?php
use Cake\ORM\TableRegistry;

$identity = $this->request->getAttribute('identity');
$isLoggedIn = $identity !== null;
$isAdmin = false; // Inicia como falso
if ($isLoggedIn) {
    // Assumindo que a propriedade na entidade User que contém os roles associados é 'roles'
    // e que a entidade Role tem uma propriedade 'name' para o nome do role.
    $userAssociatedRoles = $identity->get('roles'); 
    if (!empty($userAssociatedRoles) && (is_array($userAssociatedRoles) || $userAssociatedRoles instanceof \Traversable)) {
        foreach ($userAssociatedRoles as $roleEntity) {
            if (isset($roleEntity->name) && $roleEntity->name === 'admin') {
                $isAdmin = true;
                break; 
            }
        }
    }
}

// Verificar se a função do banco está habilitada
$configTable = TableRegistry::getTableLocator()->get('Config');
$bankFunctionConfig = $configTable->find()
    ->where(['param' => 'bank_function'])
    ->first();
$bankFunctionEnabled = $bankFunctionConfig && (int)$bankFunctionConfig->value === 1;

if ($isAdmin):
?>
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="langDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="true">
        <?= __('Admin') ?>
    </a>
    <ul class="dropdown-menu" aria-labelledby="langDropdown">
        <li class="dropdown-submenu dropdown-hover">
            <a id="membersDropdownMenuLink" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="dropdown-item dropdown-toggle">
                <?= __('Members') ?>
            </a>
            <ul aria-labelledby="membersDropdownMenuLink" class="dropdown-menu border-0 shadow">
                <li>
                    <?= $this->Html->link(__('List'), ['controller' => 'Members', 'action' => 'index'], ['class' => 'dropdown-item']) ?>
                </li>
                <li>
                    <?= $this->Html->link(__('Update'), ['controller' => 'Members', 'action' => 'updateFromCollectedChests'], ['class' => 'dropdown-item']) ?>
                </li>
                <li>
                    <?= $this->Html->link(__('Names Mapping'), ['controller' => 'PlayerNameMappings', 'action' => 'index'], ['class' => 'dropdown-item']) ?>
                </li>
            </ul>
        </li>
        <li class="dropdown-submenu dropdown-hover">
            <a id="usersDropdownMenuLink" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="dropdown-item dropdown-toggle">
                <?= __('Users') ?>
            </a>
            <ul aria-labelledby="usersDropdownMenuLink" class="dropdown-menu border-0 shadow">
                <li>
                    <?= $this->Html->link(__('List'), ['controller' => 'Users', 'action' => 'index'], ['class' => 'dropdown-item']) ?>
                </li>
                <li>
                    <?= $this->Html->link(__('Add'), ['controller' => 'Users', 'action' => 'add'], ['class' => 'dropdown-item']) ?>
                </li>
                <li>
                    <?= $this->Html->link(__('Roles'), ['controller' => 'Roles', 'action' => 'index'], ['class' => 'dropdown-item']) ?>
                </li>
            </ul>
        </li>
        <li class="dropdown-submenu dropdown-hover">
            <a id="chestsDropdownMenuLink" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="dropdown-item dropdown-toggle">
                <?= __('Chests') ?>
            </a>
            <ul aria-labelledby="chestsDropdownMenuLink" class="dropdown-menu border-0 shadow">
                <li>
                    <?= $this->Html->link(__('Standard Chests'), ['controller' => 'StandardChests', 'action' => 'index'], ['class' => 'dropdown-item']) ?>
                </li>
                <li>
                    <?= $this->Html->link(__('Lost Chests'), ['controller' => 'StandardChests', 'action' => 'lostChests'], ['class' => 'dropdown-item']) ?>
                </li>
                <li>
                    <?= $this->Html->link(__('Merge Players'), ['controller' => 'CollectedChests', 'action' => 'mergePlayers'], ['class' => 'dropdown-item']) ?>
                </li>
                <li>
                    <?php
                    // The count belongs in the menu: chests waiting to be read by
                    // hand are invisible work until somebody is told they exist.
                    try {
                        $pendingIncomplete = \Cake\ORM\TableRegistry::getTableLocator()
                            ->get('IncompleteChests')->pendingCount();
                    } catch (\Throwable $e) {
                        $pendingIncomplete = 0;
                    }
                    ?>
                    <?= $this->Html->link(
                        __('Incomplete Chests') . ($pendingIncomplete
                            ? ' <span class="badge badge-warning">' . $pendingIncomplete . '</span>'
                            : ''),
                        ['controller' => 'IncompleteChests', 'action' => 'index'],
                        ['class' => 'dropdown-item', 'escape' => false]
                    ) ?>
                </li>
                <li>
                    <?= $this->Html->link(__('Summary last cycles'), ['controller' => 'PlayerCycleSummaries', 'action' => 'index'], ['class' => 'dropdown-item']) ?>
                </li>
            </ul>
        </li>
        <?php if ($bankFunctionEnabled): ?>
        <li class="dropdown-submenu dropdown-hover">
            <a id="bankAdminDropdownMenuLink" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="dropdown-item dropdown-toggle">
                <?= __('Bank') ?>
            </a>
            <ul aria-labelledby="bankAdminDropdownMenuLink" class="dropdown-menu border-0 shadow">
                <li>
                    <?= $this->Html->link(__('Bank Approvals'), ['controller' => 'Bank', 'action' => 'approvals'], ['class' => 'dropdown-item']) ?>
                </li>
            </ul>
        </li>
        <?php endif; ?>
        
        <li class="dropdown-submenu dropdown-hover">
            <a id="eventsAdminDropdownMenuLink" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" class="dropdown-item dropdown-toggle">
                <?= __('Events') ?>
            </a>
            <ul aria-labelledby="eventsAdminDropdownMenuLink" class="dropdown-menu border-0 shadow">
                <li>
                    <?= $this->Html->link(__('Manage Events'), ['controller' => 'Events', 'action' => 'manage'], ['class' => 'dropdown-item']) ?>
                </li>
                <li>
                    <?= $this->Html->link(__('New Event'), ['controller' => 'Events', 'action' => 'add'], ['class' => 'dropdown-item']) ?>
                </li>
                <li>
                    <?= $this->Html->link(__('New Game Tournament'), ['controller' => 'Events', 'action' => 'add', '?' => ['type' => 'imported']], ['class' => 'dropdown-item']) ?>
                </li>
                <li>
                    <?= $this->Html->link(__('Tournament Catalogue'), ['controller' => 'GameTournaments', 'action' => 'index'], ['class' => 'dropdown-item']) ?>
                </li>
                <li>
                    <?= $this->Html->link(__('API Tokens'), ['controller' => 'ApiTokens', 'action' => 'index'], ['class' => 'dropdown-item']) ?>
                </li>
                <li>
                    <?= $this->Html->link(__('Event Banners'), ['controller' => 'Events', 'action' => 'settings'], ['class' => 'dropdown-item']) ?>
                </li>
            </ul>
        </li>

        <?= $this->Html->link(
            __('Branding'),
            ['controller' => 'Config', 'action' => 'branding'],
            ['class' => 'dropdown-item']
        ) ?>

        <?= $this->Html->link(
            __('Theme'),
            ['controller' => 'Config', 'action' => 'theme'],
            ['class' => 'dropdown-item']
        ) ?>

        <?= $this->Html->link(
            __('Maintenance'),
            ['controller' => 'Config', 'action' => 'maintenance'],
            ['class' => 'dropdown-item']
        ) ?>

        <?= $this->Html->link(
            __('Monitoring'),
            ['controller' => 'Monitoring', 'action' => 'index'],
            ['class' => 'dropdown-item']
        ) ?>

        <?= $this->Html->link(__('Configs'), ['controller' => 'Config', 'action' => 'index'], ['class' => 'dropdown-item']) ?>

    </ul>
</li>
<?php
endif;
?>

<?php if (!$isLoggedIn): ?>
    <li class="nav-item">
        <?= $this->Html->link(
            '<i class="fas fa-sign-in-alt nav-icon"></i> ' . __('Login'),
            ['controller' => 'Users', 'action' => 'login'],
            ['class' => 'nav-link', 'escape' => false]
        ) ?>
    </li>
<?php else: ?>
    <?php
    $pendingCount = $pendingApprovalsCount ?? 0;
    $hasPendingApprovals = $pendingCount > 0;
    $iconClass = 'fas fa-user-circle nav-icon' . ($hasPendingApprovals ? ' pending-approvals-icon' : '');
    ?>
    <li class="nav-item dropdown user-menu">
        <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">
            <i class="<?= $iconClass ?>"></i>
            <span class="d-none d-md-inline"><?= h($identity->get('username')) ?></span>
        </a>
        <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
            <!-- User image -->
            <li class="user-header bg-primary">
                <p>
                    <?= h($identity->get('username')) ?>
                    <?php if ($hasPendingApprovals): ?>
                        <small class="text-warning" style="font-weight: bold;">
                            <i class="fas fa-exclamation-circle"></i> 
                            <?= __('{0} pending approval(s)', $pendingCount) ?>
                        </small>
                    <?php else: ?>
                        <small><?= __('Member since {0}', $identity->get('created')->nice()) ?></small>
                    <?php endif; ?>
                </p>
            </li>

            <!-- Menu Footer-->
            <li class="user-footer">
                <?php // $this->Html->link(__('Profile'), ['controller' => 'Users', 'action' => 'profile', $identity->getIdentifier()], ['class' => 'btn btn-default btn-flat']) ?>
                <?= $this->Html->link(
                    __('Logout'),
                    ['controller' => 'Users', 'action' => 'logout'],
                    ['class' => 'btn btn-default btn-flat float-right']
                ) ?>
                
                <a href="#" class="btn btn-default btn-flat" data-toggle="modal" data-target="#changePasswordModal">
                    <i class="fas fa-key"></i> <?= __('Change Password') ?>
                </a>

        </ul>
    </li>
    <?php if ($hasPendingApprovals): ?>
    <style>
        .pending-approvals-icon {
            animation: blink 1s infinite;
            color: #ffc107 !important;
        }
        @keyframes blink {
            0%, 100% {
                opacity: 1;
                transform: scale(1);
            }
            50% {
                opacity: 0.4;
                transform: scale(1.1);
            }
        }
    </style>
    <?php endif; ?>
<?php endif; ?>

<?php
// The switcher is driven by config so adding a locale is a one-line change.
$languages = (array)\Cake\Core\Configure::read('I18n.languages', []);
$currentLocale = \Cake\I18n\I18n::getLocale();
$currentLabel = $languages[$currentLocale] ?? strtoupper(strtok($currentLocale, '_'));
?>
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="langDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <i class="fas fa-language nav-icon"></i> <span class="d-none d-md-inline"><?= h($currentLabel) ?></span>
    </a>
    <!-- Eleven languages overflow a short viewport, so the list scrolls. -->
    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="langDropdown"
         style="max-height: 70vh; overflow-y: auto;">
        <?php foreach ($languages as $locale => $label): ?>
            <?= $this->Html->link(
                $label,
                ['controller' => 'Locale', 'action' => 'change', $locale],
                [
                    'class' => 'dropdown-item' . ($locale === $currentLocale ? ' active' : ''),
                    'hreflang' => str_replace('_', '-', $locale),
                ]
            ) ?>
        <?php endforeach; ?>
    </div>
</li>

<?php if ($isLoggedIn): ?>
<!-- Modal de Alteração de Senha -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" role="dialog" aria-labelledby="changePasswordModalLabel" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog" role="document" style="z-index: 1060;">
        <div class="modal-content" style="position: relative; z-index: 1060; pointer-events: auto;">
            <?= $this->Form->create(null, [
                'url' => ['controller' => 'Users', 'action' => 'changePassword'],
                'id' => 'changePasswordForm'
            ]) ?>
            <div class="modal-header">
                <h5 class="modal-title" id="changePasswordModalLabel">
                    <i class="fas fa-key"></i> <?= __('Change Password') ?>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <?= $this->Form->label('current_password', __('Current Password')) ?>
                    <?= $this->Form->password('current_password', [
                        'class' => 'form-control',
                        'required' => true,
                        'autocomplete' => 'current-password'
                    ]) ?>
                </div>
                <div class="form-group">
                    <?= $this->Form->label('new_password', __('New Password')) ?>
                    <?= $this->Form->password('new_password', [
                        'class' => 'form-control',
                        'required' => true,
                        'minlength' => 6,
                        'autocomplete' => 'new-password'
                    ]) ?>
                    <small class="form-text text-muted"><?= __('Password must be at least 6 characters long.') ?></small>
                </div>
                <div class="form-group">
                    <?= $this->Form->label('confirm_password', __('Confirm New Password')) ?>
                    <?= $this->Form->password('confirm_password', [
                        'class' => 'form-control',
                        'required' => true,
                        'minlength' => 6,
                        'autocomplete' => 'new-password'
                    ]) ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal"><?= __('Cancel') ?></button>
                <?= $this->Form->button(__('Change Password'), [
                    'class' => 'btn btn-primary',
                    'type' => 'submit'
                ]) ?>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>
</div>

<style>
/* Garantir que o modal apareça acima de todos os elementos */
#changePasswordModal {
    z-index: 1060 !important;
}

#changePasswordModal .modal-dialog {
    z-index: 1060 !important;
    pointer-events: auto !important;
}

#changePasswordModal .modal-content {
    z-index: 1060 !important;
    pointer-events: auto !important;
    position: relative;
}

.modal-backdrop {
    z-index: 1055 !important;
}

/* Garantir que o dropdown não interfira */
.navbar-nav .dropdown-menu {
    z-index: 1000;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mover o modal para o body para evitar problemas de z-index
    var modal = document.getElementById('changePasswordModal');
    if (modal && modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    
    // Limpar formulário quando o modal for fechado
    $('#changePasswordModal').on('hidden.bs.modal', function () {
        $('#changePasswordForm')[0].reset();
    });
    
    // Fechar dropdown quando abrir o modal
    $('#changePasswordModal').on('show.bs.modal', function () {
        $('.user-menu .dropdown-toggle').dropdown('hide');
    });
    
    // Validação de confirmação de senha
    $('#changePasswordForm').on('submit', function(e) {
        var newPassword = $('input[name="new_password"]').val();
        var confirmPassword = $('input[name="confirm_password"]').val();
        
        if (newPassword !== confirmPassword) {
            e.preventDefault();
            alert('<?= __('New password and confirmation do not match.') ?>');
            return false;
        }
        
        if (newPassword.length < 6) {
            e.preventDefault();
            alert('<?= __('Password must be at least 6 characters long.') ?>');
            return false;
        }
        
        // Fechar o modal após o submit (a mensagem será exibida após o redirecionamento)
        $('#changePasswordModal').modal('hide');
    });
});
</script>
<?php endif; ?>

  