<?php

/**
 * Executes the KAsyncCopyCaptions
 *
 * @package Scheduler
 * @subpackage Copy
 */
require_once(__DIR__ . "/../../bootstrap.php");

$instance = new KAsyncCopyCaptions();
$instance->run();
$instance->done();
