<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ClanResultService;
use App\Service\DuplicatePlayerNameService;
use App\Service\MemberPerformanceService;
use App\Service\MemberPowerHistoryService;
use Cake\Http\Exception\NotFoundException;
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
     * @inheritDoc
     */
    public function initialize(): void
    {
        parent::initialize();

        // Like the power history: open to everyone when the Clan Data menu is on.
        $this->Authentication->addUnauthenticatedActions(['tournaments']);
    }

    /**
     * The clan in the game's tournaments: its place and points in each one,
     * and what it received. Query `type`: the game type whose evolution the
     * charts draw.
     *
     * Seen by the administrators, and by everyone when `clan_data_function` is
     * on, as the power history.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function tournaments()
    {
        $isAdmin = $this->isAdmin();
        if (!$isAdmin && !$this->fetchTable('Config')->isOn('clan_data_function')) {
            throw new NotFoundException();
        }

        $results = $this->fetchTable('ClanTournamentResults')->find()
            ->orderBy(['ended_at' => 'DESC'])
            ->all()
            ->toList();
        $names = (new ClanResultService())->typeNames();

        // Every type with results, the one ranked most often first.
        $types = [];
        foreach ($results as $result) {
            $type = (int)$result->game_type;
            $types[$type] ??= ['type' => $type, 'name' => $names[$type] ?? __('Game tournament {0}', $type), 'count' => 0, 'ranked' => 0];
            $types[$type]['count']++;
            $types[$type]['ranked'] += $result->isRanked() ? 1 : 0;
        }
        uasort($types, fn (array $a, array $b): int => [$b['ranked'], $b['count']] <=> [$a['ranked'], $a['count']]);

        $selected = (int)$this->request->getQuery('type');
        if (!isset($types[$selected])) {
            $selected = (int)(array_key_first($types) ?? 0);
        }
        $series = array_values(array_reverse(array_filter($results, fn ($r): bool => (int)$r->game_type === $selected)));

        $this->set(compact('results', 'names', 'types', 'selected', 'series', 'isAdmin'));
    }

    /**
     * Member performance dashboard: goal achievement statistics and ranking for
     * events/tournaments and chest cycles.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function performance()
    {
        $this->requireAdmin();

        $period = (int)$this->request->getQuery('period', MemberPerformanceService::DEFAULT_PERIOD);
        if (!array_key_exists($period, MemberPerformanceService::PERIODS)) {
            $period = MemberPerformanceService::DEFAULT_PERIOD;
        }

        $service = new MemberPerformanceService();
        $data = $service->getPerformanceData($period);

        $selectedPlayer = (string)$this->request->getQuery('player', '');

        $this->set(compact('data', 'period', 'selectedPlayer'));
        $this->set('periods', MemberPerformanceService::PERIODS);
    }

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
     * The clan's power over time, and how much each member grew.
     *
     * Query: `period` (days, one of MemberPowerHistoryService::PERIODS; 0 is
     * everything) and `player` (a member id, to draw their line too).
     *
     * The administrators always see it. With `clan_data_function` on, so does
     * everyone else, signed in or not, from the Clan Data menu.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function history()
    {
        $isAdmin = $this->isAdmin();
        if (!$isAdmin && !$this->fetchTable('Config')->isOn('clan_data_function')) {
            throw new NotFoundException();
        }
        $this->set('isAdmin', $isAdmin);

        $period = (int)$this->request->getQuery('period', MemberPowerHistoryService::DEFAULT_PERIOD);
        if (!in_array($period, MemberPowerHistoryService::PERIODS, true)) {
            $period = MemberPowerHistoryService::DEFAULT_PERIOD;
        }

        $service = new MemberPowerHistoryService();
        $from = $service->periodStart($period);
        $clanSeries = $service->clanSeries($from);
        $growth = $service->growth($from);

        $player = null;
        $playerSeries = [];
        $playerId = (int)$this->request->getQuery('player');
        if ($playerId > 0) {
            $player = $this->Members->find()->where(['Members.id' => $playerId])->first();
            if ($player !== null) {
                $playerSeries = $service->playerSeries((int)$player->id, $from);
            }
        }

        $this->set(compact('period', 'clanSeries', 'growth', 'player', 'playerSeries'));
        $this->set('periods', MemberPowerHistoryService::PERIODS);
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
