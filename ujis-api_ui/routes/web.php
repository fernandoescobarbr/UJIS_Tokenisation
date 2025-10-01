<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UnavailabilityPageController;
use App\Http\Controllers\VerificationController;

/**
 * Web routes (HTML views + certificate/verification flows)
 * ------------------------------------------------------------------
 * Notes:
 *  - These routes render Blade views (not JSON).
 *  - The `{startTime}` segment must carry the RAW value used as the
 *    ledger key (e.g., `2025-08-25T09:02:00-03:00`). Do not normalise
 *    to UTC in the URL; normalisation happens inside the app when needed.
 *  - Keep route ordering: the more specific `/certificate` route must
 *    appear before the generic `{system}/{location}/{startTime}` show route.
 */

/* ---------- UI: list all (optionally consuming FabricService directly) ---------- */
Route::get('/unavailabilities', [UnavailabilityPageController::class, 'index'])
    ->name('ui.unavailabilities.index'); // GET: HTML table with all records (no filters)

/* ---------- UI: detail page (canonical on-chain view for one record) ---------- */
Route::get(
    '/unavailabilities/{system}/{location}/{startTime}',
    [UnavailabilityPageController::class, 'show']
)->name('ui.unavailabilities.show'); // GET: details for a specific (system, location, startTime)

/* ---------- UI: certificate emission (TCPDF + digital signature) ---------- */
Route::get(
    '/unavailabilities/{system}/{location}/{startTime}/certificate',
    [UnavailabilityPageController::class, 'certificate']
)->name('ui.unavailabilities.certificate'); // GET: generates and streams signed PDF

/* ---------- Public verification endpoint (QR points here) ---------- */
Route::get(
    '/verify/{system}/{location}/{startTime}',
    [VerificationController::class, 'show']
)->name('verify.show'); // GET: consumes chaincode "CheckUnavailability" and renders verdict
