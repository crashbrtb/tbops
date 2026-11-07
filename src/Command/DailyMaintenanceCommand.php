<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\Event;
use App\Model\Entity\JobRun;
use App\Model\Table\JobRunsTable;
use App\Service\EventScoringService;
use App\Service\JobRunRecorder;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
// Aliased: this file already uses PHP's own DateTime, and the Cake one is what
// the ORM hands back and expects.
use Cake\I18n\DateTime as CakeDateTime;
use Cake\I18n\FrozenTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use DateTime;
use Throwable;

/**
 * DailyMaintenance command.
 *
 * Orchestrates daily automated maintenance tasks:
 * 1. Process pending/unprocessed completed cycle summaries.
 * 2. Purge old collected chests based on retention configuration.
 * 3. Record the results of events whose window has closed.
 * 4. Delete monitoring history past its retention.
 *
 * Who is in the clan is no longer worked out from the chests: the game's own
 * list of members (MemberRosterService) decides it.
 *
 * Every run writes its heartbeat to `job_runs`. A step that fails no longer
 * passes for success: the run is recorded as partial or failed and the command
 * exits with an error, so both the cron log and the health check show it.
 */
class DailyMaintenanceCommand extends Command
{
    use LocatorAwareTrait;

    /**
     * What went wrong during the current run, for its heartbeat.
     *
     * @var list<string>
     */
    protected array $problems = [];

    /**
     * Hook method for defining this command's option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to be defined
     * @return \Cake\Console\ConsoleOptionParser The built parser.
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);

        $parser->setDescription('Runs daily automated maintenance tasks (cycle summaries, purge, and events).')
            ->addOption('dry-run', [
                'boolean' => true,
                'help' => 'Perform a dry run without modifying the database.',
            ])
            ->addOption('skip-summaries', [
                'boolean' => true,
                'help' => 'Skip cycle summaries processing step.',
            ])
            ->addOption('skip-members', [
                'boolean' => true,
                'help' => 'No longer used: the members are not updated from the chests any more. Accepted so existing schedules keep working.',
            ])
            ->addOption('skip-purge', [
                'boolean' => true,
                'help' => 'Skip old chests purge step.',
            ])
            ->addOption('skip-events', [
                'boolean' => true,
                'help' => 'Skip recording the results of events that have ended.',
            ]);

        return $parser;
    }

    /**
     * Implement this method with your command's logic.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null|void The exit code or null for success
     */
    public function execute(Arguments $args, ConsoleIo $io)
    {
        $io->out('<info>Starting Daily Maintenance Task...</info>');
        $isDryRun = (bool)$args->getOption('dry-run');
        $this->problems = [];

        if ($isDryRun) {
            $io->warning('Running in DRY-RUN mode. No changes will be saved to database.');
        }

        // A dry run proves nothing about the schedule, so it leaves no heartbeat.
        $recorder = new JobRunRecorder();
        $run = $isDryRun ? null : $recorder->start(JobRunRecorder::JOB_DAILY_MAINTENANCE);

        // [option that skips it, what it does, what the skip message calls it, the step]
        $steps = [
            'summaries' => ['skip-summaries', 'Processing pending cycle summaries...', 'cycle summaries processing',
                fn (): bool => $this->runProcessCycleSummaries($io, $isDryRun)],
            'purge' => ['skip-purge', 'Purging old collected chests...', 'old chests purge',
                fn (): bool => $this->runPurgeCollectedChests($io, $isDryRun)],
            'events' => ['skip-events', 'Recording results for events that have ended...', 'event results recording',
                fn (): bool => $this->runFinalizeEndedEvents($io, $isDryRun)],
            'job_runs' => [null, 'Purging old monitoring history...', null,
                fn (): bool => $this->runPurgeJobRuns($io, $isDryRun)],
        ];

        $results = [];
        $number = 0;
        foreach ($steps as $key => [$skipOption, $title, $skipName, $step]) {
            $number++;
            $label = sprintf('[Task %d/%d]', $number, count($steps));

            if ($skipOption !== null && $args->getOption($skipOption)) {
                $io->out(sprintf('%s Skipped %s (--%s specified).', $label, $skipName, $skipOption));
                $results[$key] = 'skipped';
                continue;
            }

            $io->hr();
            $io->out(sprintf('<info>%s %s</info>', $label, $title));

            // One step that breaks must not keep the others from running.
            try {
                $results[$key] = $step() ? 'ok' : 'failed';
            } catch (Throwable $e) {
                $this->problem($io, sprintf('%s: %s', $key, $e->getMessage()));
                $results[$key] = 'failed';
            }
        }

        $failed = count(array_keys($results, 'failed', true));
        $ran = $failed + count(array_keys($results, 'ok', true));

        if ($failed === 0) {
            $status = JobRun::STATUS_SUCCESS;
        } else {
            $status = $failed < $ran ? JobRun::STATUS_PARTIAL : JobRun::STATUS_FAILED;
        }
        $recorder->finish($run, $status, [
            'steps' => $results,
            'problems' => array_slice($this->problems, 0, 10),
        ]);

        $io->hr();
        if ($failed > 0) {
            $io->error(sprintf('Daily Maintenance finished with %d failed step(s).', $failed));

            return static::CODE_ERROR;
        }
        $io->success('Daily Maintenance completed successfully.');

        return static::CODE_SUCCESS;
    }

