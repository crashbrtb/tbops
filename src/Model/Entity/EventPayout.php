<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * The Silver a player won in a published tournament, credited to their account
 * in the clan bank once an administrator confirms it.
 *
 * @property int $id
 * @property int $event_id
 * @property int|null $member_id
 * @property string $player
 * @property int $amount Millions of Silver
 * @property string $status pending, waived, credited or reversed
 * @property string|null $note
 * @property int|null $bank_transaction_id
 * @property int|null $user_id
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 *
 * @property \App\Model\Entity\Member|null $member
 * @property \App\Model\Entity\Event $event
 */
class EventPayout extends Entity
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_WAIVED = 'waived';
    public const STATUS_CREDITED = 'credited';
    public const STATUS_REVERSED = 'reversed';

    /**
     * Written by TournamentPayoutService only.
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
            self::STATUS_WAIVED => __('Not credited'),
            self::STATUS_CREDITED => __('Credited'),
            self::STATUS_REVERSED => __('Reversed'),
        ];
    }
}
