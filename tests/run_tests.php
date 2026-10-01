<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Autoloader.php';
require_once __DIR__ . '/GatewayTest.php';

use EidCloud\AiGateway\Tests\GatewayTest;

$suite = new GatewayTest();
$suite->runAll();
