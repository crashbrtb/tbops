<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\AdminNotification;
use App\Model\Entity\Member;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * Tells the administrators when two members carry a name the chest counter
 * cannot tell apart.
 *
 * A chest carries the player's name and nothing else - no game id. So what
 * matters is not whether two names resemble each other, but whether a chest
 * read under one of them could be counted for the other:
 *
 * - the database compares names without telling case or accents apart, so
 *   "ALOK BR" and "Alok BR" are one player to every score and goal;
 * - a name read off the screen loses spaces and punctuation.
 *
 * Two names collide when they are the same once all of that is set aside.
 * "Cash BR" and "Crash BR" resemble each other but are different text: their
 * chests never mix, and nothing is reported.
 *
 * Only active members are compared: the inactive ones are mostly old names and
 * players who left. Each pair is reported once - marking the alert as read is
 * how an administrator says "I know". An unread alert whose pair no longer
 * collides (renamed, merged, deleted, left the clan) is closed by the scan.
 */
class DuplicatePlayerNameService
{
    use LocatorAwareTrait;

    /**
     * Compare the active members and bring the alerts in step with them.
     *
     * @return array{created: int, resolved: int}
     */
    public function scan(): array
    {
        /** @var \App\Model\Table\AdminNotificationsTable $notifications */
        $notifications = $this->fetchTable('AdminNotifications');

        $created = 0;
        $current = [];
        foreach ($this->pairs() as [$a, $b]) {
            $key = sprintf('%s:%d-%d', AdminNotification::TYPE_DUPLICATE_PLAYER_NAME, $a->id, $b->id);
            $current[$key] = true;
            $created += (int)$notifications->raise(AdminNotification::TYPE_DUPLICATE_PLAYER_NAME, $key, [
                'members' => [
                    ['id' => $a->id, 'player' => $a->player, 'game_player_id' => $a->game_player_id],
                    ['id' => $b->id, 'player' => $b->player, 'game_player_id' => $b->game_player_id],
                ],
            ]);
        }

        $resolved = 0;
        $open = $notifications->find('unread')
            ->where(['type' => AdminNotification::TYPE_DUPLICATE_PLAYER_NAME])
            ->all();
        foreach ($open as $notification) {
            if (!isset($current[(string)$notification->dedupe_key])) {
                $notifications->resolve($notification);
                $resolved++;
            }
        }

        return ['created' => $created, 'resolved' => $resolved];
    }

    /**
     * scan() for the places where the members were just changed: an alert that
     * cannot be raised must not undo or fail what was done.
     *
     * @return void
     */
    public function scanQuietly(): void
    {
        try {
            $this->scan();
        } catch (Throwable $e) {
            Log::warning('Could not check the members for duplicate names: ' . $e->getMessage());
        }
    }

    /**
     * The pairs of active members whose names collide, lower id first.
     *
     * @return list<array{0: \App\Model\Entity\Member, 1: \App\Model\Entity\Member}>
     */
    public function pairs(): array
    {
        /** @var list<\App\Model\Entity\Member> $members */
        $members = $this->fetchTable('Members')->find()
            ->where(['active' => 1])
            ->orderBy(['id' => 'ASC'])
            ->all()
            ->toList();

        $folded = array_map(fn (Member $member): string => $this->fold((string)$member->player), $members);

        $pairs = [];
        foreach ($members as $i => $a) {
            for ($j = $i + 1; $j < count($members); $j++) {
                if ($folded[$i] !== '' && $folded[$i] === $folded[$j]) {
                    $pairs[] = [$a, $members[$j]];
                }
            }
        }

        return $pairs;
    }

    /**
     * Whether the chest counter could take one name for the other.
     *
     * @param string $a Name.
     * @param string $b Name.
     * @return bool
     */
    public function collide(string $a, string $b): bool
    {
        $a = $this->fold($a);

        return $a !== '' && $a === $this->fold($b);
    }

    /**
     * A name as the counter may end up seeing it: accents, spaces, punctuation
     * and case taken out.
     *
     * @param string $name Name.
     * @return string
     */
    private function fold(string $name): string
    {
        $name = class_exists(\Normalizer::class) ? (\Normalizer::normalize($name, \Normalizer::FORM_KD) ?: $name) : $name;

        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', preg_replace('/\p{Mn}+/u', '', $name) ?? $name) ?? '');
    }
}
