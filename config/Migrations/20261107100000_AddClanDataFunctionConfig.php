<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Add the clan_data_function parameter to the config table.
 *
 * Off by default. Set to 1, a "Clan Data" menu appears beside the Bank one and
 * everyone, signed in or not, sees the clan's power history; set to 0, only the
 * administrators see it, from the Admin menu.
 */
class AddClanDataFunctionConfig extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $row = $this->fetchRow("SELECT id FROM config WHERE param = 'clan_data_function'");
        if ($row) {
            return;
        }

        $description = '1 = Clan Data menu (power history) shown to everyone, signed in or not / 0 = administrators only';

        $this->execute(sprintf(
            "INSERT INTO config (param, value, description) VALUES ('clan_data_function', '0', '%s')",
            str_replace("'", "''", $description)
        ));
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        $this->execute("DELETE FROM config WHERE param = 'clan_data_function'");
    }
}
