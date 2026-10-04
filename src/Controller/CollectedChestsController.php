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

    /**
     * Key under which two spellings of a player name are the same player.
     *
     * Mirrors the accent- and case-insensitive collation of the `player`
     * columns, which PHP array keys do not follow.
     *
     * @param string $name Player name as stored.
     * @return string
     */
    private function playerKey(string $name): string
    {
        $decomposed = \Normalizer::normalize($name, \Normalizer::FORM_D);
        $folded = preg_replace('/\p{Mn}+/u', '', $decomposed === false ? $name : $decomposed);

        return mb_strtolower(rtrim($folded ?? $name));
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

        // O banco compara nomes sem diferenciar acento nem caixa ("EOMER" = "ÉOMER"), então o
        // GROUP BY junta as grafias mas devolve qualquer uma delas em cada grupo; o PHP não.
        // Uma grafia por jogador: a cadastrada em Members quando existir, senão a primeira vista.
        $playerSpellings = [];
        foreach (TableRegistry::getTableLocator()->get('Members')->find()->select(['player'])->all() as $member) {
            $playerSpellings[$this->playerKey((string)$member->player)] ??= (string)$member->player;
        }
        $canonicalPlayer = function (string $name) use (&$playerSpellings): string {
            return $playerSpellings[$this->playerKey($name)] ??= $name;
        };

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
                $epicMonsterDetails[$canonicalPlayer((string)$row->player)][$row->source][] = $row->collected_at->format('d/m/Y H:i');
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
            $player = $canonicalPlayer((string)$data->player);
            $source = $data->source;
            $count = $data->count;

            if (!isset($playerChestCounts[$player])) {
                $playerChestCounts[$player] = [];
                $playerFinalScores[$player] = 0;
            }
            $playerChestCounts[$player][$source] = ($playerChestCounts[$player][$source] ?? 0) + $count;

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
        $penaltyGoals = [];
        foreach ($goalPenalty->goalsForCycle($cycleStart) as $player => $raised) {
            $penaltyGoals[$canonicalPlayer((string)$player)] = $raised;
        }

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
     * Collection times of the chests one player took from one source in a cycle.
     *
     * Feeds the popup opened from the Chest Source column of the score
     * breakdown. Public like the score page itself, and answers JSON.
     *
     * @return \Cake\Http\Response
     */
    public function chestTimes(): Response
    {
        $this->request->allowMethod(['get']);

        $player = $this->request->getQuery('player');
        $source = $this->request->getQuery('source');
        $chests = [];

        $configsTable = TableRegistry::getTableLocator()->get('Config');
        $config = $configsTable->find('list', keyField: 'param', valueField: 'value')
            ->where(['param IN' => ['reference_day', 'every_how_many_days']])
            ->toArray();
        $cycleDuration = (int)($config['every_how_many_days'] ?? 0);

        if (is_string($player) && $player !== '' && is_string($source) && $source !== ''
            && !empty($config['reference_day']) && $cycleDuration > 0
        ) {
            // Mesma janela de ciclo calculada em score()
            $referenceDay = new FrozenTime($config['reference_day']);
            $currentCycleOffset = (int)floor($referenceDay->diffInDays(FrozenTime::now()) / $cycleDuration);
            $targetCycleOffset = $currentCycleOffset - (int)$this->request->getQuery('cycle', 0);
            $cycleStart = $referenceDay->addDays($targetCycleOffset * $cycleDuration);
            $cycleEnd = $cycleStart->addDays($cycleDuration)->sub(new \DateInterval('PT1S'));

            $rows = $this->CollectedChests->find()
                ->select(['name', 'collected_at'])
                ->where([
                    'player' => $player,
                    'source' => $source,
                    'collected_at >=' => $cycleStart,
                    'collected_at <=' => $cycleEnd,
                ])
                ->order(['collected_at' => 'DESC', 'id' => 'DESC'])
                ->all();

            foreach ($rows as $row) {
                $chests[] = [
                    'name' => (string)$row->name,
                    'collected_at' => $row->collected_at->format('d/m/Y H:i:s'),
                ];
            }
        }

        return $this->response
            ->withType('application/json')
            ->withStringBody((string)json_encode(['chests' => $chests], JSON_UNESCAPED_UNICODE));
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

        // A coluna compara sem diferenciar acento nem caixa: agrupar também pela forma binária
        // lista cada grafia ("EOMER" e "ÉOMER"), para que possam ser mescladas entre si.
        $uniquePlayersQuery = $collectedChestsTable->find()
            ->select(['player'])
            ->group(['player', 'BINARY player'])
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
                        // Comparação exata: a do banco casaria "EOMER" com o membro "ÉOMER" e o excluiria
                        $incorrectPlayerEntity = $membersTable->find()
                            ->where(['player' => $incorrectPlayer])
                            ->all()
                            ->filter(fn ($member) => $member->player === $incorrectPlayer)
                            ->first();
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
                                            ->group(['player', 'BINARY player'])
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
