<!doctype html>
<html lang="pt">
  <head>
    <meta charset="utf-8">
    <title>Certificate Verification</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--
      View: Certificate Verification
      Purpose: Render the outcome of on-chain verification (existence + integrity)
      Notes:
        - Expects: $ok, $reason, $result (array), $params (system/location/startTime/hash), $auto (bool)
        - When $ok=false, an error banner is shown with the failure reason.
        - When $ok=true, displays a summary and (if matched) canonical record fields.
    -->

    <style>
      body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; }
      h1 { margin-bottom: 8px; }
      .muted { color: #6b7280; }
      .ok { background:#ecfdf5; color:#065f46; padding:10px; border-radius:8px; }
      .bad { background:#fee2e2; color:#7f1d1d; padding:10px; border-radius:8px; }
      .card { border:1px solid #e5e7eb; border-radius:10px; padding:16px; max-width:1000px; }
      .row { display:flex; gap:18px; margin-bottom:8px; flex-wrap: wrap; }
      .col { flex:1; min-width:220px; }
      .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.9rem; }
      a { color:#2563eb; text-decoration:none; }
      a:hover { text-decoration:underline; }
      dl { display:grid; grid-template-columns: 220px 1fr; gap:8px 12px; }
      dt { font-weight:600; }
      dd { margin:0; }
      .kv { margin-top:12px; }
    </style>
  </head>

  <body>
    <h1>Certificate Verification</h1>

    {{-- Error branch: verification could not run or returned an error --}}
    @if(!$ok)
      <div class="bad">Verification failed: {{ $reason }}</div>
      <p class="muted">
        System: {{ $params['system'] ?? '-' }} — 
        Location: {{ $params['location'] ?? '-' }} — 
        Start: {{ $params['startTime'] ?? '-' }}
      </p>
      <p><a href="{{ route('ui.unavailabilities.index') }}">← Back to list</a></p>

    {{-- Success branch: we have a verification outcome to display --}}
    @else
      @php
        // Normalise flags for presentation
        $exists    = (bool)($result['exists'] ?? false);
        $integrity = (bool)($result['integrity'] ?? false);
        $matchCnt  = $result['matchCount'] ?? null;
      @endphp

      {{-- Status banner reflecting existence + integrity --}}
      @if($exists && $integrity)
        <div class="ok">Valid certificate — on-chain integrity confirmed.</div>
      @elseif($exists && !$integrity)
        <div class="bad">Record(s) found for the given filters, but the provided hash does not match any on-chain record.</div>
      @else
        <div class="bad">No records found on-chain for the given filters.</div>
      @endif

      <div class="card">
        <h3>Verification summary</h3>

        {{-- Key verification metrics --}}
        <dl>
          <dt>Exists</dt>
          <dd>{{ $exists ? 'yes' : 'no' }}</dd>

          <dt>Integrity</dt>
          <dd>{{ $integrity ? 'yes' : 'no' }}</dd>

          <dt>Match count</dt>
          <dd>{{ $matchCnt !== null ? $matchCnt : '-' }}</dd>

          <dt>Provided hash</dt>
          <dd>
            <span class="mono">{{ $params['hash'] ?? '-' }}</span>
            @if($auto)
              <span class="muted"> (computed automatically)</span>
            @endif
          </dd>
        </dl>

        {{-- Canonical fields from the matched record (shown only when available) --}}
        @if(!empty($result['id']) || !empty($result['System']))
          <div class="kv">
            <h3>Matched record (canonical fields)</h3>
            <dl>
              <dt>ID</dt>
              <dd class="mono">{{ $result['id'] ?? '-' }}</dd>

              <dt>System</dt>
              <dd>{{ $result['System'] ?? '-' }}</dd>

              <dt>Location</dt>
              <dd>{{ $result['Location'] ?? '-' }}</dd>

              <dt>Start</dt>
              <dd>{{ $result['startTime'] ?? '-' }}</dd>

              <dt>End</dt>
              <dd>{{ $result['endTime'] ?? '-' }}</dd>

              <dt>Duration (min)</dt>
              <dd>{{ $result['duration'] ?? '-' }}</dd>

              <dt>Blockchain timestamp</dt>
              <dd>{{ $result['blockchainTimestamp'] ?? '-' }}</dd>

              <dt>Verification hash (on-chain)</dt>
              <dd class="mono">{{ $result['verificationHash'] ?? '-' }}</dd>
            </dl>
          </div>
        @endif
      </div>

      {{-- Context and navigation --}}
      <p class="muted">
        System: {{ $params['system'] ?? '-' }} — 
        Location: {{ $params['location'] ?? '-' }} — 
        Start: {{ $params['startTime'] ?? '-' }}
      </p>
      <p><a href="{{ route('ui.unavailabilities.index') }}">← Back to list</a></p>
    @endif
  </body>
</html>
