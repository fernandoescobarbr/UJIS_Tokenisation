# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
- (placeholder)

## [0.1.0] – 2025-08-28
### Overview
Initial PoC release built on a standard **Laravel** skeleton, integrating:
- Hyperledger Fabric chaincode (Node.js) via a CLI wrapper service,
- A REST API surface mapping 1:1 to chaincode functions,
- A minimal PHP/Laravel web UI for listing, viewing and certifying unavailability records,
- Digitally signed PDF certificate issuance with a local verification flow.

### Added
**Controllers**
- `app/Http/Controllers/UnavailabilityController.php`  
  REST endpoints: `version`, `index`, `detail`, `record`, `check`.

- `app/Http/Controllers/UnavailabilityPageController.php`  
  Web UI: list, detail, and certificate PDF generation (signed).

- `app/Http/Controllers/VerificationController.php`  
  Verification web page that consumes the `check` API and reports integrity.

**Service**
- `app/Services/FabricService.php`  
  Thin wrapper around the Fabric `peer` CLI. Sources env, selects org, builds payloads, executes
  `query`/`invoke`, normalises and decodes output.

**Configuration**
- `config/fabric.php`  
  Channel, chaincode name, env bootstrap (`envVar.sh` + `setGlobals N`), absolute paths for workdir/bin/cfg,
  and full `invoke_prefix` with TLS/orderer/peers flags.

- `config/certificate.php`  
  Issuer details, certificate & private key locations (PEM), optional password, and filename prefix.

**Routes**
- `routes/api.php`  
  - `GET  /api/version` → `FunctionVersion`  
  - `GET  /api/unavailabilities` → `GetAllUnavailabilities`  
  - `GET  /api/unavailabilities/{system}/{location}/{startTime}` → `DetailUnavailability`  
  - `POST /api/unavailabilities` → `RecordUnavailability`  
  - `GET  /api/unavailabilities/check` → `CheckUnavailability`

- `routes/web.php`  
  - `GET /unavailabilities` → UI index  
  - `GET /unavailabilities/{system}/{location}/{startTime}` → UI detail  
  - `GET /unavailabilities/{system}/{location}/{startTime}/certificate` → PDF issuance  
  - `GET /verify/{system}/{location}/{startTime}` → Human-readable verification page

- `bootstrap/app.php`  
  Explicit inclusion of `routes/api.php` in `withRouting(...)` and health endpoint `/up`.

**Views**
- `resources/views/unavailabilities/index.blade.php`  
  Tabular list of all unavailability records (no filters).

- `resources/views/unavailabilities/show.blade.php`  
  Detail view with canonical on-chain fields and link to issue a PDF certificate.

- `resources/views/verify/show.blade.php`  
  Verification summary (existence, integrity, matched record, and provided hash).

**Environment**
- `.env` additions (project-specific):
  - `APP_URL=http://127.0.0.1:8000`
  - `CERT_ISSUER_NAME`, `CERT_ISSUER_EMAIL`
  - `VERIFY_BASE` (kept for potential external verifiers; local flow uses `route('verify.show')`)
  - `CERT_PEM`, `CERT_KEY`, `CERT_PASS` (paths to PEM files; password optional)

**Assets (PoC)**
- `certs/cert.pem` – X.509 certificate used for PDF digital signatures.
- `certs/key.pem`  – Matching private key.

### Changed
- **PDF & QR generation**:  
  Implemented certificate issuance with **TCPDF** (native QR code via `write2DBarcode`).  
  This removes the need for Imagick-backed QR libraries in the runtime path.

- **Timeouts & robustness**:  
  `FabricService` sets process timeout to `max(60, wait_timeout + 30)` and strips ANSI sequences.
  It also attempts to extract the first valid JSON block from noisy CLI output.

- **Routing bootstrap**:  
  `bootstrap/app.php` now includes `routes/api.php` explicitly to ensure API routes are active.

### Fixed
- Eliminated dependency on Imagick for QR codes by using TCPDF’s built-in QR renderer.
- Normalised RAW `startTime` usage in certificate and detail routes to match ledger keys precisely.
- Hardened JSON parsing for Fabric CLI output that may include logs/colour codes.

### Security
- Private key and certificate paths are configurable via `.env`. Ensure file permissions restrict read access to the PHP runtime user.
- No secrets are committed to the repository. Do **not** edit files under `vendor/…`.

### Notes on Dependencies
- If you previously installed `simplesoftwareio/simple-qrcode`, it is no longer required for this flow.
- Ensure **TCPDF** is available (e.g., `composer require tecnickcom/tcpdf`) if not already present.

### API Surface (summary)
- `GET  /api/version` → chaincode `FunctionVersion`
- `GET  /api/unavailabilities` → `GetAllUnavailabilities` (query params: `system`, `location`, `date_time`)
- `GET  /api/unavailabilities/{system}/{location}/{startTime}` → `DetailUnavailability`
- `POST /api/unavailabilities` → `RecordUnavailability` (JSON body)
- `GET  /api/unavailabilities/check` → `CheckUnavailability` (requires `hash`)

### Front-end Routes
- `/unavailabilities` (list), `/unavailabilities/{...}` (detail), `/unavailabilities/{...}/certificate` (PDF),
  `/verify/{...}` (human verification; also used by QR deep link).

### Chaincode Mapping (Node.js)
- `FunctionVersion`, `RecordUnavailability`, `DetailUnavailability`, `GetAllUnavailabilities`,
  `CheckUnavailability` (integrity check using verification hash).

### Known Issues / Operational Notes
- Fabric CLI requires correct absolute paths and environment loading; misconfigured `FABRIC_*` envs will surface as runtime exceptions.
- Network calls may be long-running depending on Fabric topology and `--waitForEvent`. Adjust `FABRIC_WAIT_TIMEOUT` appropriately.
- The verification page computes/accepts the SHA-256 hash in canonical order; any field mismatch (including time formats) will fail integrity checks by design.

---
