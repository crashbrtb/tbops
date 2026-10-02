<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Entity\JobRun;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * Writes the heartbeat of the site's own jobs into `job_runs`.
 *
 * The monitoring must never become the reason a job fails: a heartbeat that
 * cannot be written is logged and the job carries on. A job that stops writing
 * its heartbeat is caught anyway, by the health check noticing the silence.
 */
class JobRunRecorder
{
    use LocatorAwareTrait;

    public const JOB_COLLECTOR = 'collector';
    public const JOB_DAILY_MAINTENANCE = 'daily_maintenance';
    public const JOB_DATABASE_BACKUP = 'database_backup';
    public const JOB_TOURNAMENT_IMPORT = 'tournament_import';
    /** The EventUploader's automatic walk through the Journal (scheduled task). */
    public const JOB_TOURNAMENT_SEARCH = 'tournament_search';

    /**
     * Mark a job as started.
     *
     * @param string $job Job key.
     * @param string|null $host Where it runs; the server's name when omitted.
     * @return \App\Model\Entity\JobRun|null The open run, or null when it could not be written.
     */
    public function start(string $job, ?string $host = null): ?JobRun
    {
        try {
            $runs = $this->fetchTable('JobRuns');
            $run = $runs->newEntity([
                'job' => $job,
                'status' => JobRun::STATUS_RUNNING,
                'host' => $this->host($host),
                'started_at' => DateTime::now(),
            ]);

            return $runs->save($run) ?: null;
        } catch (Throwable $e) {
            Log::warning(sprintf('Could not record the start of job "%s": %s', $job, $e->getMessage()));

            return null;
        }
    }

    /**
     * Close a run.
     *
     * @param \App\Model\Entity\JobRun|null $run What start() returned.
     * @param string $status One of the JobRun statuses.
     * @param array<string, mixed> $summary What to remember about the run.
     * @return void
     */
    public function finish(?JobRun $run, string $status, array $summary = []): void
    {
        if ($run === null) {
            return;
        }

        try {
            $run->set([
                'status' => $status,
                'finished_at' => DateTime::now(),
                'summary' => $summary ?: null,
            ]);
            $this->fetchTable('JobRuns')->save($run);
        } catch (Throwable $e) {
            Log::warning(sprintf('Could not record the end of job "%s": %s', $run->job, $e->getMessage()));
        }
    }

    /**
     * Record a run that is over as soon as it starts, such as an upload.
     *
     * @param string $job Job key.
     * @param string $status One of the JobRun statuses.
     * @param array<string, mixed> $summary What to remember about the run.
     * @param string|null $host Where it came from.
     * @return void
     */
    public function record(string $job, string $status, array $summary = [], ?string $host = null): void
    {
        $this->finish($this->start($job, $host), $status, $summary);
    }

    /**
     * @param string|null $host Given host.
     * @return string
     */
    private function host(?string $host): string
    {
        $host = trim((string)($host ?? gethostname()));

        return mb_substr($host !== '' ? $host : 'unknown', 0, 100);
    }
}
