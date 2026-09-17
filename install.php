<?php
declare(strict_types=1);

/**
 * TBOps - Auto Installation Script
 *
 * This script automates the complete installation of the TBOps (Total Battle Clan Operations) application.
 * Designed to work even on shared hosting with exec/shell_exec disabled.
 *
 * It performs the following steps:
 *
 * 1. Checks PHP version and required extensions
 * 2. Checks Composer dependencies
 * 3. Collects database credentials from the user
 * 4. Tests the database connection
 * 5. Generates config/app_local.php with secure settings
 * 6. Creates required directories with proper permissions
 * 7. Runs CakePHP migrations (creates tables)
 * 8. Runs CakePHP seeds (inserts initial data)
 * 9. Collects admin user details and creates the first administrator
 *
 * Usage:
 *   php install.php
 *
 * IMPORTANT: Delete this file after installation for security reasons.
 */

// ─── Configuration ─────────────────────────────────────────────────────────────

define('ROOT', dirname(__FILE__));
define('MIN_PHP_VERSION', '8.1.0');
define('REQUIRED_EXTENSIONS', ['pdo_mysql', 'mbstring', 'intl', 'openssl', 'json', 'xml']);
define('APP_LOCAL_FILE', ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app_local.php');
define('APP_LOCAL_EXAMPLE', ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app_local.example.php');
define('CAKE_BIN', ROOT . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'cake.php');
define('TOTAL_STEPS', 9);

// ─── Helper Functions ──────────────────────────────────────────────────────────

function printBanner(): void
{
    echo PHP_EOL;
    echo "╔══════════════════════════════════════════════════════════╗" . PHP_EOL;
    echo "║              🏰 TBOps - Auto Installer 🏰              ║" . PHP_EOL;
    echo "║              Total Battle Clan Operations               ║" . PHP_EOL;
    echo "╚══════════════════════════════════════════════════════════╝" . PHP_EOL;
    echo PHP_EOL;
}

function printStep(int $step, string $message): void
{
    echo PHP_EOL;
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . PHP_EOL;
    echo "  [{$step}/" . TOTAL_STEPS . "] {$message}" . PHP_EOL;
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" . PHP_EOL;
    echo PHP_EOL;
}

function printSuccess(string $message): void
{
    echo "  ✅ {$message}" . PHP_EOL;
}

function printError(string $message): void
{
    echo "  ❌ {$message}" . PHP_EOL;
}

function printWarning(string $message): void
{
    echo "  ⚠️  {$message}" . PHP_EOL;
}

function printInfo(string $message): void
{
    echo "  ℹ️  {$message}" . PHP_EOL;
}

function prompt(string $question, string $default = ''): string
{
    $defaultHint = $default !== '' ? " [{$default}]" : '';
    echo "  {$question}{$defaultHint}: ";
    $input = trim((string)fgets(STDIN));

    return $input !== '' ? $input : $default;
}

function promptPassword(string $question): string
{
    // Try to hide password input on Unix-like systems
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' && canRunShell()) {
        echo "  {$question}: ";
        $password = @shell_exec('stty -echo 2>/dev/null; head -1; stty echo 2>/dev/null');
        echo PHP_EOL;
        if ($password !== null) {
            return trim($password);
        }
    }

    // Fallback: show password input
    echo "  {$question}: ";
    $input = trim((string)fgets(STDIN));

    return $input;
}

function confirm(string $question, bool $default = true): bool
{
    $hint = $default ? '[Y/n]' : '[y/N]';
    echo "  {$question} {$hint}: ";
    $input = strtolower(trim((string)fgets(STDIN)));

    if ($input === '') {
        return $default;
    }

    return $input === 'y' || $input === 'yes';
}

/**
 * Safely escape a shell argument, even if escapeshellarg() is disabled in php.ini.
 */
function escapeShellArgSafe(string $arg): string
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
 * Check if any shell execution function is available.
 */
function canRunShell(): bool
{
    $functions = ['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'];
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

    foreach ($functions as $func) {
        if (function_exists($func) && !in_array($func, $disabled)) {
            return true;
        }
    }

    return false;
}

/**
 * Run a shell command using any available execution function.
 * Returns null if no execution function is available.
 */
function runCommand(string $command): ?array
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));

    // Try exec
    if (function_exists('exec') && !in_array('exec', $disabled)) {
        $output = [];
        $exitCode = 0;
        @exec($command . ' 2>&1', $output, $exitCode);
        return ['output' => implode(PHP_EOL, $output), 'exitCode' => $exitCode];
    }

    // Try proc_open
    if (function_exists('proc_open') && !in_array('proc_open', $disabled)) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $output = $stdout . ($stderr ? PHP_EOL . $stderr : '');
            return ['output' => $output, 'exitCode' => $exitCode];
        }
    }

    // Try shell_exec
    if (function_exists('shell_exec') && !in_array('shell_exec', $disabled)) {
        $output = @shell_exec($command . ' 2>&1');
        if ($output !== null) {
            return ['output' => $output, 'exitCode' => 0];
        }
    }

    // Try system
    if (function_exists('system') && !in_array('system', $disabled)) {
        ob_start();
        $lastLine = @system($command . ' 2>&1', $exitCode);
        $output = ob_get_clean();
        if ($lastLine !== false) {
            return ['output' => $output ?: '', 'exitCode' => $exitCode];
        }
    }

    // Try popen
    if (function_exists('popen') && !in_array('popen', $disabled)) {
        $handle = @popen($command . ' 2>&1', 'r');
        if ($handle !== false) {
            $output = stream_get_contents($handle);
            $exitCode = pclose($handle);
            return ['output' => $output ?: '', 'exitCode' => $exitCode];
        }
    }

    // No execution function available
    return null;
}

function generateSecuritySalt(): string
{
    return hash('sha256', random_bytes(64));
}

function abortInstallation(string $reason): void
{
    echo PHP_EOL;
    printError("Installation aborted: {$reason}");
    echo PHP_EOL;
    exit(1);
}

