<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Datasource\EntityInterface;

/**
 * The goal of an event, or the default goal of a tournament in the catalogue.
 *
 * Works like the chest goals (ChestGoalService): one goal for everybody, or a
 * goal per guard level (G1 to G9) where a level left blank uses the global
 * goal and an unknown level (G0, or no member record) gets the highest goal of
 * the table. When the goal is required, only the players who reached it take
 * part in the rewards; the others are still ranked and shown.
 *
 * Stored in four columns on `events` and `game_tournaments`: goal_mode,
 * goal_points, goal_by_guard (JSON) and goal_required. Pure: no database.
 */
final class EventGoal
{
    public const MODE_NONE = 'none';
    public const MODE_GLOBAL = 'global';
    public const MODE_GUARD = 'guard';

    public const LEVEL_T9 = ChestGoalService::LEVEL_T9;

    /**
     * Ladder of levels with T9 on top, down to G1.
     */
    public const LEVELS = [self::LEVEL_T9, 9, 8, 7, 6, 5, 4, 3, 2, 1];

    public const FIELDS = ['goal_mode', 'goal_points', 'goal_by_guard', 'goal_required'];

    /**
     * Label for a level: T9 for 10, G0 for 0/unknown, G{level} for 1..9.
     *
     * @param int $level Level.
     * @return string
     */
    public static function levelLabel(int $level): string
    {
        if ($level === self::LEVEL_T9) {
            return 'T9';
        }

        return 'G' . $level;
    }

    /**
     * Determine a member's level: T9 if guards, specialists, monsters and engineers are all 9;
     * otherwise their guards level (1..9), or 0 (unknown).
     *
     * @param mixed $member Member entity, array or null.
     * @return int
     */
    public static function levelForMember(mixed $member): int
    {
        if ($member === null) {
            return ChestGoalService::UNKNOWN_LEVEL;
        }

        $guards = is_array($member) ? (int)($member['guards'] ?? 0) : (int)($member->guards ?? 0);
        $specialists = is_array($member) ? (int)($member['specialists'] ?? 0) : (int)($member->specialists ?? 0);
        $monsters = is_array($member) ? (int)($member['monsters'] ?? 0) : (int)($member->monsters ?? 0);
        $engineers = is_array($member) ? (int)($member['engineers'] ?? 0) : (int)($member->engineers ?? 0);

        if ($guards === 9 && $specialists === 9 && $monsters === 9 && $engineers === 9) {
            return self::LEVEL_T9;
        }

        return in_array($guards, ChestGoalService::LEVELS, true) ? $guards : ChestGoalService::UNKNOWN_LEVEL;
    }

    /**
     * @param string $mode One of the MODE_* constants.
     * @param int $points Global goal, and the goal of any level left blank.
     * @param array<int, int> $byGuard Guard level => goal.
     * @param bool $required Whether only players who reached it are rewarded.
     */
    public function __construct(
        public readonly string $mode = self::MODE_NONE,
        public readonly int $points = 0,
        public readonly array $byGuard = [],
        public readonly bool $required = false,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function modeOptions(): array
    {
        return [
            self::MODE_NONE => __('No goal'),
            self::MODE_GLOBAL => __('Global goal: the same goal for every player'),
            self::MODE_GUARD => __('Individual goal by guard level'),
        ];
    }

    /**
     * The goal stored on an event or a catalogue entry.
     *
     * @param \Cake\Datasource\EntityInterface $entity Event or GameTournament.
     * @return self
     */
    public static function fromEntity(EntityInterface $entity): self
    {
        $mode = (string)$entity->get('goal_mode');

        return new self(
            in_array($mode, [self::MODE_GLOBAL, self::MODE_GUARD], true) ? $mode : self::MODE_NONE,
            max(0, (int)$entity->get('goal_points')),
            ChestGoalService::decodeLevels((string)$entity->get('goal_by_guard')),
            (bool)$entity->get('goal_required'),
        );
    }

    /**
     * Column values for the posted goal fields of a form.
     *
     * The form posts `goal[mode]`, `goal[points]`, `goal[by_guard][level]` and
     * `goal[required]`. Thousands separators are accepted; anything that is not
     * a whole number is left out, the browser already refuses it.
     *
     * @param array<string, mixed> $posted The `goal` part of the request data.
     * @return array{goal_mode: string, goal_points: int|null, goal_by_guard: string|null, goal_required: bool}
     */
    public static function marshal(array $posted): array
    {
        $mode = (string)($posted['mode'] ?? self::MODE_NONE);
        if (!in_array($mode, [self::MODE_GLOBAL, self::MODE_GUARD], true)) {
            $mode = self::MODE_NONE;
        }

        $levels = [];
        foreach ((array)($posted['by_guard'] ?? []) as $level => $raw) {
            $goal = self::number($raw);
            if ($goal !== null && in_array((int)$level, self::LEVELS, true)) {
                $levels[(int)$level] = $goal;
            }
        }

        return [
            'goal_mode' => $mode,
            'goal_points' => self::number($posted['points'] ?? null),
            'goal_by_guard' => $levels === [] ? null : ChestGoalService::encodeLevels($levels),
            'goal_required' => $mode !== self::MODE_NONE && !empty($posted['required']),
        ];
    }

    /**
     * Column values of this goal, to copy it onto another event.
     *
     * @return array{goal_mode: string, goal_points: int|null, goal_by_guard: string|null, goal_required: bool}
     */
    public function toFields(): array
    {
        return [
            'goal_mode' => $this->mode,
            'goal_points' => $this->points ?: null,
            'goal_by_guard' => $this->byGuard === [] ? null : ChestGoalService::encodeLevels($this->byGuard),
            'goal_required' => $this->required,
        ];
    }

    /**
     * Whether the event has a goal anybody can reach or miss.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->mode !== self::MODE_NONE && $this->goalForLevel(0) > 0;
    }

    /**
     * Whether reaching the goal is a condition for receiving a reward.
     *
     * @return bool
     */
    public function isRequired(): bool
    {
        return $this->required && $this->isActive();
    }

    /**
     * Whether the goal follows the guard level.
     *
     * @return bool
     */
    public function isByGuard(): bool
    {
        return $this->mode === self::MODE_GUARD;
    }

    /**
     * The goal of a guard level. Unknown levels (0, or anything outside G1-G9)
     * get the highest goal of the table.
     *
     * @param int $level Guard level.
     * @return int
     */
    public function goalForLevel(int $level): int
    {
        if ($this->mode === self::MODE_NONE) {
            return 0;
        }
        if ($this->mode === self::MODE_GLOBAL) {
            return $this->points;
        }
        if (!in_array($level, self::LEVELS, true)) {
            return max(array_map(fn (int $known): int => $this->goalForLevel($known), self::LEVELS));
        }

        return $this->byGuard[$level] ?? $this->points;
    }

    /**
     * The goal of every level, highest level first and G0 last.
     *
     * @return array<int, int>
     */
    public function levelTable(): array
    {
        $table = [];
        foreach (array_merge(self::LEVELS, [ChestGoalService::UNKNOWN_LEVEL]) as $level) {
            $table[$level] = $this->goalForLevel($level);
        }

        return $table;
    }

    /**
     * @param mixed $raw Submitted value.
     * @return int|null
     */
    private static function number(mixed $raw): ?int
    {
        $raw = str_replace(['.', ',', ' '], '', trim((string)$raw));
        if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 15) {
            return null;
        }

        return (int)$raw;
    }
}
