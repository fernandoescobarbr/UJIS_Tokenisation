<?php

namespace App\Http\Controllers;

use App\Services\FabricService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Response;

/**
 * Controller: UnavailabilityPageController
 *
 * Purpose:
 * - Render HTML pages (list, detail) for unavailability records.
 * - Generate a digitally signed PDF certificate for a specific record.
 *
 * Notes:
 * - All calls that hit the ledger go through FabricService (peer CLI wrapper).
 * - The {startTime} route parameter must remain the RAW string used as part of
 *   the ledger key (e.g., "2025-08-25T09:02:00-03:00"). Do not normalise it in the URL.
 */
class UnavailabilityPageController extends Controller
{
    /**
     * Inject the FabricService used to execute peer CLI (query/invoke).
     */
    public function __construct(private readonly FabricService $fabric)
    {
    }

    /**
     * GET /unavailabilities
     *
     * Renders the list page. Fetches all records (no filters) from chaincode
     * and sorts descending by StartTime (if present).
     */
    public function index()
    {
        $resp = $this->fabric->query('GetAllUnavailabilities', ['', '', '']);

        // If the API returned a single object (not an array), wrap it into an array for the view.
        if (is_array($resp) && isset($resp['ID'])) {
            $resp = [$resp];
        }

        // If the payload is not JSON-decoded into an array, surface an error page.
        if (!is_array($resp)) {
            $msg = is_string($resp)
                ? mb_strimwidth($resp, 0, 500, '…')
                : 'Unexpected non-JSON response';

            return response()->view('unavailabilities.index', [
                'items' => [],
                'error' => $msg,
            ], 502);
        }

        // Sort by StartTime (descending) when available.
        usort($resp, fn ($a, $b) => strcmp($b['StartTime'] ?? '', $a['StartTime'] ?? ''));

        return view('unavailabilities.index', [
            'items' => $resp,
            'error' => null,
        ]);
    }

    /**
     * GET /unavailabilities/{system}/{location}/{startTime}
     *
     * Renders the detail page for a specific record.
     * The route parameters (system, location, startTime RAW) are preserved in
     * 'routeParams' to be reused for certificate generation and links.
     *
     * @param string $system
     * @param string $location
     * @param string $startTime RAW value as stored in the ledger key
     */
    public function show(string $system, string $location, string $startTime)
    {
        $item = $this->fabric->query('DetailUnavailability', [$system, $location, $startTime]);

        if (!is_array($item)) {
            return response()->view('unavailabilities.show', [
                'item'        => null,
                'error'       => is_string($item)
                    ? mb_strimwidth($item, 0, 500, '…')
                    : 'Unexpected non-JSON response',
                'routeParams' => ['system' => $system, 'location' => $location, 'startTime' => $startTime],
            ], 502);
        }

        return view('unavailabilities.show', [
            'item'        => $item,
            'error'       => null,
            // Keep the original (RAW) values coming from the list/URL.
            'routeParams' => ['system' => $system, 'location' => $location, 'startTime' => $startTime],
        ]);
    }

