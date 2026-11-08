<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\EventReward;
use App\Model\Entity\GameTournament;
use App\Service\ClanResultService;
use App\Service\EventGoal;
use App\Service\TournamentCatalogService;
use Cake\Http\Response;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Administration of the game's tournament catalogue.
 *
 * The collector registers the tournaments it meets, and here an administrator
 * sets what the game does not say. Each tournament has a page (view) with one
 * card per part - name, description, duration, image, goal, prizes - each
 * edited on its own (edit with `?section=`), its clan results, whether its
 * rankings are published without review, and its latest events.
 *
 * @property \App\Model\Table\GameTournamentsTable $GameTournaments
 */
class GameTournamentsController extends AppController
{
    private const MAX_UPLOAD_BYTES = 1048576;

    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        // Tournament pages are public, and they show this image.
        $this->Authentication->allowUnauthenticated(['image']);
    }

    /**
     * @return \Cake\Http\Response|null|void
     */
    public function index()
    {
        $this->requireAdmin();

        $tournaments = $this->GameTournaments->find('withoutImage')
            ->orderBy(['GameTournaments.name' => 'ASC', 'GameTournaments.game_type' => 'ASC'])
            ->all();

        $counts = [];
        foreach (
            $this->fetchTable('Events')->find()
                ->select(['game_tournament_id', 'total' => $this->fetchTable('Events')->find()->func()->count('*')])
                ->where(['game_tournament_id IS NOT' => null])
                ->groupBy(['game_tournament_id'])
                ->disableHydration()
                ->all() as $row
        ) {
            $counts[(int)$row['game_tournament_id']] = (int)$row['total'];
        }

        $this->set(compact('tournaments', 'counts'));
    }

    /**
     * A tournament's page: every part of it, its clan results and its events.
     *
     * @param string|null $id Catalogue entry id.
     * @return \Cake\Http\Response|null|void
     */
    public function view(?string $id = null)
    {
        $this->requireAdmin();

        $tournament = $this->GameTournaments->find('withoutImage')->where(['GameTournaments.id' => $id])->firstOrFail();
        $prizes = (new ClanResultService())->knownPrizes((int)$tournament->game_type);

        $clanResults = $this->fetchTable('ClanTournamentResults')->find()
            ->where(['game_type' => $tournament->game_type])
            ->orderBy(['ended_at' => 'DESC'])
            ->limit(10)
            ->all()
            ->toList();

        $events = $this->fetchTable('Events')->find('withoutBanner')
            ->where(['Events.game_tournament_id' => $tournament->id])
            ->orderBy(['Events.ends_at' => 'DESC'])
            ->limit(10)
            ->all();

        $missing = $tournament->missingSections();

        $this->set(compact('tournament', 'prizes', 'clanResults', 'events', 'missing'));
    }

    /**
     * Edit one part of a tournament (`?section=`), or the name, duration,
     * image and goal together when no section is given. Saving goes back to the
     * tournament's page.
     *
     * @param string|null $id Catalogue entry id.
     * @return \Cake\Http\Response|null|void
     */
    public function edit(?string $id = null)
    {
        $this->requireAdmin();

        $tournament = $this->GameTournaments->find('withoutImage')->where(['GameTournaments.id' => $id])->firstOrFail();
        $section = (string)($this->request->getData('section') ?? $this->request->getQuery('section', ''));
        if (!in_array($section, GameTournament::SECTIONS, true)) {
            $section = '';
        }
        if ($section === GameTournament::SECTION_DURATION || $section === GameTournament::SECTION_IMAGE) {
            return $this->redirect(['action' => 'view', $id]);
        }
        $shows = fn (string $part): bool => $section === ''
            ? !in_array($part, [
                GameTournament::SECTION_PRIZES,
                GameTournament::SECTION_DESCRIPTION,
                GameTournament::SECTION_DURATION,
                GameTournament::SECTION_IMAGE,
            ], true)
            : $section === $part;
        $prizes = $shows(GameTournament::SECTION_PRIZES) ? (new ClanResultService())->knownPrizes((int)$tournament->game_type) : [];

        if ($this->request->is(['post', 'put', 'patch'])) {
            $data = $this->request->getData();
            $fields = [];
            $nameBefore = (string)$tournament->name;
            if ($shows(GameTournament::SECTION_NAME)) {
                if (array_key_exists('display_name', $data)) {
                    $shown = trim((string)$data['display_name']);
                    // The game's name again is no display name.
                    $fields['display_name'] = $shown === '' || $shown === $tournament->name ? null : $shown;
                }
                if (array_key_exists('name', $data) && !$tournament->isNamedByTheGame()) {
                    $fields['name'] = trim((string)$data['name']);
                }
            }
            if ($shows(GameTournament::SECTION_DESCRIPTION)) {
                $fields['description'] = trim((string)($data['description'] ?? '')) ?: null;
            }
            if ($shows(GameTournament::SECTION_GOAL) && array_key_exists('goal', $data)) {
                // The default goal every new event of this tournament starts with.
                $fields += EventGoal::marshal((array)$data['goal']);
            }
            $tournament = $this->GameTournaments->patchEntity($tournament, $fields);
            if ((string)$tournament->name !== $nameBefore) {
                // A name typed here is final: the mapper will not replace it.
                $tournament->set('name_source', $tournament->name ? GameTournament::SOURCE_MANUAL : null, ['guard' => false]);
            }
            if ($shows(GameTournament::SECTION_PRIZES)) {
                $tournament->set('prize_items', $this->postedPrizes((array)($data['prizes'] ?? []), $prizes), ['guard' => false]);
            }

            if ($this->GameTournaments->save($tournament)) {
                if ($tournament->name) {
                    $this->GameTournaments->updateAll(['pending_picture' => null], ['id' => $tournament->id]);
                }
                if ($shows(GameTournament::SECTION_PRIZES)) {
                    $this->refreshPendingEvents($tournament);
                }
                $this->Flash->success(__('Tournament "{0}" saved.', $tournament->displayName()));

                return $this->redirect(['action' => 'view', $tournament->id]);
            }

            $this->Flash->error(__('The tournament could not be saved. Please check the fields below.'));
        }

        $this->set(compact('tournament', 'section', 'prizes'));
        $this->set('ruleOptions', array_intersect_key(EventReward::ruleOptions(), array_flip(ClanResultService::PRIZE_RULES)));
        $this->set('remainderOptions', EventReward::remainderOptions());
    }

    /**
     * Turn publishing without review on or off for the tournament. The page
     * asks first when something is not filled in; here it is said once more.
     *
     * @param string|null $id Catalogue entry id.
     * @return \Cake\Http\Response|null
     */
    public function autoPublish(?string $id = null): ?Response
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'put']);

        $tournament = $this->GameTournaments->find('withoutImage')->where(['GameTournaments.id' => $id])->firstOrFail();
        $enabled = (bool)$this->request->getData('auto_publish');
        $this->GameTournaments->updateAll(['auto_publish' => $enabled], ['id' => $tournament->id]);

        if (!$enabled) {
            $this->Flash->success(__('The rankings of "{0}" now wait for a review before they are published.', $tournament->displayName()));
        } elseif ($tournament->missingSections()) {
            $this->Flash->warning(__(
                'The rankings of "{0}" are now published without review, with {1} not filled in.',
                $tournament->displayName(),
                implode(', ', $tournament->missingSections())
            ));
        } else {
            $this->Flash->success(__('The rankings of "{0}" are now published without review.', $tournament->displayName()));
        }

        return $this->redirect(['action' => 'view', $tournament->id]);
    }

    /**
     * The clan prizes ticked on the form, as stored.
     *
     * @param array<int|string, mixed> $posted `prizes[item_id][selected|rule|min_points|remainder|name]`.
     * @param list<array{item_id: int, name: string}> $known What the clan has received in this tournament.
     * @return string|null JSON, or null when none is ticked.
     */
    private function postedPrizes(array $posted, array $known): ?string
    {
        $names = [];
        foreach ($known as $prize) {
            $names[$prize['item_id']] = $prize['name'];
        }
        $selection = [];
        foreach ($posted as $itemId => $prize) {
            if (!is_array($prize) || empty($prize['selected']) || !ctype_digit((string)$itemId)) {
                continue;
            }
            $rule = (string)($prize['rule'] ?? '');
            $remainder = (string)($prize['remainder'] ?? '');
            $selection[] = [
                'item_id' => (int)$itemId,
                'name' => mb_substr($names[(int)$itemId] ?? trim((string)($prize['name'] ?? '')), 0, 120),
                'rule' => in_array($rule, ClanResultService::PRIZE_RULES, true) ? $rule : EventReward::RULE_PROPORTIONAL,
                'min_points' => max(0, (int)($prize['min_points'] ?? 1)),
                'remainder' => array_key_exists($remainder, EventReward::remainderOptions()) ? $remainder : EventReward::REMAINDER_TOP_RANKED,
            ];
        }

        return $selection === [] ? null : (string)json_encode($selection, JSON_UNESCAPED_UNICODE);
    }

    /**
     * The events of the tournament not published yet follow the new prizes.
     *
     * @param \App\Model\Entity\GameTournament $tournament Tournament.
     * @return void
     */
    private function refreshPendingEvents(GameTournament $tournament): void
    {
        $service = new ClanResultService();
        $pending = $this->fetchTable('Events')->find('withoutBanner')
            ->where(['Events.game_tournament_id' => $tournament->id, 'Events.published_at IS' => null])
            ->all();
        foreach ($pending as $event) {
            $service->applyPrizes($event);
        }
    }

    /**
     * The tournaments waiting for a name, each with the picture the uploader sent.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function pending()
    {
        $this->requireAdmin();

        $this->set('tournaments', $this->GameTournaments->find('nameless')->all());
    }

    /**
     * The picture of a tournament waiting for a name.
     *
     * @param string|null $id Catalogue entry id.
     * @return \Cake\Http\Response
     */
    public function picture(?string $id = null): Response
    {
        $this->requireAdmin();

        $tournament = $this->GameTournaments->find()
            ->select(['id', 'pending_picture'])
            ->where(['id' => (int)$id, 'pending_picture IS NOT' => null])
            ->first();
        if ($tournament === null) {
            return $this->response->withStatus(404);
        }

        $bytes = $tournament->get('pending_picture');
        $bytes = is_resource($bytes) ? (string)stream_get_contents($bytes) : (string)$bytes;

        return $this->response->withType('png')->withStringBody($bytes);
    }

    /**
     * Names a tournament from its picture. The name is final, and the uploader's
     * next search sends the rankings that were waiting for it.
     *
     * @param string|null $id Catalogue entry id.
     * @return \Cake\Http\Response|null
     */
    public function assignName(?string $id = null): ?Response
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'put']);

        $tournament = $this->GameTournaments->find('withoutImage')->where(['GameTournaments.id' => $id])->firstOrFail();
        $name = trim((string)$this->request->getData('name'));
        if ($name === '') {
            $this->Flash->error(__('Fill in the name of the tournament.'));

            return $this->redirect(['action' => 'pending']);
        }

        $tournament = $this->GameTournaments->patchEntity($tournament, ['name' => $name]);
        $tournament->set('name_source', GameTournament::SOURCE_MANUAL, ['guard' => false]);
        if ($this->GameTournaments->save($tournament)) {
            $this->GameTournaments->updateAll(['pending_picture' => null], ['id' => $tournament->id]);
            $this->Flash->success(__('Tournament "{0}" named. Its rankings arrive with the next search.', $name));
        } else {
            $this->Flash->error(__('The tournament could not be saved. Please check the fields below.'));
        }

        return $this->redirect(['action' => 'pending']);
    }

    /**
     * @param string $name Image of the game.
     * @return string
     */
    private function gameImageUrl(string $name): string
    {
        $path = \App\Service\LiveTournamentService::filePath($name);
        if (str_starts_with($path, WWW_ROOT) && is_file($path)) {
            return \Cake\Routing\Router::url('/img/game/' . $name . '.png', true) . '?v=' . filemtime($path);
        }

        return \Cake\Routing\Router::url(['controller' => 'GameImages', 'action' => 'view', $name], true);
    }

    /**
     * The tournament's image.
     *
     * @param string|null $id Catalogue entry id.
     * @return \Cake\Http\Response
     */
    public function image(?string $id = null): Response
    {
        $tournament = $this->GameTournaments->find()
            ->select(['id', 'image', 'image_mime', 'modified', 'game_icon'])
            ->where(['id' => (int)$id])
            ->first();

        // The game's own icon first: the site shows what the game shows, and follows it when it changes.
        $icon = $tournament?->get('game_icon');
        if ($icon && $this->fetchTable('GameImages')->exists(['name' => $icon])) {
            return $this->redirect((string)$this->gameImageUrl($icon));
        }

        if ($tournament === null || empty($tournament->image_mime)) {
            return $this->response->withStatus(404);
        }

        $bytes = is_resource($tournament->image) ? (string)stream_get_contents($tournament->image) : (string)$tournament->image;

        return $this->response
            ->withType($tournament->image_mime)
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withStringBody($bytes);
    }
}
