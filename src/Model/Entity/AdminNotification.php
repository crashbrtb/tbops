<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * An alert for the administrators.
 *
 * @property int $id
 * @property string $type
 * @property string|null $dedupe_key What the alert is about; empty once it may be raised again
 * @property array<string, mixed>|null $data
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $read_at
 * @property int|null $read_by Administrator who marked it as read
 * @property \App\Model\Entity\User|null $reader
 */
class AdminNotification extends Entity
{
    public const TYPE_DUPLICATE_PLAYER_NAME = 'duplicate_player_name';
    /** An event held for review: a clan prize chosen to be split did not come with it. */
    public const TYPE_CLAN_PRIZE_MISSING = 'clan_prize_missing';

    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'type' => true,
        'dedupe_key' => true,
        'data' => true,
    ];

    /**
     * The members the alert is about, as they were when it was raised.
     *
     * @return list<array{id: int, player: string}>
     */
    public function members(): array
    {
        $members = [];
        foreach ((array)($this->data['members'] ?? []) as $member) {
            if (is_array($member) && isset($member['id'], $member['player'])) {
                $members[] = ['id' => (int)$member['id'], 'player' => (string)$member['player']];
            }
        }

        return $members;
    }

    /**
     * The alert in the reader's language.
     *
     * @return string
     */
    public function message(): string
    {
        $members = $this->members();
        if ($this->type === self::TYPE_DUPLICATE_PLAYER_NAME && count($members) === 2) {
            return __('Possible duplicate player: "{0}" and "{1}"', $members[0]['player'], $members[1]['player']);
        }
        if ($this->type === self::TYPE_CLAN_PRIZE_MISSING) {
            $number = (int)($this->data['event_number'] ?? 0);
            $name = (string)($this->data['event_name'] ?? '');
            if (!empty($this->data['no_result'])) {
                return __(
                    'Event #{0} "{1}" was not published: the clan\'s result of this tournament has not arrived, so its prizes could not be split. Review it.',
                    $number,
                    $name
                );
            }

            return __(
                'Event #{0} "{1}" was not published: the clan did not receive {2} in it, chosen to be split among the players. Review it.',
                $number,
                $name,
                implode(', ', array_map('strval', (array)($this->data['missing'] ?? [])))
            );
        }

        return (string)$this->type;
    }

    /**
     * Where the alert is dealt with, when it points somewhere.
     *
     * @return array<string, mixed>|null
     */
    public function link(): ?array
    {
        if ($this->type === self::TYPE_CLAN_PRIZE_MISSING && !empty($this->data['event_id'])) {
            return ['controller' => 'Events', 'action' => 'review', (int)$this->data['event_id']];
        }

        return null;
    }
}
