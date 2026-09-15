<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php echo payment_gateway_head('Resultado de tu Pago'); ?>

<link rel="stylesheet" type="text/css" href="<?php echo site_url('wompi/callback/css') . '?v=' . WOMPI_MODULE_VERSION; ?>">
<script>
  document.addEventListener("DOMContentLoaded", function() {
    document.body.classList.add("wompi-result-body");
  });
</script>


<div class="bg-blob"></div>

<div class="result-card <?php echo htmlspecialchars($status); ?>">
  <img class="wompi-logo" src="https://wompi.com/assets/downloadble/logos_wompi/Wompi_LogoPrincipal.svg" alt="Wompi">

  <?php if ($status === 'APPROVED'): ?>
    <div class="icon-box APPROVED">
      <svg class="svg-icon" viewBox="0 0 52 52">
        <circle class="checkmark-circle" cx="26" cy="26" r="25" fill="none"/>
        <path class="checkmark-check" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
      </svg>
    </div>
    <h2>¡Pago Exitoso!</h2>
    <p class="subtitle">Hemos recibido tu pago correctamente. Gracias por confiar en nosotros.</p>
  <?php elseif ($status === 'PENDING'): ?>
    <div class="icon-box PENDING">
      <svg class="svg-icon pending-hourglass" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <path d="M5 2h14M5 22h14M19 2v4c0 3.3-2.7 6-6 6s-6-2.7-6-6V2M5 22v-4c0-3.3 2.7-6 6-6s6 2.7 6 6v4" />
      </svg>
    </div>
    <h2>Pago en Revisión</h2>
    <p class="subtitle">Tu transacción está siendo procesada por el banco. Te avisaremos pronto.</p>
  <?php else: ?>
    <div class="icon-box DECLINED">
      <svg class="svg-icon" viewBox="0 0 52 52">
        <circle class="cross-circle" cx="26" cy="26" r="25" fill="none"/>
        <path class="cross-line1" d="M16 16l20 20" />
        <path class="cross-line2" d="M36 16L16 36" />
      </svg>
    </div>
    <h2>Pago Fallido</h2>
    <p class="subtitle">La transacción no pudo ser completada. Por favor, intenta de nuevo.</p>
  <?php endif; ?>

  <div class="details-box">
    <?php if (!empty($amount)): ?>
      <div class="amount-text"><?php echo $currency . ' ' . number_format($amount, 2, '.', ','); ?></div>
    <?php endif; ?>
    <?php if (!empty($transaction_id)): ?>
      <div class="tx-id" style="display: flex; align-items: center; justify-content: center; gap: 6px;">
        <span>Ref: <span id="tx-id-value" style="font-weight: 700;"><?php echo htmlspecialchars($transaction_id); ?></span></span>
        <button id="copy-tx-btn" title="Copiar ID de Transacción" style="background: none; border: none; padding: 4px; cursor: pointer; color: #94a3b8; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s; border-radius: 6px; outline: none;">
          <svg id="copy-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="pointer-events: none;">
            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
          </svg>
          <svg id="check-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="color: #10b981; display: none; pointer-events: none;">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
        </button>
      </div>
      <style>
        #copy-tx-btn:hover {
          background: rgba(148, 163, 184, 0.12);
          color: #475569 !important;
        }
      </style>
    <?php endif; ?>
  </div>

  <a href="<?php echo $invoice_url ?? site_url(); ?>" class="btn-action">
    <?php echo !empty($invoice_url) ? 'Regresar a la Factura' : 'Volver al Inicio'; ?>
  </a>

  <?php if (!empty($invoice_url)): ?>
    <div class="redirect-ui">
      <p class="redirect-text">Redirigiendo automáticamente...</p>
      <div class="progress-container">
        <div class="progress-bar"></div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
<script>
  (function() {
    <?php if ($status === 'APPROVED'): ?>
      // Efecto de Confetti para éxito
      var count = 200;
      var defaults = { origin: { y: 0.7 } };

      function fire(particleRatio, opts) {
        if (typeof confetti === 'function') {
          confetti(Object.assign({}, defaults, opts, {
            particleCount: Math.floor(count * particleRatio)
          }));
        }
      }

      setTimeout(function() {
          fire(0.25, { spread: 26, startVelocity: 55 });
          fire(0.2, { spread: 60 });
          fire(0.35, { spread: 100, decay: 0.91, scalar: 0.8 });
          fire(0.1, { spread: 120, startVelocity: 25, decay: 0.92, scalar: 1.2 });
          fire(0.1, { spread: 120, startVelocity: 45 });
      }, 500);
    <?php endif; ?>

    // Copiar ID de Transacción al Portapapeles
    var copyBtn = document.getElementById('copy-tx-btn');
    if (copyBtn) {
      copyBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var txId = document.getElementById('tx-id-value').textContent.trim();
        navigator.clipboard.writeText(txId).then(function() {
          var copyIcon = document.getElementById('copy-icon');
          var checkIcon = document.getElementById('check-icon');
          if (copyIcon && checkIcon) {
            copyIcon.style.display = 'none';
            checkIcon.style.display = 'inline-block';
            copyBtn.style.color = '#10b981';
            setTimeout(function() {
              copyIcon.style.display = 'inline-block';
              checkIcon.style.display = 'none';
              copyBtn.style.color = '#94a3b8';
            }, 2000);
          }
        });
      });
    }

    // Redirección
    var delay = <?php echo ($redirect_delay ?? 5) * 1000; ?>;
    var url = '<?php echo addslashes($invoice_url ?? site_url()); ?>';
    setTimeout(function() { window.location.href = url; }, delay);
  })();
</script>

<?php echo payment_gateway_footer(); ?>