    /**
     * GET /unavailabilities/{system}/{location}/{startTime}/certificate
     *
     * Generates and streams a digitally signed PDF certificate:
     * - Fetches canonical data on-chain (DetailUnavailability).
     * - Ensures a verification hash is present (fallback to local recomputation).
     * - Builds a verification URL (APP_URL-based) using RAW startTime for the route.
     * - Embeds a QR Code pointing to the verification URL.
     * - Applies a digital signature with the configured PEMs (if available).
     *
     * @param string $system
     * @param string $location
     * @param string $startTime RAW value as stored in the ledger key
     */
    public function certificate(string $system, string $location, string $startTime)
    {
        // Always use the RAW startTime (exactly as stored in the key).
        $startTimeRaw = $startTime;

        // 1) Retrieve canonical data from chaincode.
        $res = $this->fabric->query('DetailUnavailability', [$system, $location, $startTimeRaw]);
        if (!is_array($res)) {
            return response('Unable to fetch detail from blockchain', 502);
        }

        // 2) Canonical fields (normalised for display).
        $sys  = $res['System'] ?? $system;
        $loc  = $res['Location'] ?? $location;
        $stIso = $res['startTime'] ?? $startTimeRaw;
        $enIso = $res['endTime'] ?? null;
        $dur   = $res['duration'] ?? null; // minutes
        $bcts  = $res['blockchainTimestamp'] ?? null;
        $hash  = $res['verificationHash'] ?? null;

        // Fallback: if chaincode did not return a hash, recompute it locally.
        if (!$hash) {
            $canonical = implode('|', [
                $sys ?? '',
                $loc ?? '',
                $stIso ?? '',
                $enIso ?? '',
                ($dur !== null ? (string) $dur : ''),
                $bcts ?? '',
            ]);
            $hash = strtolower(hash('sha256', $canonical));
        }

        // 3) Stable & file-safe certificate identifier.
        //    - Remove ':' from startTime and restrict to safe characters.
        $stSlug  = preg_replace('/[^A-Za-z0-9T\-\._]/', '', str_replace(':', '', $startTimeRaw));
        $sysSlug = preg_replace('/[^A-Za-z0-9\-\._]/', '', $sys);
        $locSlug = preg_replace('/[^A-Za-z0-9\-\._]/', '', $loc);
        $certId  = "{$sysSlug}-{$locSlug}-{$stSlug}";

        // 4) Local verification URL (based on APP_URL) using RAW parameters.
        $verifyUrl = route('verify.show', [
            'system'    => $sys,
            'location'  => $loc,
            'startTime' => $startTimeRaw, // ALWAYS pass the RAW value
            'hash'      => $hash,         // guaranteed now
        ]);

        // 5) Build the PDF document (A4, simple layout).
        $issuerName  = config('certificate.issuer_name');
        $issuerEmail = config('certificate.issuer_email');

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('UJIS');
        $pdf->SetAuthor($issuerName);
        $pdf->SetTitle('Certificate of Availability of Judicial Information System for Electronic Processes');
        $pdf->SetMargins(15, 15, 15);
        $pdf->AddPage();

        // Header
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, 'CERTIFICATE OF SYSTEM UNAVAILABILITY', 0, 1, 'C');
        $pdf->Ln(2);

        $pdf->SetFont('helvetica', '', 11);

        // Body key-value pairs.
        $lines = [
            ['Certificate ID', $certId],
            ['System', $sys],
            ['Location', $loc],
            ['Start Date', $stIso],
            ['End Date', $enIso ?: '-'],
            ['Total Downtime (minutes)', $dur !== null ? (string) $dur : '-'],
            ['Blockchain Timestamp', $bcts ?: '-'],
            ['Issued At', gmdate('Y-m-d\TH:i:s\Z')],
            ['Issuer', $issuerName . ($issuerEmail ? " ({$issuerEmail})" : '')],
            ['Verification Hash', $hash ?: '-'],
            ['Verification URL', $verifyUrl],
        ];

        foreach ($lines as [$label, $value]) {
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(60, 6, $label . ':', 0, 0);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->MultiCell(0, 6, (string) $value, 0, 'L', false, 1);
        }

        // QR Code (TCPDF native; no Imagick dependency).
        $qrStyle = [
            'border'        => 0,
            'padding'       => 0,
            'fgcolor'       => [0, 0, 0],
            'bgcolor'       => false,
            'module_width'  => 1,
            'module_height' => 1,
        ];
        // Adjust position/size as needed (x, y, w, h) in mm.
        $pdf->write2DBarcode($verifyUrl, 'QRCODE,H', 170, 245, 25, 25, $qrStyle, 'N');

        // 6) Apply digital signature if PEMs are available.
        $certPem = config('certificate.cert_pem');
        $keyPem  = config('certificate.key_pem');
        $keyPass = config('certificate.key_pass');

        if (is_readable($certPem) && is_readable($keyPem)) {
            $sigInfo = [
                'Name'        => $issuerName,
                'Location'    => $loc,
                'Reason'      => 'Certificate issuance',
                'ContactInfo' => $issuerEmail,
            ];
            $pdf->setSignature('file://'.$certPem, 'file://'.$keyPem, $keyPass, '', 2, $sigInfo);

            // Optional: visible signature appearance placeholder (position/size in mm).
            $pdf->setSignatureAppearance(140, 245, 25, 8);
        }

        // 7) Stream the PDF inline to the browser.
        $fileName = sprintf('%s-%s.pdf', config('certificate.file_prefix', 'certificate'), $certId);
        $content  = $pdf->Output($fileName, 'S');

        return \Response::make($content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
        ]);
    }
}
