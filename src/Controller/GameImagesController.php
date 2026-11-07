<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Http\Response;

/**
 * The game's own images (banners, icons), as the collector brought them from the
 * game's CDN. Public: the tournament pages show them to everyone.
 */
class GameImagesController extends AppController
{
    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->Authentication->addUnauthenticatedActions(['view']);
    }

    /**
     * @param string|null $name Name of the image in the game.
     * @return \Cake\Http\Response
     */
    public function view(?string $name = null): Response
    {
        $image = $this->fetchTable('GameImages')->find()->where(['name' => (string)$name])->first();
        if ($image === null) {
            return $this->response->withStatus(404);
        }
        $bytes = $image->get('data');
        $bytes = is_resource($bytes) ? (string)stream_get_contents($bytes) : (string)$bytes;

        return $this->response
            ->withType((string)$image->get('mime'))
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withHeader('ETag', '"' . $image->get('file_id') . '"')
            ->withStringBody($bytes);
    }
}
