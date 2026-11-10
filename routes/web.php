<?php

use App\Http\Controllers\LanguageController;
use App\Http\Controllers\LoginController;
use App\Livewire\AddJob;
use App\Livewire\Analytics;
use App\Livewire\Applications;
use App\Livewire\Explorer;
use App\Livewire\OpportunityDetail;
use App\Livewire\Overview;
use App\Livewire\Settings;
use App\Livewire\Sources;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('/language', LanguageController::class)->middleware('throttle:10,1')->name('language');

    Route::livewire('/', Overview::class)->name('overview');
    Route::livewire('/jobs', Explorer::class)->name('jobs');
    Route::livewire('/jobs/add', AddJob::class)->name('jobs.add');
    Route::livewire('/jobs/{opportunity}', OpportunityDetail::class)->name('jobs.show')->whereNumber('opportunity');
    Route::livewire('/applications', Applications::class)->name('applications');
    Route::livewire('/analytics', Analytics::class)->name('analytics');
    Route::livewire('/sources', Sources::class)->name('sources');
    Route::livewire('/settings', Settings::class)->name('settings');
});
