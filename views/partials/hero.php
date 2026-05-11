<?php
// Hero header reusable block
$esc = isset($h) ? $h : fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$icon = $heroIcon ?? 'bi-broadcast-pin';
$title = $heroTitle ?? '';
$subtitle = $heroSubtitle ?? '';
?>
<div class="card card-hero sb-page-hero mb-3">
  <div class="hero-content">
    <div class="hero-icon" aria-hidden="true">
      <i class="bi <?= $esc($icon) ?>"></i>
    </div>
    <div class="hero-copy">
      <h2><?= $esc($title) ?></h2>
      <?php if ($subtitle): ?><div class="hero-subtitle"><?= $esc($subtitle) ?></div><?php endif; ?>
    </div>
    <?php if (!empty($heroExtras)): ?>
      <div class="hero-extras">
        <?= $heroExtras ?>
      </div>
    <?php endif; ?>
  </div>
</div>
