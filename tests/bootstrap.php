<?php

/**
 * PHPUnit Bootstrap File
 *
 * Sets up the testing environment
 */

// Define test environment
define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');
define('CRAFT_BASE_PATH', dirname(__DIR__));

// Load Composer autoloader
require_once CRAFT_VENDOR_PATH . '/autoload.php';

// Define YII_DEBUG for testing
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

// Load Yii
require_once CRAFT_VENDOR_PATH . '/yiisoft/yii2/Yii.php';

// Create a minimal Yii application for testing
$config = [
    'id' => 'craft-test',
    'basePath' => CRAFT_BASE_PATH,
    'vendorPath' => CRAFT_VENDOR_PATH,
    'components' => [
        'i18n' => [
            'class' => 'yii\i18n\I18N',
            'translations' => [
                'booked' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                    'basePath' => CRAFT_BASE_PATH . '/src/translations',
                ],
            ],
        ],
    ],
];

new yii\console\Application($config);
