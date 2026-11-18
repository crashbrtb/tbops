<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Convert every application table that is not fully utf8mb4 to utf8mb4.
 *
 * Several migrations created tables without stating a character set
 * (manual_goal_penalties, goal_penalty_waivers, troops, live_tournaments,
 * clan_tournament_results, game_images, game_items...). On a server whose
 * database default is latin1 those tables came out latin1, and then:
 *
 * - comparing one of their columns with a utf8mb4 value fails with
 *   "Illegal mix of collations (latin1_general_ci,IMPLICIT) and
 *   (utf8mb4_general_ci,COERCIBLE)" - e.g. adding a manual penalty to
 *   "Uğur 43 1907";
 * - characters latin1 cannot hold (ğ, ş, Cyrillic, CJK...) are stored as "?".
 *
 * The connection already talks utf8mb4, so whatever is stored is real
 * latin1 text and CONVERT TO translates it without loss. Characters that
 * were already replaced by "?" cannot be recovered.
 *
 * Idempotent: tables already in utf8mb4 (table default and every text
 * column) are left alone. phinxlog belongs to the migrations tool and is
 * skipped.
 */
class ConvertTablesToUtf8mb4 extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'mysql') {
            return;
        }

        $rows = $this->fetchAll(
            "SELECT DISTINCT t.TABLE_NAME AS name
               FROM information_schema.TABLES t
               LEFT JOIN information_schema.COLUMNS c
                 ON c.TABLE_SCHEMA = t.TABLE_SCHEMA
                AND c.TABLE_NAME = t.TABLE_NAME
                AND c.CHARACTER_SET_NAME IS NOT NULL
                AND c.CHARACTER_SET_NAME <> 'utf8mb4'
              WHERE t.TABLE_SCHEMA = DATABASE()
                AND t.TABLE_TYPE = 'BASE TABLE'
                AND t.TABLE_NAME <> 'phinxlog'
                AND (t.TABLE_COLLATION NOT LIKE 'utf8mb4%' OR c.COLUMN_NAME IS NOT NULL)"
        );

        foreach ($rows as $row) {
            $this->convert((string)($row['name'] ?? $row[0]));
        }
    }

    /**
     * Convert one table, working around servers that cap index keys at 767 bytes.
     *
     * utf8mb4 takes up to 4 bytes a character, so an indexed varchar(255) needs
     * 1020 bytes. MySQL 5.7+/MariaDB 10.2+ with the DYNAMIC row format allow
     * 3072; older settings (COMPACT rows, innodb_large_prefix off) stop at 767
     * and refuse with error 1071. Then, in order:
     *
     * 1. switch the table to ROW_FORMAT=DYNAMIC and try again;
     * 2. shorten each indexed varchar longer than 191 to 191 characters
     *    (191 x 4 = 764 bytes) - only when no stored value is longer - and try
     *    again. Player names are far shorter than that.
     *
     * @param string $table Table name.
     * @return void
     */
    private function convert(string $table): void
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $sql = "ALTER TABLE {$quoted} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci";

        try {
            $this->execute($sql);

            return;
        } catch (Throwable $e) {
            if (!self::isKeyTooLong($e)) {
                throw $e;
            }
        }

        try {
            $this->execute("ALTER TABLE {$quoted} ROW_FORMAT=DYNAMIC");
            $this->execute($sql);

            return;
        } catch (Throwable $e) {
            if (!self::isKeyTooLong($e)) {
                throw $e;
            }
        }

        $this->shortenIndexedColumns($table, $quoted);
        $this->execute($sql);
    }

    /**
     * Shorten every indexed varchar of the table longer than 191 characters to 191.
     *
     * @param string $table Table name.
     * @param string $quoted Table name, quoted.
     * @return void
     */
    private function shortenIndexedColumns(string $table, string $quoted): void
    {
        $columns = $this->fetchAll(
            "SELECT DISTINCT c.COLUMN_NAME AS name, c.IS_NULLABLE AS nullable,
                    c.COLUMN_DEFAULT AS dflt, c.COLUMN_COMMENT AS comment
               FROM information_schema.STATISTICS s
               JOIN information_schema.COLUMNS c
                 ON c.TABLE_SCHEMA = s.TABLE_SCHEMA
                AND c.TABLE_NAME = s.TABLE_NAME
                AND c.COLUMN_NAME = s.COLUMN_NAME
              WHERE s.TABLE_SCHEMA = DATABASE()
                AND s.TABLE_NAME = " . self::literal($table) . "
                AND s.SUB_PART IS NULL
                AND c.DATA_TYPE = 'varchar'
                AND c.CHARACTER_MAXIMUM_LENGTH > 191"
        );

        foreach ($columns as $column) {
            $name = '`' . str_replace('`', '``', (string)$column['name']) . '`';
            $longest = (int)($this->fetchAll("SELECT COALESCE(MAX(CHAR_LENGTH({$name})), 0) AS longest FROM {$quoted}")[0]['longest'] ?? 0);
            if ($longest > 191) {
                throw new RuntimeException(sprintf(
                    'Cannot convert %s to utf8mb4: the server limits index keys to 767 bytes and %s holds values of %d characters.',
                    $table,
                    $column['name'],
                    $longest
                ));
            }

            $definition = 'VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
                . ($column['nullable'] === 'YES' ? ' NULL' : ' NOT NULL');
            if ($column['dflt'] !== null && strtoupper((string)$column['dflt']) !== 'NULL') {
                $default = trim((string)$column['dflt'], "'");
                $definition .= ' DEFAULT ' . self::literal($default);
            }
            if ((string)$column['comment'] !== '') {
                $definition .= ' COMMENT ' . self::literal((string)$column['comment']);
            }

            $this->execute("ALTER TABLE {$quoted} MODIFY {$name} {$definition}");
        }
    }

    /**
     * A string as a quoted SQL literal.
     *
     * @param string $value Value.
     * @return string
     */
    private static function literal(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    /**
     * Whether the error (or one it wraps) is MySQL's "Specified key was too
     * long" (1071). Phinx throws the PDOException, the CakePHP backend wraps
     * it in a QueryException.
     *
     * @param \Throwable $e Error.
     * @return bool
     */
    private static function isKeyTooLong(Throwable $e): bool
    {
        // 1071: "Specified key was too long"; 1709: "Index column size too
        // large" (what MariaDB says for the same limit on COMPACT rows)
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            if ($error instanceof PDOException && in_array((int)($error->errorInfo[1] ?? 0), [1071, 1709], true)) {
                return true;
            }
            if (preg_match('/\b(1071|1709)\b/', $error->getMessage())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Down Method.
     *
     * Nothing to undo: going back to latin1 would only lose characters again.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
