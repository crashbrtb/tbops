<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\ORM\TableRegistry;
use Cake\I18n\FrozenTime;
use Cake\Event\EventInterface;

/**
 * PlayerCycleSummaries Controller
 *
 * @property \App\Model\Table\PlayerCycleSummariesTable $PlayerCycleSummaries
 */
class PlayerCycleSummariesController extends AppController
{
    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);
    
        $this->Authentication->allowUnauthenticated(['index']);
    }
    public function index()
    {

        // Buscar todos os resumos para identificar os ciclos
        $allSummaries = $this->PlayerCycleSummaries->find()
            ->order(['cycle_start_date' => 'DESC', 'player_name' => 'ASC'])
            ->all();

        $uniqueCycleStartDates = [];
        if (!$allSummaries->isEmpty()) {
            // Extrai todas as datas de início, remove duplicatas e garante que são objetos FrozenDate
            $dates = $allSummaries->extract('cycle_start_date')->toArray();
            $uniqueDatesStrings = array_unique(array_map(function($date) { return $date->toDateString(); }, $dates));
            foreach ($uniqueDatesStrings as $dateString) {
                $uniqueCycleStartDates[] = new \Cake\I18n\FrozenDate($dateString);
            }
            // Ordena as datas únicas em ordem descendente
            usort($uniqueCycleStartDates, function (\Cake\I18n\FrozenDate $a, \Cake\I18n\FrozenDate $b) {
                return $b <=> $a;
            });
        }
        
        $numberOfCyclesToShow = 3;
        $selectedCycleDatesToShow = array_slice($uniqueCycleStartDates, 0, $numberOfCyclesToShow);

        $summariesByCycle = [];
        $formattedCycleDates = [];

        if (!empty($selectedCycleDatesToShow)) {
            $filteredSummaries = $allSummaries->filter(function ($summary) use ($selectedCycleDatesToShow) {
                foreach ($selectedCycleDatesToShow as $date) {
                    if ($summary->cycle_start_date->equals($date)) {
                        return true;
                    }
                }
                return false;
            })->toList();

            foreach ($filteredSummaries as $summary) {
                $summariesByCycle[$summary->cycle_start_date->toDateString()][] = $summary;
            }

            foreach ($selectedCycleDatesToShow as $date) {
                // Encontrar um sumário para pegar a data de término do ciclo para exibição
                $firstSummaryInCycle = null;
                if (!empty($summariesByCycle[$date->toDateString()])) {
                    $firstSummaryInCycle = $summariesByCycle[$date->toDateString()][0];
                }
                if ($firstSummaryInCycle) {
                     $formattedCycleDates[$date->toDateString()] = [
                        'start' => $firstSummaryInCycle->cycle_start_date,
                        'end' => $firstSummaryInCycle->cycle_end_date,
                     ];
                }
            }
        }
        
        // Load configs for score coloring
        $configsTable = TableRegistry::getTableLocator()->get('Config');
        $configs = $configsTable->find('list', ['keyField' => 'param', 'valueField' => 'value'])->toArray();

        $minimumChestScore = (int)($configs['minimum_chest_score'] ?? 0);
        $minimumEpicChestScore = (int)($configs['minimum_epic_chest_score'] ?? 0);
        $scoreColorsConfig = [
            'score_color_transition_start' => (float)($configs['score_color_transition_start'] ?? 0.0),
            'score_color_start_r' => (int)($configs['score_color_start_r'] ?? 255),
            'score_color_start_g' => (int)($configs['score_color_start_g'] ?? 0),
            'score_color_start_b' => (int)($configs['score_color_start_b'] ?? 0),
            'score_color_end_r' => (int)($configs['score_color_end_r'] ?? 0),
            'score_color_end_g' => (int)($configs['score_color_end_g'] ?? 255),
            'score_color_end_b' => (int)($configs['score_color_end_b'] ?? 0),
        ];

        // A paginação original $this->paginate($query) é removida pois estamos focando nos 3 últimos ciclos.
        $this->set(compact('summariesByCycle', 'formattedCycleDates', 'minimumChestScore', 'minimumEpicChestScore', 'scoreColorsConfig'));
    }

    public function playerHistory($playerName = null)
    {
        if (!$playerName) {
            $this->Flash->error(__('Player name cannot be empty.'));
            return $this->redirect(['controller' => 'CollectedChests', 'action' => 'score']);
        }
        $playerName = urldecode($playerName);

        $playerHistory = $this->PlayerCycleSummaries->find()
            ->where(['player_name' => $playerName])
            ->order(['cycle_end_date' => 'DESC'])
            ->limit(6);

        // Load configs for score coloring
        $configsTable = TableRegistry::getTableLocator()->get('Config');
        $configs = $configsTable->find('list', ['keyField' => 'param', 'valueField' => 'value'])->toArray();

        $minimumChestScore = (int)($configs['minimum_chest_score'] ?? 0);
        $scoreColorsConfig = [
            'score_color_transition_start' => (float)($configs['score_color_transition_start'] ?? 0.0),
            'score_color_start_r' => (int)($configs['score_color_start_r'] ?? 255),
            'score_color_start_g' => (int)($configs['score_color_start_g'] ?? 0),
            'score_color_start_b' => (int)($configs['score_color_start_b'] ?? 0),
            'score_color_end_r' => (int)($configs['score_color_end_r'] ?? 0),
            'score_color_end_g' => (int)($configs['score_color_end_g'] ?? 255),
            'score_color_end_b' => (int)($configs['score_color_end_b'] ?? 0),
        ];

        $this->set(compact('playerHistory', 'playerName', 'minimumChestScore', 'scoreColorsConfig'));
    }

    public function cyclesHistory()
    {
        // Get the end dates of the last 6 distinct cycles
        $lastSixCycleDates = $this->PlayerCycleSummaries->find()
            ->select(['cycle_end_date'])
            ->distinct(['cycle_end_date'])
            ->order(['cycle_end_date' => 'DESC'])
            ->limit(6)
            ->toArray();

        $cycleEndDates = array_map(function($summary) {
            return $summary->cycle_end_date;
        }, $lastSixCycleDates);
        sort($cycleEndDates); // Sort dates chronologically for the table header

        if (empty($cycleEndDates)) {
            $this->set('playersData', []);
            $this->set('playerNames', []);
            $this->set('cycleDates', []);
            return;
        }

        // Find all summaries within those last 6 cycles
        $summaries = $this->PlayerCycleSummaries->find()
            ->where(['cycle_end_date IN' => $cycleEndDates]);

        // Get a unique, alphabetized list of players
        $playerNames = (clone $summaries)->select(['player_name'])
            ->distinct(['player_name'])
            ->order(['player_name' => 'ASC'])
            ->all()
            ->extract('player_name')
            ->toList();

        // Pivot the data for the view; playersGoals holds the raised goals of penalized players
        $pivotedData = [];
        $pivotedGoals = [];
        foreach ($summaries as $summary) {
            $dateKey = $summary->cycle_end_date->toDateString();
            $pivotedData[$summary->player_name][$dateKey] = $summary->total_score;
            if ($summary->penalty_goal !== null) {
                $pivotedGoals[$summary->player_name][$dateKey] = (int)$summary->penalty_goal;
            }
        }

        $this->set('playersData', $pivotedData);
        $this->set('playersGoals', $pivotedGoals);
        $this->set('playerNames', $playerNames);
        $this->set('cycleDates', $cycleEndDates);

        // Load configs for score coloring
        $configsTable = TableRegistry::getTableLocator()->get('Config');
        $configs = $configsTable->find('list', ['keyField' => 'param', 'valueField' => 'value'])->toArray();

        $minimumChestScore = (int)($configs['minimum_chest_score'] ?? 0);
        $scoreColorsConfig = [
            'score_color_transition_start' => (float)($configs['score_color_transition_start'] ?? 0.0),
            'score_color_start_r' => (int)($configs['score_color_start_r'] ?? 255),
            'score_color_start_g' => (int)($configs['score_color_start_g'] ?? 0),
            'score_color_start_b' => (int)($configs['score_color_start_b'] ?? 0),
            'score_color_end_r' => (int)($configs['score_color_end_r'] ?? 0),
            'score_color_end_g' => (int)($configs['score_color_end_g'] ?? 255),
            'score_color_end_b' => (int)($configs['score_color_end_b'] ?? 0),
        ];

        $this->set(compact('minimumChestScore', 'scoreColorsConfig'));
    }

    /**
     * View method
     *
     * @param string|null $id Player Cycle Summary id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $this->requireAdmin();
        $playerCycleSummary = $this->PlayerCycleSummaries->get($id, contain: []);
        $this->set(compact('playerCycleSummary'));
    }

    /**
     * Mark Fine as Paid method
     *
     * @param string|null $id Player Cycle Summary id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function markFinePaid($id = null)
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'put']);
        $playerCycleSummary = $this->PlayerCycleSummaries->get($id);

        if ($playerCycleSummary->fine_due && !$playerCycleSummary->fine_paid) {
            $playerCycleSummary->fine_paid = true;
            if ($this->PlayerCycleSummaries->save($playerCycleSummary)) {
                $this->Flash->success(__('Fine marked as paid for {0} in cycle starting {1}.', $playerCycleSummary->player_name, $playerCycleSummary->cycle_start_date->format('Y-m-d')));
            } else {
                $this->Flash->error(__('The fine could not be marked as paid. Please, try again.'));
            }
        } else {
            $this->Flash->warning(__('No fine to mark as paid or it has already been paid.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Process Cycle Summaries method
     * 
     * This method will be called, possibly by a cron job or manually, 
     * to process the scores for a completed cycle.
     */
    public function processCycleSummaries()
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'get']); // Permitir GET para teste manual
        $configsTable = TableRegistry::getTableLocator()->get('Config');
        $playerCycleSummariesTable = $this->PlayerCycleSummaries;

        // --- Lógica para determinar o ciclo a ser processado (ex: o último ciclo concluído) ---
        $referenceDayConfig = $configsTable->find()->where(['param' => 'reference_day'])->first();
        $everyHowManyDaysConfig = $configsTable->find()->where(['param' => 'every_how_many_days'])->first();
        $minimumChestScoreConfig = $configsTable->find()->where(['param' => 'minimum_chest_score'])->first();

        if (!($referenceDayConfig && $everyHowManyDaysConfig && $minimumChestScoreConfig && 
              !empty($referenceDayConfig->value) && is_numeric($everyHowManyDaysConfig->value) && is_numeric($minimumChestScoreConfig->value))) {
            $this->Flash->error(__('Configuration parameters for cycle processing are missing or invalid.'));
            return $this->redirect(['action' => 'index']);
        }

        $referenceDay = new FrozenTime($referenceDayConfig->value);
        $cycleDuration = (int) $everyHowManyDaysConfig->value;
        $minimumRequiredScore = (int) $minimumChestScoreConfig->value;

        $today = FrozenTime::now();
        $daysSinceReference = $referenceDay->diffInDays($today);
        $currentCycleOffsetFromReference = (int) floor($daysSinceReference / $cycleDuration);
        
        // Obter o offset do ciclo a ser processado da URL, default é 0 (ciclo que acabou de fechar)
        $requestedCycleOffset = $this->request->getQuery('cycle_offset');
        if ($requestedCycleOffset === null || !is_numeric($requestedCycleOffset)) {
            $targetCycleToProcessOffset = $currentCycleOffsetFromReference - 1; // Ciclo anterior ao atual
        } else {
            $targetCycleToProcessOffset = $currentCycleOffsetFromReference - 1 - (int)$requestedCycleOffset;
        }

        // Evitar processar ciclos futuros ou muito antigos (além da referência)
        if ($targetCycleToProcessOffset < 0) {
             $this->Flash->warning(__('Invalid cycle offset. Cannot process future cycles or cycles before the reference start.'));
             return $this->redirect(['action' => 'index']);
        }

        $cycleStart = $referenceDay->addDays($targetCycleToProcessOffset * $cycleDuration);
        $cycleEnd = $cycleStart->addDays($cycleDuration)->sub(new \DateInterval('PT1S'));

        $forceReprocess = (bool)$this->request->getQuery('force_reprocess');

        // Delegar o processamento para a Table model
        $result = $playerCycleSummariesTable->processCycleForDateRange(
            $cycleStart,
            $cycleEnd,
            $minimumRequiredScore,
            $forceReprocess
        );

        if ($result['skipped']) {
            $this->Flash->info(__('Cycle from {0} to {1} has already been processed. Use ?force_reprocess=1 to re-process.', $cycleStart->format('Y-m-d'), $cycleEnd->format('Y-m-d')));
        } elseif ($forceReprocess) {
            $this->Flash->info(__('Cleared existing summaries for cycle {0} due to force_reprocess.', $cycleStart->format('Y-m-d')));
        }

        if ($result['processed'] > 0) {
            $this->Flash->success(__('{0} player cycle summaries processed successfully for cycle {1} - {2}.', $result['processed'], $cycleStart->format('Y-m-d'), $cycleEnd->format('Y-m-d')));
        }
        if ($result['errors'] > 0) {
            $this->Flash->error(__('{0} errors occurred while processing player cycle summaries.', $result['errors']));
        }
        if ($result['processed'] === 0 && $result['errors'] === 0 && !$result['skipped']) {
            $this->Flash->info(__('No player data found to process for cycle {0} - {1}.', $cycleStart->format('Y-m-d'), $cycleEnd->format('Y-m-d')));
        }

        return $this->redirect(['action' => 'index']);
    }

} 