<?php

declare(strict_types=1);

// Docker keeps the editable .htaccess in the persistent storage volume.
require dirname(__DIR__) . '/src/Service/IpRestrictionService.php';

$basePath = dirname(__DIR__, 2);
$template = file_get_contents('/etc/accountplus/htaccess');
if ($template === false) {
    throw new RuntimeException('Could not read the Apache configuration template.');
}
$path = $basePath . '/app/storage/app/apache.htaccess';
$block = is_file($path)
    ? (new App\Service\IpRestrictionService($basePath, null, $path))->managedBlock()
    : '';
$temporary = tempnam(dirname($path), '.apache-');
if ($temporary === false) {
    throw new RuntimeException('Could not prepare the Apache configuration.');
}
try {
    if (file_put_contents($temporary, rtrim($template) . "\n\n" . $block . "\n") === false
        || !chmod($temporary, 0644) || !rename($temporary, $path)) {
        throw new RuntimeException('Could not save the Apache configuration.');
    }
} finally {
    if (is_file($temporary)) {
        unlink($temporary);
    }
}
