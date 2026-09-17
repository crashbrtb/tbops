<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\DatabaseBackupService;
use App\Service\MaintenanceScheduleService;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Maintenance page of App\Controller\ConfigController.
 *
 * The crontab itself belongs to the machine the tests run on, so what is checked
 * here is the page around it: that only administrators reach it, that it says
 * plainly when it cannot read the crontab instead of claiming the task is
 * scheduled, and that a schedule it cannot install is never reported as
 * installed. The Brazil-to-UTC arithmetic is covered in
 * \App\Test\TestCase\Service\MaintenanceScheduleServiceTest.
 *
 * @uses \App\Controller\ConfigController::maintenance()
 */
class ConfigControllerMaintenanceTest extends TestCase
{
    use IntegrationTestTrait;
    use LocatorAwareTrait;

    /**
     * @var list<string>
     */
    protected array $fixtures = [
        'app.Config',
        'app.Users',
        'app.Roles',
        // User 1 holds role 1, which is what requireAdmin() looks for.
        'app.RolesUsers',
    ];

    /**
     * @inheritDoc
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();
    }

    /**
     * @inheritDoc
     */
    public function tearDown(): void
    {
        \Cake\I18n\I18n::setLocale('en_US');
        parent::tearDown();
    }

    /**
     * The crontab is the server's, so only administrators may look at it.
     *
     * @return void
     */
    public function testPageIsClosedToNonAdministrators(): void
    {
        $this->signIn(2);

        $this->get('/config/maintenance');

        $this->assertResponseCode(403);
    }

    /**
     * Opening the page is the check: nothing has to be pressed to find out.
     *
     * @return void
     */
    public function testPageOffersTheSuggestedScheduleInUtc(): void
    {
        $this->signIn(1);

        $this->get('/config/maintenance');

        $this->assertResponseOk();

        // Two a day, at the suggested times in UTC, with the count named as suggested.
        $this->assertResponseContains('value="17:15"');
        $this->assertResponseContains('value="05:15"');
        $this->assertResponseContains('times a day (suggested)');

        // The block to paste is in UTC.
        $this->assertResponseContains('CRON_TZ=UTC');
        $this->assertResponseContains('15 17 * * *');
        $this->assertResponseContains('15 5 * * *');
        $this->assertResponseContains('bin/cake.php daily_maintenance');
    }

    /**
     * A server whose crontab cannot be reached is told so, rather than being
     * shown a green tick it has not earned.
     *
     * @return void
     */
    public function testPageSaysWhenTheCrontabCannotBeRead(): void
    {
        $this->signIn(1);

        $this->get('/config/maintenance');

        $reachable = (new MaintenanceScheduleService())->unavailableReason() === null;

        if ($reachable) {
            // On a machine with cron, the page reports one state or the other.
            $this->assertResponseRegExp('/Scheduled|Not scheduled/');

            return;
        }

        $this->assertResponseContains('cannot be checked from here');
        $this->assertResponseNotContains('>Scheduled<');
    }

    /**
     * A time that is not a time is refused before anything is written.
     *
     * @return void
     */
    public function testInvalidTimeIsRefused(): void
    {
        $this->signIn(1);

        $this->post('/config/maintenance', ['times' => ['14:15', '99:99']]);

        $this->assertResponseOk();
        $this->assertFlashElement('flash/error');
        $this->assertNull($this->storedSchedule());
    }

    /**
     * An empty form is refused too: "no times" is not a schedule.
     *
     * @return void
     */
    public function testEmptyScheduleIsRefused(): void
    {
        $this->signIn(1);

        $this->post('/config/maintenance', ['times' => ['']]);

        $this->assertResponseOk();
        $this->assertFlashElement('flash/error');
        $this->assertNull($this->storedSchedule());
    }

    /**
     * Nothing is stored as chosen unless it reached the crontab.
     *
     * The stored times are what the form comes back showing, so storing them
     * after a failed install would leave the page describing a schedule that is
     * not running anywhere.
     *
     * @return void
     */
    public function testScheduleIsOnlyStoredWhenItWasInstalled(): void
    {
        $this->signIn(1);

        $this->post('/config/maintenance', ['times' => ['06:30', '18:30']]);

        if ((new MaintenanceScheduleService())->unavailableReason() === null) {
            $this->markTestSkipped('This machine has a crontab, so the install is not expected to fail.');
        }

        $this->assertResponseOk();
        $this->assertFlashElement('flash/error');
        $this->assertNull($this->storedSchedule());
    }