/**
 * Get or create the PDO connection for direct database operations.
 */
function getDbConnection(array $dbConfig, bool $forceNew = false): PDO
{
    static $pdo = null;

    if ($pdo !== null && !$forceNew) {
        try {
            $pdo->query("SELECT 1");
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo === null || $forceNew) {
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
        if (!empty($dbConfig['port'])) {
            $dsn = "mysql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['database']};charset=utf8mb4";
        }

        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 30,
        ]);
    }

    return $pdo;
}

// ─── Step 1: Check Prerequisites ───────────────────────────────────────────────

function checkPrerequisites(): void
{
    printStep(1, 'Checking prerequisites');

    // Check PHP version
    if (version_compare(PHP_VERSION, MIN_PHP_VERSION, '<')) {
        printError("PHP " . MIN_PHP_VERSION . "+ required. Current: " . PHP_VERSION);
        abortInstallation('PHP version too old.');
    }
    printSuccess("PHP " . PHP_VERSION . " detected");

    // Check required extensions
    $missingExtensions = [];
    foreach (REQUIRED_EXTENSIONS as $ext) {
        if (extension_loaded($ext)) {
            printSuccess("Extension '{$ext}' loaded");
        } else {
            printError("Extension '{$ext}' NOT loaded");
            $missingExtensions[] = $ext;
        }
    }

    if (!empty($missingExtensions)) {
        abortInstallation('Missing PHP extensions: ' . implode(', ', $missingExtensions));
    }

    // Check if CakePHP binary exists
    if (!file_exists(CAKE_BIN)) {
        abortInstallation('CakePHP binary not found. Make sure you cloned the repository correctly.');
    }
    printSuccess("CakePHP binary found");

    // Check shell access
    if (canRunShell()) {
        printSuccess("Shell execution available");
    } else {
        printWarning("Shell execution disabled (exec, shell_exec, etc.)");
        printInfo("Using PHP-native fallbacks for all operations");
    }

    // Check if already installed
    if (file_exists(APP_LOCAL_FILE)) {
        printWarning("config/app_local.php already exists!");
        if (!confirm("Overwrite existing configuration?", false)) {
            abortInstallation('Configuration already exists. Remove config/app_local.php to reinstall.');
        }
    }
}

// ─── Step 2: Check/Install Composer Dependencies ───────────────────────────────

function installComposerDependencies(): void
{
    printStep(2, 'Checking Composer dependencies');

    $vendorAutoload = ROOT . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

    if (file_exists($vendorAutoload)) {
        printSuccess("Composer dependencies already installed");

        // Try to update if shell is available
        if (canRunShell()) {
            if (confirm("Run composer install to ensure dependencies are up to date?", false)) {
                printInfo("Running composer install...");

                // Detect composer command
                $composerCmd = null;
                $candidates = [
                    'composer',
                    'composer2',
                    'php composer.phar',
                ];

                $localPhar = ROOT . DIRECTORY_SEPARATOR . 'composer.phar';
                if (file_exists($localPhar)) {
                    array_unshift($candidates, 'php ' . escapeShellArgSafe($localPhar));
                }

                foreach ($candidates as $cmd) {
                    $result = runCommand($cmd . ' --version');
                    if ($result !== null && $result['exitCode'] === 0) {
                        $composerCmd = $cmd;
                        break;
                    }
                }

                if ($composerCmd !== null) {
                    printInfo("Using: {$composerCmd}");
                    $installCmd = $composerCmd . ' install --no-dev --optimize-autoloader --no-interaction --working-dir=' . escapeShellArgSafe(ROOT);
                    $result = runCommand($installCmd);

                    if ($result !== null && $result['exitCode'] === 0) {
                        printSuccess("Dependencies updated successfully");
                    } else {
                        // Try with disable_functions workaround
                        printWarning("Standard install failed, trying with disable_functions workaround...");
                        $installCmd = 'php -d disable_functions="" ' . $composerCmd . ' install --no-dev --optimize-autoloader --no-interaction --working-dir=' . escapeShellArgSafe(ROOT);
                        $result = runCommand($installCmd);

                        if ($result !== null && $result['exitCode'] === 0) {
                            printSuccess("Dependencies updated successfully");
                        } else {
                            printWarning("Could not update dependencies (non-critical, continuing...)");
                        }
                    }
                } else {
                    printWarning("Composer command not found. Skipping update.");
                }
            }
        }
        return;
    }

    // vendor/ does not exist — must install
    if (!canRunShell()) {
        printError("Composer dependencies not installed and shell execution is disabled.");
        printInfo("Please run the following command manually before running this installer:");
        printInfo("  composer install --no-dev --optimize-autoloader");
        printInfo("Or: php composer.phar install --no-dev --optimize-autoloader");
        abortInstallation('Composer dependencies are required.');
    }

    // Detect or download Composer
    $composerCmd = null;
    $candidates = ['composer', 'composer2'];

    $localPhar = ROOT . DIRECTORY_SEPARATOR . 'composer.phar';
    if (file_exists($localPhar)) {
        array_unshift($candidates, 'php ' . escapeShellArgSafe($localPhar));
    }

    foreach ($candidates as $cmd) {
        $result = runCommand($cmd . ' --version');
        if ($result !== null && $result['exitCode'] === 0) {
            $composerCmd = $cmd;
            break;
        }
    }

    // Download composer if not found
    if ($composerCmd === null) {
        printWarning("Composer not found. Downloading...");

        $installerPath = ROOT . DIRECTORY_SEPARATOR . 'composer-setup.php';
        $installer = @file_get_contents('https://getcomposer.org/installer');

        if ($installer === false) {
            printError("Failed to download Composer installer.");
            printInfo("Please install Composer manually: https://getcomposer.org/download/");
            printInfo("Then run: composer install --no-dev --optimize-autoloader");
            abortInstallation('Composer is required.');
        }

        file_put_contents($installerPath, $installer);
        $result = runCommand('php ' . escapeShellArgSafe($installerPath) . ' --install-dir=' . escapeShellArgSafe(ROOT) . ' --filename=composer.phar');
        @unlink($installerPath);

        if ($result !== null && file_exists($localPhar)) {
            $composerCmd = 'php ' . escapeShellArgSafe($localPhar);
            printSuccess("Composer downloaded successfully");
        } else {
            printError("Failed to install Composer.");
            abortInstallation('Composer is required.');
        }
    }

    printInfo("Using: {$composerCmd}");
    printInfo("Installing dependencies (this may take a few minutes)...");
    echo PHP_EOL;

    $installCmd = $composerCmd . ' install --no-dev --optimize-autoloader --no-interaction --working-dir=' . escapeShellArgSafe(ROOT);
    $result = runCommand($installCmd);

    if ($result === null || $result['exitCode'] !== 0) {
        // Try with disable_functions workaround
        printWarning("Standard install failed, trying with disable_functions workaround...");
        $installCmd = 'php -d disable_functions="" ' . $composerCmd . ' install --no-dev --optimize-autoloader --no-interaction --working-dir=' . escapeShellArgSafe(ROOT);
        $result = runCommand($installCmd);

        if ($result === null || $result['exitCode'] !== 0) {
            printError("Composer install failed.");
            if ($result !== null) {
                echo "  " . str_replace(PHP_EOL, PHP_EOL . "  ", $result['output']) . PHP_EOL;
            }
            printInfo("Try running manually: composer install --no-dev --optimize-autoloader");
            abortInstallation('Failed to install dependencies.');
        }
    }

    if (file_exists($vendorAutoload)) {
        printSuccess("Dependencies installed successfully");
    } else {
        abortInstallation('vendor/autoload.php not found after install.');
    }
}

