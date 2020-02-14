<?php

include(__DIR__ . '/webdata/init.inc.php');

Pix_Controller::addCommonHelpers();
if (!getenv('SESSION_SECRET')) {
        die("need SESSION_SECRET");
}
Pix_Session::setAdapter('cookie', array('secret' => getenv('SESSION_SECRET'), 'secure' => true, 'cookie_domain' => ''));
Pix_Controller::dispatch(__DIR__ . '/webdata/');
