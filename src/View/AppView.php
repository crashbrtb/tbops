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
     * Formats a number using the game's 3-digit notation (e.g., 1200 -> 1,20k, 100000000 -> 100m).
     *
     * @param int|float|string|null $num
     * @return string
     */
    public function gameNumber($num): string
    {
        if ($num === null || $num === '') {
            return '0';
        }
        $n = (float)$num;
        if ($n < 0) {
            return '-' . $this->gameNumber(-$n);
        }
        if ($n < 1000) {
            return (string)(int)$n;
        }

        $suffixes = ['', 'k', 'm', 'b', 't', 'q'];
        $tier = (int)floor(log10($n) / 3);
        if ($tier >= count($suffixes)) {
            $tier = count($suffixes) - 1;
        }

        $scaled = $n / pow(10, $tier * 3);
        if ($scaled >= 999.5 && $tier < count($suffixes) - 1) {
            $tier++;
            $scaled = $n / pow(10, $tier * 3);
        }

        if ($scaled < 9.995) {
            $formatted = number_format($scaled, 2, ',', '');
        } elseif ($scaled < 99.95) {
            $formatted = number_format($scaled, 1, ',', '');
        } else {
            $formatted = number_format($scaled, 0, ',', '');
        }

        return $formatted . $suffixes[$tier];
    }
}
