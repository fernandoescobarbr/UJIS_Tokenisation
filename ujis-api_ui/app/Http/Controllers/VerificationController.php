<?php

namespace App\Http\Controllers;

use App\Services\FabricService;
use Illuminate\Http\Request;

/**
 * Controller: VerificationController
 *
 * Purpose:
 * - Renders the certificate verification page.
 * - Always consults the blockchain to (a) fetch the canonical detail and
 *   (b) call the on-chain integrity check (CheckUnavailability).
 *
 * Notes:
 * - The {startTime} route parameter must be the RAW value used in the ledger key.
 * - If the URL lacks ?hash=, we compute the verification hash from canonical fields
 *   obtained via DetailUnavailability (so the page still works from a bare link).
 */
class VerificationController extends Controller
{
    /**
     * Injects the FabricService used to call peer CLI / chaincode.
     */
    public function __construct(private readonly FabricService $fabric) {}

    /**
     * GET /verify/{system}/{location}/{startTime}?hash=<hex>
     *
     * Flow:
     *  1) Retrieve canonical record via DetailUnavailability (to compute a hash if none provided).
     *  2) Determine the effective hash (URL query or computed from canonical fields).
     *  3) Call CheckUnavailability(system, location, {at:startTime}, expectedHash).
     *  4) Render the Blade view with the verification outcome.
     *
     * @param  Request $req
     * @param  string  $system
     * @param  string  $location
     * @param  string  $startTime  RAW start time as stored in the ledger key
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\Response
     */
    public function show(Request $req, string $system, string $location, string $startTime)
    {
        // 1) Fetch canonical detail from the chaincode.
        $detail = $this->fabric->query('DetailUnavailability', [$system, $location, $startTime]);

        // If the chaincode did not return a JSON object, show an error page.
        if (!is_array($detail)) {
            return response()->view('verify.show', [
                'ok'     => false,
                'reason' => is_string($detail)
                    ? mb_strimwidth($detail, 0, 500, '…')
                    : 'Unexpected non-JSON response from chaincode',
                'result' => null,
                'params' => compact('system', 'location', 'startTime') + ['hash' => null],
                'auto'   => false,
            ], 502);
        }

        // 2) Prefer the hash from the query string; otherwise compute it from canonical fields.
        $hashParam = trim((string) $req->query('hash', ''));
        $auto = false; // marks whether the hash has been computed automatically

        if ($hashParam === '') {
            // Canonical fields used for hash computation (aligned with DetailUnavailability).
            $sys   = $detail['System'] ?? $system;
            $loc   = $detail['Location'] ?? $location;
            $stIso = $detail['startTime'] ?? $startTime; // Normalised ISO string
            $enIso = $detail['endTime'] ?? '';
            $dur   = $detail['duration'] ?? '';          // Duration in minutes
            $bcts  = $detail['blockchainTimestamp'] ?? '';

            // Canonical concatenation for SHA-256 (lowercase hex).
            $canonical = implode('|', [
                $sys ?? '',
                $loc ?? '',
                $stIso ?? '',
                $enIso ?? '',
                ($dur !== '' ? (string) $dur : ''),
                $bcts ?? '',
            ]);

            $hashParam = strtolower(hash('sha256', $canonical));
            $auto = true;
        }

        // 3) Invoke the on-chain integrity check with a time filter "at" the RAW startTime.
        $dateTimeFilter = json_encode(['at' => $startTime], JSON_UNESCAPED_SLASHES);

        $resp = $this->fabric->query('CheckUnavailability', [
            $system, $location, $dateTimeFilter, $hashParam,
        ]);

        // If the chaincode did not return a JSON object, render an error state.
        if (!is_array($resp)) {
            return response()->view('verify.show', [
                'ok'     => false,
                'reason' => is_string($resp)
                    ? mb_strimwidth($resp, 0, 500, '…')
                    : 'Unexpected non-JSON response from chaincode',
                'result' => null,
                'params' => compact('system', 'location', 'startTime') + ['hash' => $hashParam],
                'auto'   => $auto,
            ], 502);
        }

        // 4) Success: pass the verification outcome and parameters to the view.
        return view('verify.show', [
            'ok'     => true,
            'reason' => null,
            'result' => $resp,
            'params' => compact('system', 'location', 'startTime') + ['hash' => $hashParam],
            'auto'   => $auto,
        ]);
    }
}