    /**
     * Report a problem on the console and keep it for the run's summary.
     *
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @param string $message What went wrong.
     * @return void
     */
    protected function problem(ConsoleIo $io, string $message): void
    {
        $io->error($message);
        $this->problems[] = $message;
    }

    /**
     * Freeze the standings of every event whose window has closed.
     *
     * This is not a convenience: `collected_chests` is purged on a retention
     * schedule, so an event nobody closed by hand loses the data its result was
     * computed from and its history becomes permanently empty. Running here
     * means that only happens if maintenance itself stops running.
     *
     * Events already recorded are left alone, so an administrator's own
     * correction is never overwritten by the next nightly run.
     *
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @param bool $isDryRun Whether to report without writing.
     * @return bool Whether the step went through without errors.
     */
    protected function runFinalizeEndedEvents(ConsoleIo $io, bool $isDryRun): bool
    {
        try {
            $events = $this->fetchTable('Events');
        } catch (Throwable $e) {
            $io->warning('Events module is not installed; skipping. (' . $e->getMessage() . ')');

            return true;
        }

        $pending = $events->find('withoutBanner')
            ->where([
                'Events.ends_at <' => CakeDateTime::now(),
                'Events.finalized_at IS' => null,
                'Events.status !=' => Event::STATUS_CANCELLED,
                // A game tournament's result is its reviewed ranking, published by
                // an administrator; there are no chests to compute it from.
                'Events.criteria !=' => Event::CRITERIA_IMPORTED,
            ])
            ->orderBy(['Events.ends_at' => 'ASC'])
            ->all();

        if ($pending->isEmpty()) {
            $io->out('No event is waiting to be recorded.');

            return true;
        }

        $service = new EventScoringService();

        foreach ($pending as $event) {
            if ($isDryRun) {
                $io->out(sprintf(
                    '[DRY-RUN] Would record the result of event #%d (%s).',
                    $event->event_number,
                    $event->name
                ));
                continue;
            }

            $recorded = $service->finalize($event);
            $event->set('finalized_at', CakeDateTime::now());
            $events->save($event, ['checkRules' => false]);

            $io->out(sprintf(
                'Recorded event #%d (%s): %d player(s) ranked.',
                $event->event_number,
                $event->name,
                $recorded
            ));
        }

        return true;
    }

