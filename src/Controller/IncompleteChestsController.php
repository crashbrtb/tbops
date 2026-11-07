<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\IncompleteChest;
use App\Model\Table\IncompleteChestsTable;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * IncompleteChests Controller
 *
 * The review queue for chests the collector opened but could not record. A game
 * type that neither `game_chest_types` nor the game's own chest catalogue names
 * is named once on the index, for all its chests (see
 * IncompleteChestsTable::nameCode). Those chests are not reviewed one by one.
 *
 * From here a chest is either finished by hand - which creates the
 * collected_chests row it should have become - or marked as examined and not
 * recoverable. Either way the row stays, so the same chest is never reviewed
 * twice and the record of what went wrong survives.
 *
 * @property \App\Model\Table\IncompleteChestsTable $IncompleteChests
 */
class IncompleteChestsController extends AppController
{
    /**
     * Initialize method
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->setLayout('CakeLte/layout/default');
    }

    /**
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);
    }

    /**
     * Index method - the queue, pending first.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $this->requireAdmin();

        $status = $this->request->getQuery('status', IncompleteChest::STATUS_PENDING);
        $query = $this->IncompleteChests->find('withoutImage');

        if ($status !== 'all') {
            $query = $query->where(['status' => $status]);
        }
        if ($status === IncompleteChest::STATUS_PENDING) {
            $query = $query->where(['NOT' => IncompleteChestsTable::AWAITING_NAME]);
        }

        $query = $query->orderBy(['collected_at' => 'DESC']);

        $incompleteChests = $this->paginate($query, ['limit' => 25]);
        $counts = [
            IncompleteChest::STATUS_PENDING => $this->IncompleteChests->find()
                ->where(['status' => IncompleteChest::STATUS_PENDING, 'NOT' => IncompleteChestsTable::AWAITING_NAME])->count(),
            IncompleteChest::STATUS_CORRECTED => $this->IncompleteChests->find()
                ->where(['status' => IncompleteChest::STATUS_CORRECTED])->count(),
            IncompleteChest::STATUS_UNRESOLVED => $this->IncompleteChests->find()
                ->where(['status' => IncompleteChest::STATUS_UNRESOLVED])->count(),
        ];

        $typesToName = $this->IncompleteChests->typesToName();
        $knownChests = $knownSources = [];
        if ($typesToName) {
            [$knownChests, $knownSources] = $this->knownNames();
        }

        $this->set(compact('incompleteChests', 'status', 'counts', 'typesToName', 'knownChests', 'knownSources'));
    }

    /**
     * Names a new game type: every chest waiting with it is recorded.
     *
     * @return \Cake\Http\Response|null Redirects.
     */
    public function nameCode()
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post']);

        $typeId = (int)$this->request->getData('game_type_id');
        $name = trim((string)$this->request->getData('name'));
        $source = trim((string)$this->request->getData('source'));

        if ($name === '' || $source === '' || mb_strlen($name) > 50 || mb_strlen($source) > 50) {
            $this->Flash->error(__('Fill in the chest name and the source.'));

            return $this->redirect(['action' => 'index']);
        }

        $recorded = $this->IncompleteChests->nameCode($typeId, $name, $source, $this->currentUserId());
        $this->Flash->success(__('{0} chest(s) recorded as "{1}".', $recorded, $name));

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Gives up on a new game type: its chests are marked as not recoverable.
     *
     * @return \Cake\Http\Response|null Redirects.
     */
    public function dropCode()
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post']);

        $count = $this->IncompleteChests->dropCode((int)$this->request->getData('game_type_id'), $this->currentUserId());
        $this->Flash->success(__('{0} chest(s) marked as not recoverable.', $count));

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Offered as suggestions in the forms: the names already in use, so a correction
     * lands on an existing spelling instead of inventing a variant to merge later.
     *
     * @return array{0: list<string>, 1: list<string>} Chest names and sources.
     */
    private function knownNames(): array
    {
        $knownChests = TableRegistry::getTableLocator()->get('CollectedChests')->find()
            ->select(['name'])->distinct(['name'])->orderBy(['name' => 'ASC'])
            ->all()->extract('name')->toList();
        $knownSources = TableRegistry::getTableLocator()->get('StandardChests')->find()
            ->select(['source'])->distinct(['source'])->orderBy(['source' => 'ASC'])
            ->all()->extract('source')->toList();

        return [$knownChests, $knownSources];
    }

