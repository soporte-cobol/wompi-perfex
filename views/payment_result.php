<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php echo payment_gateway_head('Resultado de tu Pago'); ?>

<style>
  @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap');

  :root {
    --primary: #0f172a;
    --accent: #6366f1;
    --success: #10b981;
    --error: #ef4444;
    --pending: #f59e0b;
    --glass: rgba(255, 255, 255, 0.85);
    --glass-border: rgba(255, 255, 255, 0.3);
  }

  body {
    background: radial-gradient(circle at bottom right, #e2e8f0, #f8fafc);
    font-family: 'Outfit', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0;
    overflow: hidden;
  }

  /* Background Elements */
  .bg-blob {
    position: absolute;
    width: 500px;
    height: 500px;
    background: var(--accent);
    filter: blur(100px);
    opacity: 0.15;
    border-radius: 50%;
    z-index: -1;
    animation: pulse 10s infinite alternate;
  }

  @keyframes pulse {
    from { transform: scale(1); opacity: 0.1; }
    to { transform: scale(1.2); opacity: 0.2; }
  }

  .result-card {
    background: var(--glass);
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    border: 1px solid var(--glass-border);
    border-radius: 32px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
    padding: 56px;
    max-width: 480px;
    width: 90%;
    text-align: center;
    animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
  }

  @keyframes slideUp {
    from { opacity: 0; transform: translateY(40px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }

  .wompi-logo {
    width: 110px;
    margin-bottom: 40px;
    filter: drop-shadow(0 4px 6px rgba(0,0,0,0.05));
  }

  .icon-box {
    width: 88px;
    height: 88px;
    border-radius: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 32px;
    font-size: 40px;
    position: relative;
  }

  .icon-box.APPROVED { background: rgba(16, 185, 129, 0.1); color: var(--success); }
  .icon-box.PENDING  { background: rgba(245, 158, 11, 0.1); color: var(--pending); }
  .icon-box.DECLINED, .icon-box.ERROR { background: rgba(239, 68, 68, 0.1); color: var(--error); }

  h2 {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--primary);
    margin-bottom: 12px;
    letter-spacing: -0.5px;
  }

  .subtitle {
    color: #64748b;
    font-size: 1rem;
    line-height: 1.6;
    margin-bottom: 40px;
  }

  .details-box {
    background: rgba(0,0,0,0.02);
    border-radius: 24px;
    padding: 24px;
    margin-bottom: 40px;
    border: 1px solid rgba(0,0,0,0.03);
  }

  .amount-text {
    font-size: 2rem;
    font-weight: 800;
    color: var(--primary);
    margin-bottom: 4px;
  }

  .tx-id {
    font-size: 0.85rem;
    color: #94a3b8;
    font-family: monospace;
  }

  .btn-action {
    display: inline-block;
    background: var(--primary);
    color: white;
    text-decoration: none;
    padding: 18px 40px;
    border-radius: 18px;
    font-weight: 600;
    transition: all 0.3s;
    width: 100%;
    box-shadow: 0 10px 15px -3px rgba(15, 23, 42, 0.2);
  }

  .btn-action:hover {
    transform: translateY(-2px);
    box-shadow: 0 20px 25px -5px rgba(15, 23, 42, 0.2);
    color: #fff;
    text-decoration: none;
  }

  .redirect-ui {
    margin-top: 32px;
  }

  .redirect-text {
    font-size: 0.85rem;
    color: #94a3b8;
    margin-bottom: 12px;
  }

  .progress-container {
    height: 4px;
    background: #f1f5f9;
    border-radius: 10px;
    overflow: hidden;
  }

  .progress-bar {
    height: 100%;
    background: var(--accent);
    width: 0%;
    animation: progressFill <?php echo $redirect_delay ?? 5; ?>s linear forwards;
  }

  @keyframes progressFill {
    to { width: 100%; }
  }

  /* Success Pulse */
  .icon-box.APPROVED::after {
    content: '';
    position: absolute;
    width: 100%;
    height: 100%;
    border-radius: 24px;
    border: 2px solid var(--success);
    animation: iconPulse 2s infinite;
  }

  @keyframes iconPulse {
    0% { transform: scale(1); opacity: 0.8; }
    100% { transform: scale(1.5); opacity: 0; }
  }
</style>

<div class="bg-blob"></div>

<div class="result-card">
  <img class="wompi-logo" src="https://wompi.com/assets/img/logo-wompi-color.svg" alt="Wompi">

  <?php if ($status === 'APPROVED'): ?>
    <div class="icon-box APPROVED">✓</div>
    <h2>¡Pago Exitoso!</h2>
    <p class="subtitle">Hemos recibido tu pago correctamente. Gracias por confiar en nosotros.</p>
  <?php elseif ($status === 'PENDING'): ?>
    <div class="icon-box PENDING">⏳</div>
    <h2>Pago en Revisión</h2>
    <p class="subtitle">Tu transacción está siendo procesada por el banco. Te avisaremos pronto.</p>
  <?php else: ?>
    <div class="icon-box DECLINED">✕</div>
    <h2>Pago Fallido</h2>
    <p class="subtitle">La transacción no pudo ser completada. Por favor, intenta de nuevo.</p>
  <?php endif; ?>

  <div class="details-box">
    <?php if (!empty($amount)): ?>
      <div class="amount-text"><?php echo $currency . ' ' . number_format($amount, 2, '.', ','); ?></div>
    <?php endif; ?>
    <?php if (!empty($transaction_id)): ?>
      <div class="tx-id">Ref: <?php echo htmlspecialchars($transaction_id); ?></div>
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
        confetti(Object.assign({}, defaults, opts, {
          particleCount: Math.floor(count * particleRatio)
        }));
      }

      setTimeout(function() {
          fire(0.25, { spread: 26, startVelocity: 55 });
          fire(0.2, { spread: 60 });
          fire(0.35, { spread: 100, decay: 0.91, scalar: 0.8 });
          fire(0.1, { spread: 120, startVelocity: 25, decay: 0.92, scalar: 1.2 });
          fire(0.1, { spread: 120, startVelocity: 45 });
      }, 500);
    <?php endif; ?>

    // Redirección
    var delay = <?php echo ($redirect_delay ?? 5) * 1000; ?>;
    var url = '<?php echo addslashes($invoice_url ?? site_url()); ?>';
    setTimeout(function() { window.location.href = url; }, delay);
  })();
</script>

<?php echo payment_gateway_footer(); ?>
