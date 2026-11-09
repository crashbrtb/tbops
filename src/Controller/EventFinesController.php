<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\Event;
use App\Service\TournamentFineService;
use Cake\Http\Response;

/**
 * The fines of a published tournament, reviewed by an administrator on the
 * event's page: listed again, waived, charged to the clan bank, or refunded.
 */
class EventFinesController extends AppController
{
    /**
     * The history of the fines, narrowed by event, status and player, with what
     * each player still owes (those who left the clan first).
     *
     * @return void
     */
    public function index(): void
    {
        $this->requireAdmin();
        $service = new TournamentFineService();
        $filters = [
            'event_id' => (int)$this->request->getQuery('event_id'),
            'status' => (string)$this->request->getQuery('status', ''),
            'player' => trim((string)$this->request->getQuery('player', '')),
        ];
        $fines = $this->paginate($service->search($filters), [
            'limit' => 50,
            'sortableFields' => ['EventFines.player', 'EventFines.amount', 'EventFines.status', 'Events.ends_at'],
        ]);
        $events = $this->fetchTable('Events')->find('list', keyField: 'id', valueField: fn ($e): string => $e->name . ' (' . $e->ends_at?->format('d/m/Y') . ')')
            ->where(['Events.id IN' => $this->fetchTable('EventFines')->find()->select(['event_id'])->distinct(['event_id'])])
            ->orderBy(['Events.ends_at' => 'DESC'])
            ->toArray();

        $this->set(compact('fines', 'filters', 'events'));
        $this->set('totals', $service->totals());
        $this->set('owing', $service->owing());
        $this->set('statusOptions', \App\Model\Entity\EventFine::statusOptions());
    }

    /**
     * Gives up what a player owes (`key` from the list of who owes).
     *
     * @return \Cake\Http\Response
     */
    public function writeOff(): Response
    {
        $this->request->allowMethod(['post']);
        $this->requireAdmin();
        $key = (string)$this->request->getData('key');
        $service = new TournamentFineService();
        $owing = $service->owingOf($key);
        if ($owing === null) {
            $this->Flash->error(__('This player owes nothing.'));
        } else {
            $lost = $service->writeOff($key, (int)$this->currentUserId());
            $this->Flash->success(__('{0} of {1} given up as lost.', \App\Utility\GameNumber::silver($lost), $owing['player']));
        }

        return $this->redirect(['action' => 'index', '#' => 'owing']);
    }

    /**
     * Lists the fines again from the published result and the tournament's rule.
     *
     * @param string|null $eventId Event id.
     * @return \Cake\Http\Response
     */
    public function propose(?string $eventId = null): Response
    {
        $this->request->allowMethod(['post']);
        $this->requireAdmin();
        $event = $this->event($eventId);
        $count = (new TournamentFineService())->propose($event);
        $this->Flash->success(__('{0} fine(s) waiting to be charged.', $count));

        return $this->back($event);
    }

    /**
     * Charges every pending fine of the event.
     *
     * @param string|null $eventId Event id.
     * @return \Cake\Http\Response
     */
    public function charge(?string $eventId = null): Response
    {
        $this->request->allowMethod(['post']);
        $this->requireAdmin();
        $event = $this->event($eventId);
        $done = (new TournamentFineService())->charge($event, (int)$this->currentUserId());
        $this->Flash->success(__('{0} fine(s) charged, {1} in all.', $done['charged'], \App\Utility\GameNumber::silver($done['total'])));
        if ($done['no_member'] > 0) {
            $this->Flash->warning(__('{0} player(s) are not members of the clan bank: their fines stay pending.', $done['no_member']));
        }

        return $this->back($event);
    }

    /**
     * Waives a pending fine, or takes the waiver back (`?undo=1`).
     *
     * @param string|null $id Fine id.
     * @return \Cake\Http\Response
     */
    public function waive(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $this->requireAdmin();
        /** @var \App\Model\Entity\EventFine $fine */
        $fine = $this->fetchTable('EventFines')->get((int)$id);
        $undo = (bool)$this->request->getQuery('undo');
        if ((new TournamentFineService())->waive($fine, (int)$this->currentUserId(), !$undo)) {
            $this->Flash->success($undo ? __('{0} is fined again.', $fine->player) : __('{0} is waived.', $fine->player));
        }

        return $this->back($this->event((string)$fine->event_id));
    }

    /**
     * Refunds a charged fine.
     *
     * @param string|null $id Fine id.
     * @return \Cake\Http\Response
     */
    public function refund(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $this->requireAdmin();
        /** @var \App\Model\Entity\EventFine $fine */
        $fine = $this->fetchTable('EventFines')->get((int)$id);
        if ((new TournamentFineService())->refund($fine, (int)$this->currentUserId())) {
            $this->Flash->success(__('The fine of {0} was refunded.', $fine->player));
        }

        return $this->back($this->event((string)$fine->event_id));
    }

    /**
     * @param string|null $id Event id.
     * @return \App\Model\Entity\Event
     */
    private function event(?string $id): Event
    {
        /** @var \App\Model\Entity\Event $event */
        $event = $this->fetchTable('Events')->find('withoutBanner')->where(['Events.id' => (int)$id])->firstOrFail();

        return $event;
    }

    /**
     * @param \App\Model\Entity\Event $event Event.
     * @return \Cake\Http\Response
     */
    private function back(Event $event): Response
    {
        return $this->redirect(['controller' => 'Events', 'action' => 'view', $event->id, '#' => 'fines']);
    }
}
