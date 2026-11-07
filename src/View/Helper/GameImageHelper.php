<?php
declare(strict_types=1);

namespace App\View\Helper;

use App\Service\LiveTournamentService;
use Cake\View\Helper;

/**
 * The address of one of the game's images: its file when the site has it (served
 * without PHP, versioned by when it was written, so a changed image is fetched
 * again), the image action otherwise.
 *
 * @property \Cake\View\Helper\UrlHelper $Url
 */
class GameImageHelper extends Helper
{
    /**
     * @var array<string>
     */
    protected array $helpers = ['Url'];

    /**
     * @param string|null $name Name of the image in the game.
     * @return string|null
     */
    public function url(?string $name): ?string
    {
        if ($name === null || $name === '' || !preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $name)) {
            return null;
        }
        $path = LiveTournamentService::filePath($name);
        // Only a file under the web root can be served as one.
        if (str_starts_with($path, WWW_ROOT) && is_file($path)) {
            return $this->Url->assetUrl('img/game/' . $name . '.png', ['timestamp' => false]) . '?v=' . filemtime($path);
        }

        return $this->Url->build(['controller' => 'GameImages', 'action' => 'view', $name]);
    }
}
