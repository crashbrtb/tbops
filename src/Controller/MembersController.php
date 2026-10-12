<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\DuplicatePlayerNameService;
use Cake\ORM\TableRegistry;
use Cake\I18n\FrozenTime;

/**
 * Members Controller
 *
 * @property \App\Model\Table\MembersTable $Members
 */
class MembersController extends AppController
{
    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $this->requireAdmin();
        $showInactive = (bool)$this->request->getQuery('inactive');
        $query = $this->Members->find();
        if (!$showInactive) {
            $query->where(['Members.active' => true]);
        }
        $members = $this->paginate($query, ['limit' => 100]);

        $this->set(compact('members', 'showInactive'));
    }

    /**
     * View method
     *
     * @param string|null $id Member id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $this->requireAdmin();
        $member = $this->Members->get($id, contain: ['Users']);
        $this->set(compact('member'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $this->requireAdmin();
        $member = $this->Members->newEmptyEntity();
        if ($this->request->is('post')) {
            $member = $this->Members->patchEntity($member, $this->request->getData());
            if ($this->Members->save($member)) {
                (new DuplicatePlayerNameService())->scanQuietly();
                $this->Flash->success(__('The member has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The member could not be saved. Please, try again.'));
        }
        $users = $this->Members->Users->find('list', keyField: 'id', valueField: 'name')->order(['name' => 'ASC'])->toArray();
        $this->set(compact('member', 'users'));
    }

    /**
     * Edit method
     *
     * @param string|null $id Member id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $this->requireAdmin();
        $member = $this->Members->get($id, contain: ['Users']);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $member = $this->Members->patchEntity($member, $this->request->getData());
            if ($this->Members->save($member)) {
                (new DuplicatePlayerNameService())->scanQuietly();
                $this->Flash->success(__('The member has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The member could not be saved. Please, try again.'));
        }
        $users = $this->Members->Users->find('list', keyField: 'id', valueField: 'name')->order(['name' => 'ASC'])->toArray();
        $this->set(compact('member', 'users'));
    }

    /**
     * Delete method
     *
     * @param string|null $id Member id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'delete']);
        $member = $this->Members->get($id);
        if ($this->Members->delete($member)) {
            (new DuplicatePlayerNameService())->scanQuietly();
            $this->Flash->success(__('The member has been deleted.'));
        } else {
            $this->Flash->error(__('The member could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Update members from collected chests
     * Sincroniza players da tabela collectedchests com a tabela members
     * e atualiza o status active baseado na última atividade (3 semanas)
     *
     * @return \Cake\Http\Response|null Redirects to index with flash message
     */
    public function updateFromCollectedChests()
    {
        $this->requireAdmin();
        $result = $this->Members->updateFromCollectedChests();

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $errorMsg) {
                $this->Flash->error($errorMsg);
            }
        }

        $message = __('Update completed. Found {0} players (samples: {1}). {2} new members added, {3} members updated.', 
            $result['playersCount'],
            implode(', ', $result['samplePlayerNames']),
            $result['newMembersCount'], 
            $result['updatedMembersCount']
        );
        
        $this->Flash->success($message);

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Marca ou desmarca a conta como administrativa.
     *
     * Contas administrativas aparecem nos rankings dos torneios importados, mas
     * nunca recebem premio nem entram na soma de pontos da divisao.
     *
     * @param string|null $id Member id.
     * @return \Cake\Http\Response
     */
    public function toggleAdministrative($id = null)
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post']);

        $member = $this->Members->get($id);
        $member->administrative_account = !$member->administrative_account;
        if ($this->Members->save($member)) {
            $this->Flash->success($member->administrative_account
                ? __('{0} is now an administrative account and will not receive tournament rewards.', $member->player)
                : __('{0} will receive tournament rewards again.', $member->player));
        } else {
            $this->Flash->error(__('The member could not be saved. Please, try again.'));
        }

        return $this->redirect($this->referer(['action' => 'index'], true));
    }
}
