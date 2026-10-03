<?php
declare(strict_types=1);

namespace App\Controller;
use App\Service\ChestGoalService;
use Cake\Controller\Controller;
use Cake\ORM\TableRegistry;
use Cake\I18n\Time;

/**
 * StandardChests Controller
 *
 * @property \App\Model\Table\StandardChestsTable $StandardChests
 */
class StandardChestsController extends AppController
{
    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);
    
        $this->Authentication->allowUnauthenticated(['weights']);
    }
    public function index()
    {
        $this->requireAdmin();

        $filters = $this->indexFilters();
        $query = $this->applyIndexFilters($this->StandardChests->find(), $filters);

        $this->paginate = [
            'sortableFields' => ['source', 'alias', 'score', 'monster', 'qty_chest'],
            'order' => ['StandardChests.source' => 'ASC'],
        ];
        $standardChests = $this->paginate($query);

        $this->set(compact('standardChests', 'filters'));
    }

    /**
     * Read and normalize the filters accepted by the index listing.
     *
     * @return array<string, string> Filter values, blank when not in use.
     */
    protected function indexFilters(): array
    {
        $allowed = [
            'type' => ['monster', 'regular'],
            'score' => ['scored', 'unscored'],
            'alias' => ['with', 'without'],
        ];

        $filters = ['q' => trim((string)$this->request->getQuery('q', ''))];

        foreach ($allowed as $name => $values) {
            $value = (string)$this->request->getQuery($name, '');
            $filters[$name] = in_array($value, $values, true) ? $value : '';
        }

        return $filters;
    }

    /**
     * Apply the index filters to the listing query.
     *
     * @param \Cake\ORM\Query\SelectQuery $query Query to filter.
     * @param array<string, string> $filters Normalized filter values.
     * @return \Cake\ORM\Query\SelectQuery
     */
    protected function applyIndexFilters(\Cake\ORM\Query\SelectQuery $query, array $filters): \Cake\ORM\Query\SelectQuery
    {
        if ($filters['q'] !== '') {
            // Escape the LIKE wildcards so they are searched literally.
            $like = '%' . addcslashes($filters['q'], '\%_') . '%';
            $query->where(['OR' => [
                'StandardChests.source LIKE' => $like,
                'StandardChests.alias LIKE' => $like,
            ]]);
        }

        if ($filters['type'] === 'monster') {
            $query->where(['StandardChests.monster !=' => 0]);
        } elseif ($filters['type'] === 'regular') {
            $query->where(['StandardChests.monster' => 0]);
        }

        if ($filters['score'] === 'scored') {
            $query->where(['StandardChests.score !=' => 0]);
        } elseif ($filters['score'] === 'unscored') {
            $query->where(['StandardChests.score' => 0]);
        }

        if ($filters['alias'] === 'with') {
            $query->where(['StandardChests.alias IS NOT' => null, 'StandardChests.alias !=' => '']);
        } elseif ($filters['alias'] === 'without') {
            $query->where(['OR' => [
                'StandardChests.alias IS' => null,
                'StandardChests.alias' => '',
            ]]);
        }

        return $query;
    }

    public function weights()
    {
        // Ler o parâmetro 'show_all' da query string. Padrão é '0' (não mostrar todos).
        $showAllParam = $this->request->getQuery('show_all', '0');

        // Define os campos pelos quais a paginação pode ordenar
        // Use os nomes reais das colunas no banco de dados.
        // Se 'Chests' no template se refere a 'source' na tabela:
        $this->paginate = [
            'sortableFields' => [
                'source', // Para $this->Paginator->sort('Chests') ou $this->Paginator->sort('source')
                'score'   // Para $this->Paginator->sort('Score') ou $this->Paginator->sort('score')
            ]
        ];

        $query = $this->StandardChests->find();

        // Aplicar filtro se não for para mostrar todos
        if ($showAllParam !== '1') {
            $query->where(['StandardChests.score !=' => 0]);
        }

        $standardChests = $this->paginate($query);
        $configsTable = TableRegistry::getTableLocator()->get('Config');

        // Buscar o dia de referência
        $referencegoalConfig = $configsTable->find()
            ->where(['param' => 'minimum_chest_score'])
            ->first();

        // Buscar a meta de pontuação épica
        $epicGoalConfig = $configsTable->find()
            ->where(['param' => 'minimum_epic_chest_score'])
            ->first();

        // Passar o parâmetro show_all para a view
        // Com metas por nível de guarda, a meta global não diz a meta de ninguém
        $chestGoals = new ChestGoalService();
        $guardGoalTable = $chestGoals->isByGuard() ? $chestGoals->levelTable() : [];

        $this->set(compact('standardChests', 'referencegoalConfig', 'epicGoalConfig', 'showAllParam', 'guardGoalTable'));
    }

    /**
     * View method
     *
     * @param string|null $id Standard Chest id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $this->requireAdmin();
        $standardChest = $this->StandardChests->get($id, contain: []);
        $this->set(compact('standardChest'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $this->requireAdmin();
        $standardChest = $this->StandardChests->newEmptyEntity();
        if ($this->request->is('post')) {
            $standardChest = $this->StandardChests->patchEntity($standardChest, $this->request->getData());
            if ($this->StandardChests->save($standardChest)) {
                $this->Flash->success(__('The standard chest has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error($this->saveErrorMessage($standardChest));
        }
        $this->set(compact('standardChest'));
    }

    /**
     * Edit method
     *
     * @param string|null $id Standard Chest id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $this->requireAdmin();
        $standardChest = $this->StandardChests->get($id, contain: []);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $standardChest = $this->StandardChests->patchEntity($standardChest, $this->request->getData());
            if ($this->StandardChests->save($standardChest)) {
                $this->Flash->success(__('The standard chest has been saved.'));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error($this->saveErrorMessage($standardChest));
        }
        $this->set(compact('standardChest'));
    }

    /**
     * Delete method
     *
     * @param string|null $id Standard Chest id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'delete']);
        $standardChest = $this->StandardChests->get($id);
        if ($this->StandardChests->delete($standardChest)) {
            $this->Flash->success(__('The standard chest has been deleted.'));
        } else {
            $this->Flash->error(__('The standard chest could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Build the flash message for a failed save, keeping the duplicated source
     * message instead of the generic one when that is the reason.
     *
     * @param \App\Model\Entity\StandardChest $standardChest The entity that failed to save.
     * @return string
     */
    protected function saveErrorMessage(\App\Model\Entity\StandardChest $standardChest): string
    {
        $sourceErrors = $standardChest->getError('source');
        if (!empty($sourceErrors)) {
            return (string)reset($sourceErrors);
        }

        return __('The standard chest could not be saved. Please, try again.');
    }

    /**
     * Normalize an alias coming from a form: trim it and treat blanks as null.
     *
     * @param mixed $alias Raw alias value.
     * @return string|null
     */
    protected function normalizeAlias(mixed $alias): ?string
    {
        $alias = trim((string)($alias ?? ''));

        return $alias !== '' ? $alias : null;
    }

    /**
     * Register a single chest coming from the Lost Chests screen.
     *
     * @param array<string, mixed> $chestData Raw form data for one chest.
     * @return string One of `saved`, `skipped` (source already registered) or `error`.
     */
    protected function registerLostChest(array $chestData): string
    {
        $source = trim((string)($chestData['source'] ?? ''));
        if ($source === '') {
            return 'error';
        }

        if ($this->StandardChests->exists(['source' => $source])) {
            return 'skipped';
        }

        $qtyChest = $chestData['qty_chest'] ?? null;

        $entity = $this->StandardChests->newEntity([
            'source' => $source,
            'alias' => $this->normalizeAlias($chestData['alias'] ?? null),
            'score' => (int)($chestData['score'] ?? 0),
            'monster' => !empty($chestData['monster']) ? 1 : 0,
            'qty_chest' => ($qtyChest !== null && $qtyChest !== '') ? (int)$qtyChest : null,
        ]);

        if ($this->StandardChests->save($entity)) {
            return 'saved';
        }

        // The unique rule may still reject it on a concurrent submission.
        return $entity->getError('source') ? 'skipped' : 'error';
    }

    /**
     * Compare collected chests with standard chests and list those not yet registered.
     * Allows selecting and bulk/single adding them to standard_chests.
     *
     * @return \Cake\Http\Response|null|void Renders view or redirects on save.
     */
    public function lostChests()
    {
        $this->requireAdmin();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $counters = ['saved' => 0, 'skipped' => 0, 'error' => 0];

            // Check if it's a single addition
            if (!empty($data['single_source'])) {
                $result = $this->registerLostChest([
                    'source' => $data['single_source'],
                    'alias' => $data['alias'] ?? null,
                    'score' => $data['score'] ?? 0,
                    'monster' => $data['monster'] ?? null,
                    'qty_chest' => $data['qty_chest'] ?? null,
                ]);
                $counters[$result]++;
            } elseif (!empty($data['chests']) && is_array($data['chests'])) {
                // Bulk addition
                foreach ($data['chests'] as $chestData) {
                    if (!empty($chestData['selected']) && !empty($chestData['source'])) {
                        $result = $this->registerLostChest($chestData);
                        $counters[$result]++;
                    }
                }
            }

            if ($counters['saved'] > 0) {
                $this->Flash->success(__('{0} lost chest(s) successfully added to Standard Chests.', $counters['saved']));
            } elseif ($counters['error'] > 0) {
                $this->Flash->error(__('Failed to add selected chest(s). Please check values and try again.'));
            } elseif ($counters['skipped'] === 0) {
                $this->Flash->warning(__('No chests were selected to add.'));
            }

            if ($counters['skipped'] > 0) {
                $this->Flash->warning(__('{0} chest(s) were skipped because their source is already registered.', $counters['skipped']));
            }

            return $this->redirect(['action' => 'lostChests']);
        }

        // Query collected chests that do not exist in standard chests
        $conn = \Cake\Datasource\ConnectionManager::get('default');
        $lostChests = $conn->execute("
            SELECT 
                cc.source,
                COUNT(*) AS total_count,
                COUNT(DISTINCT cc.player) AS players_count,
                MIN(cc.collected_at) AS first_seen,
                MAX(cc.collected_at) AS last_seen
            FROM collected_chests cc
            LEFT JOIN standard_chests sc ON cc.source = sc.source
            WHERE sc.source IS NULL
            GROUP BY cc.source
            ORDER BY total_count DESC, cc.source ASC
        ")->fetchAll('assoc');

        $this->set(compact('lostChests'));
    }
}
