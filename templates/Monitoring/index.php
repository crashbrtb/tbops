<?php
/**
 * Whether the collector, the maintenance, the backup and the uploads are running.
 *
 * @var \App\View\AppView $this
 * @var array{ok: bool, warnings: int, checked_at: string, jobs: list<array<string, mixed>>} $report
 * @var iterable<\App\Model\Entity\MonitoredJob> $monitoredJobs
 * @var iterable<\App\Model\Entity\JobRun> $runs
 * @var string $jobFilter
 * @var string|null $monitorKey
 * @var string $healthUrl
 * @var int $retentionDays
 */

use App\Model\Entity\JobRun;
use App\Service\HealthCheckService;

$this->assign('title', __('Monitoring'));
$this->Breadcrumbs->add([
    ['title' => __('Home'), 'url' => '/'],
    ['title' => __('Config'), 'url' => ['controller' => 'Config', 'action' => 'index']],
    ['title' => __('Monitoring')],
]);

$this->Html->css('branding', ['block' => 'css']);

$stateNames = [
    HealthCheckService::STATE_OK => __('Running'),
    HealthCheckService::STATE_WARNING => __('Running, last run had problems'),
    HealthCheckService::STATE_STALE => __('Stopped'),
    HealthCheckService::STATE_STUCK => __('Stuck'),
    HealthCheckService::STATE_NEVER => __('Never ran'),
    HealthCheckService::STATE_DISABLED => __('Not watched'),
];
$stateClasses = [
    HealthCheckService::STATE_OK => 'is-ok',
    HealthCheckService::STATE_WARNING => 'is-unknown',
    HealthCheckService::STATE_DISABLED => 'is-unknown',
];
$stateIcons = [
    HealthCheckService::STATE_OK => 'fa-check-circle',
    HealthCheckService::STATE_WARNING => 'fa-exclamation-triangle',
    HealthCheckService::STATE_DISABLED => 'fa-eye-slash',
];
$statusNames = [
    JobRun::STATUS_RUNNING => __('running'),
    JobRun::STATUS_SUCCESS => __('success'),
    JobRun::STATUS_PARTIAL => __('partial'),
    JobRun::STATUS_FAILED => __('failed'),
    JobRun::STATUS_CANCELLED => __('cancelled'),
];
$statusBadges = [
    JobRun::STATUS_RUNNING => 'badge-info',
    JobRun::STATUS_SUCCESS => 'badge-success',
    JobRun::STATUS_PARTIAL => 'badge-warning',
    JobRun::STATUS_FAILED => 'badge-danger',
    JobRun::STATUS_CANCELLED => 'badge-secondary',
];

// The stored labels are English; the jobs the site ships with are translated here.
$knownLabels = [
    'collector' => __('Chest collector'),
    'daily_maintenance' => __('Daily maintenance'),
    'database_backup' => __('Database backup'),
    'tournament_import' => __('Tournament upload'),
];
$labels = [];
foreach ($report['jobs'] as $job) {
    $labels[$job['job']] = $knownLabels[$job['job']] ?? $job['label'];
}

// 95 -> "1 h 35 min", 3000 -> "2 d 2 h".
$duration = function (?int $minutes): string {
    if ($minutes === null) {
        return '—';
    }
    if ($minutes < 60) {
        return __('{0} min', $minutes);
    }
    if ($minutes < 1440) {
        return __('{0} h {1} min', intdiv($minutes, 60), $minutes % 60);
    }

    return __('{0} d {1} h', intdiv($minutes, 1440), intdiv($minutes % 1440, 60));
};

// The summary each job writes, as one readable line.
$summaryText = function (?array $summary): string {
    if (!$summary) {
        return '';
    }
    $parts = [];
    foreach ($summary as $key => $value) {
        if ($key === 'failures' && is_array($value)) {
            foreach ($value as $failure) {
                $parts[] = is_array($failure)
                    ? trim(($failure['account'] ?? '') . ' / ' . ($failure['profile'] ?? ''), ' /') . ' — ' . ($failure['reason'] ?? '?')
                    : (string)$failure;
            }
        } elseif (is_array($value)) {
            $flat = array_filter($value, 'is_scalar');
            if ($flat === []) {
                continue;
            }
            $parts[] = $key . ': ' . implode(', ', array_is_list($flat)
                ? $flat
                : array_map(fn ($k, $v) => $k . '=' . $v, array_keys($flat), $flat));
        } elseif (is_scalar($value) && $value !== '') {
            $parts[] = $key . ': ' . (is_bool($value) ? ($value ? 'yes' : 'no') : $value);
        }
    }

    return implode(' · ', $parts);
};

