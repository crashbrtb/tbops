<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\GoalPenaltyService;
use Cake\Http\Exception\NotFoundException;
use Cake\I18n\FrozenTime;

/**
 * Who carries a raised chest goal this cycle, releasing players from it, and
 * manual penalties: a raised goal an administrator gives a player, with a
 * reason, whether or not the automatic penalty is on.
 *
 * Releases and manual penalties are only possible for the current and the
 * previous cycle. Older cycles already fed the summaries of the cycles after
 * them, so changing them would leave those summaries telling a different story.
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
        $penalties = [];
        $manualPenalties = [];
        $playerOptions = [];
        // A manual penalty can raise any goal someone has, whatever the automatic penalty watches
        $manualTargets = array_values(array_filter(
            array_keys(GoalPenaltyService::SUMMARY_COLUMNS),
            fn (string $target): bool => $service->goals()->hasGoal($target)
        ));
        if ($cycleStart !== null) {
            // Evaluated even while the penalty is off, as a preview of whom it would hit
            $evaluation = $service->evaluateCycle($cycleStart, true);
            ksort($evaluation, SORT_NATURAL | SORT_FLAG_CASE);

            /** @var \App\Model\Table\PlayerCycleSummariesTable $summaries */
            $summaries = $this->fetchTable('PlayerCycleSummaries');
            $cycleEnd = $cycleStart->addDays($settings['cycle_days'])->sub(new \DateInterval('PT1S'));
            foreach ($summaries->scoresForDateRange($cycleStart, $cycleEnd) as $player => $result) {
                foreach (array_keys(GoalPenaltyService::SUMMARY_COLUMNS) as $target) {
                    $currentScores[(string)$player][$target] = GoalPenaltyService::scoreFor($result, $target);
                }
            }

            $penalties = $service->penaltiesForCycle($cycleStart);
            $manualPenalties = $this->fetchTable('ManualGoalPenalties')->find()
                ->contain(['Users' => ['fields' => ['id', 'name']]])
                ->where(['cycle_start_date' => $cycleStart->format('Y-m-d')])
                ->orderBy(['player_name' => 'ASC'])
                ->all()
                ->indexBy('player_name')
                ->toArray();
            $playerOptions = array_values($this->knownPlayers($cycleStart, $settings['cycle_days']));
            sort($playerOptions, SORT_NATURAL | SORT_FLAG_CASE);

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
            'waivers',
            'penalties',
            'manualPenalties',
            'manualTargets',
            'playerOptions'
        ));
    }

    /**
     * Give a player a manual penalty in the current or the previous cycle.
     *
     * It does not depend on the automatic penalty: it works while that one is
     * off, and adds to it when the player already carries one. The reason is
     * required.
     *
     * @return \Cake\Http\Response|null
     */
    public function addManual()
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post']);

        $service = new GoalPenaltyService();
        $cyclesAgo = $this->cyclesAgo((string)$this->request->getData('cycle', '0'));
        $cycleStart = $service->cycleStart($cyclesAgo);
        $redirect = ['action' => 'index', '?' => ['cycle' => $cyclesAgo]];
        $failed = __('The manual penalty could not be added. Please, try again.');

        if ($cycleStart === null) {
            $this->Flash->error($failed);

            return $this->redirect($redirect);
        }

        // The name is stored the way the chests spell it, which is what the penalty is matched by
        $typed = trim((string)$this->request->getData('player_name'));
        $player = $this->knownPlayers($cycleStart, $service->settings()['cycle_days'])[mb_strtolower($typed)] ?? null;
        if ($player === null) {
            $this->Flash->error(__('Player "{0}" was not found among the members or the chests of this cycle.', $typed));

            return $this->redirect($redirect);
        }

        $target = (string)$this->request->getData('target', GoalPenaltyService::TARGET_TOTAL);
        $validTargets = [GoalPenaltyService::TARGET_TOTAL, GoalPenaltyService::TARGET_EPIC, GoalPenaltyService::TARGET_BOTH];
        if (!in_array($target, $validTargets, true)) {
            $this->Flash->error($failed);

            return $this->redirect($redirect);
        }
        foreach (GoalPenaltyService::targetsOf($target) as $one) {
            if (!$service->goals()->hasGoal($one)) {
                $this->Flash->error(__('That goal is 0 in the configs, so there is nothing to raise.'));

                return $this->redirect($redirect);
            }
        }

        $penalties = $this->fetchTable('ManualGoalPenalties');
        $penalty = $penalties->newEntity([
            'player_name' => $player,
            'cycle_start_date' => $cycleStart->format('Y-m-d'),
            'target' => $target,
            'percent' => str_replace(',', '.', trim((string)$this->request->getData('percent'))),
            'reason' => trim((string)$this->request->getData('reason')),
            'user_id' => $this->currentUserId(),
        ]);
        if (!$penalties->save($penalty)) {
            $message = $failed;
            foreach ($penalty->getErrors() as $errors) {
                $message = (string)reset($errors);
                break;
            }
            $this->Flash->error($message);

            return $this->redirect($redirect);
        }

        $service->syncSummary($player, $cycleStart);
        $this->Flash->success(__('A manual penalty of +{0}% was added to {1}.', (float)$penalty->percent, $player));

        return $this->redirect($redirect);
    }

    /**
     * Remove a manual penalty of the current or the previous cycle.
     *
     * @param string|null $id Manual penalty id.
     * @return \Cake\Http\Response|null
     */
    public function removeManual($id = null)
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'delete']);

        $service = new GoalPenaltyService();
        $penalties = $this->fetchTable('ManualGoalPenalties');
        $penalty = $penalties->get($id);

        $cyclesAgo = null;
        foreach ([0, 1] as $ago) {
            if ($service->cycleStart($ago)?->format('Y-m-d') === $penalty->cycle_start_date->format('Y-m-d')) {
                $cyclesAgo = $ago;
            }
        }
        if ($cyclesAgo === null) {
            throw new NotFoundException(__('Only manual penalties of the current or the previous cycle can be removed.'));
        }

        $penalties->deleteOrFail($penalty);
        $service->syncSummary($penalty->player_name, $service->cycleStart($cyclesAgo));
        $this->Flash->success(__('The manual penalty of {0} was removed.', $penalty->player_name));

        return $this->redirect(['action' => 'index', '?' => ['cycle' => $cyclesAgo]]);
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
     * Players a manual penalty can be given to: whoever has chests in the
     * cycle or a summary since the previous one, plus the members. Chest
     * spellings win, since penalties are matched to chest rows by name.
     *
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the cycle.
     * @param int $cycleDays Length of a cycle.
     * @return array<string, string> Lower-cased name => name.
     */
    private function knownPlayers(FrozenTime $cycleStart, int $cycleDays): array
    {
        /** @var \App\Model\Table\PlayerCycleSummariesTable $summaries */
        $summaries = $this->fetchTable('PlayerCycleSummaries');
        $cycleEnd = $cycleStart->addDays($cycleDays)->sub(new \DateInterval('PT1S'));

        $players = [];
        foreach (array_keys($summaries->scoresForDateRange($cycleStart, $cycleEnd)) as $player) {
            $players[mb_strtolower((string)$player)] ??= (string)$player;
        }
        $summarized = $summaries->find()
            ->select(['player_name'])
            ->distinct(['player_name'])
            ->where(['cycle_start_date >=' => $cycleStart->subDays($cycleDays)->format('Y-m-d')])
            ->all()
            ->extract('player_name');
        foreach ($summarized as $player) {
            $players[mb_strtolower((string)$player)] ??= (string)$player;
        }
        foreach ($this->fetchTable('Members')->find()->select(['player'])->all() as $member) {
            $players[mb_strtolower((string)$member->player)] ??= (string)$member->player;
        }

        return $players;
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
