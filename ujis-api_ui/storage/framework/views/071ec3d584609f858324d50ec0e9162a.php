<!doctype html>
<html lang="pt">
  <head>
    <meta charset="utf-8">
    <title>Unavailability – Detail</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--
      View: Unavailability – Detail
      Purpose:
        - Render a single unavailability record fetched from the blockchain (via API/FabricService).
        - Provide a link to generate the signed PDF certificate for this exact record.

      Inputs (from controller):
        - $item        : array|null  Canonical record fields (System, Location, startTime, endTime, duration, blockchainTimestamp, verificationHash)
        - $error       : string|null Error message when detail could not be retrieved.
        - $routeParams : array       Raw route parameters ['system','location','startTime'] (RAW startTime required for ledger key).

      Notes:
        - The certificate route must receive the RAW startTime as used in the ledger key.
        - Presentation only; no business logic performed in the view.
    -->

    <style>
      body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; }
      h1 { margin-bottom: 8px; }
      .muted { color: #6b7280; font-size: 0.95rem; }
      .card { border:1px solid #e5e7eb; border-radius:10px; padding:16px; max-width:800px; }
      .row { display:flex; gap:18px; margin-bottom:8px; }
      .col { flex:1; }
      .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.9rem; }
      .error { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 6px; margin-bottom: 16px; }
      a { color:#2563eb; text-decoration:none; }
      a:hover { text-decoration:underline; }
    </style>
  </head>

  <body>
    <p><a href="<?php echo e(route('ui.unavailabilities.index')); ?>">← Back to list</a></p>

    <h1>Unavailability – Detail</h1>
    <p class="muted">Fetched from the blockchain via API.</p>

    <!--
      Action: Generate a digitally signed PDF certificate for this record.
      Important: Use RAW route parameters so the chaincode key matches exactly.
    -->
    <p>
      <a
        class="pill"
        href="<?php echo e(route('ui.unavailabilities.certificate', [
          'system'    => $routeParams['system'],
          'location'  => $routeParams['location'],
          'startTime' => $routeParams['startTime'],   
        ])); ?>"
      >
        Generate Certificate (PDF)
      </a>
    </p>

    <?php if($error): ?>
      
      <div class="error"><?php echo e($error); ?></div>

    <?php elseif(!$item): ?>
      
      <p class="muted">No data available.</p>

    <?php else: ?>
      
      <div class="card">
        <div class="row">
          <div class="col">
            <strong>System</strong><br>
            <?php echo e($item['System'] ?? '-'); ?>

          </div>
          <div class="col">
            <strong>Location</strong><br>
            <?php echo e($item['Location'] ?? '-'); ?>

          </div>
        </div>

        <div class="row">
          <div class="col">
            <strong>Start</strong><br>
            <?php echo e($item['startTime'] ?? '-'); ?>

          </div>
          <div class="col">
            <strong>End</strong><br>
            <?php echo e($item['endTime'] ?? '-'); ?>

          </div>
          <div class="col">
            <strong>Duration (min)</strong><br>
            <?php echo e($item['duration'] ?? '-'); ?>

          </div>
        </div>

        <div class="row">
          <div class="col">
            <strong>Blockchain timestamp</strong><br>
            <?php echo e($item['blockchainTimestamp'] ?? '-'); ?>

          </div>
        </div>

        <div class="row">
          <div class="col">
            <strong>Verification hash</strong><br>
            <span class="mono"><?php echo e($item['verificationHash'] ?? '-'); ?></span>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </body>
</html>
<?php /**PATH /Users/fernandoescobar/projects/ujis-api/resources/views/unavailabilities/show.blade.php ENDPATH**/ ?>