    /**
     * The folder the dumps go to is on the page, because "where are my backups?"
     * is the question this page exists to answer without anyone reading a script.
     *
     * @return void
     */
    public function testPageNamesTheBackupFolderAndItsFixedTime(): void
    {
        $this->signIn(1);

        $this->get('/config/maintenance');

        $this->assertResponseOk();
        $this->assertResponseContains(__('Database backup'));
        $this->assertResponseContains(__('Saving to'));
        // The default folder, and the reset the backup is pinned to.
        $this->assertResponseContains('bkpdb');
        $this->assertResponseContains('17:00');
        $this->assertResponseContains('bin/cake.php database_backup');
        // Off until somebody turns it on.
        $this->assertResponseContains(__('Not backing up'));
    }

    /**
     * Test that the backup settings are stored.
     *
     * @return void
     */
    public function testBackupSettingsAreStored(): void
    {
        $this->signIn(1);

        $folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tbops_backup_page_' . uniqid();

        $this->post('/config/maintenance', [
            'section' => 'backup',
            'enabled' => '1',
            'directory' => $folder,
            'retention_days' => '10',
        ]);

        $this->assertRedirect(['action' => 'maintenance']);
        $this->assertFlashElement('flash/success');

        $backup = new DatabaseBackupService();
        $this->assertTrue($backup->isEnabled());
        $this->assertSame($folder, $backup->directory());
        $this->assertSame(10, $backup->retentionDays());

        @rmdir($folder);
    }

    /**
     * A retention period that would delete the dump it just took is refused, and
     * nothing at all is stored — including the folder submitted beside it.
     *
     * @return void
     */
    public function testImpossibleRetentionIsRefusedAndNothingIsStored(): void
    {
        $this->signIn(1);

        $this->post('/config/maintenance', [
            'section' => 'backup',
            'enabled' => '1',
            'directory' => '/srv/bkpdb',
            'retention_days' => '0',
        ]);

        $this->assertResponseOk();
        $this->assertFlashElement('flash/error');

        $backup = new DatabaseBackupService();
        $this->assertFalse($backup->isEnabled());
        $this->assertSame(DatabaseBackupService::DEFAULT_DIR, $backup->directory());
    }

    /**
     * A folder cron could not find is refused too.
     *
     * @return void
     */
    public function testRelativeBackupFolderIsRefused(): void
    {
        $this->signIn(1);

        $this->post('/config/maintenance', [
            'section' => 'backup',
            'enabled' => '1',
            'directory' => 'backups',
            'retention_days' => '7',
        ]);

        $this->assertResponseOk();
        $this->assertFlashElement('flash/error');
        $this->assertFalse((new DatabaseBackupService())->isEnabled());
    }

    /**
     * The backup settings are the server's too, so they are closed to everyone
     * but administrators.
     *
     * @return void
     */
    public function testBackupSettingsAreClosedToNonAdministrators(): void
    {
        $this->signIn(2);

        $this->post('/config/maintenance', [
            'section' => 'backup',
            'enabled' => '1',
            'directory' => '/srv/bkpdb',
            'retention_days' => '7',
        ]);

        $this->assertResponseCode(403);
        $this->assertFalse((new DatabaseBackupService())->isEnabled());
    }

    /**
     * When the visitor's language is Portuguese, the maintenance page renders
     * translated labels and headings instead of the source English.
     *
     * @return void
     */
    public function testMaintenancePageTranslatesToPortuguese(): void
    {
        $this->signIn(1);
        $this->session([\App\Middleware\LocaleMiddleware::SESSION_KEY => 'pt_BR']);

        $this->get('/config/maintenance');

        $this->assertResponseOk();
        $this->assertResponseContains('Manutenção');
        $this->assertResponseContains('Status atual');
        $this->assertResponseContains('Instalar no crontab');
        $this->assertResponseContains('Backup do banco de dados');
        $this->assertResponseContains('Quantas vezes ao dia');
        $this->assertResponseContains('Execução 1 (UTC)');
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Put a user in the session.
     *
     * @param int $id The user id; 1 holds the admin role in the fixtures.
     * @return void
     */
    protected function signIn(int $id): void
    {
        $this->session([
            'Auth' => [
                'id' => $id,
                'username' => 'tester',
                'email' => 'tester@example.com',
                'created' => new DateTime('2026-01-01 00:00:00'),
            ],
        ]);
    }

    /**
     * The schedule stored in the `config` table, if any.
     *
     * @return string|null
     */
    protected function storedSchedule(): ?string
    {
        $row = $this->fetchTable('Config')
            ->find()
            ->where(['param' => MaintenanceScheduleService::PARAM])
            ->first();

        return $row->value ?? null;
    }
}
