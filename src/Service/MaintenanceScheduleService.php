<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Maintenance\CrontabException;
use Cake\ORM\Locator\LocatorAwareTrait;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The `daily_maintenance` command's place in the server's crontab.
 *
 * Nothing inside the application runs the daily maintenance: cycle summaries are
 * only archived, and old chests only purged, because cron calls the command. That
 * makes a missing crontab entry an invisible failure — the site looks fine for
 * weeks and then an event's history turns out empty because the chests it was
 * computed from were never summarised. So this service exists to answer one
 * question out loud ("is it scheduled?") and to write the entry when it is not.
 *
 * All times are chosen and stored directly in UTC. The block carries `CRON_TZ=UTC`
 * so an entry means the same moment whatever timezone the server keeps; if the
 * cron implementation refuses that variable, installing falls back to the server's
 * own local time, which needs its offset but no special syntax.
 *
 * The nightly database backup lives in the same block, because it is the same
 * promise to the same administrator — something has to be running on a schedule
 * or it is not happening. Its time is not theirs to pick: it is pinned to the
 * game's 17:00 UTC reset. See \App\Service\DatabaseBackupService.
 */
class MaintenanceScheduleService
{
    use LocatorAwareTrait;

    /**
     * `config` row holding the chosen times, as "HH:MM,HH:MM" in UTC.
     */
    public const PARAM = 'maintenance_schedule';

    /**
     * The clock an administrator picks times on (always UTC).
     */
    public const SCHEDULE_TZ = 'UTC';

    /**
     * Twice a day, spaced across the day and aligned with the game cycle.
     */
    public const DEFAULT_TIMES = ['05:15', '17:15'];

    /**
     * Run counts the picker offers: each one divides the day evenly.
     */
    public const RUN_CHOICES = [1, 2, 3, 4, 6];

    /**
     * More than this a day is a mistake, not a schedule.
     */
    public const MAX_RUNS = 6;

    /**
     * The Cake command cron has to call.
     */
    public const COMMAND = 'daily_maintenance';

    /**
     * The commands this page owns lines for, the backup included.
     */
    public const COMMANDS = [self::COMMAND, DatabaseBackupService::COMMAND];

    /**
     * Fences marking the lines this page owns. Everything between them is
     * rewritten or removed wholesale; everything outside is never touched.
     */
    public const BEGIN = '# >>> tbops daily_maintenance (managed from Admin > Maintenance) >>>';

    /**
     * @see self::BEGIN
     */
    public const END = '# <<< tbops daily_maintenance <<<';

    /**
     * Fences written before the rename to TBOps, still recognised so the next
     * save replaces that block instead of leaving it running beside a new one.
     */
    public const LEGACY_BEGIN = '# >>> chestcounter daily_maintenance (managed from Admin > Maintenance) >>>';

    /**
     * @see self::LEGACY_BEGIN
     */
    public const LEGACY_END = '# <<< chestcounter daily_maintenance <<<';

    /**
     * The backup, so its setting can decide whether the block carries its line.
     *
     * @var \App\Service\DatabaseBackupService
     */
    protected DatabaseBackupService $backup;

    /**
     * Constructor.
     *
     * @param \App\Service\DatabaseBackupService|null $backup The backup settings.
     */
    public function __construct(?DatabaseBackupService $backup = null)
    {
        $this->backup = $backup ?? new DatabaseBackupService();
    }

    /**
     * Where cron output is appended, and so also the evidence that cron ran.
     *
     * @return string
     */
    public function logPath(): string
    {
        return rtrim(ROOT, '/\\') . '/logs/maintenance_cron.log';
    }

    /**
     * Where the backup's cron output is appended.
     *
     * Separate from the maintenance log so that "when did the backup last run?"
     * and "when did the maintenance last run?" are two answerable questions. The
     * backup also keeps its own log beside the dumps themselves; this one only
     * catches what cron itself has to say.
     *
     * @return string
     */
    public function backupLogPath(): string
    {
        return rtrim(ROOT, '/\\') . '/logs/database_backup_cron.log';
    }

