<?php
declare(strict_types=1);

namespace App\Controller;
use App\Service\ChestGoalService;
use App\Service\GoalPenaltyService;
use Cake\Http\Response;
use Cake\Controller\Controller;
use Cake\ORM\TableRegistry;
use Cake\I18n\Time;
use Cake\I18n\FrozenTime;

/**
 * CollectedChests Controller
 *
 * @property \App\Model\Table\CollectedChestsTable $CollectedChests
 */
class CollectedChestsController extends AppController
{
    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $this->requireAdmin();
        $query = $this->CollectedChests->find();
        $collectedChests = $this->paginate($query);

        $this->set(compact('collectedChests'));
    }

    /**
     * View method
     *
     * @param string|null $id Collected Chest id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $this->requireAdmin();
        $collectedChest = $this->CollectedChests->get($id, contain: []);
        $this->set(compact('collectedChest'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $this->requireAdmin();
        $collectedChest = $this->CollectedChests->newEmptyEntity();
        if ($this->request->is('post')) {
            $collectedChest = $this->CollectedChests->patchEntity($collectedChest, $this->request->getData());
            if ($this->CollectedChests->save($collectedChest)) {
                $this->Flash->success(__('The collected chest has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The collected chest could not be saved. Please, try again.'));
        }
        $this->set(compact('collectedChest'));
    }

    /**
     * Edit method
     *
     * @param string|null $id Collected Chest id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $this->requireAdmin();
        $collectedChest = $this->CollectedChests->get($id, contain: []);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $collectedChest = $this->CollectedChests->patchEntity($collectedChest, $this->request->getData());
            if ($this->CollectedChests->save($collectedChest)) {
                $this->Flash->success(__('The collected chest has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The collected chest could not be saved. Please, try again.'));
        }
        $this->set(compact('collectedChest'));
    }

    /**
     * Delete method
     *
     * @param string|null $id Collected Chest id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'delete']);
        $collectedChest = $this->CollectedChests->get($id);
        if ($this->CollectedChests->delete($collectedChest)) {
            $this->Flash->success(__('The collected chest has been deleted.'));
        } else {
            $this->Flash->error(__('The collected chest could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Render the "missing configuration" page for a required config parameter.
     *
     * Actions must return a Response or null, so a plain string cannot be used
     * to report a broken setup. Returns 503 so monitoring sees the app is not
     * ready rather than assuming a healthy page.
     *
     * @param string $param Name of the offending row in the `config` table.
     * @param string $reason Short description of what is wrong with it.
     * @return \Cake\Http\Response
     */
    private function renderConfigMissing(string $param, string $reason): Response
    {
        $this->set(compact('param', 'reason'));

        return $this->render('config_missing')->withStatus(503);
    }

    public function score()
    {

        $configsTable = TableRegistry::getTableLocator()->get('Config');
        $collectedChestsTable = TableRegistry::getTableLocator()->get('CollectedChests');
        $standardChestsTable = TableRegistry::getTableLocator()->get('StandardChests');

        // Buscar o dia de referência
        $referenceDayConfig = $configsTable->find()
            ->where(['param' => 'reference_day'])
            ->first();

        if (!$referenceDayConfig || empty($referenceDayConfig->value)) {
            return $this->renderConfigMissing('reference_day', 'não encontrado ou vazio');
        }

        $referenceDay = new FrozenTime($referenceDayConfig->value);

        // Buscar a duração do ciclo em dias
        $everyHowManyDaysConfig = $configsTable->find()
            ->where(['param' => 'every_how_many_days'])
            ->first();

        if (!$everyHowManyDaysConfig || !is_numeric($everyHowManyDaysConfig->value)) {
            return $this->renderConfigMissing('every_how_many_days', 'não encontrado ou inválido');
        }
        $cycleDuration = (int) $everyHowManyDaysConfig->value;

        // Buscar a pontuação mínima
        $minimumChestScore = $configsTable->find()
            ->where(['param' => 'minimum_chest_score'])
            ->first();
        if (!$minimumChestScore || !is_numeric($minimumChestScore->value)) {
            return $this->renderConfigMissing('minimum_chest_score', 'não encontrado ou inválido');
        }
        $minimumChestScore = (int) $minimumChestScore->value;

        // Buscar a pontuação mínima para Epic Chests
        $minimumEpicChestScoreConfig = $configsTable->find()
            ->where(['param' => 'minimum_epic_chest_score'])
            ->first();
        if (!$minimumEpicChestScoreConfig || !is_numeric($minimumEpicChestScoreConfig->value)) {
            // Lidar com o erro, talvez definindo um valor padrão
            $minimumEpicChestScore = 0; // ou lançar uma exceção
        } else {
            $minimumEpicChestScore = (int) $minimumEpicChestScoreConfig->value;
        }

        // Buscar as cores para o degradê da pontuação
        $scoreColorsConfig = $configsTable->find('list', keyField: 'param', valueField: 'value')
        ->where(['param IN' => [
            'score_color_start_r', 'score_color_start_g', 'score_color_start_b',
            'score_color_end_r', 'score_color_end_g', 'score_color_end_b',
            'score_color_transition_start'
        ]])
        ->toArray();

        // Determinar o ciclo a ser calculado
        $selectedCycleOffset = $this->request->getQuery('cycle', 0); // 0 para o ciclo atual

        $today = FrozenTime::now();
        $daysSinceReference = $referenceDay->diffInDays($today);
        $currentCycleOffset = (int) floor($daysSinceReference / $cycleDuration);
        $targetCycleOffset = $currentCycleOffset - $selectedCycleOffset;
        $cycleStart = $referenceDay->addDays($targetCycleOffset * $cycleDuration);
        $cycleEnd = $cycleStart->addDays($cycleDuration)->sub(new \DateInterval('PT1S'));

        // Buscar os baús coletados no ciclo selecionado
        $collectedChestsData = $collectedChestsTable->find()
            ->select(['player', 'source', 'count' => 'COUNT(*)'])
            ->where([
                'collected_at >=' => $cycleStart,
                'collected_at <=' => $cycleEnd,
            ])
            ->group(['player', 'source'])
            ->toArray();

        // Buscar a pontuação de cada tipo de baú

        $chestScoresResult = $standardChestsTable->find()
            ->select(['source', 'alias', 'score', 'monster'])
            ->toArray();

        $chestScores = [];
        // Nome exibido nos relatorios: o alias quando definido, senao o source
        $chestDisplayNames = [];
        foreach ($chestScoresResult as $row) {
            $chestScores[$row->source] = $row;
            $chestDisplayNames[$row->source] = $row->display_name;
        }

        // Identificar sources de Epic Monster (monster = 1) e buscar detalhes individuais
        $epicMonsterSources = [];
        foreach ($chestScores as $src => $chestData) {
            if (!empty($chestData->monster)) {
                $epicMonsterSources[] = $src;
            }
        }

        $epicMonsterDetails = [];
        if (!empty($epicMonsterSources)) {
            $rawEpicDetails = $collectedChestsTable->find()
                ->select(['player', 'source', 'collected_at'])
                ->where([
                    'collected_at >=' => $cycleStart,
                    'collected_at <=' => $cycleEnd,
                    'source IN' => $epicMonsterSources,
                ])
                ->order(['player' => 'ASC', 'source' => 'ASC', 'collected_at' => 'DESC'])
                ->toArray();

            foreach ($rawEpicDetails as $row) {
                $epicMonsterDetails[$row->player][$row->source][] = $row->collected_at->format('d/m/Y H:i');
            }
        }

        // Buscar os nomes (sources) dos baús que têm pontuação diferente de zero
        $sourcesWithNonZeroScore = $standardChestsTable->find('list', keyField: 'source', valueField: 'source')
        ->where(['score !=' => 0])
        ->toArray();

        $playerChestCounts = [];
        $playerFinalScores = [];

        // Processar os dados dos baús coletados
        foreach ($collectedChestsData as $data) {
            $player = $data->player;
            $source = $data->source;
            $count = $data->count;

            if (!isset($playerChestCounts[$player])) {
                $playerChestCounts[$player] = [];
                $playerFinalScores[$player] = 0;
            }
            $playerChestCounts[$player][$source] = $count;

            if (isset($chestScores[$source])) {
                $playerFinalScores[$player] += $chestScores[$source]->score * $count;
            }
        }

        // Calcular a soma total de baús por jogador
        $playerTotalChests = [];
        foreach ($playerChestCounts as $player => $counts) {
            $playerTotalChests[$player] = array_sum($counts);
        }

        // Gerar as opções para o select box
        $cycleOptions = [];
        for ($i = 0; $i <= 1; $i++) {
            $offset = $currentCycleOffset - $i;
            $start = $referenceDay->addDays($offset * $cycleDuration)->format('Y-m-d');
            $end = $referenceDay->addDays(($offset + 1) * $cycleDuration)->format('Y-m-d');
            $cycleOptions[$i] = ($i === 0 ?  __('Current Cycle') : __('Previous Cycle') ) . " ($start - $end)";
        }

        // Formatar as datas do ciclo atual para exibição
        $currentCycleFormatted = [
            'start' => $cycleStart->format('Y-m-d H:i:s'),
            'end' => $cycleEnd->format('Y-m-d H:i:s'),
        ];

        // Meta de cada jogador: global ou pelo nível dos guardas, conforme configurado
        $chestGoals = new ChestGoalService();
        $goalsByGuard = $chestGoals->isByGuard();
        $guardGoalTable = $goalsByGuard ? $chestGoals->levelTable() : [];
        $playerGoals = [];
        $playerGuardLevels = [];
        foreach (array_keys($playerChestCounts) as $player) {
            $player = (string)$player;
            $playerGoals[$player] = $chestGoals->goalsFor($player);
            $playerGuardLevels[$player] = $chestGoals->guardLevel($player);
        }

        // Penalidade de meta: quem não bateu a meta no ciclo anterior tem meta maior neste
        $goalPenalty = new GoalPenaltyService($chestGoals);
        $goalPenaltySettings = $goalPenalty->settings();
        $penaltyGoals = $goalPenalty->goalsForCycle($cycleStart);

        // Buscar a data/hora da linha mais recente da tabela CollectedChests
        $lastUpdate = $collectedChestsTable->find()
            ->order(['collected_at' => 'DESC'])
            ->first();

        // Desativa o sidebar especificamente para esta action
        $this->set('cakelte_theme', [
            'sidebar' => [
                'enable' => false
            ],
            'navbar' => [
                'enable' => true
            ]
        ]);

        // Passar os dados para a view
        $this->set(compact(
            'playerChestCounts', 
            'playerTotalChests', 
            'playerFinalScores', 
            'cycleOptions', 
            'currentCycleFormatted', 
            'selectedCycleOffset', 
            'minimumChestScore',
            'minimumEpicChestScore',
            'lastUpdate',
            'sourcesWithNonZeroScore',
            'chestScores',
            'chestDisplayNames',
            'scoreColorsConfig',
            'epicMonsterDetails',
            'goalPenaltySettings',
            'penaltyGoals',
            'goalsByGuard',
            'guardGoalTable',
            'playerGoals',
            'playerGuardLevels'
        ));

    }

    /**
     * New score layout with top blocks + full ranking table.
     *
     * Reuses the same data preparation from score() and renders scorenew.php.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function scorenew()
    {
        return $this->redirect(['action' => 'score', '?' => $this->request->getQuery()]);
    }

    public function mergePlayers()
    {
        $this->requireAdmin();
        $collectedChestsTable = $this->CollectedChests; // Ou TableRegistry::getTableLocator()->get('CollectedChests');
        $membersTable = TableRegistry::getTableLocator()->get('Members'); // Adicionar MembersTable

        $uniquePlayersQuery = $collectedChestsTable->find()
            ->select(['player'])
            ->distinct(['player'])
            ->order(['player' => 'ASC']);
        
        $playerList = $uniquePlayersQuery->all()->combine('player', 'player')->toArray();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $correctPlayer = $data['correct_player_name'] ?? null;
            $incorrectPlayer = $data['incorrect_player_name'] ?? null;

            if (empty($correctPlayer) || empty($incorrectPlayer)) {
                $this->Flash->error(__('Please select both the correct player name and the incorrect player name.'));
            } elseif ($correctPlayer === $incorrectPlayer) {
                $this->Flash->error(__('The correct and incorrect player names cannot be the same.'));
            } else {
                try {
                    $updatedRows = $collectedChestsTable->updateAll(
                        ['player' => $correctPlayer],
                        ['player' => $incorrectPlayer]
                    );

                    if ($updatedRows > 0) {
                        $this->Flash->success(__('Successfully merged player "{0}" into "{1}". {2} records were updated.', $incorrectPlayer, $correctPlayer, $updatedRows));

                        // Add mapping to player_name_mappings (ocr_text = incorrect, correct_name = correct)
                        $playerNameMappingsTable = TableRegistry::getTableLocator()->get('PlayerNameMappings');
                        $existingMapping = $playerNameMappingsTable->find()->where(['ocr_text' => $incorrectPlayer])->first();
                        if ($existingMapping) {
                            $existingMapping->correct_name = $correctPlayer;
                            $playerNameMappingsTable->save($existingMapping);
                        } else {
                            $newMapping = $playerNameMappingsTable->newEntity([
                                'ocr_text' => $incorrectPlayer,
                                'correct_name' => $correctPlayer,
                            ]);
                            $playerNameMappingsTable->save($newMapping);
                        }

                        // Excluir o jogador incorreto da tabela Members
                        $incorrectPlayerEntity = $membersTable->find()->where(['player' => $incorrectPlayer])->first();
                        if ($incorrectPlayerEntity) {
                            if ($membersTable->delete($incorrectPlayerEntity)) {
                                $this->Flash->success(__('Player "{0}" was successfully deleted from the members list.', $incorrectPlayer));
                            } else {
                                $this->Flash->error(__('Could not delete player "{0}" from the members list.', $incorrectPlayer));
                            }
                        } else {
                            // Opcional: Adicionar uma mensagem se o jogador incorreto não for encontrado na tabela Members
                            // $this->Flash->info(__('Player "{0}" not found in the members list, no deletion needed.', $incorrectPlayer));
                        }

                         // Atualizar a lista de jogadores após a mesclagem
                        $playerList = $collectedChestsTable->find()
                                            ->select(['player'])
                                            ->distinct(['player'])
                                            ->order(['player' => 'ASC'])
                                            ->all()
                                            ->combine('player', 'player')
                                            ->toArray();
                    } else {
                        $this->Flash->warning(__('No records found for player "{0}" to merge into "{1}". No changes were made.', $incorrectPlayer, $correctPlayer));
                    }
                } catch (\Exception $e) {
                    $this->Flash->error(__('An error occurred while merging players: {0}', $e->getMessage()));
                }
            }
        }

        $this->set(compact('playerList'));
        $this->set('title', __('Merge Player Names')); // Para o título da página
    }
}
