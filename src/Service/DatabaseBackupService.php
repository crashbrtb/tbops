<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Maintenance\BackupException;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\LocatorAwareTrait;
use Throwable;

/**
 * The nightly dump of the application's database.
 *
 * This is the `backup_database.sh` script that used to sit in the project root,
 * moved inside the application so that the schedule, the retention period and
 * the folder it writes to are administrator settings rather than variables
 * somebody has to edit on the server. The method is deliberately the same one
 * the script used, because it is the method whose output administrators already
 * know how to restore from:
 *
 *  - `mysqldump` of the single application database,
 *  - written as `backup_<clan>_<database>_<Y-m-d_H-i-s>.sql` (with the clan
 *    acronym when configured, to avoid collisions when multiple sites share
 *    the backup folder), then gzipped,
 *  - every step appended to `backup.log` in the same folder,
 *  - dumps older than the retention period deleted afterwards,
 *  - a dump that failed or came out empty deleted rather than kept, because a
 *    truncated backup that looks like a backup is worse than a missing one.
 *
 * The credentials come from the connection the application itself uses, so
 * there is nothing to keep in step by hand, and they are handed to `mysqldump`
 * through a temporary options file: a password on the command line is readable
 * by anyone who can run `ps` on the server.
 */
class DatabaseBackupService
{
    use LocatorAwareTrait;

    /**
     * `config` row holding whether the nightly backup runs at all.
     */
    public const PARAM_ENABLED = 'database_backup_enabled';

    /**
     * `config` row holding the folder dumps are written to.
     */
    public const PARAM_DIR = 'database_backup_dir';

    /**
     * `config` row holding how many days of dumps are kept.
     */
    public const PARAM_RETENTION = 'database_backup_retention_days';

    /**
     * `config` row holding the clan acronym.
     */
    public const PARAM_CLAN_ACRONYM = 'clan_acronym';

    /**
     * Where dumps go when nobody has said otherwise.
     *
     * Under the home directory of the user the site runs as, which is the one
     * account that certainly exists and is certainly writable.
     */
    public const DEFAULT_DIR = '~/bkpdb';

    /**
     * The week of dumps the old script kept.
     */
    public const DEFAULT_RETENTION_DAYS = 7;

    /**
     * A day of retention is the least that means anything: below it the dump
     * taken this morning would be deleted by the one taken tomorrow.
     */
    public const MIN_RETENTION_DAYS = 1;

    /**
     * A year. More than this is a disk-space accident, not a retention policy.
     */
    public const MAX_RETENTION_DAYS = 365;

    /**
     * When the backup runs, in UTC.
     *
     * Fixed rather than chosen: 17:00 UTC is the game's daily reset, so a dump
     * taken then holds a whole cycle day exactly as the game closed it, and an
     * administrator restoring it never has to reason about half a day of
     * collected chests. Making it adjustable would only offer a worse time.
     */
    public const UTC_TIME = '17:00';

    /**
     * The Cake command cron has to call.
     */
    public const COMMAND = 'database_backup';

    /**
     * The log kept beside the dumps.
     */
    public const LOG_NAME = 'backup.log';

    /**
     * The names the dump tool goes by, in the order they are looked for.
     */
    public const DUMP_BINARIES = ['mysqldump', 'mariadb-dump'];

    /**
     * Whether the nightly backup is turned on.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->truthy($this->read(self::PARAM_ENABLED, '0'));
    }

    /**
     * The folder as an administrator wrote it, `~` and all.
     *
     * @return string
     */
    public function directory(): string
    {
        $value = trim($this->read(self::PARAM_DIR, self::DEFAULT_DIR));

        return $value === '' ? self::DEFAULT_DIR : $value;
    }

    /**
     * The folder dumps are actually written to.
     *
     * @return string
     */
    public function resolvedDirectory(): string
    {
        return $this->expand($this->directory());
    }

