<?php

use App\Http\Controllers\Admin\DownloadPrintJobDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'signed'])->get(
    '/admin/tenants/{tenant}/print-jobs/{jobId}/document',
    DownloadPrintJobDocumentController::class,
)->name('admin.print-jobs.document.download');

Route::get('/', function () {
    return view('welcome');
});