    /**
     * View method - what the game said about the chest, and what to do about it.
     *
     * @param string|null $id Incomplete Chest id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $this->requireAdmin();

        $incompleteChest = $this->IncompleteChests->find('withoutImage')
            ->where(['IncompleteChests.id' => $id])
            ->firstOrFail();

        // A chest of a type without a name is settled with its type, on the index.
        if ($incompleteChest->is_pending && $incompleteChest->game_type_id !== null && !$incompleteChest->name) {
            return $this->redirect(['action' => 'index']);
        }

        $knownPlayers = TableRegistry::getTableLocator()->get('CollectedChests')->find()
            ->select(['player'])->distinct(['player'])->orderBy(['player' => 'ASC'])
            ->all()->extract('player')->toList();
        [$knownChests, $knownSources] = $this->knownNames();

        $this->set(compact('incompleteChest', 'knownPlayers', 'knownChests', 'knownSources'));
    }

    /**
     * Correct method - what the chest should have been.
     *
     * Writes the collected_chests row this chest should have produced, keeping
     * the ORIGINAL collection time: scoring runs in cycles, and a chest
     * recovered today still belongs to the day it was collected.
     *
     * @param string|null $id Incomplete Chest id.
     * @return \Cake\Http\Response|null Redirects on success.
     */
    public function correct($id = null)
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'put']);

        $incompleteChest = $this->IncompleteChests->get($id);

        if (!$incompleteChest->is_pending) {
            $this->Flash->error(__('This chest has already been reviewed.'));

            return $this->redirect(['action' => 'view', $id]);
        }

        $data = $this->request->getData();
        $candidate = $this->IncompleteChests->newEntity([
            'name' => trim((string)($data['name'] ?? '')),
            'player' => trim((string)($data['player'] ?? '')),
            'source' => trim((string)($data['source'] ?? '')),
        ], ['validate' => 'correction']);

        if ($candidate->getErrors()) {
            $this->Flash->error(__('Fill in the chest name, the player and the source.'));

            return $this->redirect(['action' => 'view', $id]);
        }

        $collectedChests = TableRegistry::getTableLocator()->get('CollectedChests');
        $chest = IncompleteChestsTable::asCollected($collectedChests, $incompleteChest, $candidate->player, $candidate->name, $candidate->source);

        if (!$collectedChests->save($chest)) {
            $this->Flash->error(__('The chest could not be saved into collected chests.'));

            return $this->redirect(['action' => 'view', $id]);
        }

        $incompleteChest = $this->IncompleteChests->patchEntity($incompleteChest, [
            'name' => $candidate->name,
            'player' => $candidate->player,
            'source' => $candidate->source,
            'status' => IncompleteChest::STATUS_CORRECTED,
            'collected_chest_id' => $chest->id,
            'review_notes' => trim((string)($data['review_notes'] ?? '')) ?: null,
            'reviewed_by' => $this->currentUserId(),
            'reviewed_at' => new DateTime(),
        ]);

        if ($this->IncompleteChests->save($incompleteChest)) {
            $this->Flash->success(__(
                'Chest corrected and recorded for "{0}". Collected chest #{1}.',
                $candidate->player,
                $chest->id
            ));
            $others = $this->IncompleteChests->nameType($incompleteChest, $candidate->name, $candidate->source, $this->currentUserId());
            if ($others > 0) {
                $this->Flash->success(__('{0} other chest(s) of this type were recorded with the same name.', $others));
            }
        } else {
            // The chest is already in collected_chests; say so plainly rather
            // than letting it look as though nothing happened.
            $this->Flash->error(__(
                'The chest was recorded as #{0}, but this row could not be marked as corrected.',
                $chest->id
            ));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Unresolved method - examined, and nothing could be recovered from it.
     *
     * @param string|null $id Incomplete Chest id.
     * @return \Cake\Http\Response|null Redirects.
     */
    public function unresolved($id = null)
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'put']);

        $incompleteChest = $this->IncompleteChests->get($id);

        if (!$incompleteChest->is_pending) {
            $this->Flash->error(__('This chest has already been reviewed.'));

            return $this->redirect(['action' => 'view', $id]);
        }

        $incompleteChest = $this->IncompleteChests->patchEntity($incompleteChest, [
            'status' => IncompleteChest::STATUS_UNRESOLVED,
            'review_notes' => trim((string)$this->request->getData('review_notes')) ?: null,
            'reviewed_by' => $this->currentUserId(),
            'reviewed_at' => new DateTime(),
        ]);

        if ($this->IncompleteChests->save($incompleteChest)) {
            $this->Flash->success(__('Marked as reviewed and not recoverable.'));
        } else {
            $this->Flash->error(__('The record could not be updated. Please, try again.'));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Bulk unresolved method - marks selected (or all pending) chests as not recoverable.
     *
     * @return \Cake\Http\Response|null Redirects.
     */
    public function bulkUnresolved()
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post']);

        $status = (string)$this->request->getData('status', $this->request->getQuery('status', 'pending'));
        $redirectUrl = ['action' => 'index', '?' => ['status' => $status]];

        $markAllPending = (bool)$this->request->getData('mark_all_pending');
        $now = new DateTime();
        $userId = $this->currentUserId();

        if ($markAllPending) {
            $count = $this->IncompleteChests->updateAll(
                [
                    'status' => IncompleteChest::STATUS_UNRESOLVED,
                    'reviewed_by' => $userId,
                    'reviewed_at' => $now->format('Y-m-d H:i:s'),
                ],
                [
                    'status' => IncompleteChest::STATUS_PENDING,
                    'NOT' => IncompleteChestsTable::AWAITING_NAME,
                ]
            );

            if ($count > 0) {
                $this->Flash->success(__('{0} pending chest(s) marked as not recoverable.', $count));
            } else {
                $this->Flash->info(__('No pending chests were found to update.'));
            }

            return $this->redirect($redirectUrl);
        }

        $ids = (array)$this->request->getData('ids');
        $ids = array_values(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            $this->Flash->warning(__('No chests selected.'));

            return $this->redirect($redirectUrl);
        }

        $count = $this->IncompleteChests->updateAll(
            [
                'status' => IncompleteChest::STATUS_UNRESOLVED,
                'reviewed_by' => $userId,
                'reviewed_at' => $now->format('Y-m-d H:i:s'),
            ],
            [
                'id IN' => $ids,
                'status' => IncompleteChest::STATUS_PENDING,
            ]
        );

        if ($count > 0) {
            $this->Flash->success(__('{0} chest(s) marked as not recoverable.', $count));
        } else {
            $this->Flash->info(__('No pending chests were updated.'));
        }

        return $this->redirect($redirectUrl);
    }

    /**
     * Reopen method - puts a reviewed chest back in the queue.
     *
     * For a decision made in error. A chest that was corrected keeps its
     * collected_chests row: undoing that is a deletion, and deletions belong in
     * the collected chests screen where they can be seen.
     *
     * @param string|null $id Incomplete Chest id.
     * @return \Cake\Http\Response|null Redirects.
     */
    public function reopen($id = null)
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'put']);

        $incompleteChest = $this->IncompleteChests->get($id);
        $incompleteChest = $this->IncompleteChests->patchEntity($incompleteChest, [
            'status' => IncompleteChest::STATUS_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        if ($this->IncompleteChests->save($incompleteChest)) {
            $this->Flash->success(__('Back in the queue.'));
        } else {
            $this->Flash->error(__('The record could not be updated. Please, try again.'));
        }

        return $this->redirect(['action' => 'view', $id]);
    }
}
