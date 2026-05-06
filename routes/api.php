<?php

use App\Http\Actions\HandlePaymentWebhook;
use App\Http\Actions\JoinWaitlist;
use App\Http\Actions\Migration\ExportUser;
use App\Http\Actions\Migration\SealUser;
use Illuminate\Support\Facades\Route;

Route::post('/waitlist', JoinWaitlist::class);
Route::post('/webhooks/ko-fi', HandlePaymentWebhook::class);

// Beta→prod migration server-to-server endpoints. Only exposed when this
// deployment is in `export` mode; bearer-token authenticated.
Route::middleware('migration.mode:export')->prefix('migration')->group(function () {
    Route::get('/export', ExportUser::class)->middleware('migration.s2s:s2s_export');
    Route::post('/seal', SealUser::class)->middleware('migration.s2s:s2s_seal');
});
