<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\DatabaseBackupService;
use App\Service\Maintenance\BackupException;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;

/**
 * App\Service\DatabaseBackupService Test Case
 *
 * Running `mysqldump` is not tested here — that needs the client tools and a
 * database to dump — so what is tested is everything either side of it: the
 * settings an administrator can get wrong, and the retention sweep, which is the
 * part that deletes files. A retention bug is the one bug in this class that
 * loses data rather than merely failing to save it, so the sweep is tested
 * against real files in a real folder.
 *
 * @uses \App\Service\DatabaseBackupService
 */
class DatabaseBackupServiceTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Config',
    ];

    /**
     * @var \App\Service\DatabaseBackupService
     */
    protected DatabaseBackupService $backup;

    /**
     * A folder of its own, so the sweep has real files to find.
     *
     * @var string
     */
    protected string $folder;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->backup = new DatabaseBackupService();
        $this->folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tbops_backup_test_' . uniqid();
        mkdir($this->folder, 0777, true);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        foreach ((array)glob($this->folder . DIRECTORY_SEPARATOR . '*') as $file) {
            @unlink((string)$file);
        }
        @rmdir($this->folder);

        parent::tearDown();
    }

    /**
     * Nothing is backed up until somebody says so, and then the defaults are the
     * old script's: a week of dumps under the home directory.
     *
     * @return void
     */
    public function testDefaultsAreOffWithTheOldScriptsFolderAndRetention(): void
    {
        $this->assertFalse($this->backup->isEnabled());
        $this->assertSame('~/bkpdb', $this->backup->directory());
        $this->assertSame(7, $this->backup->retentionDays());
        $this->assertSame('17:00', DatabaseBackupService::UTC_TIME);
    }

    /**
     * Test that the settings survive a round trip through the config table.
     *
     * @return void
     */
    public function testSettingsAreStoredAndReadBack(): void
    {
        $this->backup->save([
            'enabled' => '1',
            'directory' => $this->folder,
            'retention_days' => '14',
        ]);

        $fresh = new DatabaseBackupService();

        $this->assertTrue($fresh->isEnabled());
        $this->assertSame($this->folder, $fresh->directory());
        $this->assertSame(14, $fresh->retentionDays());

        // The rows are the ones the maintenance page and the migration name.
        foreach (
            [
                DatabaseBackupService::PARAM_ENABLED => '1',
                DatabaseBackupService::PARAM_DIR => $this->folder,
                DatabaseBackupService::PARAM_RETENTION => '14',
            ] as $param => $value
        ) {
            $row = $this->fetchTable('Config')->find()->where(['param' => $param])->firstOrFail();
            $this->assertSame($value, $row->value);
            $this->assertNotEmpty($row->description);
        }
    }

    /**
     * Turning the backup on creates the folder, so the first dump does not fail
     * at 17:00 UTC on a folder nobody made.
     *
     * @return void
     */
    public function testEnablingCreatesTheFolder(): void
    {
        $nested = $this->folder . DIRECTORY_SEPARATOR . 'nested';

        $this->assertSame([], $this->backup->save([
            'enabled' => '1',
            'directory' => $nested,
            'retention_days' => '7',
        ]));

        $this->assertDirectoryExists($nested);
        @rmdir($nested);
    }

    /**
     * A relative folder would put the dumps wherever cron happened to start, so
     * it is refused rather than resolved.
     *
     * @return void
     */
    public function testRelativeFolderIsRefused(): void
    {
        $this->expectException(BackupException::class);
        $this->backup->normalizeDirectory('backups');
    }

    /**
     * Test that a folder is tidied rather than taken literally.
     *
     * @return void
     */
    public function testFolderIsTidied(): void
    {
        $this->assertSame('/srv/bkpdb', $this->backup->normalizeDirectory('  /srv/bkpdb/  '));
        $this->assertSame('~/bkpdb', $this->backup->normalizeDirectory(''));
        $this->assertSame('/', $this->backup->normalizeDirectory('/'));
    }

    /**
     * A path the column cannot hold would be truncated into a different folder,
     * so it is refused.
     *
     * @return void
     */
    public function testAFolderTooLongForTheColumnIsRefused(): void
    {
        $this->expectException(BackupException::class);
        $this->backup->normalizeDirectory('/' . str_repeat('a', 260));
    }

    /**
     * Test that a retention period which would delete today's dump, or keep
     * dumps for ever, is refused.
     *
     * @return void
     */
    public function testRetentionIsBoundedAndWhole(): void
    {
        $this->assertSame(7, $this->backup->normalizeRetention('7'));
        $this->assertSame(7, $this->backup->normalizeRetention(''));
        $this->assertSame(365, $this->backup->normalizeRetention(365));

        foreach (['0', '-1', '366', 'soon', '2.5'] as $bad) {
            try {
                $this->backup->normalizeRetention($bad);
                $this->fail(sprintf('"%s" should not be a retention period', $bad));
            } catch (BackupException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    /**
     * A stored retention period that is out of bounds — edited into the raw
     * config list by hand — falls back rather than deleting everything.
     *
     * @return void
     */
    public function testAnImpossibleStoredRetentionFallsBack(): void
    {
        $table = $this->fetchTable('Config');
        $row = $table->newEmptyEntity();
        $row->set('param', DatabaseBackupService::PARAM_RETENTION);
        $row->set('value', '0');
        $row->set('description', 'Edited by hand.');
        $table->saveOrFail($row);

        $this->assertSame(7, (new DatabaseBackupService())->retentionDays());
    }

    /**
     * Test that `~` means the home directory of the user the site runs as.
     *
     * @return void
     */
    public function testTildeIsExpandedToTheHomeDirectory(): void
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');

        if (!is_string($home) || $home === '') {
            $this->markTestSkipped('This environment does not say where home is.');
        }

        $expanded = $this->backup->expand('~/bkpdb');

        $this->assertStringStartsWith(rtrim($home, '/\\'), $expanded);
        $this->assertStringEndsWith('bkpdb', $expanded);
        $this->assertStringNotContainsString('~', $expanded);

        // An absolute path is already an answer.
        $this->assertSame('/srv/bkpdb', $this->backup->expand('/srv/bkpdb'));
    }

    /**
     * Test that the sweep deletes what has aged out and nothing else.
     *
     * @return void
     */
    public function testPruneDeletesOnlyOurExpiredDumps(): void
    {
        $this->backup->save([
            'enabled' => '0',
            'directory' => $this->folder,
            'retention_days' => '7',
        ]);

        $backup = new DatabaseBackupService();
        $database = $backup->databaseName();
        $this->assertNotSame('', $database, 'The test connection has to name a database');

        $old = $this->write($backup->filePrefix() . '2026-01-01_17-00-00.sql.gz', time() - 8 * 86400);
        $fresh = $this->write($backup->filePrefix() . '2026-09-10_17-00-00.sql.gz', time() - 86400);
        // Old, but not ours: somebody else's backups sharing the folder, and our
        // own log, which is not a dump and must outlive every dump in it.
        $foreign = $this->write('backup_otherapp_2026-01-01_17-00-00.sql.gz', time() - 400 * 86400);
        $log = $this->write(DatabaseBackupService::LOG_NAME, time() - 400 * 86400);

        $removed = $backup->prune();

        $this->assertSame([basename($old)], $removed);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
        $this->assertFileExists($foreign);
        $this->assertFileExists($log);

        // And what is left is what the page reports, newest first.
        $listed = $backup->backups();
        $this->assertCount(1, $listed);
        $this->assertSame(basename($fresh), $listed[0]['name']);
    }

    /**
     * Test that the page's summary describes the folder without throwing, even
     * when the folder is not there.
     *
     * @return void
     */
    public function testSettingsDescribeAMissingFolderRatherThanFailing(): void
    {
        $missing = $this->folder . DIRECTORY_SEPARATOR . 'not-created';
        $this->backup->save([
            'enabled' => '0',
            'directory' => $missing,
            'retention_days' => '7',
        ]);

        $settings = (new DatabaseBackupService())->settings();

        $this->assertFalse($settings['enabled']);
        $this->assertSame($missing, $settings['resolved']);
        $this->assertFalse($settings['exists']);
        $this->assertFalse($settings['writable']);
        $this->assertSame(0, $settings['count']);
        $this->assertSame('17:00', $settings['utcTime']);
        $this->assertStringEndsWith(DatabaseBackupService::LOG_NAME, $settings['logPath']);
    }

    /**
     * Test that a dry run reports the sweep without doing it.
     *
     * @return void
     */
    public function testDryRunDeletesNothing(): void
    {
        $this->backup->save([
            'enabled' => '1',
            'directory' => $this->folder,
            'retention_days' => '7',
        ]);

        $backup = new DatabaseBackupService();

        if (
            !$backup->isMysql()
            || $backup->shellUnavailableReason() !== null
            || $backup->findDumpBinary() === null
        ) {
            $this->markTestSkipped('This machine cannot call mysqldump, which a dry run still checks for.');
        }

        $old = $this->write($backup->filePrefix() . '2026-01-01_17-00-00.sql.gz', time() - 30 * 86400);

        $reported = [];
        $result = $backup->run(function (string $line) use (&$reported): void {
            $reported[] = $line;
        }, true);

        $this->assertNull($result['file']);
        $this->assertFileExists($old);
        $this->assertStringContainsString('Would remove: ' . basename($old), implode("\n", $reported));
        // The log lives beside the dumps, and a dry run does not write to it.
        $this->assertFileDoesNotExist($this->folder . DIRECTORY_SEPARATOR . DatabaseBackupService::LOG_NAME);
    }

    /**
     * Test that sizes are reported the way a person would write them.
     *
     * @return void
     */
    public function testHumanBytes(): void
    {
        $this->assertSame('512 B', $this->backup->humanBytes(512));
        $this->assertSame('1.0 KB', $this->backup->humanBytes(1024));
        $this->assertSame('1.5 MB', $this->backup->humanBytes(1572864));
    }

    /**
     * Test that clan_acronym in config is reflected in the backup filename prefix.
     *
     * @return void
     */
    public function testFilePrefixIncludesClanAcronymWhenConfigured(): void
    {
        $table = $this->fetchTable('Config');
        $row = $table->newEmptyEntity();
        $row->set('param', DatabaseBackupService::PARAM_CLAN_ACRONYM);
        $row->set('value', 'STF');
        $row->set('description', 'Clan acronym');
        $table->saveOrFail($row);

        $backup = new DatabaseBackupService();
        $this->assertSame('STF', $backup->clanAcronym());
        $this->assertStringStartsWith('backup_STF_', $backup->filePrefix());

        $settings = $backup->settings();
        $this->assertSame('STF', $settings['clanAcronym']);
        $this->assertSame($backup->filePrefix(), $settings['filePrefix']);
    }

    /**
     * Test that special characters and whitespace in clan_acronym are sanitized
     * so they remain safe for filenames and globbing.
     *
     * @return void
     */
    public function testFilePrefixSanitizesSpecialCharactersInClanAcronym(): void
    {
        $table = $this->fetchTable('Config');
        $row = $table->newEmptyEntity();
        $row->set('param', DatabaseBackupService::PARAM_CLAN_ACRONYM);
        $row->set('value', '[~A-B C~]');
        $row->set('description', 'Clan acronym with brackets and spaces');
        $table->saveOrFail($row);

        $backup = new DatabaseBackupService();
        $this->assertSame('[~A-B C~]', $backup->clanAcronym());
        $this->assertStringStartsWith('backup_A-B_C_', $backup->filePrefix());
    }

    /**
     * Test that two sites sharing the same folder do not conflict:
     * each site lists and prunes only its own clan's dumps.
     *
     * @return void
     */
    public function testTwoSitesSharingFolderDoNotConflict(): void
    {
        $table = $this->fetchTable('Config');

        // Configure this site as clan 'ABC'
        $row = $table->find()->where(['param' => DatabaseBackupService::PARAM_CLAN_ACRONYM])->first()
            ?? $table->newEmptyEntity();
        $row->set('param', DatabaseBackupService::PARAM_CLAN_ACRONYM);
        $row->set('value', 'ABC');
        $row->set('description', 'Site 1 clan');
        $table->saveOrFail($row);

        $backupSite1 = new DatabaseBackupService();
        $prefixSite1 = $backupSite1->filePrefix();

        // Site 2 has clan 'XYZ'
        $row->set('value', 'XYZ');
        $table->saveOrFail($row);

        $backupSite2 = new DatabaseBackupService();
        $prefixSite2 = $backupSite2->filePrefix();

        // Ensure prefixes are distinct
        $this->assertNotSame($prefixSite1, $prefixSite2);
        $this->assertStringStartsWith('backup_ABC_', $prefixSite1);
        $this->assertStringStartsWith('backup_XYZ_', $prefixSite2);

        // Configure backup folder and 7 days retention
        $backupSite1->save([
            'enabled' => '1',
            'directory' => $this->folder,
            'retention_days' => '7',
        ]);

        // Reset config back to Site 1 (ABC)
        $row->set('value', 'ABC');
        $table->saveOrFail($row);
        $site1 = new DatabaseBackupService();

        // Create expired and fresh backups for Site 1
        $site1Old = $this->write($prefixSite1 . '2026-01-01_17-00-00.sql.gz', time() - 10 * 86400);
        $site1Fresh = $this->write($prefixSite1 . '2026-09-10_17-00-00.sql.gz', time() - 86400);

        // Create expired and fresh backups for Site 2 in the same folder
        $site2Old = $this->write($prefixSite2 . '2026-01-01_17-00-00.sql.gz', time() - 10 * 86400);
        $site2Fresh = $this->write($prefixSite2 . '2026-09-10_17-00-00.sql.gz', time() - 86400);

        // Site 1 only sees its own backups
        $site1Backups = $site1->backups();
        $this->assertCount(2, $site1Backups);
        $this->assertSame(basename($site1Fresh), $site1Backups[0]['name']);
        $this->assertSame(basename($site1Old), $site1Backups[1]['name']);

        // Site 1 prunes: only its own expired dump should be deleted
        $removed = $site1->prune();
        $this->assertSame([basename($site1Old)], $removed);

        $this->assertFileDoesNotExist($site1Old);
        $this->assertFileExists($site1Fresh);
        // Site 2's files must remain completely untouched
        $this->assertFileExists($site2Old);
        $this->assertFileExists($site2Fresh);
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Put a file in the backup folder, aged as asked.
     *
     * @param string $name The file name.
     * @param int $mtime When it was last written.
     * @return string The full path.
     */
    protected function write(string $name, int $mtime): string
    {
        $path = $this->folder . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, 'not really a dump');
        touch($path, $mtime);

        return $path;
    }
}
