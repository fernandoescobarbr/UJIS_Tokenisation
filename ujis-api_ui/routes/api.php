<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UnavailabilityController;

/**
 * API Routes (stateless)
 *
 * Purpose:
 * - Expose REST endpoints that map 1:1 to chaincode functions.
 * - Controllers return JSON responses suitable for programmatic clients.
 *
 * Notes:
 * - All routes are prefixed with /api by Laravel's RouteServiceProvider.
 * - Keep these routes thin; validation and CLI execution are handled in the controller/service layer.
 */

// Chaincode: FunctionVersion
Route::get('/version', [UnavailabilityController::class, 'version']); // FunctionVersion

// Chaincode: GetAllUnavailabilities (optionally filtered via query string)
Route::get('/unavailabilities', [UnavailabilityController::class, 'index']); // GetAllUnavailabilities

// Chaincode: DetailUnavailability (requires RAW key parts)
Route::get(
    '/unavailabilities/{system}/{location}/{startTime}',
    [UnavailabilityController::class, 'detail']
); // DetailUnavailability

// Chaincode: RecordUnavailability (write path; expects JSON body)
Route::post('/unavailabilities', [UnavailabilityController::class, 'record']); // RecordUnavailability

// Chaincode: CheckUnavailability (integrity / existence check)
Route::get('/unavailabilities/check', [UnavailabilityController::class, 'check']); // CheckUnavailability
