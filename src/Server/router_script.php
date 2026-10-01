<?php

declare(strict_types=1);

require_once __DIR__ . '/../Autoloader.php';

use EidCloud\AiGateway\Gateway;
use EidCloud\AiGateway\Server\HttpServer;

$gateway = Gateway::createDefault();
$server = new HttpServer($gateway);
$server->handleRequest();