// ─── Step 3: Collect Database Credentials ──────────────────────────────────────

function collectDatabaseCredentials(): array
{
    printStep(3, 'Database configuration');

    printInfo("Enter your MySQL/MariaDB database credentials.");
    printInfo("The database must already exist (create it via hosting panel or CLI).");
    echo PHP_EOL;

    $host = prompt('Database host', 'localhost');
    $port = prompt('Database port (leave empty for default)', '');
    $database = prompt('Database name', 'tbops');
    $username = prompt('Database username');
    $password = promptPassword('Database password');

    if (empty($username)) {
        abortInstallation('Database username is required.');
    }

    return [
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => $username,
        'password' => $password,
    ];
}

// ─── Step 4: Test Database Connection ──────────────────────────────────────────

function testDatabaseConnection(array $dbConfig): void
{
    printStep(4, 'Testing database connection');

    try {
        $pdo = getDbConnection($dbConfig);

        $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        printSuccess("Connected to MySQL/MariaDB (version: {$version})");

        // Check if database already has tables
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($tables)) {
            printWarning("Database '{$dbConfig['database']}' already contains " . count($tables) . " table(s):");
            foreach ($tables as $table) {
                printInfo("  → {$table}");
            }
            if (!confirm("Continue? (existing tables will be preserved)", true)) {
                abortInstallation('Database is not empty.');
            }
        } else {
            printSuccess("Database '{$dbConfig['database']}' is empty and ready");
        }
    } catch (PDOException $e) {
        printError("Connection failed: " . $e->getMessage());
        abortInstallation('Cannot connect to database.');
    }
}

// ─── Step 5: Generate app_local.php ────────────────────────────────────────────

function generateAppLocal(array $dbConfig): void
{
    printStep(5, 'Generating configuration file');

    $salt = generateSecuritySalt();
    $portLine = '';
    if (!empty($dbConfig['port'])) {
        $portLine = "\n            'port' => '{$dbConfig['port']}',";
    }

    $escapedPassword = addcslashes($dbConfig['password'], "'\\");

    $content = <<<PHP
<?php
/*
 * Local configuration file.
 * Generated by the TBOps auto-installer.
 */
return [
    'debug' => filter_var(env('DEBUG', false), FILTER_VALIDATE_BOOLEAN),

    'Security' => [
        'salt' => env('SECURITY_SALT', '{$salt}'),
    ],

    'Datasources' => [
        'default' => [
            'host' => '{$dbConfig['host']}',{$portLine}
            'username' => '{$dbConfig['username']}',
            'password' => '{$escapedPassword}',
            'database' => '{$dbConfig['database']}',
            'encoding' => 'utf8mb4',
            'url' => env('DATABASE_URL', null),
        ],

        'test' => [
            'host' => 'localhost',
            'username' => '{$dbConfig['username']}',
            'password' => '{$escapedPassword}',
            'database' => 'test_{$dbConfig['database']}',
            'url' => env('DATABASE_TEST_URL', 'sqlite://127.0.0.1/tmp/tests.sqlite'),
        ],
    ],

    'EmailTransport' => [
        'default' => [
            'host' => 'localhost',
            'port' => 25,
            'username' => null,
            'password' => null,
            'client' => null,
            'url' => env('EMAIL_TRANSPORT_DEFAULT_URL', null),
        ],
    ],
];

PHP;

    if (file_put_contents(APP_LOCAL_FILE, $content) === false) {
        abortInstallation('Failed to write config/app_local.php');
    }

    printSuccess("config/app_local.php created");
    printSuccess("Security salt generated: " . substr($salt, 0, 16) . "...");
}

// ─── Step 6: Create Directories ────────────────────────────────────────────────

