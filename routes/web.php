<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;
use Ipsocode\Acl\Http\Controllers\UiController;

// The UI's internal API: the management controllers, behind the UI's session middleware.
Route::prefix('acl-api')->group(__DIR__ . '/api.php');

// A path of the internal API that matches no route is a 404, not the app's page.
Route::any('acl-api/{path?}', static fn () => abort(404))->where('path', '.*');

Route::get('/{view?}', [UiController::class, 'index'])->where('view', '(.*)')->name('index');