    /**
     * How many days of dumps are kept.
     *
     * @return int
     */
    public function retentionDays(): int
    {
        $value = trim($this->read(self::PARAM_RETENTION, (string)self::DEFAULT_RETENTION_DAYS));

        if (!is_numeric($value)) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        $days = (int)$value;

        return $days >= self::MIN_RETENTION_DAYS && $days <= self::MAX_RETENTION_DAYS
            ? $days
            : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * The log the dumps are described in, beside the dumps themselves.
     *
     * @return string
     */
    public function logPath(): string
    {
        return $this->resolvedDirectory() . DIRECTORY_SEPARATOR . self::LOG_NAME;
    }

    /**
     * The name of the database that gets dumped.
     *
     * @return string
     */
    public function databaseName(): string
    {
        return (string)($this->connectionConfig()['database'] ?? '');
    }

    /**
     * The clan acronym from the configuration.
     *
     * @return string
     */
    public function clanAcronym(): string
    {
        return trim($this->read(self::PARAM_CLAN_ACRONYM, ''));
    }

    /**
     * What every dump's filename starts with.
     *
     * The clan acronym (when configured) and the database name go into the
     * prefix so that multiple instances on the same server sharing the backup
     * directory do not collide.
     *
     * Characters that are unsafe for filenames or glob matching are sanitized.
     *
     * @return string
     */
    public function filePrefix(): string
    {
        $parts = ['backup'];

        $acronym = (string)preg_replace('/[^A-Za-z0-9_.-]+/', '_', $this->clanAcronym());
        $acronym = trim($acronym, '_');
        if ($acronym !== '') {
            $parts[] = $acronym;
        }

        $dbName = (string)preg_replace('/[^A-Za-z0-9_.-]+/', '_', $this->databaseName());
        $dbName = trim($dbName, '_');
        if ($dbName !== '' && strcasecmp($dbName, $acronym) !== 0) {
            $parts[] = $dbName;
        }

        return implode('_', $parts) . '_';
    }

    /**
     * Everything the maintenance page needs to describe the backup.
     *
     * Never throws: it is the page that explains what is wrong, so it has to
     * render even when the folder is the thing that has gone wrong.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $resolved = $this->resolvedDirectory();
        $backups = $this->backups();
        $bytes = 0;
        foreach ($backups as $backup) {
            $bytes += $backup['bytes'];
        }

        $log = $resolved . DIRECTORY_SEPARATOR . self::LOG_NAME;

        return [
            'enabled' => $this->isEnabled(),
            'directory' => $this->directory(),
            'resolved' => $resolved,
            'retentionDays' => $this->retentionDays(),
            'utcTime' => self::UTC_TIME,
            'database' => $this->databaseName(),
            'clanAcronym' => $this->clanAcronym(),
            'filePrefix' => $this->filePrefix(),
            'exists' => is_dir($resolved),
            'writable' => is_dir($resolved) && is_writable($resolved),
            'backups' => $backups,
            'count' => count($backups),
            'bytes' => $bytes,
            'latest' => $backups[0]['mtime'] ?? null,
            'logPath' => $log,
            'logWritten' => is_file($log) ? filemtime($log) : null,
            'dumpBinary' => $this->findDumpBinary(),
            'shellReason' => $this->shellUnavailableReason(),
            'isMysql' => $this->isMysql(),
        ];
    }

    /**
     * The dumps currently in the folder, newest first.
     *
     * Only files this service would have written are listed, so a folder shared
     * with somebody else's backups is reported — and pruned — as just ours.
     *
     * @return array<int, array{path: string, name: string, bytes: int, mtime: int}>
     */
    public function backups(): array
    {
        if ($this->databaseName() === '') {
            return [];
        }

        $found = glob(
            $this->resolvedDirectory() . DIRECTORY_SEPARATOR . $this->filePrefix() . '*.sql.gz'
        );
        if ($found === false) {
            return [];
        }

        $backups = [];
        foreach ($found as $path) {
            if (!is_file($path)) {
                continue;
            }
            $backups[] = [
                'path' => $path,
                'name' => basename($path),
                'bytes' => (int)filesize($path),
                'mtime' => (int)filemtime($path),
            ];
        }

        usort($backups, fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return $backups;
    }

    /**
     * Store the backup settings coming off the maintenance form.
     *
     * @param array<string, mixed> $data Form data: `enabled`, `directory`, `retention_days`.
     * @return array<string> Things worth saying out loud that are not reasons to
     *   refuse the settings — chiefly a folder that could not be created from the
     *   web server, which cron may still manage.
     * @throws \App\Service\Maintenance\BackupException When a setting is not usable.
     */
    public function save(array $data): array
    {
        $enabled = $this->truthy($data['enabled'] ?? false);
        $directory = $this->normalizeDirectory((string)($data['directory'] ?? ''));
        $retention = $this->normalizeRetention($data['retention_days'] ?? null);

        $warnings = [];
        if ($enabled) {
            try {
                $this->ensureDirectory($this->expand($directory));
            } catch (BackupException $e) {
                // Not fatal: the web server and cron run as the same user on most
                // installs, but open_basedir and mounted home directories make
                // "PHP cannot write there" and "the backup cannot write there"
                // different claims. Saying so beats refusing a setting that works.
                $warnings[] = $e->getMessage();
            }
        }

        $this->write(
            self::PARAM_ENABLED,
            $enabled ? '1' : '0',
            'Whether the nightly database backup runs. Managed under Admin > Maintenance.'
        );
        $this->write(
            self::PARAM_DIR,
            $directory,
            'Folder the nightly database dumps are written to. "~" is the home directory of the '
                . 'user the site runs as. Managed under Admin > Maintenance.'
        );
        $this->write(
            self::PARAM_RETENTION,
            (string)$retention,
            'How many days of database dumps to keep. Older dumps are deleted after each backup. '
                . 'Managed under Admin > Maintenance.'
        );

        return $warnings;
    }

    /**
     * Take a backup: dump, compress, then delete what has aged out.
     *
     * @param callable|null $report Called with each line of progress.
     * @param bool $dryRun Report what would happen without writing or deleting.
     * @return array{file: string|null, bytes: int, removed: array<string>, kept: int}
     * @throws \App\Service\Maintenance\BackupException When the backup cannot be taken.
     */
    public function run(?callable $report = null, bool $dryRun = false): array
    {
        $database = $this->databaseName();
        if ($database === '') {
            throw new BackupException(__('The database connection does not name a database to back up.'));
        }

        if (!$this->isMysql()) {
            // mysqldump is the only tool this service knows, so a connection it
            // cannot dump has to say so rather than write a file that looks like
            // a backup and restores nothing.
            throw new BackupException(
                __('The application is not on MySQL or MariaDB, so mysqldump cannot back it up.')
            );
        }

        $reason = $this->shellUnavailableReason();
        if ($reason !== null) {
            throw new BackupException($reason);
        }

        $directory = $this->resolvedDirectory();
        $retention = $this->retentionDays();

        if (!$dryRun) {
            $this->ensureDirectory($directory);
        }

        $acronym = $this->clanAcronym();
        $log = $this->logger($directory, $report, $dryRun);
        $log('==========================================');
        $log(sprintf(
            'Starting database backup: %s%s',
            $database,
            $acronym !== '' ? sprintf(' (clan: %s)', $acronym) : ''
        ));

        $binary = $this->findDumpBinary();
        if ($binary === null) {
            $log('Error: mysqldump was not found. Install the MySQL/MariaDB client tools.');

            throw new BackupException(__('mysqldump was not found on this server, so no backup can be taken.'));
        }

        $target = $directory . DIRECTORY_SEPARATOR . $this->filePrefix() . date('Y-m-d_H-i-s') . '.sql';

        if ($dryRun) {
            $log(sprintf('[DRY-RUN] Would write %s.gz', $target));
            foreach ($this->expired($retention) as $backup) {
                $log(sprintf('[DRY-RUN] Would remove: %s', $backup['name']));
            }
            $kept = count($this->backups());
            $log(sprintf('[DRY-RUN] Retention: %d day(s); %d dump(s) in the folder now.', $retention, $kept));

            return ['file' => null, 'bytes' => 0, 'removed' => [], 'kept' => $kept];
        }

        $log('Running mysqldump...');
        $this->dump($binary, $database, $target, $log);

        $log('Compressing backup...');
        $file = $this->compress($target);
        $bytes = (int)filesize($file);
        $log(sprintf('Backup created successfully: %s (%s)', $file, $this->humanBytes($bytes)));

        $log(sprintf('Cleaning up dumps older than %d day(s)...', $retention));
        $removed = $this->prune($retention, $log);
        $log(
            $removed === []
                ? 'No old backups found to remove'
                : sprintf('Removed %d old backup(s)', count($removed))
        );

        $kept = count($this->backups());
        $log(sprintf('Total backups kept: %d', $kept));
        $log('Backup completed successfully!');
        $log('==========================================');

        return ['file' => $file, 'bytes' => $bytes, 'removed' => $removed, 'kept' => $kept];
    }

    /**
     * Delete the dumps that have aged out of the retention period.
     *
     * @param int|null $retentionDays Days to keep; the configured period by default.
     * @param callable|null $log Called with each deletion.
     * @return array<string> The names of the files deleted.
     */
    public function prune(?int $retentionDays = null, ?callable $log = null): array
    {
        $removed = [];

        foreach ($this->expired($retentionDays ?? $this->retentionDays()) as $backup) {
            if (!@unlink($backup['path'])) {
                continue;
            }
            $removed[] = $backup['name'];
            if ($log !== null) {
                $log(sprintf('Removed: %s', $backup['name']));
            }
        }

        return $removed;
    }

    /**
     * Whether the application's connection is one `mysqldump` can dump.
     *
     * @return bool
     */
    public function isMysql(): bool
    {
        $driver = $this->connectionConfig()['driver'] ?? null;
        if (is_object($driver)) {
            $driver = $driver::class;
        }

        return is_string($driver) && str_contains($driver, 'Mysql');
    }

    /**
     * Why a backup cannot be taken from this process, if it cannot.
     *
     * @return string|null Null when `mysqldump` can be called.
     */
    public function shellUnavailableReason(): ?string
    {
        if (!function_exists('exec')) {
            return __('PHP on this server cannot run shell commands, so mysqldump cannot be called.');
        }

        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return __('PHP on this server has exec() disabled, so mysqldump cannot be called.');
        }

        return null;
    }

    /**
     * Where `mysqldump` lives, if it is installed.
     *
     * MariaDB 11 renamed its copy `mariadb-dump` and some packages ship only
     * that name, so both are looked for.
     *
     * @return string|null
     */
    public function findDumpBinary(): ?string
    {
        if ($this->shellUnavailableReason() !== null) {
            return null;
        }

        $windows = DIRECTORY_SEPARATOR === '\\';

        foreach (self::DUMP_BINARIES as $name) {
            $output = [];
            $code = 0;
            @exec(($windows ? 'where ' : 'command -v ') . $name . ' 2>&1', $output, $code);
            $found = trim((string)($output[0] ?? ''));

            if ($code === 0 && $found !== '' && ($windows ? is_file($found) : $found[0] === '/')) {
                return $found;
            }
        }

        // A dump tool outside the PATH is common enough — on managed hosting, and
        // on the Windows machines the site is developed on, where nothing puts
        // the database's bin folder there — that the usual places are worth a
        // look before giving up on the whole feature.
        foreach ($this->binaryCandidates($windows) as $pattern) {
            foreach ((array)glob($pattern) as $path) {
                if (is_file((string)$path)) {
                    return (string)$path;
                }
            }
        }

        return null;
    }

    /**
     * Turn `~` into the home directory of the user the site runs as.
     *
     * @param string $path The configured path.
     * @return string An absolute path, where one can be worked out.
     */
    public function expand(string $path): string
    {
        $path = trim($path);
        if ($path !== '~' && !str_starts_with($path, '~/') && !str_starts_with($path, '~\\')) {
            return $path;
        }

        $home = $this->homeDirectory();
        if ($home === null) {
            return $path;
        }

        return $path === '~' ? $home : $home . DIRECTORY_SEPARATOR . substr($path, 2);
    }

    /**
     * Validate and tidy a folder coming off the form.
     *
     * @param string $raw The folder as submitted.
     * @return string
     * @throws \App\Service\Maintenance\BackupException When it is not a usable folder.
     */
    public function normalizeDirectory(string $raw): string
    {
        $value = trim($raw);

        if ($value === '') {
            return self::DEFAULT_DIR;
        }

        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new BackupException(__('The backup folder cannot contain line breaks.'));
        }

        $absolute = str_starts_with($value, '/')
            || str_starts_with($value, '~')
            // Windows, where the site only ever runs for development.
            || preg_match('#^[A-Za-z]:[\\\\/]#', $value) === 1;

        if (!$absolute) {
            throw new BackupException(__(
                'The backup folder has to be a full path, such as {0}, because cron does not start in the '
                    . 'site directory.',
                self::DEFAULT_DIR
            ));
        }

        // `config.value` holds 255 characters; a path that does not fit would be
        // truncated into a different folder by the database rather than refused.
        if (strlen($value) > 255) {
            throw new BackupException(__('The backup folder path is too long (255 characters at most).'));
        }

        $trimmed = rtrim($value, '/\\');

        // Everything but the root itself, which is all separator.
        return $trimmed === '' ? $value : $trimmed;
    }