    /**
     * Everything the maintenance page needs to describe the current situation.
     *
     * Never throws: this is the page that explains what is wrong, so it has to
     * render even when reading the crontab is itself the thing that failed.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $unavailable = $this->unavailableReason();
        $error = null;
        $entries = [];
        $maintenanceEntries = [];
        $backupEntries = [];
        $unmanaged = [];
        $installedTimes = [];

        if ($unavailable === null) {
            try {
                $lines = $this->readCrontab();
                $block = $this->managedBlock($lines);
                $entries = $block['entries'];
                $maintenanceEntries = $block['maintenance'];
                $backupEntries = $block['backup'];
                $installedTimes = $this->timesFromEntries($block['maintenance'], $block['timezone']);
                $unmanaged = $this->unmanagedEntries($lines);
            } catch (CrontabException $e) {
                $error = $e->getMessage();
            }
        }

        $installed = $maintenanceEntries !== [];
        $log = $this->logPath();
        $backupLog = $this->backupLogPath();

        return [
            'installed' => $installed,
            'times' => $installed && $installedTimes !== [] ? $installedTimes : $this->savedTimes(),
            'installedTimes' => $installedTimes,
            'entries' => $entries,
            'maintenanceEntries' => $maintenanceEntries,
            'unmanaged' => $unmanaged,
            'unavailable' => $unavailable,
            'error' => $error,
            'offsetMinutes' => $this->scheduleOffsetMinutes(),
            'serverOffsetMinutes' => $this->serverOffsetMinutes(),
            'logPath' => $log,
            'lastRun' => is_file($log) ? filemtime($log) : null,
            // The backup's own line, which the block carries only while the
            // backup is turned on.
            'backupInstalled' => $backupEntries !== [],
            'backupEntries' => $backupEntries,
            'backupEnabled' => $this->backup->isEnabled(),
            'backupLogPath' => $backupLog,
            'backupLastRun' => is_file($backupLog) ? filemtime($backupLog) : null,
        ];
    }

    /**
     * Write the managed block into the crontab, replacing any earlier one.
     *
     * @param array<mixed> $rawTimes Times in UTC, as "HH:MM" strings.
     * @return array<string> The times actually installed, normalised.
     * @throws \App\Service\Maintenance\CrontabException When the crontab cannot be written.
     */
    public function install(array $rawTimes): array
    {
        $times = $this->normalizeTimes($rawTimes);

        $unavailable = $this->unavailableReason();
        if ($unavailable !== null) {
            throw new CrontabException($unavailable);
        }

        $kept = $this->withoutManagedBlock($this->readCrontab());

        try {
            $this->writeCrontab(array_merge($kept, $this->blockLines($times)));
        } catch (CrontabException $e) {
            // Some cron implementations (notably BusyBox's) reject variable
            // assignments, CRON_TZ included. Falling back to the server's own
            // clock keeps the same moments without the extra syntax.
            $offset = $this->serverOffsetMinutes();
            if ($offset === null) {
                throw $e;
            }
            $this->writeCrontab(array_merge($kept, $this->blockLines($times, $offset)));
        }

        // Written is not installed: an entry cron refuses to parse can be
        // dropped silently, so the claim on screen has to come from a re-read.
        if ($this->managedBlock($this->readCrontab())['maintenance'] === []) {
            throw new CrontabException(
                __('The crontab was saved but the maintenance entries are not in it. Please add them by hand.')
            );
        }

        $this->saveTimes($times);

        return $times;
    }

    /**
     * Take the managed block back out of the crontab.
     *
     * @return void
     * @throws \App\Service\Maintenance\CrontabException When the crontab cannot be written.
     */
    public function remove(): void
    {
        $unavailable = $this->unavailableReason();
        if ($unavailable !== null) {
            throw new CrontabException($unavailable);
        }

        $lines = $this->readCrontab();
        if ($this->managedBlock($lines)['entries'] === []) {
            return;
        }

        $this->writeCrontab($this->withoutManagedBlock($lines));
    }

    /**
     * The block as it would be installed, for someone doing it by hand.
     *
     * @param array<string> $times Times in UTC.
     * @return string
     */
    public function blockFor(array $times): string
    {
        return implode("\n", $this->blockLines($this->normalizeTimes($times)));
    }

