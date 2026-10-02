<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\Event;
use App\Model\Entity\EventAsset;
use App\Model\Entity\EventImport;
use App\Model\Entity\EventReward;
use App\Model\Table\EventAssetsTable;
use App\Service\EventImportService;
use App\Service\EventScoringService;
use DomainException;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\Utility\Hash;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Events Controller
 *
 * The player-facing half (the current event, the history, the banner images) is
 * public, because the score page it hangs off is public. Everything that creates
 * or changes an event is behind requireAdmin().
 *
 * @property \App\Model\Table\EventsTable $Events
 */
class EventsController extends AppController
{
    /**
     * Images an administrator may upload as a banner, and the extension each
     * one is served with.
     *
     * @var array<int, string>
     */
    private const ALLOWED_IMAGE_TYPES = [
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_GIF => 'image/gif',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    /**
     * Upload ceiling. A banner is a strip a few hundred pixels wide; anything
     * near this is already a mistake.
     */
    private const MAX_IMAGE_BYTES = 3145728;

    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        parent::initialize();

        // The score page is public and the banner lives on it, so the pages the
        // banner leads to have to be readable without an account.
        $this->Authentication->allowUnauthenticated([
            'index', 'view', 'history', 'banner', 'asset',
        ]);
    }

    /**
     * The current event: its dashboard, or an explanation that there is none.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $event = $this->Events->currentEvent();

        if ($event === null) {
            $upcoming = $this->Events->find('upcoming')->limit(5)->all()->toList();
            $recent = $this->Events->find('past')->limit(5)->all()->toList();
            $this->set(compact('upcoming', 'recent'));

            return $this->render('no_event');
        }

        return $this->redirect(['action' => 'view', $event->id]);
    }

    /**
     * One event's dashboard: the standings plus everything a player needs to
     * know about the rules, the prize and who to talk to.
     *
     * @param string|null $id Event id.
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function view(?string $id = null)
    {
        $event = $this->Events->find('withoutBanner')
            ->where(['Events.id' => $id])
            ->contain(['EventChests' => ['StandardChests'], 'EventRewards', 'GameTournaments' => ['fields' => ['id', 'game_type', 'name', 'duration_days', 'image_mime', 'modified']]])
            ->firstOrFail();

        if ($event->is_imported) {
            return $this->viewImported($event);
        }

        $results = (new EventScoringService())->resultsFor($event);

        $runningEvent = $this->Events->currentEvent();

        $this->set('cakelte_theme', [
            'sidebar' => ['enable' => false],
            'navbar' => ['enable' => true],
        ]);

        $this->set(compact('event', 'results', 'runningEvent'));
    }

    /**
     * Everything that has already happened, plus what is coming next.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function history()
    {
        $this->paginate = [
            'limit' => 20,
            'finder' => 'past',
        ];

        $events = $this->paginate($this->Events);
        $upcoming = $this->Events->find('upcoming')->all()->toList();
        $running = $this->Events->find('running')->all()->toList();

        $this->set(compact('events', 'upcoming', 'running'));
    }

    /**
     * Serve one event's own banner.
     *
     * Its own action rather than a data URI: the image is on the score page,
     * which every player loads constantly, and this way the browser caches it.
     *
     * @param string|null $id Event id.
     * @return \Cake\Http\Response
     */
    public function banner(?string $id = null): Response
    {
        $event = $this->Events->find()
            ->select(['id', 'banner_image', 'banner_mime', 'modified'])
            ->where(['Events.id' => $id])
            ->first();

        if ($event === null || empty($event->banner_image)) {
            // Not an error: an event without its own artwork uses the default,
            // and sending the caller there keeps the template free of branching.
            return $this->redirect(['action' => 'asset', EventAsset::SLUG_EVENT_LIVE]);
        }

        return $this->sendImage(
            $this->toBytes($event->banner_image),
            (string)($event->banner_mime ?: 'image/png')
        );
    }

    /**
     * Serve one of the two default banners.
     *
     * @param string|null $slug event-live or no-event.
     * @return \Cake\Http\Response
     */
    public function asset(?string $slug = null): Response
    {
        $slug = (string)$slug;
        if (!array_key_exists($slug, EventAssetsTable::defaultLabels())) {
            throw new NotFoundException(__('Unknown banner.'));
        }

        $assets = TableRegistry::getTableLocator()->get('EventAssets');
        $asset = $assets->find()->where(['slug' => $slug])->first();
        if ($asset === null) {
            throw new NotFoundException(__('This banner has not been set up yet.'));
        }

        return $this->sendImage($this->toBytes($asset->image), (string)$asset->mime);
    }

