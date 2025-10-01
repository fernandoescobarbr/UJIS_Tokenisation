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

    
    <?php if(!$ok): ?>
      <div class="bad">Verification failed: <?php echo e($reason); ?></div>
      <p class="muted">
        System: <?php echo e($params['system'] ?? '-'); ?> — 
        Location: <?php echo e($params['location'] ?? '-'); ?> — 
        Start: <?php echo e($params['startTime'] ?? '-'); ?>

      </p>
      <p><a href="<?php echo e(route('ui.unavailabilities.index')); ?>">← Back to list</a></p>

    
    <?php else: ?>
      <?php
        // Normalise flags for presentation
        $exists    = (bool)($result['exists'] ?? false);
        $integrity = (bool)($result['integrity'] ?? false);
        $matchCnt  = $result['matchCount'] ?? null;
      ?>

      
      <?php if($exists && $integrity): ?>
        <div class="ok">Valid certificate — on-chain integrity confirmed.</div>
      <?php elseif($exists && !$integrity): ?>
        <div class="bad">Record(s) found for the given filters, but the provided hash does not match any on-chain record.</div>
      <?php else: ?>
        <div class="bad">No records found on-chain for the given filters.</div>
      <?php endif; ?>

      <div class="card">
        <h3>Verification summary</h3>

        
        <dl>
          <dt>Exists</dt>
          <dd><?php echo e($exists ? 'yes' : 'no'); ?></dd>

          <dt>Integrity</dt>
          <dd><?php echo e($integrity ? 'yes' : 'no'); ?></dd>

          <dt>Match count</dt>
          <dd><?php echo e($matchCnt !== null ? $matchCnt : '-'); ?></dd>

          <dt>Provided hash</dt>
          <dd>
            <span class="mono"><?php echo e($params['hash'] ?? '-'); ?></span>
            <?php if($auto): ?>
              <span class="muted"> (computed automatically)</span>
            <?php endif; ?>
          </dd>
        </dl>

        
        <?php if(!empty($result['id']) || !empty($result['System'])): ?>
          <div class="kv">
            <h3>Matched record (canonical fields)</h3>
            <dl>
              <dt>ID</dt>
              <dd class="mono"><?php echo e($result['id'] ?? '-'); ?></dd>

              <dt>System</dt>
              <dd><?php echo e($result['System'] ?? '-'); ?></dd>

              <dt>Location</dt>
              <dd><?php echo e($result['Location'] ?? '-'); ?></dd>

              <dt>Start</dt>
              <dd><?php echo e($result['startTime'] ?? '-'); ?></dd>

              <dt>End</dt>
              <dd><?php echo e($result['endTime'] ?? '-'); ?></dd>

              <dt>Duration (min)</dt>
              <dd><?php echo e($result['duration'] ?? '-'); ?></dd>

              <dt>Blockchain timestamp</dt>
              <dd><?php echo e($result['blockchainTimestamp'] ?? '-'); ?></dd>

              <dt>Verification hash (on-chain)</dt>
              <dd class="mono"><?php echo e($result['verificationHash'] ?? '-'); ?></dd>
            </dl>
          </div>
        <?php endif; ?>
      </div>

      
      <p class="muted">
        System: <?php echo e($params['system'] ?? '-'); ?> — 
        Location: <?php echo e($params['location'] ?? '-'); ?> — 
        Start: <?php echo e($params['startTime'] ?? '-'); ?>

      </p>
      <p><a href="<?php echo e(route('ui.unavailabilities.index')); ?>">← Back to list</a></p>
    <?php endif; ?>
  </body>
</html>
<?php /**PATH /Users/fernandoescobar/projects/ujis-api/resources/views/verify/show.blade.php ENDPATH**/ ?>