<?php

declare(strict_types=1);

// Front controller: every request that isn't a static asset comes here.

use FeedWatch\Http\Kernel;
use FeedWatch\Http\Request;
use FeedWatch\Http\Response;
use FeedWatch\Http\SecurityHeaders;
use FeedWatch\Infrastructure\Config;

$root = dirname(__DIR__);

// Never show errors to visitors. Log them outside public/, in a folder the
// web server refuses to serve; on this host the default log file would
// otherwise be created next to this script, where anyone could read it.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $root . '/storage/logs/php-error.log');
header_remove('X-Powered-By');

require $root . '/vendor/autoload.php';

$request = Request::fromGlobals();

try {
    $response = Kernel::create(Config::load($root . '/config'), $root . '/storage')->handle($request);
} catch (Throwable $e) {
    error_log('FeedWatch: unhandled ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    $response = SecurityHeaders::apply(Response::html('<!doctype html><title>FeedWatch</title><p>Something went wrong.</p>', 500));
}

$response->send($request->method !== 'HEAD');
