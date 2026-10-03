<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * One thing an event hands out, and how it is split.
 *
 * @property int $id
 * @property int $event_id
 * @property string $item_name
 * @property int $quantity
 * @property string|null $position_amounts JSON list of the amount for each place (rule position only)
 * @property string $rule
 * @property int $min_points
 * @property string $remainder
 * @property int $sort
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 */
class EventReward extends Entity
{
    public const RULE_EQUAL = 'equal';
    public const RULE_PROPORTIONAL = 'proportional';
    /** A fixed amount per place: 1st gets X, 2nd gets Y, and so on. */
    public const RULE_POSITION = 'position';

    public const REMAINDER_TOP_RANKED = 'top_ranked';
    public const REMAINDER_KEEP = 'keep';

    /**
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'item_name' => true,
        'quantity' => true,
        'position_amounts' => true,
        'rule' => true,
        'min_points' => true,
        'remainder' => true,
        'sort' => true,
    ];

    /**
     * @return array<string, string>
     */
    public static function ruleOptions(): array
    {
        return [
            self::RULE_PROPORTIONAL => __('Proportional to the points'),
            self::RULE_EQUAL => __('Equal parts'),
            self::RULE_POSITION => __('By position'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function remainderOptions(): array
    {
        return [
            self::REMAINDER_TOP_RANKED => __('Give the leftover to the best placed'),
            self::REMAINDER_KEEP => __('Keep the leftover with the clan'),
        ];
    }

    /**
     * The amount of each place, first place first (rule position only).
     *
     * @return list<int>
     */
    public function positionList(): array
    {
        $decoded = json_decode((string)$this->position_amounts, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('intval', array_filter($decoded, 'is_numeric')));
    }

    /**
     * Short text for lists: "500 x Artifact pieces (proportional)", or
     * "Gold: 1st 500, 2nd 250" for a reward by position.
     *
     * @return string
     */
    public function summary(): string
    {
        if ($this->rule === self::RULE_POSITION) {
            $places = [];
            foreach ($this->positionList() as $index => $amount) {
                $places[] = __('#{0} {1}', $index + 1, number_format($amount, 0, ',', '.'));
            }

            return __('{0}: {1}', $this->item_name, implode(', ', $places));
        }

        return __('{0} x {1} ({2})', number_format((int)$this->quantity, 0, ',', '.'), $this->item_name, mb_strtolower(
            self::ruleOptions()[$this->rule] ?? (string)$this->rule
        ));
    }
}