    /**
     * Validate a retention period coming off the form.
     *
     * @param mixed $raw The number of days as submitted.
     * @return int
     * @throws \App\Service\Maintenance\BackupException When it is not a usable period.
     */
    public function normalizeRetention(mixed $raw): int
    {
        if (is_string($raw)) {
            $raw = trim($raw);
        }

        if ($raw === null || $raw === '') {
            return self::DEFAULT_RETENTION_DAYS;
        }

        if (!is_numeric($raw) || (float)$raw !== (float)(int)$raw) {
            throw new BackupException(__('The retention period has to be a whole number of days.'));
        }

        $days = (int)$raw;

        if ($days < self::MIN_RETENTION_DAYS || $days > self::MAX_RETENTION_DAYS) {
            throw new BackupException(__(
                'The retention period has to be between {0} and {1} days.',
                self::MIN_RETENTION_DAYS,
                self::MAX_RETENTION_DAYS
            ));
        }

        return $days;
    }

    /**
     * A size as a person would write it.
     *
     * @param int $bytes The size.
     * @return string
     */
    public function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $largest = count($units) - 1;
        $size = (float)$bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < $largest) {
            $size /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%d %s' : '%.1f %s', $size, $units[$unit]);
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Places a dump tool sits when it is not in the PATH, as glob patterns.
     *
     * @param bool $windows Whether this is a Windows machine.
     * @return array<string>
     */
    protected function binaryCandidates(bool $windows): array
    {
        if ($windows) {
            return [
                'C:\\Program Files\\MariaDB*\\bin\\mysqldump.exe',
                'C:\\Program Files\\MySQL\\MySQL Server*\\bin\\mysqldump.exe',
                'C:\\Program Files\\MariaDB*\\bin\\mariadb-dump.exe',
                'C:\\xampp\\mysql\\bin\\mysqldump.exe',
                'C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe',
            ];
        }

        $candidates = [];
        foreach (['/usr/bin', '/usr/local/bin', '/usr/local/mysql/bin', '/opt/homebrew/bin'] as $folder) {
            foreach (self::DUMP_BINARIES as $name) {
                $candidates[] = $folder . '/' . $name;
            }
        }

        return $candidates;
    }

    /**
     * The dumps past the retention period.
     *
     * @param int $retentionDays Days to keep.
     * @return array<int, array{path: string, name: string, bytes: int, mtime: int}>
     */
    protected function expired(int $retentionDays): array
    {
        $cutoff = time() - $retentionDays * 86400;

        return array_values(array_filter(
            $this->backups(),
            fn (array $backup): bool => $backup['mtime'] < $cutoff
        ));
    }

    /**
     * Run `mysqldump` into a file, and leave nothing behind if it fails.
     *
     * @param string $binary Path to mysqldump.
     * @param string $database The database to dump.
     * @param string $target The `.sql` file to write.
     * @param callable $log Progress reporter.
     * @return void
     * @throws \App\Service\Maintenance\BackupException When the dump fails or comes out empty.
     */
    protected function dump(string $binary, string $database, string $target, callable $log): void
    {
        $config = $this->connectionConfig();
        $defaults = $this->writeDefaultsFile($config);

        $flags = [
            // A consistent snapshot that does not lock the site out of its own
            // tables, which matters because this runs while the site is up.
            '--single-transaction',
            '--quick',
            '--default-character-set=' . ($config['encoding'] ?? 'utf8mb4'),
        ];

        $output = [];
        $code = 0;

        try {
            // --defaults-extra-file has to come first, before any other option.
            $command = sprintf(
                '%s --defaults-extra-file=%s %s %s > %s',
                self::escapeArg($binary),
                self::escapeArg($defaults),
                implode(' ', $flags),
                self::escapeArg($database),
                self::escapeArg($target)
            );

            @exec($command . ' 2>&1', $output, $code);
        } finally {
            @unlink($defaults);
        }

        if ($code !== 0) {
            $said = trim(implode("\n", $output));
            $log('Error executing mysqldump!');
            foreach (explode("\n", $said) as $line) {
                if (trim($line) !== '') {
                    $log('  ' . trim($line));
                }
            }
            @unlink($target);

            throw new BackupException(__(
                'mysqldump failed: {0}',
                $said !== '' ? $said : __('it exited with code {0}.', $code)
            ));
        }

        if (!is_file($target) || filesize($target) === 0) {
            @unlink($target);
            $log('Error: the dump file was not created or is empty!');

            throw new BackupException(__('The dump file was not created or came out empty, so it was deleted.'));
        }
    }

    /**
     * Gzip a finished dump, and delete the uncompressed copy.
     *
     * PHP's zlib is used when it is there and the `gzip` command otherwise, so
     * the result is the same `.sql.gz` the old script produced either way.
     *
     * @param string $file The `.sql` file.
     * @return string The `.sql.gz` file.
     * @throws \App\Service\Maintenance\BackupException When it cannot be compressed.
     */
    protected function compress(string $file): string
    {
        $target = $file . '.gz';

        if (function_exists('gzopen')) {
            $in = @fopen($file, 'rb');
            $out = $in === false ? false : @gzopen($target, 'wb6');

            if ($in !== false && $out !== false) {
                while (!feof($in)) {
                    $chunk = fread($in, 1048576);
                    if ($chunk === false || ($chunk !== '' && gzwrite($out, $chunk) === false)) {
                        fclose($in);
                        gzclose($out);
                        @unlink($target);

                        throw new BackupException(__('The dump was written but could not be compressed.'));
                    }
                }
                fclose($in);
                gzclose($out);
                @unlink($file);

                return $target;
            }

            if ($in !== false) {
                fclose($in);
            }
        }

        $output = [];
        $code = 0;
        @exec('gzip ' . self::escapeArg($file) . ' 2>&1', $output, $code);

        if ($code !== 0 || !is_file($target)) {
            throw new BackupException(__(
                'The dump was written but could not be compressed: {0}',
                trim(implode("\n", $output))
            ));
        }

        return $target;
    }

    /**
     * A `mysqldump` options file holding the credentials.
     *
     * The password goes in a file readable only by this user rather than on the
     * command line, where `ps` would show it to everyone logged in.
     *
     * @param array<string, mixed> $config The connection configuration.
     * @return string Path to the temporary file.
     * @throws \App\Service\Maintenance\BackupException When it cannot be written.
     */
    protected function writeDefaultsFile(array $config): string
    {
        $file = tempnam(sys_get_temp_dir(), 'tbops_dump_');
        if ($file === false) {
            throw new BackupException(__('A temporary file for the database credentials could not be created.'));
        }

        @chmod($file, 0600);

        $lines = ['[client]'];
        $names = ['host' => 'host', 'port' => 'port', 'username' => 'user', 'password' => 'password'];

        foreach ($names as $key => $name) {
            $value = $config[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $lines[] = sprintf('%s="%s"', $name, addcslashes((string)$value, '\\"'));
        }

        if (!empty($config['unix_socket'])) {
            $lines[] = sprintf('socket="%s"', addcslashes((string)$config['unix_socket'], '\\"'));
        }

        file_put_contents($file, implode("\n", $lines) . "\n");

        return $file;
    }

    /**
     * A reporter that writes each line to `backup.log` and to the caller.
     *
     * @param string $directory The backup folder.
     * @param callable|null $report The caller's reporter, if any.
     * @param bool $dryRun Whether to leave the log alone.
     * @return callable
     */
    protected function logger(string $directory, ?callable $report, bool $dryRun): callable
    {
        $path = $directory . DIRECTORY_SEPARATOR . self::LOG_NAME;

        return function (string $line) use ($path, $report, $dryRun): void {
            if (!$dryRun) {
                @file_put_contents(
                    $path,
                    sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $line, PHP_EOL),
                    FILE_APPEND
                );
            }
            if ($report !== null) {
                $report($line);
            }
        };
    }

    /**
     * Make sure the backup folder is there and can be written to.
     *
     * @param string $directory The folder.
     * @return void
     * @throws \App\Service\Maintenance\BackupException When it cannot be used.
     */
    protected function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new BackupException(__('The backup folder {0} could not be created.', $directory));
        }

        if (!is_writable($directory)) {
            throw new BackupException(__('The backup folder {0} cannot be written to.', $directory));
        }
    }

    /**
     * The home directory of the user this process runs as.
     *
     * @return string|null
     */
    protected function homeDirectory(): ?string
    {
        $home = getenv('HOME');

        // Cron passes HOME, but PHP-FPM pools often do not, so the password
        // database is asked before giving up: that is what `~` means here.
        if (
            (!is_string($home) || $home === '')
            && function_exists('posix_getpwuid')
            && function_exists('posix_geteuid')
        ) {
            $entry = @posix_getpwuid(posix_geteuid());
            $home = is_array($entry) ? (string)($entry['dir'] ?? '') : '';
        }

        if (!is_string($home) || $home === '') {
            $home = getenv('USERPROFILE');
        }

        return is_string($home) && $home !== '' ? rtrim($home, '/\\') : null;
    }

    /**
     * The configuration of the connection the application uses.
     *
     * @return array<string, mixed>
     */
    protected function connectionConfig(): array
    {
        try {
            return ConnectionManager::get('default')->config();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * A `config` row's value.
     *
     * @param string $param The parameter name.
     * @param string $default What to answer when it is not there.
     * @return string
     */
    protected function read(string $param, string $default): string
    {
        try {
            $row = $this->fetchTable('Config')->find()->where(['param' => $param])->first();
        } catch (Throwable $e) {
            return $default;
        }

        $value = $row->value ?? null;

        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    /**
     * Store a `config` row, creating it when it is not there yet.
     *
     * @param string $param The parameter name.
     * @param string $value The value.
     * @param string $description What the row is for, for the raw config list.
     * @return void
     */
    protected function write(string $param, string $value, string $description): void
    {
        $table = $this->fetchTable('Config');
        $row = $table->find()->where(['param' => $param])->first();

        if ($row === null) {
            $row = $table->newEmptyEntity();
            $row->set('param', $param);
        }

        $row->set('value', $value);
        $row->set('description', $description);
        $table->saveOrFail($row);
    }

    /**
     * Whether a stored flag or a checkbox says yes.
     *
     * @param mixed $value The value.
     * @return bool
     */
    protected function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Escape an argument for safe use in a shell command line.
     *
     * Fallback when escapeshellarg() is disabled in php.ini.
     *
     * @param string $arg The argument to escape.
     * @return string
     */
    public static function escapeArg(string $arg): string
    {
        return MaintenanceScheduleService::escapeArg($arg);
    }
}
