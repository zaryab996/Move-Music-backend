<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiProxyController;



Route::post('/auth/obtain-token', [ApiProxyController::class, 'login']);
Route::post('/auth/refresh-token', [ApiProxyController::class, 'refreshToken']);
Route::get('/artists', [ApiProxyController::class, 'artists']);
Route::get('/view-artist/{id}', [ApiProxyController::class, 'view_artist']);
Route::get('/releases', [ApiProxyController::class, 'releases']);
Route::get('/view-release/{id}', [ApiProxyController::class, 'view_release']);
Route::get('/tracks/{id}', [ApiProxyController::class, 'view_track']);
Route::get('/labels', [ApiProxyController::class, 'labels']);
Route::get('/delivered', [ApiProxyController::class, 'delivered_list']);
Route::get('/delivered/{id}', [ApiProxyController::class, 'view_delivered_list']);
Route::get('/statements', [ApiProxyController::class, 'statements']);


