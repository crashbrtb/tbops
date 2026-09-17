<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * A tournament of the game, as the catalogue knows it.
 *
 * @property int $id
 * @property int $game_type
 * @property string $ranking
 * @property string|null $name
 * @property string|null $name_source
 * @property int|null $duration_days
 * @property string|null $image_mime
 * @property resource|string|null $image
 * @property string|null $last_variant
 * @property \Cake\I18n\DateTime|null $first_seen_at
 * @property \Cake\I18n\DateTime|null $last_seen_at
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property bool $has_image
 */
class GameTournament extends Entity
{
    public const SOURCE_JOURNAL = 'journal';
    public const SOURCE_UPLOADER = 'uploader';
    public const SOURCE_MANUAL = 'manual';

    /** The ranking of a classic tournament result: the only one most types have. */
    public const RANKING_DEFAULT = '';

    /** Duration assumed for a tournament whose length nobody has set. */
    public const DEFAULT_DURATION_DAYS = 1;

    /**
     * Only what the administration form edits. Everything else is written by
     * the catalogue service.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'name' => true,
        'duration_days' => true,
    ];

    /**
     * @var list<string>
     */
    protected array $_hidden = ['image'];

    /**
     * @var list<string>
     */
    protected array $_virtual = ['has_image'];

    /**
     * @return bool
     */
    protected function _getHasImage(): bool
    {
        return !empty($this->image_mime);
    }

    /**
     * The name to show, or a placeholder built from the game's id.
     *
     * @return string
     */
    public function displayName(): string
    {
        return $this->name !== null && $this->name !== ''
            ? $this->name
            : __('Game tournament {0}', $this->game_type);
    }

    /**
     * Short name of the ranking for the administration pages: the statistic
     * when there is one, otherwise the message kind without its `_entry` ending.
     *
     * @return string
     */
    public function rankingLabel(): string
    {
        $ranking = (string)$this->ranking;
        $colon = strpos($ranking, ':');

        return $colon !== false ? substr($ranking, $colon + 1) : (string)preg_replace('/_entry$/', '', $ranking);
    }

    /**
     * @return int
     */
    public function effectiveDurationDays(): int
    {
        return max(1, (int)($this->duration_days ?: self::DEFAULT_DURATION_DAYS));
    }
}