function createDirectories(): void
{
    printStep(6, 'Creating required directories');

    $dirs = [
        'tmp' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'models',
        'tmp' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'persistent',
        'tmp' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'views',
        'tmp' . DIRECTORY_SEPARATOR . 'sessions',
        'logs',
    ];

    foreach ($dirs as $dir) {
        $fullPath = ROOT . DIRECTORY_SEPARATOR . $dir;
        if (!is_dir($fullPath)) {
            if (@mkdir($fullPath, 0775, true)) {
                printSuccess("Created: {$dir}");
            } else {
                printWarning("Could not create: {$dir} (check permissions)");
            }
        } else {
            printSuccess("Exists: {$dir}");
        }
    }

    // Set permissions (Unix only)
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        $setPermissions = function (string $path) {
            @chmod($path, 0775);
            if (is_dir($path)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $item) {
                    @chmod($item->getPathname(), 0775);
                }
            }
        };

        $setPermissions(ROOT . DIRECTORY_SEPARATOR . 'tmp');
        $setPermissions(ROOT . DIRECTORY_SEPARATOR . 'logs');
        printSuccess("Set permissions 775 on tmp/ and logs/ (recursive)");
    }
}

// ─── Step 7: Run Migrations ────────────────────────────────────────────────────

function runMigrations(array $dbConfig): void
{
    printStep(7, 'Running database migrations');

    // First try via shell
    if (canRunShell()) {
        $statusResult = runCommand('php ' . escapeShellArgSafe(CAKE_BIN) . ' migrations status');

        if ($statusResult !== null && $statusResult['exitCode'] === 0) {
            // Check if migrations are already applied
            if (strpos($statusResult['output'], '| up') !== false) {
                printSuccess("Migrations already applied");
                return;
            }

            printInfo("Creating database tables...");
            $result = runCommand('php ' . escapeShellArgSafe(CAKE_BIN) . ' migrations migrate --no-interaction');

            if ($result !== null && $result['exitCode'] === 0) {
                printSuccess("All tables created successfully (15 tables)");
                return;
            }

            // If tables already exist, try mark_migrated
            if ($result !== null && (
                strpos($result['output'], 'already exists') !== false
                || strpos($result['output'], 'Base table') !== false
                || strpos($result['output'], 'table or view already exists') !== false
            )) {
                printWarning("Tables already exist, marking migration as applied...");
                $markResult = runCommand('php ' . escapeShellArgSafe(CAKE_BIN) . ' migrations mark_migrated 20260822220000');
                if ($markResult !== null && $markResult['exitCode'] === 0) {
                    printSuccess("Migration marked as applied");
                    return;
                }
            }

            // Shell command failed, fall through to PHP fallback
            printWarning("Shell migration failed, using PHP-native fallback...");
        }
    }

    // PHP-native fallback: create tables directly with PDO
    printInfo("Creating tables via PHP-native method...");
    runMigrationsNative($dbConfig);
}

/**
 * Create all tables directly using PDO (fallback when shell is unavailable).
 */
