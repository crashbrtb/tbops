<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * The fine of a player who missed the goal of a published tournament.
 *
 * @property int $id
 * @property int $event_id
 * @property int|null $member_id
 * @property string $player
 * @property int $points
 * @property int $goal
 * @property int $amount
 * @property int|null $lost_amount What was still owed when it was given up as lost
 * @property string $status pending, waived, charged, refunded or lost
 * @property string|null $note
 * @property int|null $bank_transaction_id
 * @property int|null $user_id
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 *
 * @property \App\Model\Entity\Member|null $member
 * @property \App\Model\Entity\Event $event
 */
class EventFine extends Entity
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_WAIVED = 'waived';
    public const STATUS_CHARGED = 'charged';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_LOST = 'lost';

    /**
     * Written by TournamentFineService only.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [];

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => __('Pending'),
            self::STATUS_WAIVED => __('Waived'),
            self::STATUS_CHARGED => __('Charged'),
            self::STATUS_REFUNDED => __('Refunded'),
            self::STATUS_LOST => __('Lost'),
        ];
    }

    /**
     * How much of the goal the player reached, in percent.
     *
     * @return int
     */
    public function reachedPercent(): int
    {
        return $this->goal > 0 ? (int)floor(100 * min($this->points, $this->goal) / $this->goal) : 0;
    }
}
