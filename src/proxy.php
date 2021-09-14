<?php

require __DIR__ . '/../vendor/autoload.php';

$debug = new \bdk\Debug(array(
    'collect' => false,
    'output' => false,
));

$xdebugWamp = new \bdk\XdebugWamp\XdebugWamp(array(
    'dbgpRepeat' => array(
        'enabled' => true,
    ),
));
$xdebugWamp->run();
