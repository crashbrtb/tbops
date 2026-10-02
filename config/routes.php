<?php
/**
 * Routes configuration.
 *
 * In this file, you set up routes to your controllers and their actions.
 * Routes are very important mechanism that allows you to freely connect
 * different URLs to chosen controllers and their actions (functions).
 *
 * It's loaded within the context of `Application::routes()` method which
 * receives a `RouteBuilder` instance `$routes` as method argument.
 *
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

/*
 * This file is loaded in the context of the `Application` class.
  * So you can use  `$this` to reference the application class instance
  * if required.
 */
return function (RouteBuilder $routes): void {
    /*
     * The default class to use for all routes
     *
     * The following route classes are supplied with CakePHP and are appropriate
     * to set as the default:
     *
     * - Route
     * - InflectedRoute
     * - DashedRoute
     *
     * If no call is made to `Router::defaultRouteClass()`, the class used is
     * `Route` (`Cake\Routing\Route\Route`)
     *
     * Note that `Route` does not do any inflections on URLs which will result in
     * inconsistently cased URLs when used with `{plugin}`, `{controller}` and
     * `{action}` markers.
     */
    $routes->setRouteClass(DashedRoute::class);

    // Rota personalizada para /score
    $routes->connect('/score', ['controller' => 'CollectedChests', 'action' => 'score']);
    $routes->connect('/scorenew', ['controller' => 'CollectedChests', 'action' => 'scorenew']);
    $routes->connect('/main', ['controller' => 'CollectedChests', 'action' => 'score']);
    $routes->connect('/main/score', ['controller' => 'CollectedChests', 'action' => 'score']);

    // API used by the EventUploader desktop tool. Authenticated with a personal
    // token (Authorization: Bearer ...), never with the session, and exempt from
    // CSRF for that reason: see Application::middleware().
    $routes->scope('/api/v1', ['prefix' => 'Api'], function (RouteBuilder $builder): void {
        $builder->setExtensions(['json']);
        $builder->connect('/me', ['controller' => 'Uploader', 'action' => 'me']);
        $builder->connect('/events/awaiting', ['controller' => 'Uploader', 'action' => 'awaiting']);
        // The uploader's everyday call: registers the tournament if it is new
        // and attaches the ranking as a draft.
        $builder->connect('/tournaments', ['controller' => 'Uploader', 'action' => 'tournament']);
        $builder->connect('/tournaments/known', ['controller' => 'Uploader', 'action' => 'known']);
        // Which results the site already has, so nothing is sent twice.
        $builder->connect('/tournaments/lookup', ['controller' => 'Uploader', 'action' => 'lookup']);
        // The automatic search: how far back to look, and what each run covered.
        $builder->connect('/searches/last', ['controller' => 'Uploader', 'action' => 'searchState']);
        $builder->connect('/searches', ['controller' => 'Uploader', 'action' => 'searchReport']);
        // The tournament mapper reports the names it reads off the Journal.
        $builder->connect('/tournament-catalog', ['controller' => 'Uploader', 'action' => 'catalog']);
        $builder->connect(
            '/events/{id}/imports',
            ['controller' => 'Uploader', 'action' => 'import'],
            ['pass' => ['id'], 'id' => '\d+']
        );
        // Job heartbeats for the external monitor, behind its own read-only key.
        $builder->connect('/health', ['controller' => 'Health', 'action' => 'index']);
    });

    $routes->scope('/', function (RouteBuilder $builder): void {
        /*
         * Here, we are connecting '/' (base path) to a controller called 'Pages',
         * its action called 'display', and we pass a param to select the view file
         * to use (in this case, templates/Pages/home.php)...
         */
        $builder->connect('/', ['controller' => 'CollectedChests', 'action' => 'score']);
        $builder->connect('/history', ['controller' => 'PlayerCycleSummaries', 'action' => 'cycles_history']);
        $builder->connect('/scorenew', ['controller' => 'CollectedChests', 'action' => 'scorenew']);

        /*
         * ...and connect the rest of 'Pages' controller's URLs.
         */
        $builder->connect('/pages/*', 'Pages::display');

        // Users routes
        $builder->connect('/users/login', ['controller' => 'Users', 'action' => 'login']);
        $builder->connect('/users/google-login', ['controller' => 'Users', 'action' => 'googleLogin']);
        $builder->connect('/users/awaiting-approval', ['controller' => 'Users', 'action' => 'awaitingApproval']);
        $builder->connect('/users/toggle-active/*', ['controller' => 'Users', 'action' => 'toggleActive']);

        // Bank module routes
        $builder->connect('/bank', ['controller' => 'Bank', 'action' => 'index']);
        $builder->connect('/bank/:action/*', ['controller' => 'Bank']);

        // Standard Chests routes
        $builder->connect('/standard-chests/lost-chests', ['controller' => 'StandardChests', 'action' => 'lostChests']);

        // Troop calculator
        $builder->connect('/calculator', ['controller' => 'TroopCalculator', 'action' => 'index']);

        // Language switcher. Public: the score page is readable without
        // logging in, so the switch there has to work without a session.
        $builder->connect('/lang/*', ['controller' => 'Locale', 'action' => 'change']);

        // Events module. The banner on the public score page links here, so the
        // reading routes have to resolve for anonymous visitors too.
        $builder->connect('/events', ['controller' => 'Events', 'action' => 'index']);
        $builder->connect('/events/history', ['controller' => 'Events', 'action' => 'history']);
        $builder->connect('/events/banner/*', ['controller' => 'Events', 'action' => 'banner']);
        $builder->connect('/events/asset/*', ['controller' => 'Events', 'action' => 'asset']);

        /*
         * Connect catchall routes for all controllers.
         *
         * The `fallbacks` method is a shortcut for
         *
         * ```
         * $builder->connect('/{controller}', ['action' => 'index']);
         * $builder->connect('/{controller}/{action}/*', []);
         * ```
         *
         * You can remove these routes once you've connected the
         * routes you want in your application.
         */
        $builder->fallbacks();
    });

    $routes->prefix('Admin', function (RouteBuilder $builder): void {
        $builder->fallbacks();
    });

    /*
     * If you need a different set of middleware or none at all,
     * open new scope and define routes there.
     *
     * ```
     * $routes->scope('/api', function (RouteBuilder $builder): void {
     *     // No $builder->applyMiddleware() here.
     *
     *     // Parse specified extensions from URLs
     *     // $builder->setExtensions(['json', 'xml']);
     *
     *     // Connect API actions here.
     * });
     * ```
     */
};
