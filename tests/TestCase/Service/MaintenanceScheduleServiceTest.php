<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\DatabaseBackupService;
use App\Service\Maintenance\CrontabException;
use App\Service\MaintenanceScheduleService;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\TestCase;
use ReflectionMethod;

/**
 * App\Service\MaintenanceScheduleService Test Case
 *
 * Reading and writing the real crontab cannot be tested here — that needs a
 * server with cron on it — so what is tested is everything either side of the
 * `crontab` call: the Brazil-to-UTC arithmetic, the block the schedule is
 * rendered into, and the parsing that reads a crontab back. The parsing is the
 * part that matters most: if it misreads a block the page tells an administrator
 * the maintenance is not scheduled when it is, or the other way round.
 *
 * @uses \App\Service\MaintenanceScheduleService
 */
class MaintenanceScheduleServiceTest extends TestCase
{
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Config',
    ];

    /**
     * @var \App\Service\MaintenanceScheduleService
     */
    protected MaintenanceScheduleService $schedule;

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->schedule = new MaintenanceScheduleService();
    }

    /**
     * All times are in UTC, so the offset is 0 and toUtc/fromUtc return the time as-is.
     *
     * @return void
     */
    public function testSuggestedTimesAreInUtc(): void
    {
        $this->assertSame(0, $this->schedule->scheduleOffsetMinutes());
        $this->assertSame('17:15', $this->schedule->toUtc('17:15'));
        $this->assertSame('05:15', $this->schedule->toUtc('05:15'));
        $this->assertSame('02:30', $this->schedule->toUtc('02:30'));
        $this->assertSame('21:00', $this->schedule->fromUtc('21:00'));
    }

    /**
     * Test that converting and converting back is a no-op.
     *
     * @return void
     */
    public function testUtcRoundTrip(): void
    {
        foreach (['00:00', '02:15', '14:15', '12:34', '23:59'] as $time) {
            $this->assertSame($time, $this->schedule->fromUtc($this->schedule->toUtc($time)));
        }
    }

    /**
     * Test the times coming off the form are tidied into a schedule.
     *
     * @return void
     */
    public function testNormalizeTimesSortsAndDeduplicates(): void
    {
        $this->assertSame(
            ['02:15', '09:00', '14:15'],
            $this->schedule->normalizeTimes(['14:15', ' 02:15 ', '09:00', '14:15', ''])
        );
        // A browser time input with seconds enabled sends three fields.
        $this->assertSame(['07:05'], $this->schedule->normalizeTimes(['07:05:00']));
    }

    /**
     * Test that nonsense is refused rather than written to the crontab.
     *
     * @return void
     */
    public function testNormalizeTimesRejectsInvalidTimes(): void
    {
        $this->expectException(CrontabException::class);
        $this->schedule->normalizeTimes(['24:00']);
    }

    /**
     * Test that an empty form is refused.
     *
     * @return void
     */
    public function testNormalizeTimesRejectsEmptySchedule(): void
    {
        $this->expectException(CrontabException::class);
        $this->schedule->normalizeTimes(['', '   ']);
    }

    /**
     * Test that an absurd number of runs is refused.
     *
     * @return void
     */
    public function testNormalizeTimesRejectsTooManyRuns(): void
    {
        $times = [];
        for ($hour = 0; $hour <= MaintenanceScheduleService::MAX_RUNS; $hour++) {
            $times[] = sprintf('%02d:15', $hour);
        }

        $this->expectException(CrontabException::class);
        $this->schedule->normalizeTimes($times);
    }

    /**
     * Test the suggestion offered for each run count the picker shows.
     *
     * @return void
     */
    public function testSuggestionsCoverEveryRunChoice(): void
    {
        $suggestions = $this->schedule->suggestions();

        $this->assertSame(MaintenanceScheduleService::RUN_CHOICES, array_keys($suggestions));
        // Two a day is the recommendation, and it is the clan's own pair of times.
        $this->assertSame(MaintenanceScheduleService::DEFAULT_TIMES, $suggestions[2]);

        foreach ($suggestions as $count => $times) {
            $this->assertCount($count, $times, sprintf('%d runs should suggest %d times', $count, $count));
            $this->assertSame($times, $this->schedule->normalizeTimes($times));
        }
    }

    /**
     * Test the block that goes into the crontab.
     *
     * @return void
     */
    public function testBlockIsWrittenInUtcBetweenMarkers(): void
    {
        $block = $this->schedule->blockFor(['17:15', '05:15']);
        $lines = explode("\n", $block);

        $this->assertSame(MaintenanceScheduleService::BEGIN, $lines[0]);
        $this->assertSame(MaintenanceScheduleService::END, (string)end($lines));
        $this->assertStringContainsString('CRON_TZ=UTC', $block);

        // The schedule fields are UTC.
        $this->assertMatchesRegularExpression('/^15 5 \* \* \* /m', $block);
        $this->assertMatchesRegularExpression('/^15 17 \* \* \* /m', $block);
        $this->assertStringContainsString('# 05:15 UTC', $block);
        $this->assertStringContainsString('# 17:15 UTC', $block);
        $this->assertStringContainsString('bin/cake.php daily_maintenance', $block);
        $this->assertStringContainsString('logs/maintenance_cron.log', $block);
    }

    /**
     * The backup rides in the same block, at the game reset rather than at a
     * time of anybody's choosing.
     *
     * @return void
     */
    public function testBackupLineIsAddedAtTheGameResetWhenItIsTurnedOn(): void
    {
        $this->assertStringNotContainsString('database_backup', $this->schedule->blockFor(['02:15']));

        $this->enableBackup();
        $block = (new MaintenanceScheduleService())->blockFor(['02:15']);

        // 17:00 UTC, written as UTC because the block declares CRON_TZ=UTC.
        $this->assertMatchesRegularExpression('/^0 17 \* \* \* .*database_backup/m', $block);
        $this->assertStringContainsString('logs/database_backup_cron.log', $block);
        $this->assertStringContainsString('# Database backup at the 17:00 UTC game reset', $block);
        // And the maintenance is still there, called once.
        $this->assertSame(1, preg_match_all('/bin\/cake\.php daily_maintenance/', $block));
    }

    /**
     * The backup's line is not a maintenance time, and must not be read back as
     * one: doing so would show a schedule nobody chose and then write it.
     *
     * @return void
     */
    public function testBackupLineIsNotReadBackAsAMaintenanceTime(): void
    {
        $this->enableBackup();
        $schedule = new MaintenanceScheduleService();
        $crontab = explode("\n", $schedule->blockFor(['05:15', '17:15']));

        $reflected = new ReflectionMethod($schedule, 'managedBlock');
        $reflected->setAccessible(true);
        $block = $reflected->invokeArgs($schedule, [$crontab]);

        $this->assertCount(3, $block['entries']);
        $this->assertCount(2, $block['maintenance']);
        $this->assertCount(1, $block['backup']);

        $times = new ReflectionMethod($schedule, 'timesFromEntries');
        $times->setAccessible(true);
        $this->assertSame(
            ['05:15', '17:15'],
            $times->invokeArgs($schedule, [$block['maintenance'], $block['timezone']])
        );
    }

    /**
     * Test that a block this service wrote is read back as the same times.
     *
     * @return void
     */
    public function testInstalledBlockIsReadBackAsUtcTimes(): void
    {
        $crontab = array_merge(
            ['@reboot /usr/local/bin/warm-cache'],
            explode("\n", $this->schedule->blockFor(['17:15', '05:15']))
        );

        $block = $this->invoke('managedBlock', [$crontab]);

        $this->assertSame('UTC', $block['timezone']);
        $this->assertCount(2, $block['entries']);
        $this->assertSame(
            ['05:15', '17:15'],
            $this->invoke('timesFromEntries', [$block['entries'], $block['timezone']])
        );
    }

    /**
     * Test that a block written in a named timezone is still understood and converted to UTC.
     *
     * Only this service writes `CRON_TZ=UTC`, but an administrator who edited
     * the block by hand may well have put their own zone there.
     *
     * @return void
     */
    public function testEntriesInAnotherTimezoneAreConverted(): void
    {
        $times = $this->invoke('timesFromEntries', [['15 18 * * * cd /srv && php bin/cake.php daily_maintenance'], 'UTC']);
        $this->assertSame(['18:15'], $times);

        $fromSaoPaulo = $this->invoke('timesFromEntries', [['15 14 * * * cd /srv && php bin/cake.php daily_maintenance'], 'America/Sao_Paulo']);
        $this->assertSame(['17:15'], $fromSaoPaulo);
    }

    /**
     * Test that a schedule this page cannot express is not guessed at.
     *
     * @return void
     */
    public function testSteppedScheduleIsNotReadAsATime(): void
    {
        $entries = ['*/15 * * * * cd /srv && php bin/cake.php daily_maintenance'];

        $this->assertSame([], $this->invoke('timesFromEntries', [$entries, 'UTC']));
    }

    /**
     * Test that rewriting the block leaves the rest of the crontab alone.
     *
     * @return void
     */
    public function testRemovingTheBlockKeepsEveryOtherLine(): void
    {
        $crontab = array_merge(
            [
                'MAILTO=ops@example.com',
                '0 4 * * * /usr/local/bin/backup',
            ],
            explode("\n", $this->schedule->blockFor(['02:15'])),
            ['30 6 * * 1 /usr/local/bin/weekly-report']
        );

        $this->assertSame(
            [
                'MAILTO=ops@example.com',
                '0 4 * * * /usr/local/bin/backup',
                '30 6 * * 1 /usr/local/bin/weekly-report',
            ],
            $this->invoke('withoutManagedBlock', [$crontab])
        );
    }

    /**
     * Test that a block written under the old chestcounter markers is still owned.
     *
     * @return void
     */
    public function testBlockWithLegacyMarkersIsStillManaged(): void
    {
        $block = explode("\n", $this->schedule->blockFor(['05:15']));
        $block[0] = MaintenanceScheduleService::LEGACY_BEGIN;
        $block[count($block) - 1] = MaintenanceScheduleService::LEGACY_END;
        $crontab = array_merge(['MAILTO=ops@example.com'], $block);

        $this->assertSame(['05:15'], $this->invoke('timesFromEntries', [
            $this->invoke('managedBlock', [$crontab])['maintenance'],
            'UTC',
        ]));
        $this->assertSame([], $this->invoke('unmanagedEntries', [$crontab]));
        $this->assertSame(['MAILTO=ops@example.com'], $this->invoke('withoutManagedBlock', [$crontab]));
    }

    /**
     * Test that a maintenance line added by hand is reported, not adopted.
     *
     * @return void
     */
    public function testEntriesAddedByHandAreReportedSeparately(): void
    {
        $byHand = '0 3 * * * cd /var/www/tbops && php bin/cake.php daily_maintenance';
        $crontab = array_merge(
            [$byHand, '# 0 5 * * * php bin/cake.php daily_maintenance'],
            explode("\n", $this->schedule->blockFor(['05:15']))
        );

        // Ours is inside the markers, so only the hand-added line is listed, and
        // the commented-out one is not an entry at all.
        $this->assertSame([$byHand], $this->invoke('unmanagedEntries', [$crontab]));
    }

    /**
     * Test that the chosen times survive a round trip through the config table.
     *
     * @return void
     */
    public function testSavedTimesFallBackToTheSuggestionAndThenToWhatWasChosen(): void
    {
        $this->assertSame(MaintenanceScheduleService::DEFAULT_TIMES, $this->schedule->savedTimes());

        $this->invoke('saveTimes', [['06:30', '18:30']]);
        $this->assertSame(['06:30', '18:30'], (new MaintenanceScheduleService())->savedTimes());

        $row = $this->fetchTable('Config')
            ->find()
            ->where(['param' => MaintenanceScheduleService::PARAM])
            ->firstOrFail();
        $this->assertSame('06:30,18:30', $row->value);
        // The column holds 45 characters, which the longest schedule has to fit in.
        $this->assertLessThanOrEqual(45, strlen(implode(',', $this->schedule->suggestions()[6])));
    }

    /**
     * Test that the command cron runs names this installation.
     *
     * @return void
     */
    public function testCommandLineRunsTheCakeCommandFromTheApplicationDirectory(): void
    {
        $command = $this->schedule->commandLine();

        $this->assertStringContainsString(rtrim(ROOT, '/\\'), $command);
        $this->assertStringContainsString('bin/cake.php daily_maintenance', $command);
        $this->assertStringEndsWith('2>&1', $command);
    }

    /**
     * Turn the nightly database backup on.
     *
     * @return void
     */
    protected function enableBackup(): void
    {
        (new DatabaseBackupService())->save([
            'enabled' => '1',
            'directory' => '/srv/bkpdb',
            'retention_days' => '7',
        ]);
    }

    /**
     * Test shell argument escaping.
     *
     * @return void
     */
    public function testEscapeArg(): void
    {
        $path = '/home/storage/5/60/a8/counter1/public_html';
        $escaped = MaintenanceScheduleService::escapeArg($path);
        $this->assertNotEmpty($escaped);
        $this->assertStringContainsString($path, $escaped);

        $complex = "path with spaces/and 'single' quotes";
        $escapedComplex = MaintenanceScheduleService::escapeArg($complex);
        $this->assertNotEmpty($escapedComplex);
    }

    /**
     * Call a protected method on the service under test.
     *
     * @param string $method The method name.
     * @param array<mixed> $arguments Its arguments.
     * @return mixed
     */
    protected function invoke(string $method, array $arguments = []): mixed
    {
        $reflected = new ReflectionMethod($this->schedule, $method);
        $reflected->setAccessible(true);

        return $reflected->invokeArgs($this->schedule, $arguments);
    }
}
