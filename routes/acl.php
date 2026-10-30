<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;
use Ipsocode\Acl\Http\Middleware\ResolveAcl;

$config = config('acl.routes');

Route::prefix($config['prefix'])
    ->middleware([...$config['middleware'], ResolveAcl::class])
    ->name($config['name'])
    ->group(__DIR__ . '/api.php');
