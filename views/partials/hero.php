<?php
// Hero header reusable block
$esc = isset($h) ? $h : fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$icon = $heroIcon ?? 'bi-broadcast-pin';
$title = $heroTitle ?? '';
$subtitle = $heroSubtitle ?? '';
?>
<div class="card card-hero mb-3 p-3">
  <div class="d-flex flex-column align-items-center gap-3 text-center">
    <div class="hero-icon">
      <i class="bi <?= $esc($icon) ?>"></i>
    </div>
    <div>
      <h2 class="mb-0 text-white"><?= $esc($title) ?></h2>
      <?php if ($subtitle): ?><div class="text-white-50"><?= $esc($subtitle) ?></div><?php endif; ?>
    </div>
    <?php if (!empty($heroExtras)): ?>
      <div class="d-flex flex-wrap gap-2 justify-content-center">
        <?= $heroExtras ?>
      </div>
    <?php endif; ?>
  </div>
</div>
