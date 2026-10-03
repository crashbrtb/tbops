<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Table\ConfigTable;
use App\Service\Branding\BrandingImageException;
use App\Service\BrandingService;
use App\Service\ChestGoalService;
use App\Service\DatabaseBackupService;
use App\Service\Maintenance\BackupException;
use App\Service\Maintenance\CrontabException;
use App\Service\MaintenanceScheduleService;
use App\Service\ThemeService;
use InvalidArgumentException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Config Controller
 *
 * @property \App\Model\Table\ConfigTable $Config
 */
class ConfigController extends AppController
{
    /**
     * Choose the colour scheme the whole site wears.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function theme()
    {
        $this->requireAdmin();

        $themes = new ThemeService();

        if ($this->request->is(['post', 'put'])) {
            try {
                $themes->select((string)$this->request->getData('theme'));
                $this->Flash->success(__('Theme updated.'));

                return $this->redirect(['action' => 'theme']);
            } catch (InvalidArgumentException $e) {
                $this->Flash->error(__('That theme is not available.'));
            }
        }

        $this->set([
            'themes' => ThemeService::THEMES,
            'chosen' => $themes->slug(),
        ]);
    }

    /**
     * Choose the site's logo and favicon.
     *
     * Uploading and choosing are one submission because that is how it reads to
     * the person doing it: picking a file from your own machine is a way of
     * choosing that artwork, not a separate step before choosing it.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function branding()
    {
        $this->requireAdmin();

        $branding = new BrandingService();

        if ($this->request->is(['post', 'put'])) {
            $chosen = [];
            $rejected = [];
            $failed = false;

            foreach (['logo' => 'logo_file', 'favicon' => 'favicon_file'] as $kind => $field) {
                $upload = $this->request->getData($field);
                if (
                    !$upload instanceof UploadedFileInterface
                    || $upload->getError() === UPLOAD_ERR_NO_FILE
                ) {
                    continue;
                }

                try {
                    if ($kind === 'logo') {
                        $branding->storeCustomLogo($upload);
                    } else {
                        $branding->storeCustomFavicon($upload);
                    }
                    // Uploading is how an administrator says "use this one".
                    $chosen[$kind] = BrandingService::CUSTOM;
                } catch (BrandingImageException $e) {
                    $this->Flash->error($e->getMessage());
                    $rejected[$kind] = true;
                    $failed = true;
                }
            }

            foreach (['logo', 'favicon'] as $kind) {
                // A slot whose upload was refused is left exactly as it was.
                // The administrator asked for their own file there; quietly
                // applying whichever card happened to be selected instead
                // would change the site in a way they never asked for.
                if (isset($chosen[$kind]) || isset($rejected[$kind])) {
                    continue;
                }
                $slug = $this->request->getData($kind);
                if (is_string($slug) && $slug !== '') {
                    $chosen[$kind] = $slug;
                }
            }

            foreach ($chosen as $kind => $slug) {
                try {
                    if ($kind === 'logo') {
                        $branding->selectLogo($slug);
                    } else {
                        $branding->selectFavicon($slug);
                    }
                } catch (BrandingImageException $e) {
                    $this->Flash->error($e->getMessage());
                    $failed = true;
                }
            }

            if (!$failed) {
                $this->Flash->success(__('Branding updated.'));

                return $this->redirect(['action' => 'branding']);
            }
        }

        $presets = BrandingService::PRESETS;
        $logoSlug = $branding->logoSlug();
        $faviconSlug = $branding->faviconSlug();
        $hasCustomLogo = $branding->hasCustom('logo');
        $hasCustomFavicon = $branding->hasCustom('favicon');

        $this->set(compact(
            'branding',
            'presets',
            'logoSlug',
            'faviconSlug',
            'hasCustomLogo',
            'hasCustomFavicon'
        ));
    }

    /**
     * Check whether the daily maintenance is in the server's crontab, and put it
     * there.
     *
     * The whole point of the page is the answer to "is it scheduled?", so a plain
     * GET has to do the checking: an administrator who has to press something to
     * find out has already been told nothing.
     *
     * The nightly database backup is settled here too — whether it runs, where it
     * writes and how long dumps are kept — because it is the same question about
     * the same crontab, and because the folder it writes to is something an
     * administrator has to be able to read off a page rather than off a script.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function maintenance()
    {
        $this->requireAdmin();

        $backup = new DatabaseBackupService();
        $schedule = new MaintenanceScheduleService($backup);

        if ($this->request->is(['post', 'put'])) {
            try {
                if ($this->request->getData('remove') !== null) {
                    $schedule->remove();
                    $this->Flash->success(__('The maintenance entries were removed from the crontab.'));
                } elseif ($this->request->getData('section') === 'backup') {
                    $this->saveBackupSettings($backup, $schedule);
                } else {
                    $times = $schedule->install((array)$this->request->getData('times'));
                    $this->Flash->success(__(
                        'Scheduled: {0} UTC.',
                        implode(', ', $times)
                    ));
                }

                return $this->redirect(['action' => 'maintenance']);
            } catch (BackupException | CrontabException $e) {
                $this->Flash->error($e->getMessage());
            }
        }

        $status = $schedule->status();

        $this->set([
            'status' => $status,
            'times' => $status['times'],
            'suggestions' => $schedule->suggestions(),
            'runChoices' => MaintenanceScheduleService::RUN_CHOICES,
            'defaultTimes' => MaintenanceScheduleService::DEFAULT_TIMES,
            'scheduleZone' => MaintenanceScheduleService::SCHEDULE_TZ,
            'manualBlock' => $schedule->blockFor($status['times']),
            'backup' => $backup->settings(),
            'backupService' => $backup,
            'backupLocalTime' => DatabaseBackupService::UTC_TIME,
            'backupDefaultDir' => DatabaseBackupService::DEFAULT_DIR,
            'backupMinDays' => DatabaseBackupService::MIN_RETENTION_DAYS,
            'backupMaxDays' => DatabaseBackupService::MAX_RETENTION_DAYS,
        ]);
    }

    /**
     * Store the nightly backup's settings, and put its cron line in step.
     *
     * The backup's entry lives in the same managed block as the maintenance, so
     * turning the backup on or off has to rewrite that block — otherwise the
     * setting says one thing and cron goes on doing the other until somebody
     * happens to save the schedule. Nothing is rewritten when the block is not
     * installed at all: there is no schedule to add a line to yet.
     *
     * @param \App\Service\DatabaseBackupService $backup The backup settings.
     * @param \App\Service\MaintenanceScheduleService $schedule The crontab.
     * @return void
     * @throws \App\Service\Maintenance\BackupException When a setting is not usable.
     */
    protected function saveBackupSettings(DatabaseBackupService $backup, MaintenanceScheduleService $schedule): void
    {
        $warnings = $backup->save([
            'enabled' => $this->request->getData('enabled'),
            'directory' => $this->request->getData('directory'),
            'retention_days' => $this->request->getData('retention_days'),
        ]);

        foreach ($warnings as $warning) {
            $this->Flash->warning($warning);
        }

        $status = $schedule->status();
        $rewritable = $status['installed'] && $status['installedTimes'] !== [];

        if ($rewritable) {
            try {
                $schedule->install($status['installedTimes']);
            } catch (CrontabException $e) {
                $this->Flash->error(__(
                    'The settings were saved, but the crontab could not be rewritten: {0}',
                    $e->getMessage()
                ));

                return;
            }
        } elseif ($status['installed']) {
            // The block holds a schedule this page cannot express as a list of
            // times — something like `*/15`, added by hand. Rewriting it would
            // replace an administrator's own schedule with a guess, so the
            // backup's line has to be added by hand as well.
            $this->Flash->warning(__(
                'The schedule in the crontab was written by hand and this page cannot rewrite it, so the '
                    . 'backup line was not added. Copy the block at the bottom of this page into the crontab.'
            ));
        }

        if (!$backup->isEnabled()) {
            $this->Flash->success(__('The nightly database backup is turned off.'));

            return;
        }

        $this->Flash->success(__(
            'The database is backed up every day at {0} UTC to {1}, keeping {2} day(s).',
            DatabaseBackupService::UTC_TIME,
            $backup->resolvedDirectory(),
            $backup->retentionDays()
        ));

        if (!$status['installed']) {
            // A setting nothing acts on is the failure this page exists to
            // prevent, so it is said out loud rather than left to be noticed.
            $this->Flash->warning($status['unavailable'] === null
                ? __('Nothing is scheduled yet, so no backup will be taken. Install the schedule above.')
                : __('The crontab cannot be reached from here, so nothing will take the backup until the block at the bottom of this page is installed by hand.'));
        }
    }

