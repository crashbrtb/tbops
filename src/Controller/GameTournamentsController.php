<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\GameTournament;
use App\Service\EventGoal;
use App\Service\TournamentCatalogService;
use Cake\Http\Response;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Administration of the game's tournament catalogue.
 *
 * The mapper fills in names and ids; here an administrator sets what the game
 * does not say: the duration in days (an event's start is its end minus that)
 * and the image, and may correct a name - which the mapper then leaves alone.
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
     * @param string|null $id Catalogue entry id.
     * @return \Cake\Http\Response|null|void
     */
    public function edit(?string $id = null)
    {
        $this->requireAdmin();

        $tournament = $this->GameTournaments->find('withoutImage')->where(['GameTournaments.id' => $id])->firstOrFail();

        if ($this->request->is(['post', 'put', 'patch'])) {
            $data = $this->request->getData();
            $nameBefore = (string)$tournament->name;
            $tournament = $this->GameTournaments->patchEntity($tournament, [
                'name' => trim((string)($data['name'] ?? '')),
                'duration_days' => ($data['duration_days'] ?? '') === '' ? null : $data['duration_days'],
                // The default goal every new event of this tournament starts with.
            ] + EventGoal::marshal((array)($data['goal'] ?? [])));
            if ((string)$tournament->name !== $nameBefore) {
                // A name typed here is final: the mapper will not replace it.
                $tournament->set('name_source', $tournament->name ? GameTournament::SOURCE_MANUAL : null, ['guard' => false]);
            }

            $imageError = null;
            $removeImage = (bool)($data['remove_image'] ?? false);
            $upload = $data['image'] ?? null;
            $image = null;
            if (!$removeImage && $upload instanceof UploadedFileInterface && $upload->getError() !== UPLOAD_ERR_NO_FILE) {
                if ($upload->getError() !== UPLOAD_ERR_OK || ($upload->getSize() ?? 0) > self::MAX_UPLOAD_BYTES) {
                    $imageError = __('The image must be a PNG, JPEG, WebP or GIF of at most {0} KB.', (int)(self::MAX_UPLOAD_BYTES / 1024));
                } else {
                    $image = (new TournamentCatalogService())->imageType((string)$upload->getStream());
                    if ($image === null) {
                        $imageError = __('The image must be a PNG, JPEG, WebP or GIF of at most {0} KB.', (int)(self::MAX_UPLOAD_BYTES / 1024));
                    }
                }
            }

            if ($imageError === null && $this->GameTournaments->save($tournament)) {
                if ($removeImage) {
                    $this->GameTournaments->updateAll(['image' => null, 'image_mime' => null], ['id' => $tournament->id]);
                } elseif ($image !== null) {
                    $this->GameTournaments->updateAll(['image' => $image[0], 'image_mime' => $image[1]], ['id' => $tournament->id]);
                }
                $this->Flash->success(__('Tournament "{0}" saved.', $tournament->displayName()));

                return $this->redirect(['action' => 'index']);
            }

            $this->Flash->error($imageError ?? __('The tournament could not be saved. Please check the fields below.'));
        }

        $events = $this->fetchTable('Events')->find('withoutBanner')
            ->where(['Events.game_tournament_id' => $tournament->id])
            ->orderBy(['Events.ends_at' => 'DESC'])
            ->limit(10)
            ->all();

        $this->set(compact('tournament', 'events'));
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
            ->select(['id', 'image', 'image_mime', 'modified'])
            ->where(['id' => (int)$id])
            ->first();

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