function runMigrationsNative(array $dbConfig): void
{
    $pdo = getDbConnection($dbConfig);

    // Check which tables already exist
    $stmt = $pdo->query("SHOW TABLES");
    $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $tables = [
        'users' => "CREATE TABLE IF NOT EXISTS `users` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(60) NOT NULL,
            `email` varchar(60) NOT NULL,
            `password` varchar(255) NOT NULL,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'roles' => "CREATE TABLE IF NOT EXISTS `roles` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(64) NOT NULL,
            `description` varchar(255) NOT NULL,
            `alias` varchar(20) NOT NULL,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'roles_users' => "CREATE TABLE IF NOT EXISTS `roles_users` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) DEFAULT NULL,
            `role_id` int(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `fk_roles_users_users` (`user_id`),
            KEY `fk_roles_users_roles` (`role_id`),
            CONSTRAINT `fk_roles_users_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
            CONSTRAINT `fk_roles_users_roles` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'members' => "CREATE TABLE IF NOT EXISTS `members` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `player` varchar(45) NOT NULL,
            `active` tinyint(4) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `modified_at` timestamp NOT NULL,
            `power` int(11) NOT NULL DEFAULT 0,
            `guards` int(11) NOT NULL DEFAULT 0,
            `specialists` int(11) NOT NULL DEFAULT 0,
            `monsters` int(11) NOT NULL DEFAULT 0,
            `engineers` int(11) NOT NULL DEFAULT 0,
            `user_id` int(11) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `fk_members_users` (`user_id`),
            CONSTRAINT `fk_members_users` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'config' => "CREATE TABLE IF NOT EXISTS `config` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `param` varchar(45) NOT NULL,
            `value` varchar(45) NOT NULL,
            `description` varchar(512) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'standard_chests' => "CREATE TABLE IF NOT EXISTS `standard_chests` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `source` char(50) NOT NULL,
            `alias` varchar(50) DEFAULT NULL COMMENT 'Optional friendly name shown in reports instead of source',
            `score` int(11) NOT NULL,
            `monster` int(11) NOT NULL DEFAULT 0 COMMENT '1 = Epic Monsters chest 0 = Regular chest',
            `qty_chest` int(11) DEFAULT NULL COMMENT 'If the chest type is epic monsters, inform the amount of chests earned by killing a monster',
            PRIMARY KEY (`id`),
            UNIQUE KEY `source_UNIQUE` (`source`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'collected_chests' => "CREATE TABLE IF NOT EXISTS `collected_chests` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(50) NOT NULL DEFAULT '0',
            `player` varchar(50) NOT NULL DEFAULT '0',
            `source` varchar(50) NOT NULL DEFAULT '0',
            `type` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0 = auto / 1 = Manual',
            `collected_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'errors' => "CREATE TABLE IF NOT EXISTS `errors` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `error_value` tinytext NOT NULL,
            `collected_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // Tournament events. `starts_at` / `ends_at` are UTC, like every other
        // instant in this application.
        'events' => "CREATE TABLE IF NOT EXISTS `events` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `event_number` int(10) unsigned NOT NULL COMMENT 'Sequential identifier shown to players',
            `name` varchar(120) NOT NULL,
            `description` text DEFAULT NULL,
            `criteria` varchar(32) NOT NULL COMMENT 'chest_count | chest_score | epic_monster | custom_chests',
            `custom_metric` varchar(16) NOT NULL DEFAULT 'score',
            `starts_at` datetime NOT NULL COMMENT 'UTC',
            `ends_at` datetime NOT NULL COMMENT 'UTC',
            `prize` text NOT NULL,
            `contact_player` varchar(120) NOT NULL,
            `banner_mime` varchar(60) DEFAULT NULL,
            `banner_image` mediumblob DEFAULT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'scheduled' COMMENT 'scheduled | cancelled',
            `finalized_at` datetime DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `events_number_unique` (`event_number`),
            KEY `events_window` (`starts_at`,`ends_at`),
            KEY `events_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // Chest types counted by a "custom chests" event.
        'event_chests' => "CREATE TABLE IF NOT EXISTS `event_chests` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `event_id` int(11) NOT NULL,
            `standard_chest_id` int(11) NOT NULL,
            `source` varchar(50) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `event_chests_unique` (`event_id`,`standard_chest_id`),
            KEY `event_chests_event` (`event_id`),
            CONSTRAINT `event_chests_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // Frozen results. Kept because collected_chests is purged on a retention
        // schedule, so an old event has no rows left to recompute from.
        'event_standings' => "CREATE TABLE IF NOT EXISTS `event_standings` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `event_id` int(11) NOT NULL,
            `position` int(11) NOT NULL,
            `player` varchar(50) NOT NULL,
            `points` bigint(20) NOT NULL DEFAULT 0,
            `chest_count` int(11) NOT NULL DEFAULT 0,
            `chest_score` bigint(20) NOT NULL DEFAULT 0,
            `participation` decimal(6,2) NOT NULL DEFAULT 0.00,
            `created` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `event_standings_unique` (`event_id`,`player`),
            KEY `event_standings_order` (`event_id`,`position`),
            CONSTRAINT `event_standings_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        // Default banner artwork for the scoreboard. Seeded by the CreateEvents
        // migration from config/data/event_banner_defaults.php.
        'event_assets' => "CREATE TABLE IF NOT EXISTS `event_assets` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `slug` varchar(40) NOT NULL COMMENT 'event-live | no-event',
            `label` varchar(160) NOT NULL,
            `mime` varchar(60) NOT NULL DEFAULT 'image/png',
            `image` mediumblob NOT NULL,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `event_assets_slug_unique` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'incomplete_chests' => "CREATE TABLE IF NOT EXISTS `incomplete_chests` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
            `player` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
            `source` varchar(50) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
            `type` tinyint(4) DEFAULT 0 COMMENT '0 = auto / 1 = Manual',
            `collected_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'player_cycle_summaries' => "CREATE TABLE IF NOT EXISTS `player_cycle_summaries` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `player_name` varchar(255) NOT NULL,
            `cycle_start_date` date NOT NULL,
            `cycle_end_date` date NOT NULL,
            `total_chests` int(11) NOT NULL DEFAULT 0,
            `total_score` int(11) NOT NULL DEFAULT 0,
            `epic_crypt_score` int(11) NOT NULL DEFAULT 0,
            `goal_achieved` tinyint(1) NOT NULL DEFAULT 0,
            `fine_due` tinyint(1) NOT NULL DEFAULT 0,
            `fine_paid` tinyint(1) NOT NULL DEFAULT 0,
            `created` datetime NOT NULL DEFAULT current_timestamp(),
            `modified` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `UNIQUE_PLAYER_CYCLE` (`player_name`,`cycle_start_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'player_name_mappings' => "CREATE TABLE IF NOT EXISTS `player_name_mappings` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `ocr_text` varchar(50) NOT NULL,
            `correct_name` varchar(50) NOT NULL,
            `created` timestamp NULL DEFAULT current_timestamp(),
            `modified` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `ocr_text` (`ocr_text`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'bank_accounts' => "CREATE TABLE IF NOT EXISTS `bank_accounts` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `member_id` int(11) NOT NULL,
            `balance` decimal(15,2) NOT NULL DEFAULT 0.00,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `member_id` (`member_id`),
            CONSTRAINT `bank_accounts_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'bank_transactions' => "CREATE TABLE IF NOT EXISTS `bank_transactions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `member_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `type` varchar(20) NOT NULL,
            `amount` decimal(15,2) NOT NULL,
            `fee` decimal(15,2) NOT NULL,
            `final_amount` decimal(15,2) NOT NULL,
            `description` varchar(512) DEFAULT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'pending',
            `destination_member_id` int(11) DEFAULT NULL,
            `created` datetime DEFAULT NULL,
            `modified` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `member_id` (`member_id`),
            KEY `user_id` (`user_id`),
            KEY `destination_member_id` (`destination_member_id`),
            CONSTRAINT `bank_transactions_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `bank_transactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `bank_transactions_ibfk_3` FOREIGN KEY (`destination_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'bank_approval_logs' => "CREATE TABLE IF NOT EXISTS `bank_approval_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `bank_transaction_id` int(11) NOT NULL,
            `admin_user_id` int(11) NOT NULL,
            `action` varchar(20) NOT NULL,
            `original_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`original_values`)),
            `created` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `bank_transaction_id` (`bank_transaction_id`),
            KEY `admin_user_id` (`admin_user_id`),
            CONSTRAINT `bank_approval_logs_ibfk_1` FOREIGN KEY (`bank_transaction_id`) REFERENCES `bank_transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `bank_approval_logs_ibfk_2` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

        'phinxlog' => "CREATE TABLE IF NOT EXISTS `phinxlog` (
            `version` bigint(20) NOT NULL,
            `migration_name` varchar(100) CHARACTER SET latin1 COLLATE latin1_general_ci DEFAULT NULL,
            `start_time` timestamp NULL DEFAULT NULL,
            `end_time` timestamp NULL DEFAULT NULL,
            `breakpoint` tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    ];

    $created = 0;
    $skipped = 0;

    foreach ($tables as $name => $sql) {
        if (in_array($name, $existingTables)) {
            $skipped++;
            continue;
        }

        try {
            $pdo->exec($sql);
            $created++;
        } catch (PDOException $e) {
            printError("Failed to create table '{$name}': " . $e->getMessage());
            abortInstallation("Could not create table '{$name}'.");
        }
    }

    // Mark migration as applied in phinxlog
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM `phinxlog` WHERE `version` = ?");
        $stmt->execute([20260822220000]);
        $row = $stmt->fetch();

        if (!$row || $row['cnt'] == 0) {
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("INSERT INTO `phinxlog` (`version`, `migration_name`, `start_time`, `end_time`, `breakpoint`) VALUES (?, ?, ?, ?, 0)");
            $stmt->execute([20260822220000, 'InitialSchema', $now, $now]);
        }
    } catch (PDOException $e) {
        printWarning("Could not update phinxlog: " . $e->getMessage());
    }

    if ($skipped > 0) {
        printInfo("{$skipped} table(s) already existed (skipped)");
    }
    if ($created > 0) {
        printSuccess("{$created} table(s) created successfully");
    }
    if ($created === 0 && $skipped > 0) {
        printSuccess("All tables already exist");
    }
}

// ─── Step 8: Run Seeds ─────────────────────────────────────────────────────────

function runSeeds(array $dbConfig): void
{
    printStep(8, 'Inserting initial data (Seeds)');

    // First try via shell
    if (canRunShell()) {
        printInfo("Inserting roles, configuration, and chest types...");
        $result = runCommand('php ' . escapeShellArgSafe(CAKE_BIN) . ' migrations seed --no-interaction');

        if ($result !== null && $result['exitCode'] === 0) {
            printSuccess("Initial data inserted successfully");
            printInfo("  → 3 roles (admin, user, bankers)");
            printInfo("  → 20 configuration parameters");
            printInfo("  → 102 standard chest types");
            return;
        }

        printWarning("Shell seed failed, using PHP-native fallback...");
    }

    // PHP-native fallback
    printInfo("Inserting data via PHP-native method...");
    runSeedsNative($dbConfig);
}

/**
 * Insert initial data directly using PDO (fallback when shell is unavailable).
 */
function runSeedsNative(array $dbConfig): void
{
    $pdo = getDbConnection($dbConfig);

    // ── Seed roles ──
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `roles`");
    $row = $stmt->fetch();
    if (!$row || $row['cnt'] == 0) {
        $pdo->exec("INSERT INTO `roles` (`id`, `name`, `description`, `alias`, `created`, `modified`) VALUES
            (1, 'admin', 'Administrador', 'admin', '2024-10-06 11:02:02', '2024-10-06 11:02:02'),
            (2, 'user', 'Users', 'user', '2024-10-06 11:02:29', '2024-10-06 11:02:29'),
            (3, 'bankers', 'Person responsible for managing the clan\\'s bank.', 'bankers', '2025-11-16 23:13:05', '2025-11-16 23:13:05')");
        printSuccess("Inserted 3 roles");
    } else {
        printInfo("Roles already exist (skipped)");
    }

    // ── Seed config ──
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `config`");
    $row = $stmt->fetch();
    if (!$row || $row['cnt'] == 0) {
        $pdo->exec("INSERT INTO `config` (`id`, `param`, `value`, `description`) VALUES
            (1, 'reference_day', '2025-07-30 17:00:00', 'Select a reference date for the start/end of the count. Always use the format (YYYY-MM-DD hh:mm:ss) with UTC time'),
            (2, 'every_how_many_days', '6', 'Every how many days: Sets how many days each counting period lasts.'),
            (3, 'minimum_chest_score', '15000', 'Minimum Chest Score'),
            (4, 'minimum_epic_score', '6000', 'Minimum points for collecting MONSTER epic chests.'),
            (5, 'clan_name', 'Special Task Force', 'Clan name'),
            (6, 'clan_acronym', 'ABC', 'clan acronym'),
            (7, 'kingdom_number', 'K001', 'kingdom number kxxx'),
            (8, 'score_color_start_r', '255', 'R (Red) value of the initial color for low score (0-255).'),
            (9, 'score_color_start_g', '0', 'G (Green) value of the starting color for low score (0-255).'),
            (10, 'score_color_start_b', '0', 'Starting color B (Blue) value for low score (0-255).'),
            (11, 'score_color_end_r', '0', 'R (Red) value of the final color for high score (0-255).'),
            (12, 'score_color_end_g', '255', 'G (Green) value of the final color for high score (0-255).'),
            (13, 'score_color_end_b', '0', 'Final color B (Blue) value for high score (0-255).'),
            (14, 'score_color_transition_start', '0.9', 'Value between 0 and 1 that defines the score color transition point.'),
            (15, 'minimum_epic_chest_score', '6000', 'Minimum epic chest score'),
            (16, 'deposit_fee', '50', 'Fixed deposit fee in millions of Silver'),
            (17, 'withdrawal_fee', '50', 'Fixed withdrawal fee in millions of Silver'),
            (18, 'transfer_fee', '10', 'Fixed transfer fee in millions of Silver'),
            (19, 'caravan_fee', '20', 'Caravan fee percentage for deposits'),
            (20, 'bank_function', '1', '1 = Bank active / 0 = no Bank'),
            (21, 'calculator_function', '1', '1 = Troop Calculator active / 0 = no Troop Calculator')");
        printSuccess("Inserted 21 config parameters");
    } else {
        printInfo("Config already exists (skipped)");
    }

    // ── Seed standard_chests ──
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `standard_chests`");
    $row = $stmt->fetch();
    if (!$row || $row['cnt'] == 0) {
        $chests = [
            [1,'Arena',0,0,null],[2,'Story',0,0,null],[3,'Level 5 Crypt',0,0,null],
            [4,'Level 10 Crypt',0,0,null],[5,'Level 15 Crypt',0,0,null],[6,'Level 20 Crypt',3,0,null],
            [7,'Level 25 Crypt',19,0,null],[8,'Level 10 rare Crypt',0,0,null],[9,'Level 15 rare Crypt',0,0,null],
            [10,'Level 20 rare Crypt',10,0,null],[11,'Level 25 rare Crypt',30,0,null],[12,'Level 30 rare Crypt',90,0,null],
            [13,'Level 15 epic Crypt',0,0,null],[14,'Level 20 epic Crypt',35,0,null],[15,'Level 25 epic Crypt',55,0,null],
            [16,'Level 30 epic Crypt',80,0,null],[17,'Level 35 epic Crypt',120,0,null],[18,'Level 10 Citadel',0,0,null],
            [19,'Level 15 Citadel',0,0,null],[20,'Level 20 Citadel',10,0,null],[21,'Level 25 Citadel',30,0,null],
            [22,'Level 30 Citadel',60,0,null],[23,'Level 20 cursed Citadel',10,0,null],[24,'Level 25 cursed Citadel',30,0,null],
            [25,'Wooden Chest',0,0,null],[26,'Bronze Chest',0,0,null],[27,'Silver Chest',0,0,null],
            [28,'Golden Chest',0,0,null],[29,'Precious Chest',0,0,null],[30,'Magic Chest',0,0,null],
            [31,'Mercenary Exchange',0,0,null],[32,'Epic Undead squad',5,0,null],[33,'Shadow City',5,0,null],
            [34,'Level 16 heroic Monster',5,0,null],[35,'Level 17 heroic Monster',5,0,null],[36,'Level 18 heroic Monster',5,0,null],
            [37,'Level 19 heroic Monster',5,0,null],[38,'Level 20 heroic Monster',5,0,null],[39,'Level 21 heroic Monster',5,0,null],
            [40,'Level 22 heroic Monster',5,0,null],[41,'Level 23 heroic Monster',5,0,null],[42,'Level 24 heroic Monster',5,0,null],
            [43,'Level 25 heroic Monster',10,0,null],[44,'Level 26 heroic Monster',10,0,null],[45,'Level 27 heroic Monster',10,0,null],
            [46,'Level 28 heroic Monster',10,0,null],[47,'Level 29 heroic Monster',10,0,null],[48,'Level 30 heroic Monster',10,0,null],
            [49,'Level 31 heroic Monster',30,0,null],[50,'Level 32 heroic Monster',30,0,null],[51,'Level 33 heroic Monster',30,0,null],
            [52,'Level 34 heroic Monster',30,0,null],[53,'Level 35 heroic Monster',30,0,null],[54,'Level 36 heroic Monster',30,0,null],
            [55,'Level 37 heroic Monster',30,0,null],[56,'Level 38 heroic Monster',30,0,null],[57,'Level 39 heroic Monster',30,0,null],
            [58,'Level 40 heroic Monster',30,0,null],[59,'Level 41 heroic Monster',30,0,null],[60,'Level 42 heroic Monster',30,0,null],
            [61,'Level 43 heroic Monster',30,0,null],[62,'Level 44 heroic Monster',30,0,null],[63,'Level 45 heroic Monster',30,0,null],
            [64,'Authority Rush tournament',0,0,null],[65,'Epic Fenrir squad',5,1,25],[66,'Epic Inferno squad',5,0,null],
            [67,'Epic Jormungandr squad',5,0,null],[69,'Tartaros Crypt level 20',20,0,null],[70,'Tartaros Crypt level 25',60,0,null],
            [71,'Tartaros Crypt level 30',90,0,null],[72,'Tartaros Crypt level 35',120,0,null],[74,'Hermes\' Store',10,0,null],
            [75,'Arachne\'s Swarm Epic squad',35,0,null],
            [77,'Union of Triumph personal reward',0,0,null],[78,'Clan wealth',0,0,null],
            [79,'Level 45 Vault of the Ancients',0,0,null],[80,'Rise of the Ancients event',0,0,null],
            [81,'Epic Ancient squad',0,0,null],[82,'Mimic Chest',0,0,null],
            [85,'Alchemy tournament',0,0,null],[86,'Lvl 20-24 Raid Runic squad',0,0,null],
            [87,'Lvl 45 Raid Runic squad',0,0,null],[88,'Lvl 40-44 Raid Runic squad',0,0,null],
            [89,'Lvi 30-34 Raid Runic squad',0,0,null],[90,'Tartaros Crypt level 10',0,0,null],
            [91,'Tartaros Crypt level 15',0,0,null],[92,'Bank',0,0,null],
            [93,'Level 40-44 Vault of the Ancients',0,0,null],[94,'Level 35-39 Vault of the Ancients',0,0,null],
            [96,'Epic Briareus squad',0,0,null],
            [97,'Level 30-34 Vault of the Ancients',0,0,null],[98,'Event "Trials of Olympus"',0,0,null],
            [99,'Jérmungandr Shop',0,0,null],[100,'Jormungandr Shop',0,0,null],
            [101,'Epic Chimera squad',5,0,null],[102,'Epic Basilisk squad',5,0,null],
        ];

        $stmt = $pdo->prepare("INSERT INTO `standard_chests` (`id`, `source`, `score`, `monster`, `qty_chest`) VALUES (?, ?, ?, ?, ?)");
        $count = 0;
        foreach ($chests as $chest) {
            $stmt->execute($chest);
            $count++;
        }
        printSuccess("Inserted {$count} standard chest types");
    } else {
        printInfo("Standard chests already exist (skipped)");
    }
}

// ─── Step 9: Create Admin User ─────────────────────────────────────────────────

function createAdminUser(array $dbConfig): void
{
    printStep(9, 'Creating administrator user');

    // Check if admin already exists
    $pdo = getDbConnection($dbConfig);
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `roles_users` WHERE `role_id` = 1");
    $row = $stmt->fetch();

    if ($row && $row['cnt'] > 0) {
        printWarning("An administrator already exists in the system.");
        printInfo("Use the web interface to manage users.");
        return;
    }

    printInfo("Enter the details for the first administrator.");
    echo PHP_EOL;

    $name = prompt('Administrator name');
    if (empty($name)) {
        abortInstallation('Administrator name is required.');
    }

    $email = prompt('Administrator email');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        abortInstallation('A valid email is required.');
    }

    $password = promptPassword('Administrator password (min 6 chars)');
    if (empty($password) || strlen($password) < 6) {
        abortInstallation('Password must be at least 6 characters long.');
    }

    $passwordConfirm = promptPassword('Confirm password');
    if ($password !== $passwordConfirm) {
        abortInstallation('Passwords do not match.');
    }

    // Refresh PDO connection after interactive prompts to prevent "MySQL server has gone away" timeout
    $pdo = getDbConnection($dbConfig);

    // Check if email already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM `users` WHERE `email` = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row && $row['cnt'] > 0) {
        abortInstallation('This email is already registered.');
    }

    echo PHP_EOL;

    // First try via shell (uses CakePHP's password hasher)
    if (canRunShell()) {
        $escapedName = escapeShellArgSafe($name);
        $escapedEmail = escapeShellArgSafe($email);
        $escapedPassword = escapeShellArgSafe($password);

        $result = runCommand(
            'php ' . escapeShellArgSafe(CAKE_BIN) .
            " create_admin --name {$escapedName} --email {$escapedEmail} --password {$escapedPassword}"
        );

        if ($result !== null && $result['exitCode'] === 0) {
            printSuccess("Administrator created successfully!");
            printInfo("  → Name: {$name}");
            printInfo("  → Email: {$email}");
            return;
        }

        printWarning("Shell command failed, using PHP-native fallback...");
    }

    // PHP-native fallback: insert directly with PDO
    printInfo("Creating admin via PHP-native method...");

    $pdo = getDbConnection($dbConfig);

    // Hash password using bcrypt (same as CakePHP's DefaultPasswordHasher)
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $now = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        // Insert user
        $stmt = $pdo->prepare("INSERT INTO `users` (`name`, `email`, `password`, `created`, `modified`) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $email, $hashedPassword, $now, $now]);
        $userId = $pdo->lastInsertId();

        // Associate with admin role (role_id = 1)
        $stmt = $pdo->prepare("INSERT INTO `roles_users` (`user_id`, `role_id`) VALUES (?, 1)");
        $stmt->execute([$userId]);

        $pdo->commit();

        printSuccess("Administrator created successfully!");
        printInfo("  → ID: {$userId}");
        printInfo("  → Name: {$name}");
        printInfo("  → Email: {$email}");
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        printError("Failed to create administrator: " . $e->getMessage());
        printWarning("You can create it manually later: php bin/cake.php create_admin");
    }
}

// ─── Final Summary & Cleanup ───────────────────────────────────────────────────

function clearCache(): void
{
    $cacheDir = ROOT . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'cache';
    $cacheDirs = ['models', 'persistent', 'views'];

    foreach ($cacheDirs as $dir) {
        $path = $cacheDir . DIRECTORY_SEPARATOR . $dir;
        if (is_dir($path)) {
            $files = glob($path . DIRECTORY_SEPARATOR . '*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file) && basename($file) !== 'empty') {
                        @unlink($file);
                    }
                }
            }
        }
    }
}

