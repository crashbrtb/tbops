<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Service\ChestGoalService;
use App\Service\GoalPenaltyService;
use Cake\I18n\FrozenTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Validation\Validator;

/**
 * PlayerCycleSummaries Model
 *
 * @method \App\Model\Entity\PlayerCycleSummary newEmptyEntity() 
 * @method \App\Model\Entity\PlayerCycleSummary newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\PlayerCycleSummary[] newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\PlayerCycleSummary get($primaryKey, $options = [])
 * @method \App\Model\Entity\PlayerCycleSummary findOrCreate($search, ?callable $callback = null, $options = [])
 * @method \App\Model\Entity\PlayerCycleSummary patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\PlayerCycleSummary[] patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\PlayerCycleSummary|false save(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\PlayerCycleSummary saveOrFail(\Cake\Datasource\EntityInterface $entity, $options = [])
 * @method \App\Model\Entity\PlayerCycleSummary[]|\Cake\Datasource\ResultSetInterface|false saveMany(iterable $entities, $options = [])
 * @method \App\Model\Entity\PlayerCycleSummary[]|\Cake\Datasource\ResultSetInterface saveManyOrFail(iterable $entities, $options = [])
 * @method \App\Model\Entity\PlayerCycleSummary[]|\Cake\Datasource\ResultSetInterface|false deleteMany(iterable $entities, $options = [])
 * @method \App\Model\Entity\PlayerCycleSummary[]|\Cake\Datasource\ResultSetInterface deleteManyOrFail(iterable $entities, $options = [])
 *
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class PlayerCycleSummariesTable extends Table
{
    /**
     * Initialize method
     *
     * @param array $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('player_cycle_summaries');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('player_name')
            ->maxLength('player_name', 255)
            ->requirePresence('player_name', 'create')
            ->notEmptyString('player_name');

        $validator
            ->date('cycle_start_date')
            ->requirePresence('cycle_start_date', 'create')
            ->notEmptyDate('cycle_start_date');

        $validator
            ->date('cycle_end_date')
            ->requirePresence('cycle_end_date', 'create')
            ->notEmptyDate('cycle_end_date');

        $validator
            ->integer('total_chests')
            ->notEmptyString('total_chests');

        $validator
            ->integer('total_score')
            ->notEmptyString('total_score');

        $validator
            ->integer('epic_crypt_score')
            ->notEmptyString('epic_crypt_score');

        $validator
            ->boolean('goal_achieved')
            ->notEmptyString('goal_achieved');

        $validator
            ->boolean('fine_due')
            ->notEmptyString('fine_due');

        $validator
            ->boolean('fine_paid')
            ->notEmptyString('fine_paid');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        // Adiciona uma regra para garantir que player_name e cycle_start_date sejam únicos juntos
        $rules->add($rules->isUnique(['player_name', 'cycle_start_date']), [
            'errorField' => 'player_name',
            'message' => 'This player already has a summary for this cycle start date.'
        ]);

        return $rules;
    }

    /**
     * Process cycle summaries for a given date range.
     *
     * Calculates player scores from collected chests within the cycle period
     * and saves summary records to the player_cycle_summaries table.
     *
     * This method is shared by both the web controller (manual processing)
     * and the DailyMaintenanceCommand (automated processing).
     *
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the cycle period.
     * @param \Cake\I18n\FrozenTime $cycleEnd End of the cycle period.
     * @param int $minimumRequiredScore Unused: each player is judged by their own chest score goal
     *   (ChestGoalService, by guard level when so configured), or by the raised goal when the goal
     *   penalty applies to it. Kept so existing callers do not break.
     * @param bool $forceReprocess If true, deletes existing summaries for this cycle before reprocessing.
     * @return array{processed: int, errors: int, skipped: bool} Result of the processing.
     */
    public function processCycleForDateRange(
        FrozenTime $cycleStart,
        FrozenTime $cycleEnd,
        int $minimumRequiredScore,
        bool $forceReprocess = false
    ): array {
        // Check if this cycle has already been processed
        $existingCount = $this->find()
            ->where(['cycle_start_date' => $cycleStart->format('Y-m-d')])
            ->count();

        if ($existingCount > 0 && !$forceReprocess) {
            return ['processed' => 0, 'errors' => 0, 'skipped' => true];
        }

        // If force reprocess, clear existing records for this cycle
        if ($forceReprocess && $existingCount > 0) {
            $this->deleteAll(['cycle_start_date' => $cycleStart->format('Y-m-d')]);
        }

        $playerSummaries = $this->scoresForDateRange($cycleStart, $cycleEnd);

        // Each player's goal (by guard level when so configured); players who missed the goal
        // in the previous cycle, or got a manual penalty, carry a raised one in this cycle
        $goals = new ChestGoalService();
        $penalties = (new GoalPenaltyService($goals))->penaltiesForCycle($cycleStart);

        // Save summaries
        $processedCount = 0;
        $errorCount = 0;

        foreach ($playerSummaries as $playerName => $data) {
            $playerName = (string)$playerName;
            $raised = $penalties[$playerName]['goals'] ?? [];
            $baseGoals = $goals->goalsFor($playerName);
            $requiredScore = $raised[GoalPenaltyService::TARGET_TOTAL] ?? $baseGoals[ChestGoalService::TARGET_TOTAL];
            $goalAchieved = $data['total_score'] >= $requiredScore;
            $fineDue = !$goalAchieved;

            $summary = $this->newEntity([
                'player_name' => $playerName,
                'cycle_start_date' => $cycleStart->format('Y-m-d'),
                'cycle_end_date' => $cycleEnd->format('Y-m-d'),
                'total_chests' => $data['total_chests'],
                'total_score' => $data['total_score'],
                'epic_crypt_score' => $data['epic_crypt_score'],
                'guard_level' => $goals->guardLevel($playerName),
                'chest_goal' => $baseGoals[ChestGoalService::TARGET_TOTAL],
                'epic_goal' => $baseGoals[ChestGoalService::TARGET_EPIC],
                'penalty_goal' => $raised[GoalPenaltyService::TARGET_TOTAL] ?? null,
                'penalty_epic_goal' => $raised[GoalPenaltyService::TARGET_EPIC] ?? null,
                'penalty_target' => GoalPenaltyService::targetLabel($raised),
                'penalty_reason' => $penalties[$playerName]['automatic_reason'] ?? null,
                'goal_achieved' => $goalAchieved,
                'fine_due' => $fineDue,
                'fine_paid' => false,
            ]);

            if ($this->save($summary)) {
                $processedCount++;
            } else {
                $errorCount++;
            }
        }

        return ['processed' => $processedCount, 'errors' => $errorCount, 'skipped' => false];
    }

    /**
     * Chest totals and scores per player for a date range, read from collected_chests.
     *
     * The epic crypt score adds up chests whose source contains "epic", the
     * same rule the score page uses.
     *
     * @param \Cake\I18n\FrozenTime $cycleStart Start of the period.
     * @param \Cake\I18n\FrozenTime $cycleEnd End of the period.
     * @return array<string, array{total_chests: int, total_score: int, epic_crypt_score: int}>
     */
    public function scoresForDateRange(FrozenTime $cycleStart, FrozenTime $cycleEnd): array
    {
        $collectedChestsTable = TableRegistry::getTableLocator()->get('CollectedChests');
        $standardChestsTable = TableRegistry::getTableLocator()->get('StandardChests');

        $collectedChestsData = $collectedChestsTable->find()
            ->select(['player', 'source', 'count' => 'COUNT(*)'])
            ->where([
                'collected_at >=' => $cycleStart,
                'collected_at <=' => $cycleEnd,
            ])
            ->group(['player', 'source'])
            ->toArray();

        $chestScoresResult = $standardChestsTable->find()
            ->select(['source', 'score'])
            ->toArray();

        $chestScores = [];
        foreach ($chestScoresResult as $row) {
            $chestScores[$row->source] = $row;
        }

        // Build player summaries
        $playerSummaries = [];
        foreach ($collectedChestsData as $chest) {
            $playerName = $chest->player;
            $sourceName = $chest->source;
            $chestCount = (int)$chest->count;

            if (!isset($playerSummaries[$playerName])) {
                $playerSummaries[$playerName] = [
                    'total_chests' => 0,
                    'total_score' => 0,
                    'epic_crypt_score' => 0,
                ];
            }

            $playerSummaries[$playerName]['total_chests'] += $chestCount;

            if (isset($chestScores[$sourceName])) {
                $scorePerChest = $chestScores[$sourceName]->score;
                $scoreForThisGroup = $scorePerChest * $chestCount;
                $playerSummaries[$playerName]['total_score'] += $scoreForThisGroup;

                if (stripos($sourceName, 'epic') !== false) {
                    $playerSummaries[$playerName]['epic_crypt_score'] += $scoreForThisGroup;
                }
            }
        }

        return $playerSummaries;
    }
} 