<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Fines by ranges of the goal reached.
 *
 * A tournament can fine a miss by how much of the goal the player reached, each
 * range with its own amount: 0% to 10% owes 1B, 10% to 50% owes 500M, 50% to
 * 100% owes 100M. The ranges are kept as JSON in fine_tiers and used when
 * fine_mode is "tiers".
 */
class AddFineTiersToGameTournaments extends AbstractMigration
{
    /**
     * Up Method.
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('game_tournaments');
        if (!$table->hasColumn('fine_tiers')) {
            $table->addColumn('fine_tiers', 'text', [
                'null' => true,
                'default' => null,
                'after' => 'fine_amount',
                'comment' => 'JSON list of {from, to, amount}: the fine by share of the goal reached (tiers mode)',
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
        if ($table->hasColumn('fine_tiers')) {
            $table->removeColumn('fine_tiers')->update();
        }
    }
}
