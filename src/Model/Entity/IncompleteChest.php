<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * IncompleteChest Entity
 *
 * A chest the collector opened but could not record: its type had no name, the
 * game did not name its player, or the database refused the row. A person
 * finishes it by hand, since the chest itself is already gone from the game.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $player
 * @property string|null $source
 * @property string|null $screenshot PNG of the chest panel, from when the collector read the screen; no longer written
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
     * `screenshot` is deliberately absent: old rows may still hold one, and it
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
     * An old row's image is hundreds of kilobytes; it has no business inside a
     * JSON payload or a debug dump.
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
}
