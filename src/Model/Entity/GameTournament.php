<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Service\EventGoal;
use Cake\ORM\Entity;

/**
 * A tournament of the game, as the catalogue knows it.
 *
 * @property int $id
 * @property int $game_type
 * @property string $ranking
 * @property string|null $name The game's own name, in English
 * @property string|null $display_name The name the clan shows, when not the game's
 * @property string|null $description
 * @property string|null $prize_items JSON list of the clan prizes split among the players
 * @property bool $auto_publish Publish a complete ranking without review
 * @property string|null $name_source
 * @property int|null $duration_days
 * @property string|null $image_mime
 * @property resource|string|null $image
 * @property string|null $last_variant
 * @property \Cake\I18n\DateTime|null $first_seen_at
 * @property \Cake\I18n\DateTime|null $last_seen_at
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property bool $has_image
 */
class GameTournament extends Entity
{
    public const SOURCE_JOURNAL = 'journal';
    public const SOURCE_UPLOADER = 'uploader';
    public const SOURCE_MANUAL = 'manual';

    /** The ranking of a classic tournament result: the only one most types have. */
    public const RANKING_DEFAULT = '';

    /** Duration assumed for a tournament whose length nobody has set. */
    public const DEFAULT_DURATION_DAYS = 1;

    /** The parts of a tournament's page, each edited on its own. */
    public const SECTION_NAME = 'name';
    public const SECTION_DESCRIPTION = 'description';
    public const SECTION_DURATION = 'duration';
    public const SECTION_IMAGE = 'image';
    public const SECTION_GOAL = 'goal';
    public const SECTION_PRIZES = 'prizes';
    public const SECTIONS = [
        self::SECTION_NAME, self::SECTION_DESCRIPTION, self::SECTION_DURATION,
        self::SECTION_IMAGE, self::SECTION_GOAL, self::SECTION_PRIZES,
    ];

    /**
     * Only what the administration form edits. Everything else is written by
     * the catalogue service.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'name' => true,
        'display_name' => true,
        'description' => true,
        'duration_days' => true,
        'goal_mode' => true,
        'goal_points' => true,
        'goal_by_guard' => true,
        'goal_required' => true,
    ];

    /**
     * @var list<string>
     */
    protected array $_hidden = ['image'];

    /**
     * @var list<string>
     */
    protected array $_virtual = ['has_image'];

    /**
     * @return bool
     */
    protected function _getHasImage(): bool
    {
        return !empty($this->image_mime) || !empty($this->get('game_icon'));
    }

    /**
     * The name to show, or a placeholder built from the game's id.
     *
     * @return string
     */
    public function displayName(): string
    {
        $shown = trim((string)$this->display_name);

        return $shown !== '' ? $shown : $this->gameName();
    }

    /**
     * The game's own name, or a placeholder built from the game's id.
     *
     * @return string
     */
    public function gameName(): string
    {
        return $this->name !== null && $this->name !== ''
            ? $this->name
            : __('Game tournament {0}', $this->game_type);
    }

    /**
     * Whether the game's name is known: read off the Journal, or typed by an
     * administrator while the game had not said it.
     *
     * @return bool
     */
    public function hasGameName(): bool
    {
        return $this->name !== null && $this->name !== '';
    }

    /**
     * Whether the game itself named the tournament: that name is not edited
     * here, only the display name.
     *
     * @return bool
     */
    public function isNamedByTheGame(): bool
    {
        return $this->hasGameName() && $this->name_source === self::SOURCE_JOURNAL;
    }

    /**
     * The clan prizes split among the players of every new event.
     *
     * @return list<array{item_id: int, name: string, rule: string, min_points: int, remainder: string}>
     */
    public function prizeSelection(): array
    {
        $decoded = json_decode((string)$this->get('prize_items'), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $prize) {
            if (!is_array($prize) || !isset($prize['item_id'])) {
                continue;
            }
            $out[] = [
                'item_id' => (int)$prize['item_id'],
                'name' => (string)($prize['name'] ?? ''),
                'rule' => (string)($prize['rule'] ?? EventReward::RULE_PROPORTIONAL),
                'min_points' => max(0, (int)($prize['min_points'] ?? 1)),
                'remainder' => (string)($prize['remainder'] ?? EventReward::REMAINDER_TOP_RANKED),
            ];
        }

        return $out;
    }

    /**
     * The parts of the page not filled in yet, by section, with the label to
     * show. Publishing without review asks before going on without them.
     *
     * @return array<string, string>
     */
    public function missingSections(): array
    {
        $missing = [];
        if (!$this->hasGameName()) {
            $missing[self::SECTION_NAME] = __('the name');
        }
        if (trim((string)$this->get('description')) === '') {
            $missing[self::SECTION_DESCRIPTION] = __('the description');
        }
        if (!$this->duration_days) {
            $missing[self::SECTION_DURATION] = __('the duration');
        }
        if (!$this->has_image) {
            $missing[self::SECTION_IMAGE] = __('the image');
        }
        if (!EventGoal::fromEntity($this)->isActive()) {
            $missing[self::SECTION_GOAL] = __('the goal');
        }
        if ($this->prizeSelection() === []) {
            $missing[self::SECTION_PRIZES] = __('the prizes');
        }

        return $missing;
    }

    /**
     * Short name of the ranking for the administration pages: the statistic
     * when there is one, otherwise the message kind without its `_entry` ending.
     *
     * @return string
     */
    public function rankingLabel(): string
    {
        $ranking = (string)$this->ranking;
        $colon = strpos($ranking, ':');

        return $colon !== false ? substr($ranking, $colon + 1) : (string)preg_replace('/_entry$/', '', $ranking);
    }

    /**
     * @return int
     */
    public function effectiveDurationDays(): int
    {
        return max(1, (int)($this->duration_days ?: self::DEFAULT_DURATION_DAYS));
    }
}
