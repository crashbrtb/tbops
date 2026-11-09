<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\Event;
use App\Service\TournamentPayoutService;
use App\Utility\GameNumber;
use Cake\Http\Response;

/**
 * The Silver won in a published tournament, reviewed by an administrator on
 * the event's page: listed again, left out, credited to the clan bank, or
 * reversed.
 */
class EventPayoutsController extends AppController
{
    /**
     * Lists the Silver again from the published split.
     *
     * @param string|null $eventId Event id.
     * @return \Cake\Http\Response
     */
    public function propose(?string $eventId = null): Response
    {
        $this->request->allowMethod(['post']);
        $this->requireAdmin();
        $event = $this->event($eventId);
        $count = (new TournamentPayoutService())->propose($event);
        $this->Flash->success(__('{0} Silver credit(s) waiting to be confirmed.', $count));

        return $this->back($event);
    }

    /**
     * Credits every pending Silver of the event.
     *
     * @param string|null $eventId Event id.
     * @return \Cake\Http\Response
     */
    public function credit(?string $eventId = null): Response
    {
        $this->request->allowMethod(['post']);
        $this->requireAdmin();
        $event = $this->event($eventId);
        $done = (new TournamentPayoutService())->credit($event, (int)$this->currentUserId());
        $this->Flash->success(__('{0} player(s) credited, {1} in all.', $done['credited'], GameNumber::silver($done['total'])));
        if ($done['no_member'] > 0) {
            $this->Flash->warning(__('{0} player(s) are not members of the clan bank: their Silver stays pending.', $done['no_member']));
        }

        return $this->back($event);
    }

    /**
     * Leaves a pending credit out, or takes that back (`?undo=1`).
     *
     * @param string|null $id Payout id.
     * @return \Cake\Http\Response
     */
    public function waive(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $this->requireAdmin();
        /** @var \App\Model\Entity\EventPayout $payout */
        $payout = $this->fetchTable('EventPayouts')->get((int)$id);
        $undo = (bool)$this->request->getQuery('undo');
        if ((new TournamentPayoutService())->waive($payout, (int)$this->currentUserId(), !$undo)) {
            $this->Flash->success($undo
                ? __('{0} will be credited again.', $payout->player)
                : __('{0} will not be credited.', $payout->player));
        }

        return $this->back($this->event((string)$payout->event_id));
    }

    /**
     * Takes a credited Silver back.
     *
     * @param string|null $id Payout id.
     * @return \Cake\Http\Response
     */
    public function reverse(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $this->requireAdmin();
        /** @var \App\Model\Entity\EventPayout $payout */
        $payout = $this->fetchTable('EventPayouts')->get((int)$id);
        if ((new TournamentPayoutService())->reverse($payout, (int)$this->currentUserId())) {
            $this->Flash->success(__('The Silver credited to {0} was taken back.', $payout->player));
        }

        return $this->back($this->event((string)$payout->event_id));
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
        return $this->redirect(['controller' => 'Events', 'action' => 'view', $event->id, '#' => 'payouts']);
    }
}