    /**
     * Administration list: every event, whatever its state.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function manage()
    {
        $this->requireAdmin();

        $this->paginate = [
            'limit' => 25,
            'finder' => 'withoutBanner',
            'order' => ['Events.event_number' => 'DESC'],
        ];

        $events = $this->paginate($this->Events);
        $nextNumber = $this->Events->nextEventNumber();

        $this->set(compact('events', 'nextNumber'));
    }

    /**
     * Create an event.
     *
     * @return \Cake\Http\Response|null|void Redirects on success.
     */
    public function add()
    {
        $this->requireAdmin();

        $event = $this->Events->newEmptyEntity();
        $event->set('criteria', $this->request->getQuery('type') === Event::CRITERIA_IMPORTED
            ? Event::CRITERIA_IMPORTED
            : Event::CRITERIA_CHEST_SCORE);
        $event->set('custom_metric', Event::METRIC_SCORE);

        if ($this->request->is('post')) {
            $event = $this->save($event);
            if ($event instanceof Response) {
                return $event;
            }
        }

        $this->set('nextNumber', $this->Events->nextEventNumber());
        $this->prepareForm($event);

        return $this->render('form');
    }

    /**
     * Edit an event.
     *
     * @param string|null $id Event id.
     * @return \Cake\Http\Response|null|void Redirects on success.
     */
    public function edit(?string $id = null)
    {
        $this->requireAdmin();

        $event = $this->Events->get($id, contain: ['EventChests', 'EventRewards']);

        if ($this->request->is(['patch', 'post', 'put'])) {
            $saved = $this->save($event);
            if ($saved instanceof Response) {
                return $saved;
            }
            $event = $saved;
        }

        $this->set('nextNumber', $event->event_number);
        $this->prepareForm($event);

        return $this->render('form');
    }

