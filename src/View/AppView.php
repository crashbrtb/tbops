<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link      https://cakephp.org CakePHP(tm) Project
 * @since     3.0.0
 * @license   https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\View;

use App\Utility\GameNumber;
use Cake\View\View;
use CakeLte\View\CakeLteTrait;

/**
 * Application View
 *
 * Your application's default view class
 *
 * @link https://book.cakephp.org/4/en/views.html#the-app-view
 */
class AppView extends View
{
    use CakeLteTrait;

    /**
     * Initialization hook method.
     *
     * Use this method to add common initialization code like adding helpers.
     *
     * e.g. `$this->addHelper('Html');`
     *
     * @return void
     */
    public string $layout = 'CakeLte/layout/default';
    public function initialize(): void
    {
        parent::initialize();
        $this->addHelper('CakeLte.CakeLte');
        // Branding is in the <head> and the navbar of every layout, so the
        // helper has to be here rather than added per-controller.
        $this->addHelper('Branding');
        // The theme goes on the <html> element of every layout.
        $this->addHelper('SiteTheme');
        $this->addHelper('GameImage');
    }

    /**
     * An amount of the clan bank (whole millions of Silver) in the game's
     * notation: 1000 is 1B, 500 is 500M.
     *
     * @param int|float|string|null $millions Millions of Silver.
     * @return string
     */
    public function silver($millions): string
    {
        return GameNumber::silver($millions);
    }

    /**
     * What a player receives of a prize line: Silver (in millions) or items,
     * both in the game's notation.
     *
     * @param \App\Model\Entity\EventReward|array<string, mixed> $reward Prize line.
     * @param int|float|string|null $amount Amount.
     * @return string
     */
    public function rewardAmount($reward, $amount): string
    {
        $silver = is_array($reward) ? !empty($reward['is_silver']) : (bool)$reward->get('is_silver');

        return $silver ? GameNumber::silver($amount) : GameNumber::format($amount);
    }

    /**
     * A number in the game's notation: at most 3 digits and a letter (1,2K,
     * 12,3K, 100M, 10B), the same everywhere on the site.
     *
     * @param int|float|string|null $num
     * @return string
     */
    public function gameNumber($num): string
    {
        return GameNumber::format($num);
    }
}
