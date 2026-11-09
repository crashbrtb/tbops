<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * A fine given up as lost: the part the player still owed when an administrator
 * gave up collecting it (most often a player who left the clan).
 */
class AddLostAmountToEventFines extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('event_fines');
        if (!$table->hasColumn('lost_amount')) {
            $table->addColumn('lost_amount', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'amount',
                'comment' => 'What was still owed when the fine was given up as lost',
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
        $table = $this->table('event_fines');
        if ($table->hasColumn('lost_amount')) {
            $table->removeColumn('lost_amount')->update();
        }
    }
}
