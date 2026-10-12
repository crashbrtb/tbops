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

        return (string)$this->type;
    }
}