    /**
     * Suggested times for each run count the picker offers.
     *
     * Two a day is the suggestion, and its times are the ones the clan settled on
     * rather than anything derived; the other counts spread evenly from the same
     * small-hours start.
     *
     * @return array<int, array<string>>
     */
    public function suggestions(): array
    {
        $suggestions = [];

        foreach (self::RUN_CHOICES as $count) {
            if ($count === count(self::DEFAULT_TIMES)) {
                $suggestions[$count] = self::DEFAULT_TIMES;
                continue;
            }

            $step = (int)round(24 * 60 / $count);
            $times = [];
            for ($i = 0; $i < $count; $i++) {
                $minutes = (5 * 60 + 15 + $i * $step) % (24 * 60);
                $times[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            }
            sort($times);
            $suggestions[$count] = $times;
        }

        return $suggestions;
    }

    /**
     * The times last chosen here, or the suggestion when nothing was chosen.
     *
     * @return array<string>
     */
    public function savedTimes(): array
    {
        try {
            $row = $this->fetchTable('Config')->find()->where(['param' => self::PARAM])->first();
        } catch (Throwable $e) {
            return self::DEFAULT_TIMES;
        }

        $value = $row->value ?? '';
        if (!is_string($value) || trim($value) === '') {
            return self::DEFAULT_TIMES;
        }

        try {
            return $this->normalizeTimes(explode(',', $value));
        } catch (CrontabException $e) {
            return self::DEFAULT_TIMES;
        }
    }

    /**
     * Returns the time string as UTC (identity function, since all times are UTC).
     *
     * @param string $time Time string.
     * @return string Time in UTC.
     */
    public function toUtc(string $time): string
    {
        return $time;
    }

    /**
     * Returns the time string in UTC (identity function, since all times are UTC).
     *
     * @param string $time Time in UTC.
     * @return string Time in UTC.
     */
    public function fromUtc(string $time): string
    {
        return $time;
    }

    /**
     * How far from UTC the schedule clock is, in minutes (always 0 since it is UTC).
     *
     * @return int
     */
    public function scheduleOffsetMinutes(): int
    {
        return 0;
    }

    /**
     * How far from UTC the server's own clock is, in minutes.
     *
     * This is the system clock cron reads, not PHP's `date.timezone`, so it has
     * to be asked of the shell.
     *
     * @return int|null Null when the shell cannot be used.
     */
    public function serverOffsetMinutes(): ?int
    {
        if ($this->unavailableReason() !== null) {
            return null;
        }

        $result = $this->shell('date +%z');
        $raw = trim(implode('', $result['output']));

        if ($result['code'] !== 0 || !preg_match('/^([+-])(\d{2})(\d{2})$/', $raw, $match)) {
            return null;
        }

        $minutes = (int)$match[2] * 60 + (int)$match[3];

        return $match[1] === '-' ? -$minutes : $minutes;
    }

    /**
     * Validate and tidy a set of times coming off the form.
     *
     * @param array<mixed> $raw Times as submitted.
     * @return array<string> Normalised, de-duplicated and in order.
     * @throws \App\Service\Maintenance\CrontabException When a time is not a time.
     */
    public function normalizeTimes(array $raw): array
    {
        $times = [];

        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            // Browsers send "HH:MM", but a time input with seconds enabled, or a
            // hand-typed value, can arrive as "HH:MM:SS".
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $match)) {
                throw new CrontabException(__('"{0}" is not a valid time of day.', $value));
            }
            $times[] = $match[1] . ':' . $match[2];
        }

        $times = array_values(array_unique($times));
        sort($times);

        if ($times === []) {
            throw new CrontabException(__('Choose at least one time of day for the maintenance to run.'));
        }

        if (count($times) > self::MAX_RUNS) {
            throw new CrontabException(
                __('The maintenance cannot be scheduled more than {0} times a day.', self::MAX_RUNS)
            );
        }

        return $times;
    }

    /**
     * Store the chosen times so the form comes back showing them.
     *
     * @param array<string> $times Times in Brazil time.
     * @return void
     */
    protected function saveTimes(array $times): void
    {
        $table = $this->fetchTable('Config');
        $row = $table->find()->where(['param' => self::PARAM])->first();

        if ($row === null) {
            $row = $table->newEmptyEntity();
            $row->set('param', self::PARAM);
            $row->set(
                'description',
                'Times of day (UTC) the daily maintenance runs. Managed under Admin > Maintenance.'
            );
        }

        $row->set('value', implode(',', $times));
        $table->saveOrFail($row);
    }

    /**
     * The crontab lines this page owns.
     *
     * @param array<string> $times Times in UTC.
     * @param int|null $serverOffsetMinutes When given, write the entries in the
     *   server's local time instead of declaring `CRON_TZ=UTC`.
     * @return array<string>
     */
    protected function blockLines(array $times, ?int $serverOffsetMinutes = null): array
    {
        $useCronTz = $serverOffsetMinutes === null;
        $lines = [self::BEGIN];
        $lines[] = '# Edit this from the site: Admin > Maintenance. Lines between the';
        $lines[] = '# markers are rewritten whenever the schedule is saved there.';

        if ($useCronTz) {
            $lines[] = 'CRON_TZ=UTC';
        }

        // In a crontab a bare % ends the command and starts its standard input,
        // so a path containing one has to be escaped or the entry is cut in half.
        $command = str_replace('%', '\\%', $this->commandLine());

        foreach ($times as $time) {
            $utc = $this->toUtc($time);
            $when = $useCronTz ? $utc : $this->shiftMinutes($utc, $serverOffsetMinutes);
            [$hour, $minute] = array_map('intval', explode(':', $when));

            $lines[] = sprintf(
                '# %s UTC%s',
                $utc,
                $useCronTz ? '' : sprintf(' = %s server local time', $when)
            );
            $lines[] = sprintf('%d %d * * * %s', $minute, $hour, $command);
        }

        if ($this->backup->isEnabled()) {
            $utc = DatabaseBackupService::UTC_TIME;
            $when = $useCronTz ? $utc : $this->shiftMinutes($utc, $serverOffsetMinutes);
            [$hour, $minute] = array_map('intval', explode(':', $when));
            $backupCommand = str_replace(
                '%',
                '\\%',
                $this->commandLine(DatabaseBackupService::COMMAND, $this->backupLogPath())
            );

            $lines[] = sprintf(
                '# Database backup at the %s UTC game reset%s',
                $utc,
                $useCronTz ? '' : sprintf(' = %s server local time', $when)
            );
            $lines[] = sprintf('%d %d * * * %s', $minute, $hour, $backupCommand);
        }

        $lines[] = self::END;

        return $lines;
    }

    /**
     * The shell command a cron entry runs.
     *
     * @param string|null $command The Cake command; the daily maintenance by default.
     * @param string|null $logPath Where to append its output; the maintenance log by default.
     * @return string
     */
    public function commandLine(?string $command = null, ?string $logPath = null): string
    {
        return sprintf(
            'cd %s && %s bin/cake.php %s >> %s 2>&1',
            self::escapeArg(rtrim(ROOT, '/\\')),
            self::escapeArg($this->phpBinary()),
            $command ?? self::COMMAND,
            self::escapeArg($logPath ?? $this->logPath())
        );
    }

    /**
     * Escape an argument for safe use in a shell command line.
     *
     * Fallback when escapeshellarg() is disabled in php.ini (e.g. shared hosting).
     *
     * @param string $arg The argument to escape.
     * @return string
     */
    public static function escapeArg(string $arg): string
    {
        if (function_exists('escapeshellarg')) {
            $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
            if (!in_array('escapeshellarg', $disabled, true)) {
                return escapeshellarg($arg);
            }
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            return '"' . str_replace(['"', '%'], ' ', $arg) . '"';
        }

        return "'" . str_replace("'", "'\\''", $arg) . "'";
    }

    /**
     * The PHP command-line binary cron should use.
     *
     * `PHP_BINARY` is the running interpreter, which under mod_php or FPM is not
     * something cron can call, so the PATH is asked first.
     *
     * @return string
     */
    public function phpBinary(): string
    {
        if ($this->unavailableReason() === null) {
            $result = $this->shell('command -v php');
            $found = trim((string)($result['output'][0] ?? ''));
            if ($result['code'] === 0 && $found !== '' && $found[0] === '/') {
                return $found;
            }
        }

        $running = PHP_BINARY;
        if ($running !== '' && preg_match('/^php(\d.*)?$/', basename($running)) === 1) {
            return $running;
        }

        return '/usr/bin/php';
    }

    /**
     * Why this server cannot be scheduled from here, if it cannot.
     *
     * @return string|null Null when the crontab can be read and written.
     */
    public function unavailableReason(): ?string
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return __(
                'This server runs Windows, which has no crontab. Use Task Scheduler with the command shown below.'
            );
        }

        if (!function_exists('exec')) {
            return __('PHP on this server cannot run shell commands, so the crontab cannot be reached from here.');
        }

        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return __('PHP on this server has exec() disabled, so the crontab cannot be reached from here.');
        }

        if ($this->shell('command -v crontab')['code'] !== 0) {
            return __('The crontab command is not installed on this server.');
        }

        return null;
    }

    /**
     * The user's crontab, line by line.
     *
     * @return array<string>
     * @throws \App\Service\Maintenance\CrontabException When it cannot be read.
     */
    protected function readCrontab(): array
    {
        $result = $this->shell('crontab -l');

        if ($result['code'] === 0) {
            return $result['output'];
        }

        $message = trim(implode("\n", $result['output']));

        // An empty crontab is reported as a failure by most implementations.
        // That is not an error here: it is the state right before the first
        // install, and the commonest one.
        if ($message === '' || preg_match('/no crontab for/i', $message) === 1) {
            return [];
        }

        throw new CrontabException(__('The crontab could not be read: {0}', $message));
    }

    /**
     * Replace the user's crontab with these lines.
     *
     * @param array<string> $lines The whole crontab.
     * @return void
     * @throws \App\Service\Maintenance\CrontabException When it cannot be written.
     */
    protected function writeCrontab(array $lines): void
    {
        while ($lines !== [] && trim((string)end($lines)) === '') {
            array_pop($lines);
        }

        $file = tempnam(sys_get_temp_dir(), 'tbops_cron_');
        if ($file === false) {
            throw new CrontabException(__('A temporary file for the new crontab could not be created.'));
        }

        try {
            file_put_contents($file, implode("\n", $lines) . "\n");
            $result = $this->shell('crontab ' . self::escapeArg($file));
        } finally {
            @unlink($file);
        }

        if ($result['code'] !== 0) {
            $said = trim(implode("\n", $result['output']));

            throw new CrontabException(__(
                'The crontab could not be written: {0}',
                $said !== '' ? $said : __('the crontab command failed.')
            ));
        }
    }

    /**
     * The managed block's schedule lines, and the timezone they are written in.
     *
     * The lines are also split by the command they call, because the two are read
     * for different things: the maintenance entries are turned back into a list
     * of times, and reading the backup's 17:00 line as one of them would show an
     * administrator a schedule they never chose.
     *
     * @param array<string> $lines The whole crontab.
     * @return array{entries: array<string>, maintenance: array<string>, backup: array<string>, timezone: string}
     */
    protected function managedBlock(array $lines): array
    {
        $inside = false;
        $entries = [];
        $maintenance = [];
        $backup = [];
        $timezone = 'server';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (self::isBegin($trimmed)) {
                $inside = true;
                continue;
            }
            if (self::isEnd($trimmed)) {
                $inside = false;
                continue;
            }
            if (!$inside || $trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/^CRON_TZ\s*=\s*(\S+)/i', $trimmed, $match) === 1) {
                $timezone = $match[1];
                continue;
            }
            $entries[] = $trimmed;

            if (str_contains($trimmed, DatabaseBackupService::COMMAND)) {
                $backup[] = $trimmed;
            } else {
                // Anything else between the markers was put there for the
                // maintenance, including a line an older version wrote.
                $maintenance[] = $trimmed;
            }
        }

        return [
            'entries' => $entries,
            'maintenance' => $maintenance,
            'backup' => $backup,
            'timezone' => $timezone,
        ];
    }

    /**
     * Times in UTC read back out of installed cron entries.
     *
     * The crontab is the authority on what is scheduled, so the page shows what
     * is in it rather than what was last chosen here — the two differ whenever
     * someone has edited the crontab by hand.
     *
     * @param array<string> $entries Schedule lines from the managed block.
     * @param string $timezone The timezone those lines are written in.
     * @return array<string>
     */
    protected function timesFromEntries(array $entries, string $timezone): array
    {
        $offset = $timezone === 'server' ? $this->serverOffsetMinutes() : null;
        $times = [];

        foreach ($entries as $entry) {
            if (preg_match('/^(\d{1,2})\s+(\d{1,2})\s/', $entry, $match) !== 1) {
                // A stepped or listed field (`*/15`, `0,30`) is a schedule this
                // page cannot round-trip into a list of times; saying so by
                // omission beats guessing one.
                continue;
            }

            $written = sprintf('%02d:%02d', (int)$match[2], (int)$match[1]);

            if (strcasecmp($timezone, 'UTC') === 0) {
                $times[] = $written;
                continue;
            }
            if ($timezone !== 'server') {
                $times[] = $this->shiftZone($written, $timezone, 'UTC');
                continue;
            }
            if ($offset !== null) {
                $times[] = $this->shiftMinutes($written, -$offset);
            }
        }

        $times = array_values(array_unique($times));
        sort($times);

        return $times;
    }

    /**
     * Maintenance or backup entries living somewhere else in the crontab.
     *
     * Earlier versions of the install guide told administrators to add the line
     * by hand, so finding one is expected rather than alarming — but it has to be
     * pointed out, because installing from here would leave it running as well.
     *
     * @param array<string> $lines The whole crontab.
     * @return array<string>
     */
    protected function unmanagedEntries(array $lines): array
    {
        $inside = false;
        $found = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (self::isBegin($trimmed)) {
                $inside = true;
                continue;
            }
            if (self::isEnd($trimmed)) {
                $inside = false;
                continue;
            }
            if ($inside || $trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            foreach (self::COMMANDS as $command) {
                if (str_contains($trimmed, $command)) {
                    $found[] = $trimmed;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Whether a trimmed crontab line opens the managed block, old name included.
     *
     * @param string $line Trimmed crontab line.
     * @return bool
     */
    protected static function isBegin(string $line): bool
    {
        return $line === self::BEGIN || $line === self::LEGACY_BEGIN;
    }

    /**
     * Whether a trimmed crontab line closes the managed block, old name included.
     *
     * @param string $line Trimmed crontab line.
     * @return bool
     */
    protected static function isEnd(string $line): bool
    {
        return $line === self::END || $line === self::LEGACY_END;
    }

    /**
     * The crontab without the managed block.
     *
     * @param array<string> $lines The whole crontab.
     * @return array<string>
     */
    protected function withoutManagedBlock(array $lines): array
    {
        $kept = [];
        $inside = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (self::isBegin($trimmed)) {
                $inside = true;
                continue;
            }
            if (self::isEnd($trimmed)) {
                $inside = false;
                continue;
            }
            if (!$inside) {
                $kept[] = $line;
            }
        }

        while ($kept !== [] && trim((string)end($kept)) === '') {
            array_pop($kept);
        }

        return $kept;
    }

    /**
     * The same moment read in another timezone.
     *
     * @param string $time "HH:MM".
     * @param string $from Timezone the time is given in.
     * @param string $to Timezone to read it in.
     * @return string "HH:MM".
     */
    protected function shiftZone(string $time, string $from, string $to): string
    {
        try {
            $source = new DateTimeZone($from);
            $target = new DateTimeZone($to);
        } catch (Throwable $e) {
            return $time;
        }

        $today = (new DateTimeImmutable('now', $source))->format('Y-m-d');

        return (new DateTimeImmutable($today . ' ' . $time, $source))->setTimezone($target)->format('H:i');
    }

    /**
     * A time moved by a number of minutes, wrapping around midnight.
     *
     * @param string $time "HH:MM".
     * @param int $minutes Minutes to add.
     * @return string "HH:MM".
     */
    protected function shiftMinutes(string $time, int $minutes): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $total = (($hour * 60 + $minute + $minutes) % (24 * 60) + 24 * 60) % (24 * 60);

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }

    /**
     * Run a shell command, with its error output folded into its output.
     *
     * @param string $command The command.
     * @return array{code: int, output: array<string>}
     */
    protected function shell(string $command): array
    {
        $output = [];
        $code = 0;

        @exec($command . ' 2>&1', $output, $code);

        return ['code' => $code, 'output' => $output];
    }
}
