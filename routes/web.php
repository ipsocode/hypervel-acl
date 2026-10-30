<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;
use Ipsocode\Acl\Http\Controllers\UiController;

// The UI's internal API: the management controllers, behind the UI's session middleware.
Route::prefix('acl-api')->group(__DIR__ . '/api.php');

Route::get('/{view?}', [UiController::class, 'index'])->where('view', '(.*)')->name('index');
