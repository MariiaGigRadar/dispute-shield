<?php
// Все секреты берутся из переменных окружения Railway
// Не храни ключи прямо в коде!
require_once __DIR__ . '/vendor/autoload.php';

define('STRIPE_SECRET_KEY',   getenv('STRIPE_SECRET_KEY')   ?: '');
define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: '');
define('POSTHOG_API_KEY',     getenv('POSTHOG_API_KEY')     ?: '');
define('POSTHOG_PROJECT_ID',  getenv('POSTHOG_PROJECT_ID')  ?: '304522');
define('POSTHOG_HOST',        'https://us.posthog.com');
define('TELEGRAM_BOT_TOKEN',  getenv('TELEGRAM_BOT_TOKEN')  ?: '');
define('TELEGRAM_CHAT_ID',    getenv('TELEGRAM_CHAT_ID')    ?: '');
define('DASH_KEY',            getenv('DASH_KEY')            ?: 'changeme');
define('SITE_NAME',           getenv('SITE_NAME')           ?: 'My App');