    /**
     * All parameters, split into the general, bank and chests sections.
     *
     * The chests section opens with the goals form: the chest goals, global or
     * by guard level, are a small table rather than one value each, so their
     * rows are edited there and left out of the raw list.
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $this->requireAdmin();

        $managed = array_merge(
            [ChestGoalService::MODE_PARAM],
            array_values(ChestGoalService::GLOBAL_PARAMS),
            array_values(ChestGoalService::BY_GUARD_PARAMS)
        );
        $sections = array_fill_keys(ConfigTable::SECTIONS, []);
        foreach ($this->Config->find()->orderBy(['param' => 'ASC'])->all() as $row) {
            if (in_array($row->param, $managed, true)) {
                continue;
            }
            $sections[ConfigTable::sectionOf($row->param)][] = $row;
        }

        $section = (string)$this->request->getQuery('section');
        if (!in_array($section, ConfigTable::SECTIONS, true)) {
            $section = ConfigTable::SECTION_GENERAL;
        }

        $chestGoals = new ChestGoalService();

        $this->set([
            'sections' => $sections,
            'section' => $section,
            'goalSettings' => $chestGoals->settings(),
            'guardGoalTable' => $chestGoals->levelTable(),
            // Highest level first: the table reads like a ladder, best guards at the top
            'guardLevels' => array_reverse(ChestGoalService::LEVELS),
        ]);
    }

    /**
     * Store the chest goals: global, or by guard level.
     *
     * @return \Cake\Http\Response|null Redirects to the chests section.
     */
    public function chestGoals()
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'put']);

        try {
            (new ChestGoalService())->save([
                'mode' => $this->request->getData('mode'),
                'global' => (array)$this->request->getData('global'),
                'by_guard' => (array)$this->request->getData('by_guard'),
            ]);
            $this->Flash->success(__('Chest goals saved.'));
        } catch (InvalidArgumentException $e) {
            $this->Flash->error($e->getMessage());
        }

        return $this->redirect(['action' => 'index', '?' => ['section' => ConfigTable::SECTION_CHESTS]]);
    }

    /**
     * The Configs page opened on the section a parameter belongs to.
     *
     * @param string $param Parameter name.
     * @return array<string, mixed>
     */
    protected function sectionUrl(string $param): array
    {
        return ['action' => 'index', '?' => ['section' => ConfigTable::sectionOf($param)]];
    }

    /**
     * View method
     *
     * @param string|null $id Config id.
     * @return \Cake\Http\Response|null|void Renders view
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function view($id = null)
    {
        $this->requireAdmin();
        $config = $this->Config->get($id, contain: []);
        $this->set(compact('config'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $this->requireAdmin();
        $config = $this->Config->newEmptyEntity();
        if ($this->request->is('post')) {
            $config = $this->Config->patchEntity($config, $this->request->getData());
            if ($this->Config->save($config)) {
                $this->Flash->success(__('The config has been saved.'));

                return $this->redirect($this->sectionUrl((string)$config->param));
            }
            $this->Flash->error(__('The config could not be saved. Please, try again.'));
        }
        $this->set(compact('config'));
    }

    /**
     * Edit method
     *
     * @param string|null $id Config id.
     * @return \Cake\Http\Response|null|void Redirects on successful edit, renders view otherwise.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function edit($id = null)
    {
        $this->requireAdmin();
        $config = $this->Config->get($id, contain: []);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $config = $this->Config->patchEntity($config, $this->request->getData());
            if ($this->Config->save($config)) {
                $this->Flash->success(__('The config has been saved.'));

                return $this->redirect($this->sectionUrl((string)$config->param));
            }
            $this->Flash->error(__('The config could not be saved. Please, try again.'));
        }
        $this->set(compact('config'));
    }

    /**
     * Delete method
     *
     * @param string|null $id Config id.
     * @return \Cake\Http\Response|null Redirects to index.
     * @throws \Cake\Datasource\Exception\RecordNotFoundException When record not found.
     */
    public function delete($id = null)
    {
        $this->requireAdmin();
        $this->request->allowMethod(['post', 'delete']);
        $config = $this->Config->get($id);
        if ($this->Config->delete($config)) {
            $this->Flash->success(__('The config has been deleted.'));
        } else {
            $this->Flash->error(__('The config could not be deleted. Please, try again.'));
        }

        return $this->redirect($this->sectionUrl((string)$config->param));
    }
}
