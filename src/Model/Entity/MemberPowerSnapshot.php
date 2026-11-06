<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * A member's power on one day, as the game reported it.
 *
 * @property int $id
 * @property int $member_id
 * @property int|null $game_player_id
 * @property \Cake\I18n\Date $snapshot_date
 * @property int $power
 * @property string $source member_list | tournament
 * @property \Cake\I18n\DateTime $recorded_at When the game was read
 * @property \App\Model\Entity\Member|null $member
 */
class MemberPowerSnapshot extends Entity
{
    public const SOURCE_MEMBER_LIST = 'member_list';
    public const SOURCE_TOURNAMENT = 'tournament';

    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'member_id' => true,
        'game_player_id' => true,
        'snapshot_date' => true,
        'power' => true,
        'source' => true,
        'recorded_at' => true,
    ];
}