    /**
     * Runs the process cycle summaries task for any completed cycle that has not been summarized yet.
     *
     * @param \Cake\Console\ConsoleIo $io
     * @param bool $isDryRun
     * @return bool Whether the step went through without errors.
     */
    protected function runProcessCycleSummaries(ConsoleIo $io, bool $isDryRun): bool
    {
        $configsTable = $this->fetchTable('Config');
        $playerCycleSummariesTable = $this->fetchTable('PlayerCycleSummaries');

        $referenceDayConfig = $configsTable->find()->where(['param' => 'reference_day'])->first();
        $everyHowManyDaysConfig = $configsTable->find()->where(['param' => 'every_how_many_days'])->first();
        $minimumChestScoreConfig = $configsTable->find()->where(['param' => 'minimum_chest_score'])->first();

        if (!($referenceDayConfig && $everyHowManyDaysConfig && $minimumChestScoreConfig &&
              !empty($referenceDayConfig->value) && is_numeric($everyHowManyDaysConfig->value) && is_numeric($minimumChestScoreConfig->value))) {
            $this->problem($io, 'Configuration parameters for cycle processing (reference_day, every_how_many_days, minimum_chest_score) are missing or invalid.');

            return false;
        }

        $referenceDay = new FrozenTime($referenceDayConfig->value);
        $cycleDuration = (int)$everyHowManyDaysConfig->value;
        $minimumRequiredScore = (int)$minimumChestScoreConfig->value;

        $today = FrozenTime::now();
        $daysSinceReference = $referenceDay->diffInDays($today);
        $currentCycleOffset = (int)floor($daysSinceReference / $cycleDuration);

        // Check completed cycles starting from cycle 0 up to currentCycleOffset - 1
        $processedCountAll = 0;
        $unprocessedFound = 0;
        $failed = false;

        for ($offset = 0; $offset < $currentCycleOffset; $offset++) {
            $cycleStart = $referenceDay->addDays($offset * $cycleDuration);
            $cycleEnd = $cycleStart->addDays($cycleDuration)->sub(new \DateInterval('PT1S'));

            $existingSummaryCheck = $playerCycleSummariesTable->find()
                ->where(['cycle_start_date' => $cycleStart->format('Y-m-d')])
                ->count();

            if ($existingSummaryCheck > 0) {
                continue; // Cycle already processed
            }

            $unprocessedFound++;
            $io->out(sprintf('Found unprocessed cycle: %s to %s', $cycleStart->format('Y-m-d'), $cycleEnd->format('Y-m-d')));

            if ($isDryRun) {
                $io->out(' [DRY-RUN] Would process summaries for this cycle.');
                continue;
            }

            $result = $playerCycleSummariesTable->processCycleForDateRange(
                $cycleStart,
                $cycleEnd,
                $minimumRequiredScore,
                false
            );

            if ($result['processed'] > 0) {
                $io->success(sprintf(' Successfully processed %d player summaries for cycle %s.', $result['processed'], $cycleStart->format('Y-m-d')));
                $processedCountAll += $result['processed'];
            }
            if ($result['errors'] > 0) {
                $this->problem($io, sprintf('Encountered %d errors processing cycle %s.', $result['errors'], $cycleStart->format('Y-m-d')));
                $failed = true;
            }
        }

        if ($unprocessedFound === 0) {
            $io->out('No pending cycles to process. All completed cycles have summaries recorded.');
        }

        return !$failed;
    }

    /**
     * Runs the purge task for collected chests older than retention period.
     *
     * @param \Cake\Console\ConsoleIo $io
     * @param bool $isDryRun
     * @return bool Whether the step went through without errors.
     */
    protected function runPurgeCollectedChests(ConsoleIo $io, bool $isDryRun): bool
    {
        $configTable = $this->fetchTable('Config');
        $collectedChestsTable = $this->fetchTable('CollectedChests');

        $config = $configTable->find()
            ->where(['param' => 'collected_chests_retention_days'])
            ->first();

        $retentionDays = $config ? (int)$config->value : 30;

        if ($retentionDays <= 0) {
            $io->out('Automatic purge is disabled in configuration (collected_chests_retention_days = 0).');

            return true;
        }

        $cutoffDate = (new DateTime())
            ->modify("-{$retentionDays} days")
            ->format('Y-m-d H:i:s');

        $io->out(sprintf('Retention days: %d days (cutoff date: %s)', $retentionDays, $cutoffDate));

        $chestsToPurgeCount = $collectedChestsTable->find()
            ->where(['collected_at <' => $cutoffDate])
            ->count();

        if ($chestsToPurgeCount === 0) {
            $io->out('No old collected chests found to purge.');

            return true;
        }

        if ($isDryRun) {
            $io->out(sprintf(' [DRY-RUN] Would purge %d old chest record(s).', $chestsToPurgeCount));

            return true;
        }

        $count = $collectedChestsTable->deleteAll(['collected_at <' => $cutoffDate]);
        $io->success(sprintf('Successfully purged %d old collected chest record(s).', $count));

        return true;
    }

    /**
     * Delete monitoring history past its retention, keeping the last good run
     * of every job.
     *
     * @param \Cake\Console\ConsoleIo $io Console io.
     * @param bool $isDryRun Whether to report without writing.
     * @return bool Whether the step went through without errors.
     */
    protected function runPurgeJobRuns(ConsoleIo $io, bool $isDryRun): bool
    {
        /** @var \App\Model\Table\JobRunsTable $jobRuns */
        $jobRuns = $this->fetchTable('JobRuns');

        if ($isDryRun) {
            $io->out(sprintf(
                ' [DRY-RUN] Would delete monitoring history older than %d days.',
                JobRunsTable::RETENTION_DAYS
            ));

            return true;
        }

        $count = $jobRuns->purgeOlderThan();
        $io->out(sprintf(
            'Deleted %d monitoring record(s) older than %d days.',
            $count,
            JobRunsTable::RETENTION_DAYS
        ));

        return true;
    }
}
