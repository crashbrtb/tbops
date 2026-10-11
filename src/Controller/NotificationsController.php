<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\DuplicatePlayerNameService;

/**
 * The alerts for the administrators: the history of every one sent, and
 * marking them as read - which is what takes them off the user menu.
 */
class NotificationsController extends AppController
{
    protected ?string $defaultTable = 'AdminNotifications';

    /**
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $this->requireAdmin();

        $notifications = $this->paginate(
            $this->fetchTable()->find()->contain(['Readers']),
            ['limit' => 50, 'order' => ['AdminNotifications.created' => 'DESC', 'AdminNotifications.id' => 'DESC']]
        );

        $this->set(compact('notifications'));
    }

    /**
     * @param string|null $id Notification id.
     * @return \Cake\Http\Response
     */
    public function markRead($id = null)
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post']);

        if ($this->fetchTable()->markRead($this->currentUserId(), (int)$id) > 0) {
            $this->Flash->success(__('Notification marked as read.'));
        }

        return $this->redirect($this->referer(['action' => 'index'], true));
    }

    /**
     * @return \Cake\Http\Response
     */
    public function markAllRead()
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post']);

        $this->fetchTable()->markRead($this->currentUserId());
        $this->Flash->success(__('All notifications marked as read.'));

        return $this->redirect($this->referer(['action' => 'index'], true));
    }

    /**
     * Look for what there is to report now, without waiting for the members to change.
     *
     * @return \Cake\Http\Response
     */
    public function check()
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post']);

        $result = (new DuplicatePlayerNameService())->scan();
        $this->Flash->success(__('Check finished: {0} new notification(s).', $result['created']));

        return $this->redirect(['action' => 'index']);
    }
}
