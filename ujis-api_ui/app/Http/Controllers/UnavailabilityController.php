<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\FabricService;

/**
 * REST Controller: UnavailabilityController
 *
 * Purpose:
 * - Expose a thin HTTP layer over the chaincode functions.
 * - Perform light input validation and delegate execution to FabricService,
 *   which encapsulates peer CLI invocation (query/invoke).
 *
 * Notes:
 * - Responses are JSON-encoded and preserve the payload returned by the chaincode.
 * - Keep this controller stateless; do not cache ledger reads unless explicitly required.
 */
class UnavailabilityController extends Controller
{
    /**
     * Injects the FabricService dependency (peer CLI wrapper).
     */
    public function __construct(private readonly FabricService $fabric)
    {
    }

    /**
     * GET /api/version
     *
     * Calls the chaincode function "FunctionVersion" and returns its result.
     * Useful as a liveness/configuration probe and to confirm rollout versions.
     *
     * @return \Illuminate\Http\JsonResponse { "version": "<semver|string>" }
     */
    public function version()
    {
        // Calls FunctionVersion()
        $res = $this->fabric->query('FunctionVersion', []);

        return response()->json(['version' => $res], 200);
    }

    /**
     * GET /api/unavailabilities?system=...&location=...&date_time=...
     *
     * Delegates to "GetAllUnavailabilities" with optional filters:
     * - system   : string|empty (normalised by chaincode)
     * - location : string|empty
     * - date_time: string|JSON; supports {"from": "...", "to": "..."} or {"at": "..."}
     *
     * @param  Request $req
     * @return \Illuminate\Http\JsonResponse  Array of records (possibly empty)
     */
    public function index(Request $req)
    {
        $system   = $req->query('system', '');
        $location = $req->query('location', '');
        $dateTime = $req->query('date_time', '');

        // GetAllUnavailabilities(ctx, system, location, date_time)
        $res = $this->fabric->query('GetAllUnavailabilities', [$system, $location, $dateTime]);

        return response()->json($res, 200);
    }

    /**
     * GET /api/unavailabilities/{system}/{location}/{startTime}
     *
     * Fetches the canonical on-chain record by composite key parts.
     * The {startTime} must be the RAW value used in the ledger key.
     *
     * @param  string $system
     * @param  string $location
     * @param  string $startTime
     * @return \Illuminate\Http\JsonResponse
     */
    public function detail(string $system, string $location, string $startTime)
    {
        // DetailUnavailability(ctx, system, location, startTime)
        $res = $this->fabric->query('DetailUnavailability', [$system, $location, $startTime]);

        return response()->json($res, 200);
    }

    /**
     * POST /api/unavailabilities
     * Body JSON: { "system": "...", "location": "...", "startTime": "...", "endTime": "..." }
     *
     * Records a new unavailability via "RecordUnavailability".
     * Input is lightly validated; timestamps are expected to be ISO-8601 strings.
     *
     * @param  Request $req
     * @return \Illuminate\Http\JsonResponse 201 on success with the chaincode's echo payload
     */
    public function record(Request $req)
    {
        $data = $req->validate([
            'system'    => 'required|string',
            'location'  => 'required|string',
            'startTime' => 'required|string', // recommend ISO-8601 UTC
            'endTime'   => 'required|string', // recommend ISO-8601 UTC
        ]);

        // RecordUnavailability(ctx, system, location, startTime, endTime)
        $res = $this->fabric->invoke('RecordUnavailability', [
            $data['system'], $data['location'], $data['startTime'], $data['endTime'],
        ]);

        return response()->json($res, 201);
    }

    /**
     * GET /api/unavailabilities/check?system=...&location=...&date_time=...&hash=...
     *
     * Performs integrity verification through "CheckUnavailability".
     * - Requires a verification hash (hex SHA-256) in the query string.
     * - The chaincode returns existence/integrity flags and optional matched record summary.
     *
     * @param  Request $req
     * @return \Illuminate\Http\JsonResponse 422 if hash is missing; 200 with check result otherwise
     */
    public function check(Request $req)
    {
        $system   = $req->query('system', '');
        $location = $req->query('location', '');
        $dateTime = $req->query('date_time', '');
        $hash     = $req->query('hash', '');

        if (!$hash) {
            // Client error: integrity check requires a hash to compare against on-chain data.
            return response()->json(['error' => 'hash is required'], 422);
        }

        // CheckUnavailability(ctx, system, location, date_time, expectedHash)
        $res = $this->fabric->query('CheckUnavailability', [$system, $location, $dateTime, $hash]);

        return response()->json($res, 200);
    }
}
