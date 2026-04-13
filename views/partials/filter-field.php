<?php
$esc = $esc ?? fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$field = $field ?? [];
$col = (int)($field['col'] ?? 3);
$label = $field['label'] ?? '';
$name = $field['name'] ?? '';
$value = $field['value'] ?? '';
$type = $field['type'] ?? 'text';
$options = $field['options'] ?? [];
$inputId = $field['id'] ?? ($name ? 'filter-' . preg_replace('/[^a-z0-9]+/i', '-', $name) : 'filter-field');
$floating = $field['floating'] ?? true;
$ariaLabel = $field['aria_label'] ?? $label;
?>
<div class="col-md-<?= max(1, min(6, $col)) ?>">
  <?php if ($floating): ?>
    <div class="form-floating">
      <?php if ($type === 'select'): ?>
        <select
          id="<?= $esc($inputId) ?>"
          name="<?= $esc($name) ?>"
          class="form-select form-select-sm"
          aria-label="<?= $esc($ariaLabel) ?>">
          <?php foreach ($options as $optValue => $optLabel): ?>
            <option value="<?= $esc($optValue) ?>" <?= (string)$optValue === (string)$value ? 'selected' : '' ?>>
              <?= $esc($optLabel) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <label for="<?= $esc($inputId) ?>"><?= $esc($label) ?></label>
      <?php else: ?>
        <input
          type="<?= $esc($type) ?>"
          id="<?= $esc($inputId) ?>"
          name="<?= $esc($name) ?>"
          value="<?= $esc($value) ?>"
          class="form-control form-control-sm"
          placeholder=" "
          aria-label="<?= $esc($ariaLabel) ?>">
        <label for="<?= $esc($inputId) ?>"><?= $esc($label) ?></label>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <label class="form-label"><?= $esc($label) ?></label>
    <?php if ($type === 'select'): ?>
      <select
        id="<?= $esc($inputId) ?>"
        name="<?= $esc($name) ?>"
        class="form-select form-select-sm"
        aria-label="<?= $esc($ariaLabel) ?>">
        <?php foreach ($options as $optValue => $optLabel): ?>
          <option value="<?= $esc($optValue) ?>" <?= (string)$optValue === (string)$value ? 'selected' : '' ?>>
            <?= $esc($optLabel) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <input
        type="<?= $esc($type) ?>"
        id="<?= $esc($inputId) ?>"
        name="<?= $esc($name) ?>"
        value="<?= $esc($value) ?>"
        class="form-control form-control-sm"
        aria-label="<?= $esc($ariaLabel) ?>">
    <?php endif; ?>
  <?php endif; ?>
</div>
