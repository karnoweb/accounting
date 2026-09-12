<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Karnoweb\Accounting\Http\Controllers\AccountCatalogController;

Route::get('/accounts/hierarchy', [AccountCatalogController::class, 'hierarchy'])
    ->name('accounting.accounts.hierarchy');

Route::get('/accounts', [AccountCatalogController::class, 'index'])
    ->name('accounting.accounts.index');