    /**
     * Close an event and freeze its standings.
     *
     * Runs automatically for the administrator who asks, rather than on a
     * schedule, because a snapshot taken before somebody fixes a misread chest
     * would preserve the mistake. See EventScoringService::finalize().
     *
     * @param string|null $id Event id.
     * @return \Cake\Http\Response Redirects back.
     */
    public function finalize(?string $id = null): Response
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'put']);

        $event = $this->Events->get($id);

        if ($event->is_imported) {
            // A game tournament's result comes from its reviewed ranking, not
            // from collected chests.
            return $this->redirect(['action' => 'review', $event->id]);
        }

        // Only a closed window can be recorded. While an event is still running
        // its dashboard is live and any snapshot would be out of date the moment
        // the next chest is collected.
        if ($event->state === Event::STATE_SCHEDULED || $event->state === Event::STATE_RUNNING) {
            $this->Flash->warning(__('An event can only be closed once its end date has passed.'));

            return $this->redirect(['action' => 'manage']);
        }

        $recorded = (new EventScoringService())->finalize($event);

        $event->set('finalized_at', DateTime::now());
        if ($this->Events->save($event, ['checkRules' => false])) {
            $this->Flash->success(__(
                'Results for event #{0} were recorded: {1} player(s) ranked.',
                $event->event_number,
                $recorded
            ));
        } else {
            $this->Flash->error(__('The results could not be recorded. Please, try again.'));
        }

        return $this->redirect(['action' => 'view', $event->id]);
    }

    /**
     * Cancel an event, or bring a cancelled one back.
     *
     * @param string|null $id Event id.
     * @return \Cake\Http\Response Redirects back.
     */
    public function cancel(?string $id = null): Response
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'put']);

        $event = $this->Events->get($id);
        $reopening = $event->status === Event::STATUS_CANCELLED;
        $event->set('status', $reopening ? Event::STATUS_SCHEDULED : Event::STATUS_CANCELLED);

        // Rules are skipped deliberately: an event that has already run cannot
        // satisfy "the start date must be in the future", and cancelling one
        // must not depend on its window still being ahead of us.
        if ($this->Events->save($event, ['checkRules' => false])) {
            $this->Flash->success($reopening
                ? __('Event #{0} is active again.', $event->event_number)
                : __('Event #{0} was cancelled.', $event->event_number));
        } else {
            $this->Flash->error(__('The event could not be updated. Please, try again.'));
        }

        return $this->redirect(['action' => 'manage']);
    }

    /**
     * Delete an event and everything hanging off it.
     *
     * @param string|null $id Event id.
     * @return \Cake\Http\Response Redirects to the list.
     */
    public function delete(?string $id = null): Response
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'delete']);

        $event = $this->Events->get($id);
        if ($this->Events->delete($event)) {
            $this->Flash->success(__('Event #{0} was deleted.', $event->event_number));
        } else {
            $this->Flash->error(__('The event could not be deleted. Please, try again.'));
        }

        return $this->redirect(['action' => 'manage']);
    }

    /**
     * Replace the two default banners shown on the score page.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function settings()
    {
        $this->requireAdmin();

        $assets = TableRegistry::getTableLocator()->get('EventAssets');

        if ($this->request->is(['post', 'put'])) {
            $changed = 0;
            $rejected = 0;
            foreach (array_keys(EventAssetsTable::defaultLabels()) as $slug) {
                $upload = $this->request->getData($slug);
                if (!$upload instanceof UploadedFileInterface || $upload->getError() === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                try {
                    [$bytes, $mime] = $this->readImageUpload($upload);
                } catch (BadRequestException $e) {
                    $this->Flash->error($e->getMessage());
                    $rejected++;
                    continue;
                }

                if ($assets->replaceImage($slug, $bytes, $mime)) {
                    $changed++;
                } else {
                    $this->Flash->error(__('The banner "{0}" could not be saved.', $slug));
                    $rejected++;
                }
            }

            if ($changed > 0) {
                $this->Flash->success(__('{0} banner(s) updated.', $changed));

                return $this->redirect(['action' => 'settings']);
            }

            if ($rejected === 0) {
                $this->Flash->warning(__('No image was chosen, so nothing changed.'));
            }
        }

        $current = $assets->find('withoutImage')->all()->indexBy('slug')->toArray();

        $this->set(compact('current'));
    }

    /**
     * Shared save path for add and edit.
     *
     * @param \App\Model\Entity\Event $event The event being written.
     * @return \Cake\Http\Response|\App\Model\Entity\Event A redirect on success, the patched entity otherwise.
     */
    private function save(Event $event): Response|Event
    {
        $data = $this->request->getData();
        $isNew = $event->isNew();

        $data['event_chests'] = $this->chestRows($data);
        unset($data['chest_ids'], $data['banner'], $data['remove_banner']);

        // Clearing the artwork is a checkbox on this same form rather than its
        // own action, so the two ways of changing a banner cannot be submitted
        // at once and leave the outcome to whichever ran last.
        $removeBanner = (bool)$this->request->getData('remove_banner');
        if ($removeBanner) {
            $data['banner_image'] = null;
            $data['banner_mime'] = null;
        }

        $banner = $this->request->getData('banner');
        if (
            !$removeBanner
            && $banner instanceof UploadedFileInterface
            && $banner->getError() !== UPLOAD_ERR_NO_FILE
        ) {
            try {
                [$bytes, $mime] = $this->readImageUpload($banner);
                $data['banner_image'] = $bytes;
                $data['banner_mime'] = $mime;
            } catch (BadRequestException $e) {
                $this->Flash->error($e->getMessage());
            }
        }

        if ($isNew) {
            $data['created_by'] = $this->currentUserId();
        }

        // A published game tournament stays a game tournament: its result is
        // frozen. Its rewards can still change, and are split again over the
        // published standings without touching anybody's points.
        $published = !$isNew && $event->is_imported && $event->published_at !== null;
        if ($published) {
            $data['criteria'] = Event::CRITERIA_IMPORTED;
        }

        $event = $this->Events->patchEntity($event, $data, [
            'associated' => ['EventChests', 'EventRewards'],
        ]);

        $allocations = null;
        try {
            $saved = $this->Events->getConnection()->transactional(function () use ($event, $published, &$allocations): bool {
                if (!$this->Events->save($event)) {
                    return false;
                }
                if ($published) {
                    $allocations = (new EventImportService())->redistribute($event);
                }

                return true;
            });
        } catch (DomainException $e) {
            // Rolled back: start again from what is stored, not from entities
            // that were given ids the database no longer has.
            $this->Flash->error(__('The rewards were not saved: {0}', $e->getMessage()));

            return $this->redirect(['action' => 'edit', $event->id]);
        }

        if ($saved) {
            $this->Flash->success($isNew
                ? __('Event #{0} was created.', $event->event_number)
                : __('Event #{0} was saved.', $event->event_number));
            if ($allocations !== null) {
                $this->Flash->success(__('The rewards were split again over the published result; the points did not change.'));
            }

            // A game tournament has nothing to show until its ranking arrives:
            // its review page is where the administrator goes next.
            return $this->redirect(['action' => $event->is_imported ? 'review' : 'view', $event->id]);
        }

        $this->Flash->error(__('The event could not be saved. Please check the fields below.'));

        return $event;
    }

    /**
     * Turn the checked chest ids into association rows, snapshotting the source.
     *
     * @param array<string, mixed> $data Submitted form data.
     * @return list<array{standard_chest_id: int, source: string}>
     */
    private function chestRows(array $data): array
    {
        $ids = array_filter(array_map('intval', (array)($data['chest_ids'] ?? [])));
        if (!$ids) {
            return [];
        }

        $sources = TableRegistry::getTableLocator()->get('StandardChests')
            ->find('list', keyField: 'id', valueField: 'source')
            ->where(['id IN' => $ids])
            ->toArray();

        $rows = [];
        foreach ($ids as $id) {
            if (!isset($sources[$id])) {
                continue;
            }
            $rows[] = ['standard_chest_id' => $id, 'source' => $sources[$id]];
        }

        return $rows;
    }

    /**
     * Validate an uploaded image and return its bytes and mime type.
     *
     * The type comes from reading the file rather than from the browser's
     * Content-Type header, which the client controls.
     *
     * @param \Psr\Http\Message\UploadedFileInterface $file The upload.
     * @return array{0: string, 1: string} Bytes and mime type.
     * @throws \Cake\Http\Exception\BadRequestException When the file is unusable.
     */
    private function readImageUpload(UploadedFileInterface $file): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new BadRequestException(__('The image could not be uploaded. Please, try again.'));
        }

        $size = $file->getSize();
        if ($size !== null && $size > self::MAX_IMAGE_BYTES) {
            throw new BadRequestException(__(
                'The image is too large. The limit is {0} MB.',
                round(self::MAX_IMAGE_BYTES / 1048576, 1)
            ));
        }

        $bytes = (string)$file->getStream();
        if ($bytes === '') {
            throw new BadRequestException(__('The image is empty.'));
        }

        // Silenced deliberately: the bytes came from an upload, so a file that is
        // not an image at all is an expected input, not a programming error. The
        // false return below is what reports it.
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset(self::ALLOWED_IMAGE_TYPES[$info[2]])) {
            throw new BadRequestException(__('Use a PNG, JPEG, GIF or WebP image.'));
        }

        return [$bytes, self::ALLOWED_IMAGE_TYPES[$info[2]]];
    }

    /**
     * Send image bytes with caching headers.
     *
     * Cached publicly and briefly: the banner is on a public page and changes
     * only when an event starts, ends, or somebody uploads new artwork.
     *
     * @param string $bytes Raw image data.
     * @param string $mime Mime type.
     * @return \Cake\Http\Response
     */
    private function sendImage(string $bytes, string $mime): Response
    {
        return $this->response
            ->withType($mime)
            ->withHeader('Cache-Control', 'public, max-age=300')
            ->withStringBody($bytes);
    }

    /**
     * Blob columns come back as a stream on some drivers and a string on others.
     *
     * @param mixed $value The column value.
     * @return string
     */
    private function toBytes(mixed $value): string
    {
        if (is_resource($value)) {
            return (string)stream_get_contents($value);
        }

        return (string)$value;
    }

    /**
     * View variables every form render needs.
     *
     * @param \App\Model\Entity\Event $event The event being edited.
     * @return void
     */
    private function prepareForm(Event $event): void
    {
        // Only chests that are worth something can be picked: a chest with no
        // score cannot decide a ranking, and listing every unscored source would
        // bury the ones that matter.
        $scoredChests = TableRegistry::getTableLocator()->get('StandardChests')->find()
            ->where(['score !=' => 0])
            ->orderBy(['monster' => 'DESC', 'score' => 'DESC', 'source' => 'ASC'])
            ->all()
            ->toList();

        $selectedChestIds = [];
        foreach ((array)$event->event_chests as $chest) {
            $selectedChestIds[] = (int)$chest->standard_chest_id;
        }

        $this->set(compact('event', 'scoredChests', 'selectedChestIds'));
        $this->set('criteriaOptions', Event::criteriaOptions());
        $this->set('criteriaHints', Event::criteriaHints());
        $this->set('ruleOptions', EventReward::ruleOptions());
        $this->set('remainderOptions', EventReward::remainderOptions());
    }

    /**
     * A game tournament's page: its published result with every player's share.
     *
     * Until the result is published the page does not exist for players, only
     * for administrators, who are sent to the review instead.
     *
     * @param \App\Model\Entity\Event $event The event, rewards loaded.
     * @return \Cake\Http\Response|null
     */
    private function viewImported(Event $event): ?Response
    {
        if ($event->published_at === null) {
            if ($this->isAdmin()) {
                return $this->redirect(['action' => 'review', $event->id]);
            }
            throw new NotFoundException(__('This event has no published result yet.'));
        }

        $result = (new EventImportService())->publishedResult($event);
        $isAdmin = $this->isAdmin();

        $this->set('cakelte_theme', [
            'sidebar' => ['enable' => false],
            'navbar' => ['enable' => true],
        ]);
        $this->set(compact('event', 'result', 'isAdmin'));

        return $this->render('view_imported');
    }

    /**
     * Review a game tournament's uploaded ranking, and publish it.
     *
     * GET shows the current draft (or the published import) with each player's
     * share of every reward. POST does one of:
     * - `intent=upload`: take a CSV ranking (the discovery tool's ranking.csv)
     * - `intent=save`: store the corrections made in the table
     * - `intent=publish`: store them and publish the result
     *
     * @param string|null $id Event id.
     * @return \Cake\Http\Response|null|void
     */
    public function review(?string $id = null)
    {
        $this->requireAdmin();

        $event = $this->Events->find('withoutBanner')
            ->where(['Events.id' => $id])
            ->contain(['EventRewards', 'GameTournaments' => ['fields' => ['id', 'game_type', 'name', 'duration_days', 'image_mime', 'modified']]])
            ->firstOrFail();

        if (!$event->is_imported) {
            $this->Flash->warning(__('Only game tournaments have a ranking to review.'));

            return $this->redirect(['action' => 'view', $event->id]);
        }

        $service = new EventImportService();
        $imports = $this->fetchTable('EventImports');

        if ($this->request->is(['post', 'put'])) {
            $intent = (string)$this->request->getData('intent');

            if ($intent === 'upload') {
                $this->uploadCsv($service, $event);

                return $this->redirect(['action' => 'review', $event->id]);
            }

            if ($intent === 'dates') {
                $this->saveDates($event);

                return $this->redirect(['action' => 'review', $event->id]);
            }

            $import = $imports->current($event->id);
            if ($import === null || $import->status !== EventImport::STATUS_DRAFT) {
                $this->Flash->warning(__('There is no draft ranking to change.'));

                return $this->redirect(['action' => 'review', $event->id]);
            }

            try {
                $changed = $service->applyReview($import, (array)$this->request->getData('rows'));
                if ($intent === 'publish') {
                    $recorded = $service->publish($event, $imports->current($event->id));
                    $this->Flash->success(__('The result of event #{0} was published: {1} player(s).', $event->event_number, $recorded));

                    return $this->redirect(['action' => 'view', $event->id]);
                }
                $this->Flash->success(__('{0} change(s) saved. The shares below are recalculated.', $changed));
            } catch (DomainException $e) {
                $this->Flash->error($e->getMessage());
            }

            return $this->redirect(['action' => 'review', $event->id]);
        }

        $import = $imports->current($event->id);
        $preview = $import !== null ? $service->preview($event, $import) : null;

        $history = $imports->find()
            ->where(['EventImports.event_id' => $event->id])
            ->contain(['Users'])
            ->orderBy(['EventImports.id' => 'DESC'])
            ->limit(10)
            ->all()
            ->toList();

        $members = [];
        foreach ($this->fetchTable('Members')->find()->select(['id', 'player', 'administrative_account'])->orderBy(['player' => 'ASC'])->all() as $member) {
            $members[$member->id] = $member->player . ($member->administrative_account ? ' (' . __('administrative') . ')' : '');
        }

        $this->set(compact('event', 'import', 'preview', 'history', 'members'));
    }

    /**
     * Take a published result down so the ranking can be corrected.
     *
     * @param string|null $id Event id.
     * @return \Cake\Http\Response
     */
    public function unpublish(?string $id = null): Response
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post']);

        $event = $this->Events->get($id);
        if (!$event->is_imported || $event->published_at === null) {
            $this->Flash->warning(__('This event has no published result.'));

            return $this->redirect(['action' => 'manage']);
        }

        (new EventImportService())->unpublish($event);
        $this->Flash->success(__('The result of event #{0} is a draft again and no longer public.', $event->event_number));

        return $this->redirect(['action' => 'review', $event->id]);
    }

    /**
     * Start a new event from an existing one: same rules, same rewards, dated
     * today. Tournaments repeat every day with the same prizes.
     *
     * @param string|null $id Event id.
     * @return \Cake\Http\Response
     */
    public function duplicate(?string $id = null): Response
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post']);

        $source = $this->Events->get($id, contain: ['EventRewards', 'EventChests']);

        $today = DateTime::now()->setTime(0, 0);
        $isImported = $source->is_imported;
        $data = [
            'name' => $source->name,
            'description' => $source->description,
            'criteria' => $source->criteria,
            'custom_metric' => $source->custom_metric,
            'prize' => $isImported ? '' : $source->prize,
            'contact_player' => $source->contact_player,
            // A chest event has to start in the future; a tournament is dated
            // the day it is played.
            'starts_at' => $isImported ? $today : DateTime::now()->addHours(1),
            'ends_at' => $isImported ? $today->setTime(23, 59) : DateTime::now()->addHours(1)->addSeconds(
                $source->ends_at->getTimestamp() - $source->starts_at->getTimestamp()
            ),
            'event_chests' => array_map(fn ($c) => ['standard_chest_id' => $c->standard_chest_id, 'source' => $c->source], (array)$source->event_chests),
            'event_rewards' => array_map(fn (EventReward $r) => [
                'item_name' => $r->item_name,
                'quantity' => $r->quantity,
                'rule' => $r->rule,
                'min_points' => $r->min_points,
                'remainder' => $r->remainder,
            ], (array)$source->event_rewards),
        ];

        $copy = $this->Events->newEntity($data, ['associated' => ['EventChests', 'EventRewards']]);
        $copy->set('created_by', $this->currentUserId());

        if ($this->Events->save($copy, ['associated' => ['EventChests', 'EventRewards']])) {
            $this->Flash->success(__('Event #{0} was created from #{1}. Check the name and the date.', $copy->event_number, $source->event_number));

            return $this->redirect(['action' => 'edit', $copy->id]);
        }

        $this->Flash->error(__(
            'The event could not be duplicated: {0}',
            implode(' ', array_values(Hash::flatten($copy->getErrors())))
        ));

        return $this->redirect(['action' => 'manage']);
    }

    /**
     * Change when a game tournament ran. The game only reports the end; the
     * start is estimated from the catalogue's duration and corrected here.
     *
     * @param \App\Model\Entity\Event $event The event.
     * @return void
     */
    private function saveDates(Event $event): void
    {
        $data = [
            'starts_at' => (string)$this->request->getData('starts_at'),
            'ends_at' => (string)$this->request->getData('ends_at'),
        ];
        $events = $this->Events;
        $entity = $events->patchEntity($events->get($event->id), $data, ['fields' => ['starts_at', 'ends_at']]);

        if ($events->save($entity, ['allowNoRewards' => true])) {
            $this->Flash->success(__('The dates of event #{0} were saved.', $event->event_number));

            return;
        }

        $this->Flash->error(__('The dates were not saved: {0}', implode(' ', array_values(Hash::flatten($entity->getErrors())))));
    }

    /**
     * Store a CSV ranking as the event's draft.
     *
     * @param \App\Service\EventImportService $service Import service.
     * @param \App\Model\Entity\Event $event The event.
     * @return void
     */
    private function uploadCsv(EventImportService $service, Event $event): void
    {
        $file = $this->request->getData('ranking_file');
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $this->Flash->error(__('Choose the ranking CSV file to upload.'));

            return;
        }
        if (($file->getSize() ?? 0) > 1048576) {
            $this->Flash->error(__('The file is too large for a ranking of at most {0} players.', EventImportService::MAX_ROWS));

            return;
        }

        $checked = $service->validate($service->parseCsv((string)$file->getStream()));
        if ($checked['errors']) {
            $this->Flash->error(__('The ranking was not accepted: {0}', implode(' ', array_slice($checked['errors'], 0, 5))));

            return;
        }

        try {
            $result = $service->import($event, $checked['payload'], $this->currentUserId());
            $this->Flash->success($result['created']
                ? __('Ranking with {0} player(s) received. Review it below.', count($checked['payload']['rows']))
                : __('This ranking is the same as the current draft; nothing changed.'));
        } catch (DomainException $e) {
            $this->Flash->error($e->getMessage());
        }
    }
}
