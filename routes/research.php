<?php

use App\Http\Controllers\ResearchStudioController as R;
use App\Http\Middleware\ResearchRequestLimits;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/research')->middleware([ResearchRequestLimits::class, 'verified-mobile-ip', 'auth:sanctum', 'active', 'interactive-token', 'throttle:api'])->group(function () {
    Route::get('status', [R::class, 'readiness']);
    Route::get('projects', [R::class, 'index']);
    Route::post('projects', [R::class, 'create']);
    Route::get('invitations', [R::class, 'invitations']);
    Route::post('invitations/{invite}/accept', [R::class, 'accept'])->whereNumber('invite');
    Route::prefix('projects/{id}')->whereNumber('id')->group(function () {
        Route::get('', [R::class, 'show']);
        Route::put('settings', [R::class, 'settings']);
        Route::post('runs', [R::class, 'run'])->middleware('throttle:chat');
        Route::post('components', [R::class, 'component'])->middleware('throttle:chat');
        Route::post('control', [R::class, 'control']);
        Route::get('events', [R::class, 'events'])->withoutMiddleware('throttle:api')->middleware('throttle:research-events');
        Route::put('document', [R::class, 'saveDocument']);
        Route::get('revisions', [R::class, 'revisions']);
        Route::post('sources', [R::class, 'import']);
        Route::get('sources/{source}', [R::class, 'source'])->whereNumber('source');
        Route::put('sources/{source}', [R::class, 'sourceUpdate'])->whereNumber('source');
        Route::post('uploads', [R::class, 'upload'])->middleware('throttle:sensitive');
        Route::put('evidence/{evidence}', [R::class, 'evidence'])->whereNumber('evidence');
        Route::post('comments', [R::class, 'comment']);
        Route::post('members', [R::class, 'invite']);
        Route::delete('members/{member}', [R::class, 'revoke'])->whereNumber('member');
        Route::post('approvals', [R::class, 'approval']);
        Route::post('experiments', [R::class, 'experiment']);
        Route::post('experiments/{experiment}/cancel', [R::class, 'cancelExperiment'])->whereNumber('experiment');
        Route::post('experiments/{experiment}/execute', [R::class, 'execute'])->whereNumber('experiment');
        Route::post('exports', [R::class, 'export']);
        Route::get('exports/{file}/download', [R::class, 'download'])->whereNumber('file');
        Route::get('artifacts/{file}/download', [R::class, 'artifact'])->whereNumber('file');
    });
});
