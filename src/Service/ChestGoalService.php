<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Locator\LocatorAwareTrait;
use InvalidArgumentException;

/**
 * The chest goals each player has to reach in a cycle.
 *
 * Two modes, chosen in Configs > Chests (chest_goal_mode):
 * - global: everyone has minimum_chest_score and minimum_epic_chest_score;
 * - guard: the goal follows the player's guardsmen level (members.guards),
 *   G1 to G9. A level with no goal of its own uses the global goal, so the
 *   table can be filled in gradually.
 *
 * G0 means the level was never identified. Those players, and players with
 * no member record at all, get the highest goal of the table: nobody should
 * get a lighter goal just because their profile was not filled in.
 *
 * These are the goals before the goal penalty. GoalPenaltyService raises them
 * for players who missed the goal in the previous cycle.
 */
class ChestGoalService
{
    use LocatorAwareTrait;

    public const MODE_GLOBAL = 'global';
    public const MODE_GUARD = 'guard';

    public const TARGET_TOTAL = 'total';
    public const TARGET_EPIC = 'epic';

    /**
     * Guard level of a player whose level is not known.
     */
    public const UNKNOWN_LEVEL = 0;

    /**
     * Guard levels that can carry a goal of their own.
     */
    public const LEVELS = [1, 2, 3, 4, 5, 6, 7, 8, 9];

    /**
     * The config row holding each target's global goal.
     */
    public const GLOBAL_PARAMS = [
        self::TARGET_TOTAL => 'minimum_chest_score',
        self::TARGET_EPIC => 'minimum_epic_chest_score',
    ];

    /**
     * The config row holding each target's goals by guard level, as JSON.
     */
    public const BY_GUARD_PARAMS = [
        self::TARGET_TOTAL => 'chest_goal_by_guard',
        self::TARGET_EPIC => 'epic_goal_by_guard',
    ];

    public const MODE_PARAM = 'chest_goal_mode';

    /**
     * Highest goal a single level may be given; keeps the JSON inside the
     * config column and catches a slipped finger.
     */
    private const MAX_GOAL = 99999999;

    /**
     * @var array{mode: string, global: array<string, int>, by_guard: array<string, array<int, int>>}|null
     */
    private ?array $settings = null;

    /**
     * @var array<string, int>|null Player name (lower case) => guard level.
     */
    private ?array $levels = null;

    /**
     * The goal settings, read once from the config table.
     *
     * @return array{mode: string, global: array<string, int>, by_guard: array<string, array<int, int>>}
     */
    public function settings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $configs = $this->fetchTable('Config')->find('list', keyField: 'param', valueField: 'value')
            ->where(['param IN' => array_merge(
                [self::MODE_PARAM],
                array_values(self::GLOBAL_PARAMS),
                array_values(self::BY_GUARD_PARAMS)
            )])
            ->toArray();

        $global = [];
        $byGuard = [];
        foreach (self::GLOBAL_PARAMS as $target => $param) {
            $global[$target] = is_numeric($configs[$param] ?? null) ? max(0, (int)$configs[$param]) : 0;
            $byGuard[$target] = self::decodeLevels((string)($configs[self::BY_GUARD_PARAMS[$target]] ?? ''));
        }

        $mode = strtolower(trim((string)($configs[self::MODE_PARAM] ?? '')));

