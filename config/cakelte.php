<?php
use App\Service\BrandingService;
use Cake\ORM\TableRegistry;

/**
 * Reads the branding parameters from the `config` table.
 *
 * This file is loaded by CakeLteHelper while the View is being built, which
 * also happens when rendering an error page. If the config table is missing
 * rows (fresh install before seeding, or a broken database) it must degrade
 * gracefully instead of emitting warnings and masking the real error.
 */
$readConfig = function (string $param, string $default = ''): string {
    try {
        $row = TableRegistry::getTableLocator()->get('Config')
            ->find()
            ->where(['param' => $param])
            ->first();
    } catch (\Throwable $e) {
        return $default;
    }

    return $row->value ?? $default;
};

/**
 * The navbar renders the logo through the Branding helper, but `app-logo` is
 * part of the theme's published configuration and plugin elements may read it,
 * so it is kept pointing at whatever branding is actually selected.
 */
$logo = (function (): string {
    try {
        return (new BrandingService())->logoUrl();
    } catch (\Throwable $e) {
        return BrandingService::urlFor(BrandingService::DEFAULT_SLUG) . 'logo.png';
    }
})();

$kingdomNumber = $readConfig('kingdom_number');
$clanAcronym = $readConfig('clan_acronym');
$clanName = $readConfig('clan_name', 'TBOps');

return [
    'CakeLte' => [
        'app-name' => '<b>' . $kingdomNumber . ' </b> ' . $clanAcronym . ' <b>' . $clanName . '</b>',
        'app-logo' => $logo,
        'small-text' => true,
        'dark-mode' => false,
        'layout-boxed' => false,

        'theme' => [
            'folder' => 'CakeLte',
            'skin' => 'blue',
        ],
        'footer' => [
            'left' => 'TBOps',
            'right' => 'Versão 0.3'
        ],
        'sidebar' => [
            'enable' => false,
            'collapse' => false
        ],
        'navbar' => [
            'enable' => true
        ]
    ]
];
