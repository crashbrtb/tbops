<?php
declare(strict_types=1);

use Migrations\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

/**
 * The picture of a tournament nobody has named yet.
 *
 * The game only sends a tournament's type, and its name exists only on screen, so
 * the uploader sends a picture of the Journal page for a person to read. It is
 * dropped once the tournament has a name.
 */
class AddPendingPictureToGameTournaments extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('game_tournaments');
        if (!$table->hasColumn('pending_picture')) {
            $table->addColumn('pending_picture', 'blob', [
                'limit' => MysqlAdapter::BLOB_MEDIUM,
                'null' => true,
                'default' => null,
                'comment' => 'PNG of the Journal page with its card outlined until someone names the tournament',
            ])->update();
        }
    }

    /**
     * Down Method.
     *
     * @return void
     */
    public function down(): void
    {
        $table = $this->table('game_tournaments');
        if ($table->hasColumn('pending_picture')) {
            $table->removeColumn('pending_picture')->update();
        }
    }
}
