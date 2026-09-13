<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ResearchController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->name('login.store');
    Route::get('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/register', [AuthController::class, 'store'])->name('register.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', [ResearchController::class, 'index'])->name('research.index');
    Route::post('/research', [ResearchController::class, 'store'])->name('research.store');
    Route::post('/research/{researchRun}/execute', [ResearchController::class, 'execute'])->name('research.execute');
    Route::get('/research/{researchRun}', [ResearchController::class, 'show'])->name('research.show');
    Route::get('/prospects/{prospect}', [ResearchController::class, 'prospect'])->name('prospects.show');
    Route::post('/prospects/{prospect}/feedback', [ResearchController::class, 'feedback'])->name('prospects.feedback');
    Route::get('/prospects/{prospect}/workspace', [ResearchController::class, 'workspace'])->name('prospects.workspace');
    Route::get('/campaigns', [WorkspaceController::class, 'campaigns'])->name('campaigns.index');
    Route::get('/prospects', [WorkspaceController::class, 'prospects'])->name('prospects.index');
    Route::get('/organization', [WorkspaceController::class, 'organization'])->name('organization.edit');
    Route::put('/organization', [WorkspaceController::class, 'updateOrganization'])->name('organization.update');
    Route::post('/organization/enrich', [WorkspaceController::class, 'enrichOrganization'])->name('organization.enrich');
    Route::get('/organization/enrichment-status', [WorkspaceController::class, 'organizationEnrichmentStatus'])->name('organization.enrichment.status');
    Route::get('/settings', [WorkspaceController::class, 'settings'])->name('settings.index');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
