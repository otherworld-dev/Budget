<?php

declare(strict_types=1);

use OCA\Budget\AppInfo\Application;

$app = new Application();
$app->getContainer()->get('OCP\AppFramework\Bootstrap\IBootContext');