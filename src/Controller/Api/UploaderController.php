<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Entity\ApiToken;
use App\Model\Entity\Event;
use App\Model\Entity\EventImport;
use App\Model\Entity\EventReward;
use App\Model\Entity\JobRun;
use App\Service\EventImportService;
use App\Service\JobRunRecorder;
use App\Service\MemberRosterService;
use App\Service\TournamentCatalogService;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Cake\Routing\Router;
use DomainException;
use Throwable;

/**
 * API for the EventUploader desktop tool.
 *
 * Every request carries `Authorization: Bearer <token>`, a personal token an
 * administrator creates on the site. The session is never used, and the tool
 * never touches the database.
 *
 * The everyday call is `POST /tournaments`: the uploader sends the tournament
 * as the game identifies it plus its ranking, and the site registers the event
 * if it is new and attaches the ranking as a draft for review; with
 * `auto_publish: true` and every player identified, the result is published
 * (the tournament closed) in the same call. The older
 * `events/awaiting` and `events/{id}/imports` pair serves events an
 * administrator created by hand.
 *
 * Answers are always JSON, errors included, with the HTTP status saying what
 * went wrong: 401 bad token, 403 not an administrator, 404 no such event,
 * 409 the event cannot take a ranking now, 422 the ranking is invalid.
 */
class UploaderController extends Controller
{
    public const API_VERSION = 1;

