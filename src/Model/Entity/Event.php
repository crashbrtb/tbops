<?php
declare(strict_types=1);

namespace App\Model\Entity;

use App\Service\EventGoal;
use Cake\I18n\DateTime;
use Cake\ORM\Entity;

/**
 * Event Entity
 *
 * @property int $id
 * @property int $event_number
 * @property string $name
 * @property string|null $description
 * @property string $criteria
 * @property string $custom_metric
 * @property \Cake\I18n\DateTime $starts_at
 * @property \Cake\I18n\DateTime $ends_at
 * @property string $prize
 * @property string $contact_player
 * @property string|null $banner_mime
 * @property resource|string|null $banner_image
 * @property string $status
 * @property string $goal_mode none | global | guard
 * @property int|null $goal_points
 * @property string|null $goal_by_guard JSON map of guard level => goal
 * @property bool $goal_required
 * @property \Cake\I18n\DateTime|null $finalized_at
 * @property \Cake\I18n\DateTime|null $published_at
 * @property string|null $game_result_uid
 * @property string|null $game_tournament_key
 * @property int|null $game_tournament_id
 * @property \App\Model\Entity\GameTournament|null $game_tournament
 * @property int|null $created_by
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property array<\App\Model\Entity\EventChest> $event_chests
 * @property array<\App\Model\Entity\EventStanding> $event_standings
 * @property array<\App\Model\Entity\EventReward> $event_rewards
 * @property string $state
 * @property bool $is_running
 * @property bool $is_imported
 * @property bool $has_custom_banner
 */
class Event extends Entity
{
    public const CRITERIA_CHEST_COUNT = 'chest_count';
    public const CRITERIA_CHEST_SCORE = 'chest_score';
    public const CRITERIA_EPIC_MONSTER = 'epic_monster';
    public const CRITERIA_CUSTOM_CHESTS = 'custom_chests';
    /**
     * A tournament played in the game. Its ranking is not computed from chests:
     * it is uploaded by the EventUploader, reviewed, and published with every
     * player's share of the rewards.
     */
    public const CRITERIA_IMPORTED = 'imported';

    public const METRIC_SCORE = 'score';
    public const METRIC_COUNT = 'count';

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Derived lifecycle states. Only `cancelled` is stored; the rest follow from
     * the window, so an event starts and ends without anybody having to run a job.
     */
    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_RUNNING = 'running';
    public const STATE_FINISHED = 'finished';
    public const STATE_CANCELLED = 'cancelled';
    /** Imported event whose result has not been published yet. */
    public const STATE_AWAITING = 'awaiting';

    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * `event_number` is deliberately absent: it is assigned by the table, never
     * by the form, so two administrators cannot pick the same one.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'name' => true,
        'description' => true,
        'criteria' => true,
        'custom_metric' => true,
        'starts_at' => true,
        'ends_at' => true,
        'prize' => true,
        'contact_player' => true,
        'banner_mime' => true,
        'banner_image' => true,
        'status' => true,
        'goal_mode' => true,
        'goal_points' => true,
        'goal_by_guard' => true,
        'goal_required' => true,
        'finalized_at' => true,
        'created_by' => true,
        'event_chests' => true,
        'event_rewards' => true,
    ];

    /**
     * The blob never belongs in a serialized entity: it is served by its own
     * action and would otherwise be dragged into every JSON response.
     *
     * @var list<string>
     */
    protected array $_hidden = ['banner_image'];

    /**
     * @var list<string>
     */
    protected array $_virtual = ['state', 'is_running', 'is_imported', 'has_custom_banner'];

    /**
     * Human-readable labels for each criteria value.
     *
     * @return array<string, string>
     */
    public static function criteriaOptions(): array
    {
        return [
            self::CRITERIA_CHEST_COUNT => __('Number of chests collected'),
            self::CRITERIA_CHEST_SCORE => __('Chest score'),
            self::CRITERIA_EPIC_MONSTER => __('Number of epic monster chests'),
            self::CRITERIA_CUSTOM_CHESTS => __('Custom chests'),
            self::CRITERIA_IMPORTED => __('Game tournament (imported score)'),
        ];
    }

    /**
     * Short explanation of what each criteria counts, shown under the selector.
     *
     * @return array<string, string>
     */
    public static function criteriaHints(): array
    {
        return [
            self::CRITERIA_CHEST_COUNT => __('Every chest collected inside the event window counts as one point.'),
            self::CRITERIA_CHEST_SCORE => __('Chests are worth the score configured for their type.'),
            self::CRITERIA_EPIC_MONSTER => __('Only chests from epic monsters count, one point each.'),
            self::CRITERIA_CUSTOM_CHESTS => __('Only the chest types you pick below count.'),
            self::CRITERIA_IMPORTED => __('The ranking is read from the game by the EventUploader and the rewards below are split among the players.'),
        ];
    }

    /**
     * Label for this event's criteria.
     *
     * @return string
     */
    public function criteriaLabel(): string
    {
        return self::criteriaOptions()[$this->criteria] ?? (string)$this->criteria;
    }

    /**
     * What the "points" column of the standings actually measures, for the
     * dashboard headings.
     *
     * @return string
     */
    public function pointsLabel(): string
    {
        return match (true) {
            $this->criteria === self::CRITERIA_IMPORTED => __('Points'),
            $this->criteria === self::CRITERIA_CHEST_COUNT => __('Chests'),
            $this->criteria === self::CRITERIA_EPIC_MONSTER => __('Epic Chests'),
            $this->criteria === self::CRITERIA_CUSTOM_CHESTS
                && $this->custom_metric === self::METRIC_COUNT => __('Chests'),
            default => __('Total Score'),
        };
    }

    /**
     * The goal players have to reach in this event.
     *
     * @return \App\Service\EventGoal
     */
    public function goal(): EventGoal
    {
        return EventGoal::fromEntity($this);
    }

    /**
     * Whether the result is recorded: published for a game tournament, closed
     * for a clan event. Rewards and goal changed after that are split again
     * over the recorded standings.
     *
     * @return bool
     */
    public function hasRecordedResult(): bool
    {
        return $this->criteria === self::CRITERIA_IMPORTED
            ? $this->published_at !== null
            : $this->finalized_at !== null;
    }

    /**
     * Lifecycle state derived from the stored status and the event window.
     *
     * @return string
     */
    protected function _getState(): string
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return self::STATE_CANCELLED;
        }

        // An imported tournament already happened in the game: its dates say
        // when, not whether it is running. What matters is whether the result
        // has been published.
        if ($this->criteria === self::CRITERIA_IMPORTED) {
            return $this->published_at !== null ? self::STATE_FINISHED : self::STATE_AWAITING;
        }

        $now = DateTime::now();
        if ($this->starts_at !== null && $now->lessThan($this->starts_at)) {
            return self::STATE_SCHEDULED;
        }

        if ($this->ends_at !== null && $now->greaterThan($this->ends_at)) {
            return self::STATE_FINISHED;
        }

        return self::STATE_RUNNING;
    }

    /**
     * @return bool
     */
    protected function _getIsRunning(): bool
    {
        return $this->state === self::STATE_RUNNING;
    }

    /**
     * @return bool
     */
    protected function _getIsImported(): bool
    {
        return $this->criteria === self::CRITERIA_IMPORTED;
    }

    /**
     * @return bool
     */
    protected function _getHasCustomBanner(): bool
    {
        return !empty($this->banner_image);
    }
}
