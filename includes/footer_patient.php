<?php
// ============================================================
// ABC Connect — Patient Footer Partial (Bottom Nav + closing tags)
// Requires: $active_nav ('home'|'schedule'|'profile')
// ============================================================
if (!isset($active_nav)) $active_nav = 'home';
?>
</main><!-- /patient-main -->

<!-- Bottom Navigation Bar -->
<nav class="bottom-nav">
  <a href="<?= APP_BASE ?>/patient/dashboard.php"
     class="bottom-nav__item <?= $active_nav === 'home' ? 'active' : '' ?>">
    <span class="material-symbols-outlined <?= $active_nav === 'home' ? 'icon-filled' : '' ?>">home</span>
    <span>Home</span>
  </a>
  <a href="<?= APP_BASE ?>/patient/schedule.php"
     class="bottom-nav__item <?= $active_nav === 'schedule' ? 'active' : '' ?>">
    <span class="bottom-nav__icon-wrap">
      <span class="material-symbols-outlined <?= $active_nav === 'schedule' ? 'icon-filled' : '' ?>">calendar_month</span>
      <?php if ($schedule_badge_count > 0): ?>
      <span class="bottom-nav__badge"><?= $schedule_badge_count > 99 ? '99+' : $schedule_badge_count ?></span>
      <?php endif; ?>
    </span>
    <span>Schedule</span>
  </a>
  <a href="<?= APP_BASE ?>/patient/book.php"
     class="bottom-nav__item <?= $active_nav === 'book' ? 'active' : '' ?>">
    <span class="material-symbols-outlined <?= $active_nav === 'book' ? 'icon-filled' : '' ?>">add_circle</span>
    <span>Book</span>
  </a>
  <a href="<?= APP_BASE ?>/patient/vaccine_card.php"
     class="bottom-nav__item <?= $active_nav === 'card' ? 'active' : '' ?>">
    <span class="material-symbols-outlined <?= $active_nav === 'card' ? 'icon-filled' : '' ?>">badge</span>
    <span>My Card</span>
  </a>
  <a href="<?= APP_BASE ?>/patient/profile.php"
     class="bottom-nav__item <?= $active_nav === 'profile' ? 'active' : '' ?>">
    <span class="material-symbols-outlined <?= $active_nav === 'profile' ? 'icon-filled' : '' ?>">person</span>
    <span>Profile</span>
  </a>
</nav>

<script src="<?= APP_BASE ?>/assets/js/patient.js"></script>
<?php if (isset($extra_js)) echo $extra_js; ?>
</body>
</html>
