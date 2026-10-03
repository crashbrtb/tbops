<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\GoalPenaltyService;
use Cake\Http\Exception\NotFoundException;

/**
 * Who carries a raised chest goal this cycle, and releasing players from it.
 *
 * Releases are only possible for the current and the previous cycle. Older
 * cycles already fed the summaries of the cycles after them, so changing them
 * would leave those summaries telling a different story.
 *
 * @property \App\Model\Table\GoalPenaltyWaiversTable $GoalPenaltyWaivers
 */
class GoalPenaltiesController extends AppController
{
    /**
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $this->requireAdmin();

        $service = new GoalPenaltyService();
        $settings = $service->settings();
        $cyclesAgo = $this->cyclesAgo((string)$this->request->getQuery('cycle', '0'));
        $cycleStart = $service->cycleStart($cyclesAgo);

        $evaluation = [];
        $currentScores = [];
        $waivers = [];
        $cycleOptions = [];
        if ($cycleStart !== null) {
            // Evaluated even while the penalty is off, as a preview of whom it would hit
            $evaluation = $service->evaluateCycle($cycleStart, true);
            ksort($evaluation, SORT_NATURAL | SORT_FLAG_CASE);

            /** @var \App\Model\Table\PlayerCycleSummariesTable $summaries */
            $summaries = $this->fetchTable('PlayerCycleSummaries');
            $cycleEnd = $cycleStart->addDays($settings['cycle_days'])->sub(new \DateInterval('PT1S'));
            foreach ($summaries->scoresForDateRange($cycleStart, $cycleEnd) as $player => $result) {
                foreach ($settings['targets'] as $target) {
                    $currentScores[(string)$player][$target] = GoalPenaltyService::scoreFor($result, $target);
                }
            }

            $waivers = $this->fetchTable('GoalPenaltyWaivers')->find()
                ->contain(['Users' => ['fields' => ['id', 'name']]])
                ->where(['cycle_start_date' => $cycleStart->format('Y-m-d')])
                ->orderBy(['player_name' => 'ASC'])
                ->all()
                ->indexBy('player_name')
                ->toArray();

            foreach ([0, 1] as $ago) {
                $start = $service->cycleStart($ago);
                $end = $start->addDays($settings['cycle_days']);
                $cycleOptions[$ago] = ($ago === 0 ? __('Current Cycle') : __('Previous Cycle'))
                    . ' (' . $start->format('Y-m-d') . ' - ' . $end->format('Y-m-d') . ')';
            }
        }

        $this->set(compact(
            'settings',
            'cyclesAgo',
            'cycleStart',
            'cycleOptions',
            'evaluation',
            'currentScores',
            'waivers'
        ));
    }

    /**
     * Release a player from the penalty in the current or the previous cycle.
     *
     * @return \Cake\Http\Response|null
     */
    public function release()
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post']);

        $service = new GoalPenaltyService();
        $cyclesAgo = $this->cyclesAgo((string)$this->request->getData('cycle', '0'));
        $cycleStart = $service->cycleStart($cyclesAgo);
        $player = trim((string)$this->request->getData('player_name'));
        $redirect = ['action' => 'index', '?' => ['cycle' => $cyclesAgo]];

        if ($cycleStart === null || $player === '') {
            $this->Flash->error(__('The player could not be released. Please, try again.'));

            return $this->redirect($redirect);
        }

        $waivers = $this->fetchTable('GoalPenaltyWaivers');
        $waiver = $waivers->newEntity([
            'player_name' => $player,
            'cycle_start_date' => $cycleStart->format('Y-m-d'),
            'reason' => trim((string)$this->request->getData('reason')) ?: null,
            'user_id' => $this->currentUserId(),
        ]);
        if (!$waivers->save($waiver)) {
            $errors = $waiver->getError('player_name');
            $this->Flash->error($errors ? (string)reset($errors) : __('The player could not be released. Please, try again.'));

            return $this->redirect($redirect);
        }

        $service->syncSummary($player, $cycleStart);
        $this->Flash->success(__('{0} was released from the goal penalty in this cycle.', $player));

        return $this->redirect($redirect);
    }

    /**
     * Undo a release: the player carries the raised goal again if it applies.
     *
     * @param string|null $id Waiver id.
     * @return \Cake\Http\Response|null
     */
    public function revoke($id = null)
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'delete']);

        $service = new GoalPenaltyService();
        $waivers = $this->fetchTable('GoalPenaltyWaivers');
        $waiver = $waivers->get($id);

        $cyclesAgo = null;
        foreach ([0, 1] as $ago) {
            if ($service->cycleStart($ago)?->format('Y-m-d') === $waiver->cycle_start_date->format('Y-m-d')) {
                $cyclesAgo = $ago;
            }
        }
        if ($cyclesAgo === null) {
            throw new NotFoundException(__('Only releases of the current or the previous cycle can be undone.'));
        }

        $waivers->deleteOrFail($waiver);
        $service->syncSummary($waiver->player_name, $service->cycleStart($cyclesAgo));
        $this->Flash->success(__('The release of {0} was undone.', $waiver->player_name));

        return $this->redirect(['action' => 'index', '?' => ['cycle' => $cyclesAgo]]);
    }

    /**
     * @param string $value The cycle the request asked for.
     * @return int 0 for the current cycle, 1 for the previous one.
     */
    private function cyclesAgo(string $value): int
    {
        return $value === '1' ? 1 : 0;
    }
}