        return $this->settings = [
            'mode' => $mode === self::MODE_GUARD ? self::MODE_GUARD : self::MODE_GLOBAL,
            'global' => $global,
            'by_guard' => $byGuard,
        ];
    }

    /**
     * Whether goals follow the guard level.
     *
     * @return bool
     */
    public function isByGuard(): bool
    {
        return $this->settings()['mode'] === self::MODE_GUARD;
    }

    /**
     * The global goal of a target.
     *
     * @param string $target TARGET_TOTAL or TARGET_EPIC.
     * @return int
     */
    public function globalGoal(string $target): int
    {
        return $this->settings()['global'][$target] ?? 0;
    }

    /**
     * The goal of a guard level. Unknown levels (0, or anything outside G1-G9)
     * get the highest goal of the table.
     *
     * @param int $level Guard level.
     * @param string $target TARGET_TOTAL or TARGET_EPIC.
     * @return int
     */
    public function goalForLevel(int $level, string $target): int
    {
        if (!$this->isByGuard()) {
            return $this->globalGoal($target);
        }

        if (!in_array($level, self::LEVELS, true)) {
            return max(array_map(fn (int $known): int => $this->goalForLevel($known, $target), self::LEVELS));
        }

        return $this->settings()['by_guard'][$target][$level] ?? $this->globalGoal($target);
    }

    /**
     * Both goals of every level, G0 first.
     *
     * @return array<int, array<string, int>> Level => target => goal.
     */
    public function levelTable(): array
    {
        $table = [];
        foreach (array_merge([self::UNKNOWN_LEVEL], self::LEVELS) as $level) {
            foreach (array_keys(self::GLOBAL_PARAMS) as $target) {
                $table[$level][$target] = $this->goalForLevel($level, $target);
            }
        }

        return $table;
    }

    /**
     * Whether anyone can have a goal above 0 for the target.
     *
     * @param string $target TARGET_TOTAL or TARGET_EPIC.
     * @return bool
     */
    public function hasGoal(string $target): bool
    {
        return $this->goalForLevel(self::UNKNOWN_LEVEL, $target) > 0;
    }

    /**
     * The guard level of a player, from their member record; 0 when there is
     * no record or the level was never set.
     *
     * Chest rows carry the player's name, so this is a match by name; it
     * ignores case the way the database does.
     *
     * @param string $player Player name.
     * @return int
     */
    public function guardLevel(string $player): int
    {
        if ($this->levels === null) {
            $this->levels = [];
            $rows = $this->fetchTable('Members')->find()
                ->select(['player', 'guards'])
                ->all();
            foreach ($rows as $row) {
                $this->levels[mb_strtolower((string)$row->player)] = (int)$row->guards;
            }
        }

        $level = $this->levels[mb_strtolower($player)] ?? self::UNKNOWN_LEVEL;

        return in_array($level, self::LEVELS, true) ? $level : self::UNKNOWN_LEVEL;
    }

    /**
     * Both goals of a player.
     *
     * @param string $player Player name.
     * @return array<string, int> Target => goal.
     */
    public function goalsFor(string $player): array
    {
        $level = $this->isByGuard() ? $this->guardLevel($player) : self::UNKNOWN_LEVEL;
        $goals = [];
        foreach (array_keys(self::GLOBAL_PARAMS) as $target) {
            $goals[$target] = $this->goalForLevel($level, $target);
        }

        return $goals;
    }

    /**
     * Store the goal settings.
     *
     * @param array<string, mixed> $data `mode`, `global` (target => goal) and
     *   `by_guard` (target => level => goal; blank = use the global goal).
     * @return void
     * @throws \InvalidArgumentException When a value is not a whole number of 0 or more.
     */
    public function save(array $data): void
    {
        $mode = (string)($data['mode'] ?? self::MODE_GLOBAL);
        if (!in_array($mode, [self::MODE_GLOBAL, self::MODE_GUARD], true)) {
            throw new InvalidArgumentException(__('Choose how the chest goals are set.'));
        }

        $values = [self::MODE_PARAM => $mode];
        foreach (self::GLOBAL_PARAMS as $target => $param) {
            $global = self::parseGoal($data['global'][$target] ?? null);
            if ($global === null) {
                throw new InvalidArgumentException(__('The global goals must be whole numbers of 0 or more.'));
            }
            $values[$param] = (string)$global;

            $levels = [];
            foreach (self::LEVELS as $level) {
                $raw = $data['by_guard'][$target][$level] ?? '';
                if (trim((string)$raw) === '') {
                    continue;
                }
                $goal = self::parseGoal($raw);
                if ($goal === null) {
                    throw new InvalidArgumentException(
                        __('G{0}: the goal must be a whole number of 0 or more, or left blank.', $level)
                    );
                }
                $levels[$level] = $goal;
            }
            $values[self::BY_GUARD_PARAMS[$target]] = self::encodeLevels($levels);
        }

        $config = $this->fetchTable('Config');
        $config->getConnection()->transactional(function () use ($config, $values): void {
            foreach ($values as $param => $value) {
                $row = $config->find()->where(['param' => $param])->first()
                    ?? $config->newEntity(['param' => $param, 'description' => $param], ['validate' => false]);
                $row->value = $value;
                $config->saveOrFail($row);
            }
        });

        $this->settings = null;
    }

    /**
     * A stored JSON map of level => goal, keeping only levels G1-G9 and goals of 0 or more.
     *
     * @param string $json Stored value.
     * @return array<int, int>
     */
    public static function decodeLevels(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $levels = [];
        foreach ($decoded as $level => $goal) {
            $known = is_numeric($level) && in_array((int)$level, self::LEVELS, true);
            if ($known && is_numeric($goal) && (int)$goal >= 0) {
                $levels[(int)$level] = (int)$goal;
            }
        }
        ksort($levels);

        return $levels;
    }

    /**
     * A map of level => goal as stored in the config row.
     *
     * @param array<int, int> $levels Level => goal.
     * @return string
     */
    public static function encodeLevels(array $levels): string
    {
        ksort($levels);
        $object = [];
        foreach ($levels as $level => $goal) {
            $object[(string)$level] = $goal;
        }

        return $object === [] ? '{}' : (string)json_encode($object, JSON_FORCE_OBJECT);
    }

    /**
     * @param mixed $raw Submitted value.
     * @return int|null Null when it is not a whole number between 0 and MAX_GOAL.
     */
    private static function parseGoal(mixed $raw): ?int
    {
        $raw = trim((string)$raw);
        if ($raw === '' || !ctype_digit($raw) || strlen($raw) > 8) {
            return null;
        }
        $goal = (int)$raw;

        return $goal <= self::MAX_GOAL ? $goal : null;
    }
}