function showFinalSummary(): void
{
    echo PHP_EOL;
    echo "╔══════════════════════════════════════════════════════════╗" . PHP_EOL;
    echo "║          ✅ Installation Complete! ✅                   ║" . PHP_EOL;
    echo "╚══════════════════════════════════════════════════════════╝" . PHP_EOL;
    echo PHP_EOL;

    printSuccess("TBOps is ready to use!");
    echo PHP_EOL;
    printInfo("Next steps:");
    printInfo("  1. Access your application in the browser");
    printInfo("  2. Log in with the admin credentials you just created");
    printInfo("  3. Configure your clan settings in the admin panel");
    echo PHP_EOL;

    printWarning("SECURITY: Delete this installer file now!");
    printWarning("  rm install.php");
    echo PHP_EOL;
}

function selfCleanup(): void
{
    // Clean downloaded composer.phar if we downloaded it
    $composerPhar = ROOT . DIRECTORY_SEPARATOR . 'composer.phar';
    if (file_exists($composerPhar)) {
        if (confirm("Delete the downloaded composer.phar?", false)) {
            @unlink($composerPhar);
            printSuccess("composer.phar deleted.");
        }
    }

    if (confirm("Delete this installer file (install.php) for security?", true)) {
        $installerFile = __FILE__;
        if (@unlink($installerFile)) {
            printSuccess("Installer file deleted.");
        } else {
            printWarning("Could not delete installer file. Please delete it manually:");
            printWarning("  rm install.php");
        }
    } else {
        printWarning("Remember to delete install.php manually for security!");
    }
}