    protected ?ApiToken $token = null;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        // Loaded only to be told these actions need no session identity; the
        // token check in beforeFilter() is what guards them.
        $this->loadComponent('Authentication.Authentication');
        $this->Authentication->allowUnauthenticated([
            'me', 'awaiting', 'import', 'tournament', 'known', 'catalog', 'lookup', 'searchState', 'searchReport',
        ]);
    }

    /**
     * Resolve the token before any action runs.
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event The event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // Authorization: Bearer is the standard place. Some hosts running PHP as
        // FastCGI drop that header before PHP sees it, so X-Api-Token is
        // accepted as well and the uploader can fall back to it.
        $plain = null;
        if (preg_match('/^Bearer\s+(\S+)\s*$/i', $this->request->getHeaderLine('Authorization'), $match)) {
            $plain = $match[1];
        } elseif (trim($this->request->getHeaderLine('X-Api-Token')) !== '') {
            $plain = trim($this->request->getHeaderLine('X-Api-Token'));
        }
        if ($plain === null) {
            $this->halt($event, 401, __('Send the API token in the Authorization header: Bearer <token>.'));

            return;
        }

        $tokens = $this->fetchTable('ApiTokens');
        $token = $tokens->findActiveByPlainToken($plain);
        if ($token === null) {
            $this->halt($event, 401, __('This API token is invalid, expired or revoked.'));

            return;
        }

        $user = $token->user;
        $isAdmin = $this->fetchTable('RolesUsers')->exists(['user_id' => $token->user_id, 'role_id' => 1]);
        if (!$isAdmin || ($user !== null && $user->has('active') && !$user->active)) {
            $this->halt($event, 403, __('Only an active administrator can use the uploader.'));

            return;
        }

        $tokens->recordUse($token, $this->request->clientIp());
        $this->token = $token;
    }

    /**
     * Who the token belongs to. The uploader calls it to test the connection.
     *
     * @return \Cake\Http\Response
     */
    public function me(): Response
    {
        $this->request->allowMethod(['get']);

        return $this->json([
            'api_version' => self::API_VERSION,
            'user' => ['id' => $this->token->user_id, 'name' => $this->token->user?->name],
            'token' => ['name' => $this->token->name, 'prefix' => $this->token->prefix],
        ]);
    }

    /**
     * Tournaments waiting for a ranking, for the uploader's picker.
     *
     * @return \Cake\Http\Response
     */
    public function awaiting(): Response
    {
        $this->request->allowMethod(['get']);

        $events = $this->fetchTable('Events')->find('awaitingImport')
            ->contain(['EventRewards'])
            ->limit(50)
            ->all();

        $drafts = [];
        $ids = $events->extract('id')->toList();
        if ($ids) {
            foreach (
                $this->fetchTable('EventImports')->find()
                    ->select(['event_id', 'row_count', 'created'])
                    ->where(['event_id IN' => $ids, 'status' => 'draft'])
                    ->all() as $draft
            ) {
                $drafts[$draft->event_id] = ['rows' => $draft->row_count, 'created' => $draft->created?->toIso8601String()];
            }
        }

        $out = [];
        foreach ($events as $event) {
            $out[] = [
                'id' => $event->id,
                'event_number' => $event->event_number,
                'name' => $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'rewards' => array_map(fn (EventReward $r): array => [
                    'item_name' => $r->item_name,
                    'quantity' => $r->quantity,
                    'rule' => $r->rule,
                ], (array)$event->event_rewards),
                'draft' => $drafts[$event->id] ?? null,
                'review_url' => $this->reviewUrl($event),
            ];
        }

        return $this->json(['events' => $out]);
    }

    /**
     * Receive a ranking for one tournament.
     *
     * Body (JSON): `{game_event_name, game_event_at, capture_method, client_version,
     * rows: [{position, name, points, player_id, power}]}`.
     *
     * @param string $id Event id.
     * @return \Cake\Http\Response
     */
    public function import(string $id): Response
    {
        $this->request->allowMethod(['post']);

        /** @var \App\Model\Entity\Event|null $event */
        $event = $this->fetchTable('Events')->find('withoutBanner')
            ->where(['Events.id' => (int)$id])
            ->contain(['EventRewards'])
            ->first();
        if ($event === null) {
            return $this->json(['error' => __('Event not found.')], 404);
        }

        $service = new EventImportService();
        try {
            $service->assertImportable($event);
        } catch (DomainException $e) {
            return $this->json(['error' => $e->getMessage()], $event->is_imported ? 409 : 422);
        }

        $data = $this->request->getData();
        if (!is_array($data) || $data === []) {
            return $this->json(['error' => __('Send the ranking as a JSON body.')], 422);
        }

        $checked = $service->validate($data);
        if ($checked['errors']) {
            return $this->json(['error' => __('The ranking was not accepted.'), 'errors' => $checked['errors']], 422);
        }

        try {
            [$result, $roster] = $this->fetchTable('Events')->getConnection()->transactional(
                function () use ($service, $event, $checked): array {
                    $roster = new MemberRosterService();
                    $summary = $roster->apply($event, $checked['payload']['rows']);
                    $result = $service->import($event, $checked['payload'], $this->token->user_id, $this->token->id);
                    $roster->markApplied($result['import'], $summary);
                    $result['completed'] = $service->completeDrafts($checked['payload']['rows']);

                    return [$result, $summary];
                }
            );
        } catch (DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        $result = $this->autoPublish($service, $event, $result);

        return $this->importResponse($service, $event, $result, ['members' => $roster], $result['created'] ? 201 : 200);
    }

    /**
     * Register a game tournament and its ranking in one call.
     *
     * Body (JSON): `{result_uid, tournament_key, name, ended_at, capture_method,
     * client_version, auto_publish, rows: [{position, name, points, player_id, power}]}`.
     * `result_uid` is the id the game gives the result in the Journal; sending
     * the same one again reaches the same event.
     *
     * @return \Cake\Http\Response
     */
    public function tournament(): Response
    {
        $this->request->allowMethod(['post']);

        $data = $this->request->getData();
        if (!is_array($data) || $data === []) {
            return $this->json(['error' => __('Send the ranking as a JSON body.')], 422);
        }

        $service = new EventImportService();
        $tournament = $service->validateTournament($data);
        $ranking = $service->validate($data);
        $errors = $tournament['errors'] + $ranking['errors'];
        if ($errors) {
            return $this->json(['error' => __('The ranking was not accepted.'), 'errors' => $errors], 422);
        }

        try {
            // One transaction: a ranking that cannot be stored leaves no empty
            // event behind.
            [$registered, $result, $roster] = $this->fetchTable('Events')->getConnection()->transactional(
                function () use ($service, $tournament, $ranking): array {
                    $registered = $service->registerTournament(
                        $tournament['tournament'],
                        $this->token->user_id,
                        $this->token->user?->name
                    );
                    $service->assertImportable($registered['event']);

                    // The ranking lists the whole clan: the members table follows
                    // it before the players are matched, so they link by game id.
                    $rosterService = new MemberRosterService();
                    $roster = $rosterService->apply($registered['event'], $ranking['payload']['rows']);
                    $result = $service->import($registered['event'], $ranking['payload'], $this->token->user_id, $this->token->id);
                    $rosterService->markApplied($result['import'], $roster);
                    // After the roster, so rows can also link to members it just created.
                    $result['completed'] = $service->completeDrafts($ranking['payload']['rows']);

                    return [$registered, $result, $roster];
                }
            );
        } catch (DomainException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        $event = $registered['event'];
        $result = $this->autoPublish($service, $event, $result);

        return $this->importResponse($service, $event, $result, [
            'event_id' => $event->id,
            'event_number' => $event->event_number,
            'event_name' => $event->name,
            'event_created' => $registered['created'],
            'name_source' => $registered['name_source'],
            'rewards' => count((array)$event->event_rewards),
            'rewards_copied' => $registered['rewards_copied'],
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'members' => $roster,
        ], $registered['created'] || $result['created'] ? 201 : 200);
    }

    /**
     * Tournament types the site has seen, with the name and rewards of the last
     * one, so the uploader can show the name before sending.
     *
     * @return \Cake\Http\Response
     */
    public function known(): Response
    {
        $this->request->allowMethod(['get']);

        return $this->json(['tournaments' => (new EventImportService())->knownTournaments()]);
    }

    /**
     * Add names (and images, where missing) to the tournament catalogue.
     *
     * Body (JSON): `{entries: [{tournament_key, name, ended_at, image}]}`, where
     * `image` is an optional base64 PNG of the tournament's card icon.
     *
     * @return \Cake\Http\Response
     */
    public function catalog(): Response
    {
        $this->request->allowMethod(['post']);

        $data = $this->request->getData();
        $result = (new TournamentCatalogService())->register(is_array($data) ? $data : []);
        if ($result['errors']) {
            return $this->json(['error' => __('The tournaments were not accepted.'), 'errors' => $result['errors']], 422);
        }

        return $this->json(['results' => $result['results']]);
    }

    /**
     * What the site already has for some game results, so the uploader sends
     * nothing twice.
     *
     * Body (JSON): `{result_uids: [...]}`, at most 500. Answers
     * `{tournaments: {uid: {event_id, event_number, event_name, published,
     * cancelled, has_draft}}}` with only the uids the site knows.
     *
     * @return \Cake\Http\Response
     */
    public function lookup(): Response
    {
        $this->request->allowMethod(['post']);

        $uids = $this->request->getData('result_uids');
        if (!is_array($uids) || count($uids) > 500) {
            return $this->json(['error' => __('Send result_uids as a list of at most {0} ids.', 500)], 422);
        }
        $uids = array_values(array_unique(array_filter(
            $uids,
            fn ($uid): bool => is_string($uid) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $uid) === 1
        )));
        if ($uids === []) {
            return $this->json(['tournaments' => (object)[]]);
        }

        $events = $this->fetchTable('Events')->find()
            ->select(['id', 'event_number', 'name', 'game_result_uid', 'published_at', 'status'])
            ->where(['Events.game_result_uid IN' => $uids])
            ->all()
            ->toList();

        $drafts = [];
        $ids = array_map(fn (Event $e): int => (int)$e->id, $events);
        if ($ids) {
            $drafts = $this->fetchTable('EventImports')->find()
                ->select(['event_id'])
                ->where(['event_id IN' => $ids, 'status' => EventImport::STATUS_DRAFT])
                ->all()
                ->combine('event_id', fn () => true)
                ->toArray();
        }

        $out = [];
        foreach ($events as $event) {
            $out[$event->game_result_uid] = [
                'event_id' => $event->id,
                'event_number' => $event->event_number,
                'event_name' => $event->name,
                'published' => $event->published_at !== null,
                'cancelled' => $event->status === Event::STATUS_CANCELLED,
                'has_draft' => isset($drafts[$event->id]),
            ];
        }

        return $this->json(['tournaments' => $out ?: (object)[]]);
    }

    /**
     * Where the next automatic search has to go back to.
     *
     * `covered_until` is the start of the newest search that walked the
     * Journal to its end, or back to where the search before it had covered,
     * and sent everything it found. Tournaments that ended before it are on the
     * site already. When no search ever completed, `latest_ended_at` (the end
     * of the newest tournament on the site) is the fallback.
     *
     * @return \Cake\Http\Response
     */
    public function searchState(): Response
    {
        $this->request->allowMethod(['get']);

        $covered = null;
        $runs = $this->fetchTable('JobRuns')->find()
            ->where(['job' => JobRunRecorder::JOB_TOURNAMENT_SEARCH, 'status' => JobRun::STATUS_SUCCESS])
            ->orderBy(['id' => 'DESC'])
            ->limit(20)
            ->all();
        foreach ($runs as $run) {
            $value = is_array($run->summary) ? ($run->summary['covered_until'] ?? null) : null;
            if (is_string($value) && ($covered === null || strcmp($value, $covered) > 0)) {
                $covered = $value;
            }
        }

        $latest = $this->fetchTable('Events')->find()
            ->select(['ends_at'])
            ->where(['Events.game_result_uid IS NOT' => null])
            ->orderBy(['Events.ends_at' => 'DESC'])
            ->first();

        $last = $this->fetchTable('JobRuns')->latest(JobRunRecorder::JOB_TOURNAMENT_SEARCH);

        return $this->json([
            'covered_until' => $covered,
            'latest_ended_at' => $latest?->ends_at?->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
            'last_run' => $last === null ? null : [
                'status' => $last->status,
                'host' => $last->host,
                'started_at' => $last->started_at?->toIso8601String(),
                'summary' => $last->summary,
            ],
        ]);
    }

    /**
     * Record one automatic search.
     *
     * Body (JSON): `{started_at, complete, reason, cards, sent, published,
     * for_review, errors, cutoff, client_version}`. Only a complete search moves
     * `covered_until` forward: an interrupted one is recorded as partial and the
     * next search goes back to the same point.
     *
     * @return \Cake\Http\Response
     */
    public function searchReport(): Response
    {
        $this->request->allowMethod(['post']);

        $data = $this->request->getData();
        if (!is_array($data)) {
            return $this->json(['error' => __('Send the search as a JSON body.')], 422);
        }
        try {
            $startedAt = (new DateTime((string)($data['started_at'] ?? '')))->setTimezone('UTC');
        } catch (Throwable) {
            return $this->json(['error' => __('started_at is not a valid date.')], 422);
        }
        $now = DateTime::now();
        if ($startedAt->greaterThan($now)) {
            // A clock ahead of the server's must not mark the future as searched.
            $startedAt = $now;
        }

        $complete = filter_var($data['complete'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $count = fn (string $key): int => max(0, (int)($data[$key] ?? 0));
        $summary = [
            'started_at' => $startedAt->format('Y-m-d\TH:i:s\Z'),
            'complete' => $complete,
            'reason' => mb_substr((string)($data['reason'] ?? ''), 0, 60),
            'cutoff' => is_string($data['cutoff'] ?? null) ? mb_substr($data['cutoff'], 0, 30) : null,
            'cards' => $count('cards'),
            'sent' => $count('sent'),
            'published' => $count('published'),
            'for_review' => $count('for_review'),
            'errors' => $count('errors'),
            'client_version' => mb_substr((string)($data['client_version'] ?? ''), 0, 32),
        ];
        if ($complete) {
            $summary['covered_until'] = $summary['started_at'];
        }

        $status = $complete ? JobRun::STATUS_SUCCESS : ($summary['sent'] > 0 ? JobRun::STATUS_PARTIAL : JobRun::STATUS_FAILED);
        (new JobRunRecorder())->record(JobRunRecorder::JOB_TOURNAMENT_SEARCH, $status, $summary, $this->token?->name);

        return $this->json(['recorded' => true, 'status' => $status, 'covered_until' => $summary['covered_until'] ?? null]);
    }

    /**
     * Publish (close) the draft right away when the uploader asks for it and
     * nobody in the ranking is left to identify.
     *
     * The uploader sends `auto_publish: true` only when every player came with
     * a name; the site checks again (EventImportService::autoPublishBlocker()).
     * Anything else stays a draft for the review page, as before. An event
     * without rewards is published too: rewards can be added to it later.
     *
     * @param \App\Service\EventImportService $service Service.
     * @param \App\Model\Entity\Event $event Event with rewards.
     * @param array{import: \App\Model\Entity\EventImport, created: bool, completed?: int} $result From import().
     * @return array{import: \App\Model\Entity\EventImport, created: bool, completed?: int, published?: bool, publish_blocked?: string|null}
     */
    private function autoPublish(EventImportService $service, Event $event, array $result): array
    {
        if (!filter_var($this->request->getData('auto_publish'), FILTER_VALIDATE_BOOLEAN)) {
            return $result;
        }

        $import = $this->fetchTable('EventImports')->current($event->id);
        if ($import === null) {
            return $result;
        }

        $blocker = $service->autoPublishBlocker($import);
        if ($blocker !== null) {
            $result['publish_blocked'] = $blocker;

            return $result;
        }

        try {
            $service->publish($event, $import);
        } catch (DomainException $e) {
            // Left as a draft; the administrator finishes it from the review page.
            $result['publish_blocked'] = $e->getMessage();

            return $result;
        }

        $result['published'] = true;

        return $result;
    }

    /**
     * What the uploader is told after a ranking is stored.
     *
     * @param \App\Service\EventImportService $service Service.
     * @param \App\Model\Entity\Event $event Event with rewards.
     * @param array{import: \App\Model\Entity\EventImport, created: bool, completed?: int, published?: bool} $result From import(), with the draft rows completeDrafts() changed.
     * @param array<string, mixed> $extra Fields to add.
     * @param int $status HTTP status.
     * @return \Cake\Http\Response
     */
    private function importResponse(EventImportService $service, Event $event, array $result, array $extra, int $status): Response
    {
        $import = $this->fetchTable('EventImports')->current($event->id);
        $preview = $service->preview($event, $import);

        (new JobRunRecorder())->record(JobRunRecorder::JOB_TOURNAMENT_IMPORT, JobRun::STATUS_SUCCESS, [
            'event_id' => $event->id,
            'event_name' => $event->name,
            'rows' => $preview['totals']['players'],
            'unlinked' => $preview['totals']['unmatched'],
        ], $this->token?->name);

        return $this->json($extra + [
            'import_id' => $result['import']->id,
            'created' => $result['created'],
            'published' => $result['published'] ?? false,
            'publish_blocked' => $result['publish_blocked'] ?? null,
            'rows' => $preview['totals']['players'],
            'linked' => $preview['totals']['players'] - $preview['totals']['unmatched'],
            'unlinked' => $preview['totals']['unmatched'],
            'administrative' => $preview['totals']['administrative'],
            'drafts_completed' => $result['completed'] ?? 0,
            'warnings' => array_column($preview['warnings'], 'text'),
            'review_url' => $this->reviewUrl($event),
        ], $status);
    }

    /**
     * @param \App\Model\Entity\Event $event Event.
     * @return string
     */
    private function reviewUrl(Event $event): string
    {
        return Router::url(['prefix' => false, 'controller' => 'Events', 'action' => 'review', $event->id], true);
    }

    /**
     * @param array<string, mixed> $data Body.
     * @param int $status HTTP status.
     * @return \Cake\Http\Response
     */
    private function json(array $data, int $status = 200): Response
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Stop the request with a JSON error.
     *
     * @param \Cake\Event\EventInterface<\Cake\Controller\Controller> $event The filter event.
     * @param int $status HTTP status.
     * @param string $message Message.
     * @return void
     */
    private function halt(EventInterface $event, int $status, string $message): void
    {
        $response = $this->json(['error' => $message], $status);
        if ($status === 401) {
            $response = $response->withHeader('WWW-Authenticate', 'Bearer');
        }
        $event->setResult($response);
        $event->stopPropagation();
    }
}
