<?php
/**
 * Shared App Shell Navigation Partial (Sidebar + Sticky Topbar)
 * Expects: $user (array), $pageTitle (string), $activeNav ('home'|'history'|'settings'|'about')
 */
$currentPage = $activeNav ?? 'home';
$topbarTitle = $pageTitle ?? 'Monitoring Kenyamanan Termal';
$avatarSrc = resolve_profile_pic($user['profile_pic'] ?? null);
$initials = user_initials($user['username'] ?? 'U');
?>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<aside class="app-sidebar" id="appSidebar" aria-label="Navigasi Utama">
  <div class="sidebar-brand">
    <img src="logo.png" alt="Logo Monitoring Termal">
    <div>
      <div class="sidebar-brand-title">ThermoMonitor</div>
      <div class="sidebar-brand-sub">Kenyamanan Termal</div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="sidebar-section-label">Pemantauan</div>
    <a href="index.php" class="sidebar-link <?= $currentPage === 'home' ? 'is-active' : '' ?>">
      <i class="fas fa-gauge-high"></i>
      <span>Dashboard Real-Time</span>
    </a>
    <a href="history.php" class="sidebar-link <?= $currentPage === 'history' ? 'is-active' : '' ?>">
      <i class="fas fa-clock-rotate-left"></i>
      <span>Data Historis</span>
    </a>

    <div class="sidebar-section-label" style="margin-top: 20px;">Konfigurasi</div>
    <a href="settings.php" class="sidebar-link <?= $currentPage === 'settings' ? 'is-active' : '' ?>">
      <i class="fas fa-sliders"></i>
      <span>Pengaturan</span>
    </a>
    <a href="about.php" class="sidebar-link <?= $currentPage === 'about' ? 'is-active' : '' ?>">
      <i class="fas fa-circle-question"></i>
      <span>Panduan &amp; Integrasi</span>
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user-card">
      <?php if ($avatarSrc): ?>
        <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="Profil" class="avatar-circle">
      <?php else: ?>
        <span class="avatar-circle"><?= htmlspecialchars($initials) ?></span>
      <?php endif; ?>
      <div style="min-width: 0; flex: 1;">
        <div style="font-size: 13px; font-weight: 700; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
          <?= htmlspecialchars($user['username']) ?>
        </div>
        <div style="font-size: 11.5px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
          <?= htmlspecialchars($user['email']) ?>
        </div>
      </div>
      <a href="logout.php" class="btn-icon-only" title="Keluar" aria-label="Keluar" style="width: 32px; height: 32px; color: var(--status-critical);">
        <i class="fas fa-right-from-bracket" style="font-size: 13px;"></i>
      </a>
    </div>
  </div>
</aside>

<div class="app-main">
  <header class="app-topbar">
    <div class="topbar-left">
      <button type="button" class="btn-icon-only mobile-menu-trigger" id="mobileMenuToggle" aria-label="Buka menu navigasi">
        <i class="fas fa-bars"></i>
      </button>
      <h1 class="topbar-title"><?= htmlspecialchars($topbarTitle) ?></h1>
    </div>

    <div class="topbar-right">
      <span class="status-pill-live" id="topbarLiveStatus" title="Status koneksi sensor">
        <span class="status-dot"></span>
        <span class="hide-mobile">Sensor Aktif</span>
      </span>

      <button type="button" class="btn-icon-only" id="themeToggleBtn" aria-label="Ganti tema tampilan" title="Ganti tema terang/gelap">
        <i class="fas fa-sun" id="themeToggleIcon"></i>
      </button>

      <div class="profile-menu-wrap">
        <button type="button" class="profile-trigger" id="profileMenuBtn" aria-expanded="false" aria-haspopup="true">
          <?php if ($avatarSrc): ?>
            <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="Profil" class="avatar-circle" style="width: 30px; height: 30px;">
          <?php else: ?>
            <span class="avatar-circle" style="width: 30px; height: 30px; font-size: 12px;"><?= htmlspecialchars($initials) ?></span>
          <?php endif; ?>
          <span class="hide-mobile" style="font-size: 13.5px; font-weight: 600; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            <?= htmlspecialchars($user['username']) ?>
          </span>
          <i class="fas fa-chevron-down" style="font-size: 11px; color: var(--text-muted);"></i>
        </button>

        <div class="profile-dropdown" id="profileMenuDropdown" role="menu">
          <div class="profile-dropdown-header">
            <div style="font-size: 14px; font-weight: 700; color: var(--text-primary);"><?= htmlspecialchars($user['username']) ?></div>
            <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px; word-break: break-all;"><?= htmlspecialchars($user['email']) ?></div>
            <?php if (!empty($user['phone'])): ?>
              <div class="mono" style="font-size: 11.5px; color: var(--text-muted); margin-top: 2px;"><?= htmlspecialchars($user['phone']) ?></div>
            <?php endif; ?>
          </div>
          <a href="settings.php?tab=profile" class="profile-dropdown-item" role="menuitem">
            <i class="fas fa-user-pen"></i> Kelola Profil
          </a>
          <a href="settings.php?tab=preferences" class="profile-dropdown-item" role="menuitem">
            <i class="fas fa-sliders"></i> Ambang Batas Sensor
          </a>
          <a href="logout.php" class="profile-dropdown-item is-danger" role="menuitem">
            <i class="fas fa-right-from-bracket"></i> Keluar
          </a>
        </div>
      </div>
    </div>
  </header>

<script>
(function() {
  const sidebar = document.getElementById('appSidebar');
  const backdrop = document.getElementById('sidebarBackdrop');
  const mobileToggle = document.getElementById('mobileMenuToggle');
  const profileBtn = document.getElementById('profileMenuBtn');
  const profileDropdown = document.getElementById('profileMenuDropdown');
  const themeBtn = document.getElementById('themeToggleBtn');
  const themeIcon = document.getElementById('themeToggleIcon');

  function syncThemeIcon() {
    const theme = document.documentElement.getAttribute('data-theme') || 'dark';
    if (themeIcon) {
      themeIcon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
  }
  syncThemeIcon();

  if (themeBtn) {
    themeBtn.addEventListener('click', function() {
      const current = document.documentElement.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('thermal_theme', next);
      syncThemeIcon();
      window.dispatchEvent(new CustomEvent('thermal:themechange', { detail: { theme: next } }));
    });
  }

  function closeSidebar() {
    sidebar && sidebar.classList.remove('is-open');
    backdrop && backdrop.classList.remove('is-open');
  }

  if (mobileToggle) {
    mobileToggle.addEventListener('click', function() {
      sidebar && sidebar.classList.toggle('is-open');
      backdrop && backdrop.classList.toggle('is-open');
    });
  }
  if (backdrop) {
    backdrop.addEventListener('click', closeSidebar);
  }

  if (profileBtn && profileDropdown) {
    profileBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      const open = profileDropdown.classList.toggle('is-open');
      profileBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', function(e) {
      if (!profileDropdown.contains(e.target) && !profileBtn.contains(e.target)) {
        profileDropdown.classList.remove('is-open');
        profileBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }
})();
</script>