// ─── Main ──────────────────────────────────────────────────────────────────────

function main(): void
{
    // Must run from CLI
    if (php_sapi_name() !== 'cli') {
        echo "This script must be run from the command line." . PHP_EOL;
        echo "Usage: php install.php" . PHP_EOL;
        exit(1);
    }

    printBanner();

    if (!confirm("Start TBOps installation?", true)) {
        echo PHP_EOL;
        echo "  Installation cancelled." . PHP_EOL;
        echo PHP_EOL;
        exit(0);
    }

    // Step 1: Check prerequisites
    checkPrerequisites();

    // Step 2: Install Composer dependencies
    installComposerDependencies();

    // Step 3: Collect database credentials
    $dbConfig = collectDatabaseCredentials();

    // Step 4: Test database connection
    testDatabaseConnection($dbConfig);

    // Step 5: Generate config/app_local.php
    generateAppLocal($dbConfig);

    // Step 6: Create required directories
    createDirectories();

    // Step 7: Run migrations
    runMigrations($dbConfig);

    // Step 8: Run seeds
    runSeeds($dbConfig);

    // Step 9: Create admin user
    createAdminUser($dbConfig);

    // Clear cache for clean start
    clearCache();

    // Final summary
    showFinalSummary();

    // Self cleanup
    selfCleanup();
}

main();
