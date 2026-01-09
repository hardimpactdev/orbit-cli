<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\ProjectController;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/health', [ProjectController::class, 'health']);

// Status & Info
Route::get('/status', [ApiController::class, 'status']);
Route::get('/sites', [ApiController::class, 'sites']);
Route::get('/projects', [ApiController::class, 'projects']);
Route::get('/config', [ApiController::class, 'config']);
Route::post('/config', [ApiController::class, 'saveConfig']);
Route::get('/php-versions', [ApiController::class, 'phpVersions']);

// Service Control
Route::post('/start', [ApiController::class, 'start']);
Route::post('/stop', [ApiController::class, 'stop']);
Route::post('/restart', [ApiController::class, 'restart']);
Route::post('/services/{service}/start', [ApiController::class, 'startService']);
Route::post('/services/{service}/stop', [ApiController::class, 'stopService']);
Route::post('/services/{service}/restart', [ApiController::class, 'restartService']);
Route::get('/services/{service}/logs', [ApiController::class, 'serviceLogs']);

// PHP Management
Route::get('/php/{site}', [ApiController::class, 'getPhp']);
Route::post('/php/{site}', [ApiController::class, 'setPhp']);
Route::post('/php/{site}/reset', [ApiController::class, 'resetPhp']);

// Projects
Route::post('/projects', [ProjectController::class, 'store']);
Route::get('/projects/{slug}/provision-status', [ApiController::class, 'provisionStatus']);
Route::delete('/projects/{slug}', [ApiController::class, 'deleteProject']);
Route::post('/projects/{slug}/rebuild', [ApiController::class, 'rebuildProject']);

// Worktrees
Route::get('/worktrees', [ApiController::class, 'worktrees']);
Route::get('/worktrees/{site}', [ApiController::class, 'siteWorktrees']);
Route::post('/worktrees/refresh', [ApiController::class, 'refreshWorktrees']);
Route::delete('/worktrees/{site}/{name}', [ApiController::class, 'unlinkWorktree']);

// Workspaces
Route::get('/workspaces', [ApiController::class, 'workspaces']);
Route::post('/workspaces', [ApiController::class, 'createWorkspace']);
Route::delete('/workspaces/{name}', [ApiController::class, 'deleteWorkspace']);
Route::post('/workspaces/{workspace}/projects', [ApiController::class, 'addWorkspaceProject']);
Route::delete('/workspaces/{workspace}/projects/{project}', [ApiController::class, 'removeWorkspaceProject']);

// Package Linking
Route::get('/packages/{app}/linked', [ApiController::class, 'linkedPackages']);
Route::post('/packages/{app}/link', [ApiController::class, 'linkPackage']);
Route::delete('/packages/{app}/unlink/{package}', [ApiController::class, 'unlinkPackage']);

// GitHub
Route::get('/github/user', [ApiController::class, 'githubUser']);
Route::get('/github/repo/{owner}/{repo}', [ApiController::class, 'checkRepo']);
