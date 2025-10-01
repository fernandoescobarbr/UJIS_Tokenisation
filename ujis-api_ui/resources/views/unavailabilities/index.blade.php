<!doctype html>
<html lang="pt">
  <head>
    <meta charset="utf-8">
    <title>Unavailabilities</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--
      View: Unavailabilities – Index
      Purpose:
        - Render a tabular list of all unavailability records fetched from the blockchain (no filters).
        - Each row links to the detailed page for the corresponding record.

      Inputs (from controller):
        - $items : array   List of records; each record exposes System, Location, StartTime, EndTime.
        - $error : ?string Optional error message to display when the fetch fails.

      Accessibility:
        - Table includes role="table" and an aria-label for screen readers.
        - Link in the last column navigates to the detail page (route: ui.unavailabilities.show).
    -->

    <style>
      body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; }
      h1 { margin-bottom: 12px; }
      table { border-collapse: collapse; width: 100%; }
      th, td { border-bottom: 1px solid #e5e7eb; padding: 8px 10px; text-align: left; }
      tr:hover { background: #f9fafb; }
      .muted { color: #6b7280; font-size: 0.9rem; }
      .error { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 6px; margin-bottom: 16px; }
      .pill { display: inline-block; background: #eef2ff; color: #3730a3; padding: 2px 8px; border-radius: 999px; font-size: 12px; }
    </style>
  </head>

  <body>
    <h1>Unavailabilities</h1>
    <p class="muted">Listing all records from the blockchain (no filters).</p>

    {{-- Error banner (non-blocking) --}}
    @if ($error)
      <div class="error">{{ $error }}</div>
    @endif

    {{-- Empty state --}}
    @if (empty($items))
      <p class="muted">No records found.</p>
    @else
      {{-- Main results table --}}
      <table role="table" aria-label="Unavailabilities">
        <thead>
          <tr>
            <th>System</th>
            <th>Location</th>
            <th>Start</th>
            <th>End</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          @foreach ($items as $r)
            <tr>
              <td>{{ $r['System'] ?? '-' }}</td>
              <td>{{ $r['Location'] ?? '-' }}</td>
              <td>{{ $r['StartTime'] ?? '-' }}</td>
              <td>{{ $r['EndTime'] ?? '-' }}</td>
              <td>
                {{-- Deep link to detail view; uses RAW key parts to match ledger composite key --}}
                <a
                  class="pill"
                  href="{{ route('ui.unavailabilities.show', [
                    'system'   => $r['System'] ?? '',
                    'location' => $r['Location'] ?? '',
                    'startTime'=> $r['StartTime'] ?? '',
                  ]) }}"
                >
                  View
                </a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </body>
</html>
