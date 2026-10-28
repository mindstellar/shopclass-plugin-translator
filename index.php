<?php
/*
Plugin Name: Translator
Plugin URI: https://github.com/mindstellar/shopclass-plugin-translator
Description: Change or translate any text on your site.
Version: 0.1.0
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: translator
Requires Shopclass: 7.0.6
Tested up to: 7.1
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/shopclass-plugin-translator/issues
Modes: classifieds, shop
*/

/*
 * This file is part of the Translator plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

use mindstellar\translator\admin\Screen;
use mindstellar\translator\Loader;
use mindstellar\translator\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\translator\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

osc_register_plugin(osc_plugin_path(__FILE__), array(Plugin::class, 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array(Plugin::class, 'uninstall'));

// Needs the translation_load_after hook of Shopclass 7.0.6. On an older core it loads and only says so.
if (!defined('OSCLASS_VERSION') || version_compare((string) OSCLASS_VERSION, '7.0.6', '<')) {
    osc_add_hook('admin_page_header', static function (): void {
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('Translator needs Shopclass 7.0.6 or newer', 'translator'))
            . '</div>';
    }, 10);

    return;
}

osc_add_hook('translation_load_after', array(Loader::class, 'onLoaded'));

if (defined('OC_ADMIN') && OC_ADMIN) {
    Screen::register(__FILE__);
}
