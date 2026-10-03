<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * EventStanding Entity
 *
 * A player's frozen result for a finished event. Written once, when the event is
 * closed, because the collected chests it was computed from are eventually purged.
 *
 * @property int $id
 * @property int $event_id
 * @property int $position
 * @property string $player
 * @property int $points
 * @property int $chest_count
 * @property int $chest_score
 * @property float $participation
 * @property int|null $member_id
 * @property int|null $game_player_id
 * @property int|null $power
 * @property bool $eligible
 * @property int|null $guard_level Guard level when the result was recorded; 0 = unknown
 * @property int|null $goal Goal the player had in this event; null when the event has none
 * @property bool|null $goal_met Whether the player reached it
 * @property \Cake\I18n\DateTime|null $created
 * @property \App\Model\Entity\Event $event
 * @property array<\App\Model\Entity\EventRewardAllocation> $event_reward_allocations
 */
class EventStanding extends Entity
{
    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'event_id' => true,
        'position' => true,
        'player' => true,
        'points' => true,
        'chest_count' => true,
        'chest_score' => true,
        'participation' => true,
        'member_id' => true,
        'game_player_id' => true,
        'power' => true,
        'eligible' => true,
        'guard_level' => true,
        'goal' => true,
        'goal_met' => true,
    ];
}
