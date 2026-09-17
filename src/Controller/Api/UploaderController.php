<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Entity\ApiToken;
use App\Model\Entity\Event;
use App\Model\Entity\EventReward;
use App\Model\Entity\JobRun;
use App\Service\EventImportService;
use App\Service\JobRunRecorder;
use App\Service\MemberRosterService;
use App\Service\TournamentCatalogService;
use Cake\Controller\Controller;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\Routing\Router;
use DomainException;

/**
 * API for the EventUploader desktop tool.
 *
 * Every request carries `Authorization: Bearer <token>`, a personal token an
 * administrator creates on the site. The session is never used, and the tool
 * never touches the database.
 *
 * The everyday call is `POST /tournaments`: the uploader sends the tournament
 * as the game identifies it plus its ranking, and the site registers the event
 * if it is new and attaches the ranking as a draft for review. The older
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
        $this->Authentication->allowUnauthenticated(['me', 'awaiting', 'import', 'tournament', 'known', 'catalog']);
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

        return $this->importResponse($service, $event, $result, ['members' => $roster], $result['created'] ? 201 : 200);
    }

    /**
     * Register a game tournament and its ranking in one call.
     *
     * Body (JSON): `{result_uid, tournament_key, name, ended_at, capture_method,
     * client_version, rows: [{position, name, points, player_id, power}]}`.
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
     * What the uploader is told after a ranking is stored.
     *
     * @param \App\Service\EventImportService $service Service.
     * @param \App\Model\Entity\Event $event Event with rewards.
     * @param array{import: \App\Model\Entity\EventImport, created: bool, completed?: int} $result From import(), with the draft rows completeDrafts() changed.
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