$appsScript = <<<'JS'
/**
 * TBOps monitor.
 *
 * 1. Project Settings > Script Properties: add MONITOR_KEY with the key shown
 *    on the Monitoring page.
 * 2. Triggers: add a time-driven trigger for checkHealth, every 30 minutes.
 *
 * An e-mail goes out only when something changes: a job stops, or comes back.
 */
const HEALTH_URL = __HEALTH_URL__;
const ALERT_EMAIL = Session.getEffectiveUser().getEmail();
// Also warn when a job is alive but its last run failed or was partial.
const ALERT_ON_WARNING = true;
// Failed requests in a row before the site itself counts as down.
const SITE_DOWN_AFTER = 2;

function checkHealth() {
  const props = PropertiesService.getScriptProperties();
  const previous = JSON.parse(props.getProperty('LAST_STATES') || '{}');
  const states = Object.assign({}, previous);
  const details = {};

  let failure = null;
  try {
    const response = UrlFetchApp.fetch(HEALTH_URL, {
      headers: { 'X-Monitor-Key': props.getProperty('MONITOR_KEY') || '' },
      muteHttpExceptions: true,
    });
    if (response.getResponseCode() !== 200) {
      failure = 'HTTP ' + response.getResponseCode() + ': ' + response.getContentText().slice(0, 300);
    } else {
      JSON.parse(response.getContentText()).jobs.forEach(function (job) {
        if (job.state === 'disabled') {
          delete states[job.job];
          return;
        }
        const bad = !job.ok || (ALERT_ON_WARNING && job.state === 'warning');
        states[job.job] = bad ? job.state : 'ok';
        const run = job.last_run || {};
        details[job.job] = job.label + ': ' + job.state
          + ' | last good run: ' + (job.last_success_at || 'never')
          + (job.minutes_since_success !== null ? ' (' + job.minutes_since_success + ' min ago, limit ' + job.max_silence_minutes + ')' : '')
          + ' | last run: ' + (run.status || '-') + ' on ' + (run.host || '-')
          + (run.summary ? ' | ' + JSON.stringify(run.summary).slice(0, 400) : '');
      });
    }
  } catch (e) {
    failure = String(e);
  }

  const fails = failure ? Number(props.getProperty('FAILS') || 0) + 1 : 0;
  props.setProperty('FAILS', String(fails));
  states.site = fails >= SITE_DOWN_AFTER ? 'down' : 'ok';
  if (failure) {
    details.site = 'Site: ' + failure;
  }

  const changed = Object.keys(states).filter(function (job) {
    return states[job] !== (previous[job] || 'ok');
  });
  if (changed.length > 0) {
    const problems = Object.keys(states).filter(function (job) { return states[job] !== 'ok'; });
    const lines = changed.map(function (job) {
      return (states[job] === 'ok' ? 'RECOVERED  ' : 'PROBLEM    ') + (details[job] || job + ': ' + states[job]);
    });
    MailApp.sendEmail(
      ALERT_EMAIL,
      problems.length > 0 ? '[TBOps] Problem: ' + problems.join(', ') : '[TBOps] All jobs running again',
      lines.join('\n\n') + '\n\n' + HEALTH_URL
    );
  }
  props.setProperty('LAST_STATES', JSON.stringify(states));
}
JS;
$appsScript = str_replace('__HEALTH_URL__', json_encode($healthUrl, JSON_UNESCAPED_SLASHES), $appsScript);
?>
<div class="content-page-wrap maintenance-page">

    <div class="score-toolbar">
        <div class="score-title-group">
            <h1 class="score-title">
                <i class="fas fa-heartbeat text-primary"></i> <?= __('Monitoring') ?>
            </h1>
            <p class="cycle-subtitle">
                <?= __('Whether the chest collector, the maintenance, the database backup and the tournament uploads are running') ?>
            </p>
        </div>
        <div class="toolbar-actions">
            <?= $this->Html->link(
                '<i class="fas fa-sync-alt mr-1"></i>' . __('Check again'),
                ['action' => 'index'],
                ['class' => 'btn btn-outline-primary btn-sm', 'escape' => false]
            ) ?>
        </div>
    </div>

    <!-- Every job, as the external monitor sees it -->
    <div class="brand-section">
        <div class="brand-section-head">
            <h2><i class="fas fa-stethoscope text-primary"></i> <?= __('Current status') ?></h2>
            <p class="section-hint">
                <?= __('A job is running when it had a good run within its limit. A run that found no chests still counts: this watches the process, not the data.') ?>
            </p>
        </div>

        <?php foreach ($report['jobs'] as $job): ?>
            <?php
            $state = $job['state'];
            $run = $job['last_run'];
            ?>
            <div class="cron-verdict <?= $stateClasses[$state] ?? 'is-missing' ?>">
                <i class="fas <?= $stateIcons[$state] ?? 'fa-times-circle' ?>"></i>
                <div>
                    <strong><?= h($labels[$job['job']]) ?> — <?= h($stateNames[$state] ?? $state) ?></strong>
                    <p class="mb-0">
                        <?php if ($job['last_success_at'] !== null): ?>
                            <?= __(
                                'Last good run {0} ago (limit {1}).',
                                $duration($job['minutes_since_success']),
                                $duration($job['max_silence_minutes'])
                            ) ?>
                        <?php else: ?>
                            <?= __('No good run on record (limit {0}).', $duration($job['max_silence_minutes'])) ?>
                        <?php endif; ?>
                        <?php if ($run !== null): ?>
                            <?= __(
                                'Last run: {0} on {1}, started {2} UTC.',
                                h($statusNames[$run['status']] ?? $run['status']),
                                h($run['host'] ?? '—'),
                                h(substr(str_replace('T', ' ', (string)$run['started_at']), 0, 16))
                            ) ?>
                        <?php endif; ?>
                    </p>
                    <?php if ($run !== null && $summaryText($run['summary']) !== ''): ?>
                        <p class="mb-0"><small class="text-muted"><?= h($summaryText($run['summary'])) ?></small></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- How long each job may stay silent -->
    <?= $this->Form->create(null) ?>
    <?= $this->Form->hidden('section', ['value' => 'jobs']) ?>
    <div class="brand-section">
        <div class="brand-section-head">
            <h2><i class="fas fa-sliders-h text-primary"></i> <?= __('Limits') ?></h2>
            <p class="section-hint">
                <?= __('How long each job may go without a good run before the monitor raises the alarm. Leave room for one missed run: a collector scheduled every hour does well with 180 minutes.') ?>
            </p>
        </div>

        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th><?= __('Job') ?></th>
                        <th><?= __('Watched') ?></th>
                        <th><?= __('Limit (minutes)') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monitoredJobs as $monitored): ?>
                        <tr>
                            <td>
                                <strong><?= h($knownLabels[$monitored->job] ?? $monitored->label) ?></strong>
                                <br><small class="text-muted"><code><?= h($monitored->job) ?></code></small>
                            </td>
                            <td>
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="job-enabled-<?= (int)$monitored->id ?>"
                                        name="jobs[<?= (int)$monitored->id ?>][enabled]" value="1" <?= $monitored->enabled ? 'checked' : '' ?>>
                                    <label class="custom-control-label" for="job-enabled-<?= (int)$monitored->id ?>"></label>
                                </div>
                            </td>
                            <td style="max-width: 160px;">
                                <input type="number" class="form-control form-control-sm" min="5" max="20160" step="5" required
                                    name="jobs[<?= (int)$monitored->id ?>][max_silence_minutes]"
                                    value="<?= (int)$monitored->max_silence_minutes ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="event-form-actions">
            <?= $this->Form->button(
                '<i class="fas fa-save mr-1"></i>' . __('Save limits'),
                ['class' => 'btn btn-primary', 'escapeTitle' => false]
            ) ?>
        </div>
    </div>
    <?= $this->Form->end() ?>

    <!-- History -->
    <div class="brand-section">
        <div class="brand-section-head">
            <h2><i class="fas fa-history text-primary"></i> <?= __('Recent runs') ?></h2>
            <p class="section-hint">
                <?= __('The last 50 runs, newest first. History older than {0} days is deleted by the daily maintenance.', $retentionDays) ?>
            </p>
        </div>

        <div class="mb-2">
            <?= $this->Html->link(__('All'), ['action' => 'index'], [
                'class' => 'btn btn-sm ' . ($jobFilter === '' ? 'btn-primary' : 'btn-outline-primary'),
            ]) ?>
            <?php foreach ($labels as $key => $label): ?>
                <?= $this->Html->link($label, ['action' => 'index', '?' => ['job' => $key]], [
                    'class' => 'btn btn-sm ' . ($jobFilter === $key ? 'btn-primary' : 'btn-outline-primary'),
                ]) ?>
            <?php endforeach; ?>
        </div>

        <?php if (count($runs) === 0): ?>
            <p class="text-muted mb-0"><?= __('No run recorded yet.') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th><?= __('Job') ?></th>
                            <th><?= __('Status') ?></th>
                            <th><?= __('Started (UTC)') ?></th>
                            <th><?= __('Took') ?></th>
                            <th><?= __('Host') ?></th>
                            <th><?= __('Summary') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($runs as $run): ?>
                            <tr>
                                <td><?= h($labels[$run->job] ?? $run->job) ?></td>
                                <td>
                                    <span class="badge <?= $statusBadges[$run->status] ?? 'badge-secondary' ?>">
                                        <?= h($statusNames[$run->status] ?? $run->status) ?>
                                    </span>
                                </td>
                                <td class="text-nowrap"><?= h($run->started_at->format('Y-m-d H:i')) ?></td>
                                <td class="text-nowrap">
                                    <?php if ($run->finished_at !== null): ?>
                                        <?php $seconds = max(0, $run->finished_at->getTimestamp() - $run->started_at->getTimestamp()); ?>
                                        <?= $seconds < 60 ? __('{0} s', $seconds) : h($duration(intdiv($seconds, 60))) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?= h($run->host ?? '—') ?></td>
                                <td><small><?= h($summaryText($run->summary)) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- The external monitor -->
    <div class="brand-section">
        <div class="brand-section-head">
            <h2><i class="fas fa-satellite-dish text-primary"></i> <?= __('External monitor') ?></h2>
            <p class="section-hint">
                <?= __('The alarm has to come from outside the server: if the server goes down, nothing on it can send one. A Google Apps Script reads the address below and e-mails you when a job stops or comes back.') ?>
            </p>
        </div>

        <ul class="cron-facts">
            <li>
                <i class="fas fa-link"></i>
                <?= __('Address') ?> <code><?= h($healthUrl) ?></code>
            </li>
            <li>
                <i class="fas fa-key"></i>
                <?php if ($monitorKey !== null): ?>
                    <?= __('Key, sent in the {0} header:', '<code>X-Monitor-Key</code>') ?>
                    <code id="monitor-key"><?= h($monitorKey) ?></code>
                <?php else: ?>
                    <span class="text-danger"><?= __('No key yet: the address answers 503 until one is created.') ?></span>
                <?php endif; ?>
            </li>
            <li>
                <i class="fas fa-lock"></i>
                <?= __('The key only reads this status. It cannot change anything on the site.') ?>
            </li>
        </ul>

        <?= $this->Form->create(null) ?>
        <?= $this->Form->hidden('section', ['value' => 'key']) ?>
        <?= $this->Form->button(
            '<i class="fas fa-redo mr-1"></i>' . ($monitorKey !== null ? __('Create a new key') : __('Create key')),
            ['class' => 'btn btn-outline-danger btn-sm', 'escapeTitle' => false]
                + ($monitorKey !== null
                    ? ['confirm' => __('Create a new key? The monitor stops working until you paste the new one in it.')]
                    : [])
        ) ?>
        <?= $this->Form->end() ?>

        <p class="cron-label mt-3"><?= __('The script, ready to paste into a new project at script.google.com:') ?></p>
        <pre class="cron-code" id="apps-script"><?= h($appsScript) ?></pre>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="apps-script-copy">
            <i class="fas fa-copy mr-1"></i><?= __('Copy') ?>
        </button>
    </div>
</div>

<?php $this->start('script'); ?>
<script>
    (function () {
        var copy = document.getElementById('apps-script-copy');
        if (!copy) {
            return;
        }
        copy.addEventListener('click', function () {
            var text = document.getElementById('apps-script').textContent;
            var done = function () {
                copy.innerHTML = '<i class="fas fa-check mr-1"></i><?= __('Copied') ?>';
                setTimeout(function () {
                    copy.innerHTML = '<i class="fas fa-copy mr-1"></i><?= __('Copy') ?>';
                }, 1800);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done);
                return;
            }
            var area = document.createElement('textarea');
            area.value = text;
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            document.body.removeChild(area);
            done();
        });
    })();
</script>
<?php $this->end(); ?>
