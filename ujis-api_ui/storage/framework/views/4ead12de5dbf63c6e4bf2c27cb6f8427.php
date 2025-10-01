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

    
    <?php if($error): ?>
      <div class="error"><?php echo e($error); ?></div>
    <?php endif; ?>

    
    <?php if(empty($items)): ?>
      <p class="muted">No records found.</p>
    <?php else: ?>
      
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
          <?php $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $r): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
              <td><?php echo e($r['System'] ?? '-'); ?></td>
              <td><?php echo e($r['Location'] ?? '-'); ?></td>
              <td><?php echo e($r['StartTime'] ?? '-'); ?></td>
              <td><?php echo e($r['EndTime'] ?? '-'); ?></td>
              <td>
                
                <a
                  class="pill"
                  href="<?php echo e(route('ui.unavailabilities.show', [
                    'system'   => $r['System'] ?? '',
                    'location' => $r['Location'] ?? '',
                    'startTime'=> $r['StartTime'] ?? '',
                  ])); ?>"
                >
                  View
                </a>
              </td>
            </tr>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
      </table>
    <?php endif; ?>
  </body>
</html>
<?php /**PATH /Users/fernandoescobar/projects/ujis-api/resources/views/unavailabilities/index.blade.php ENDPATH**/ ?>