<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$seeders = dirname(__DIR__) . '/database/seeders';
require_once $seeders . '/SettingsSeeder.php';
require_once $seeders . '/CatalogSeeder.php';
require_once $seeders . '/FakeUsersSeeder.php';
require_once $seeders . '/RegulationsSeeder.php';
require_once $seeders . '/ClosuresSeeder.php';
require_once $seeders . '/DemoOrdersSeeder.php';
