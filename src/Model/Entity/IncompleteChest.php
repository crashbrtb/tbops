<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * IncompleteChest Entity
 *
 * A chest the collector opened but could not read completely. The screenshot is
 * the part that matters: it is what lets a person finish the record by hand,
 * since the chest itself is already gone from the game.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $player
 * @property string|null $source
 * @property string|null $screenshot PNG of the chest panel as it was on screen
 * @property int|null $type
 * @property string $status pending | corrected | unresolved
 * @property int|null $collected_chest_id
 * @property string|null $review_notes
 * @property int|null $reviewed_by
 * @property \Cake\I18n\DateTime|null $reviewed_at
 * @property \Cake\I18n\DateTime $collected_at
 * @property int|null $game_gift_id
 * @property int|null $game_type_id
 * @property int|null $game_player_id
 */
class IncompleteChest extends Entity
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CORRECTED = 'corrected';
    public const STATUS_UNRESOLVED = 'unresolved';

    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * `screenshot` is deliberately absent: it is written by the collector and
     * must never be replaced by a form post.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'name' => true,
        'player' => true,
        'source' => true,
        'type' => true,
        'status' => true,
        'collected_chest_id' => true,
        'review_notes' => true,
        'reviewed_by' => true,
        'reviewed_at' => true,
        'collected_at' => true,
    ];

    /**
     * Fields hidden when the entity is serialised.
     *
     * The image is hundreds of kilobytes and is served by its own action; it has
     * no business inside a JSON payload or a debug dump.
     *
     * @var list<string>
     */
    protected array $_hidden = [
        'screenshot',
    ];

    /**
     * Whether this chest is still waiting for someone to look at it.
     *
     * @return bool
     */
    protected function _getIsPending(): bool
    {
        return ($this->status ?? self::STATUS_PENDING) === self::STATUS_PENDING;
    }

    /**
     * Whether there is a picture to show.
     *
     * Answers from `screenshot_bytes` when the row was loaded without the blob -
     * which is how the review screen loads it, since the image is fetched by its
     * own request and there is no reason to carry it twice.
     *
     * @return bool
     */
    protected function _getHasScreenshot(): bool
    {
        if ($this->has('screenshot_bytes')) {
            return (int)$this->get('screenshot_bytes') > 0;
        }

        return !empty($this->screenshot);
    }
}
