<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     0.2.9
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\Controller;

use Authentication\Controller\Component\AuthenticationComponent;
use Cake\Controller\Component\AuthComponent;
use Cake\Controller\Controller;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\ORM\TableRegistry;


/**
 * Application Controller
 *
 * Add your application-wide methods in the class below, your controllers
 * will inherit them.
 *
 * @link https://book.cakephp.org/4/en/controllers.html#the-app-controller
 * @property \Authentication\Controller\Component\AuthenticationComponent $Authentication
 * @property \Cake\Controller\Component\FlashComponent $Flash
 */
class AppController extends Controller
{
    /**
     * Initialization hook method.
     *
     * Use this method to add common initialization code like loading components.
     *
     * e.g. `$this->loadComponent('Security');`
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication');
        $this->loadComponent('Flash');
        $this->Authentication->allowUnauthenticated(['score', 'history', 'scorenew', 'chestTimes']);
        
        // Configuração do CakeLTE
        $this->viewBuilder()->setLayout('CakeLte/layout/default');

        // Define as configurações do tema

        /*
         * Enable the following component for recommended CakePHP form protection settings.
         * see https://book.cakephp.org/4/en/controllers/components/form-protection.html
         */
        //$this->loadComponent('FormProtection');
    }

    /**
     * beforeFilter callback.
     *
     * @param \Cake\Event\EventInterface $event An Event instance.
     * @return \Cake\Http\Response|null|void
     */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
        // for all controllers in our application, make index and view actions
        // require a logged in user.whitelist all public actions to allow all users to access them
        // $this->Authentication->addUnauthenticatedActions([
        //     'login', 'register', 'forgotPassword', 'resetPassword', // Adicione aqui actions públicas do UsersController
        //     'display' // Exemplo para PagesController::display
        // ]);

        // }

    }

    public function beforeRender(EventInterface $event)
    {
        parent::beforeRender($event);

        // Count pending bank approvals for admin users
        $pendingApprovalsCount = 0;
        $userId = $this->currentUserId();
        if ($userId && $this->isAdmin($userId)) {
            $bankTransactions = TableRegistry::getTableLocator()->get('BankTransactions');
            $pendingApprovalsCount = $bankTransactions->find()
                ->where([
                    'BankTransactions.status' => \App\Model\Table\BankTransactionsTable::STATUS_PENDING,
                    'BankTransactions.type IN' => [
                        \App\Model\Table\BankTransactionsTable::TYPE_DEPOSIT,
                        \App\Model\Table\BankTransactionsTable::TYPE_WITHDRAWAL,
                    ],
                ])
                ->count();
        }

        $this->set('pendingApprovalsCount', $pendingApprovalsCount);
    }

    /**
     * Retorna o ID do usuário autenticado ou null.
     */
    protected function currentUserId(): ?int
    {
        $identity = $this->request->getAttribute('identity');
        if ($identity === null) {
            return null;
        }

        if (method_exists($identity, 'getIdentifier')) {
            return (int)$identity->getIdentifier();
        }

        if (method_exists($identity, 'get')) {
            return (int)$identity->get('id');
        }

        if (isset($identity['id'])) {
            return (int)$identity['id'];
        }

        return null;
    }

    /**
     * Verifica se o usuário é administrador (role_id = 1).
     */
    protected function isAdmin(?int $userId = null): bool
    {
        $userId ??= $this->currentUserId();
        if (!$userId) {
            return false;
        }

        $rolesUsers = TableRegistry::getTableLocator()->get('RolesUsers');

        return $rolesUsers->exists([
            'user_id' => $userId,
            'role_id' => 1,
        ]);
    }

    /**
     * Verifica se o usuário atual é administrador e lança exceção se não for.
     * 
     * @throws \Cake\Http\Exception\ForbiddenException Se o usuário não for administrador
     */
    protected function requireAdmin(): void
    {
        $userId = $this->currentUserId();
        if (!$userId) {
            throw new ForbiddenException(__('You must be logged in to access this page.'));
        }

        if (!$this->isAdmin($userId)) {
            throw new ForbiddenException(__('Only administrators can access this page.'));
        }
    }
}
