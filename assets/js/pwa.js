let deferredInstallPrompt = null;
const APP_RELEASE = 'ja-tuckshop-2026-07-09-update-1';

function serviceWorkerPath() {
  const path = window.location.pathname;
  if (path.includes('/pos/') || path.includes('/admin/') || path.includes('/seller/') || path.includes('/parent/') || path.includes('/student/')) {
    return '../sw.js';
  }
  return 'sw.js';
}

function deviceInstallMessage() {
  const ua = window.navigator.userAgent.toLowerCase();
  const isIos = /iphone|ipad|ipod/.test(ua);
  const isAndroid = /android/.test(ua);
  const isDesktop = !isIos && !isAndroid;

  if (isIos) {
    return 'On iPhone or iPad, tap Share and choose "Add to Home Screen".';
  }
  if (isAndroid) {
    return 'If no install prompt appears, open the browser menu and tap Install App.';
  }
  if (isDesktop) {
    return 'If no install prompt appears, open the browser menu and choose Install App.';
  }
  return 'Use your browser install option to add JA Tuckshop.';
}

function createInstallUi() {
  if (document.getElementById('install-app-btn')) return;

  const button = document.createElement('button');
  button.type = 'button';
  button.id = 'install-app-btn';
  button.className = 'btn btn-primary install-app-btn';
  button.textContent = 'Install JA Tuckshop';

  const panel = document.createElement('div');
  panel.id = 'install-helper-panel';
  panel.className = 'install-helper-panel hidden';
  panel.innerHTML = `
    <div class="install-helper-title">Install help</div>
    <p id="install-helper-text">${deviceInstallMessage()}</p>
    <button type="button" class="btn btn-secondary" id="install-helper-close" style="padding:0.5rem 0.8rem;">Close</button>
  `;

  document.body.appendChild(button);
  document.body.appendChild(panel);

  document.getElementById('install-helper-close')?.addEventListener('click', () => {
    panel.classList.add('hidden');
  });

  button.addEventListener('click', async () => {
    if (deferredInstallPrompt) {
      deferredInstallPrompt.prompt();
      const result = await deferredInstallPrompt.userChoice.catch(() => null);
      if (result?.outcome === 'accepted') {
        deferredInstallPrompt = null;
        button.classList.add('hidden');
        panel.classList.add('hidden');
        return;
      }
    }

    panel.querySelector('#install-helper-text').textContent = deviceInstallMessage();
    panel.classList.remove('hidden');
  });
}

function isStandaloneApp() {
  return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
}

function createUpdateNotice() {
  if (document.getElementById('app-update-notice')) return;

  const notice = document.createElement('div');
  notice.id = 'app-update-notice';
  notice.className = 'app-update-notice hidden';
  notice.innerHTML = `
    <div class="app-update-title">JA Tuckshop update available</div>
    <p>The app has been updated. Please open the JA Tuckshop website again and click "Install JA Tuckshop" to refresh your app shortcut.</p>
    <button type="button" class="btn btn-primary" id="dismiss-update-notice" style="padding:0.55rem 0.9rem;">OK</button>
  `;
  document.body.appendChild(notice);

  document.getElementById('dismiss-update-notice')?.addEventListener('click', () => {
    notice.classList.add('hidden');
  });
}

function showUpdateNoticeIfNeeded() {
  createUpdateNotice();
  const seenRelease = localStorage.getItem('ja_tuckshop_seen_release');
  const notice = document.getElementById('app-update-notice');

  if (seenRelease && seenRelease !== APP_RELEASE && isStandaloneApp()) {
    notice?.classList.remove('hidden');
  }

  localStorage.setItem('ja_tuckshop_seen_release', APP_RELEASE);
}

window.addEventListener('beforeinstallprompt', (event) => {
  event.preventDefault();
  deferredInstallPrompt = event;
});

window.addEventListener('appinstalled', () => {
  deferredInstallPrompt = null;
  document.getElementById('install-app-btn')?.classList.add('hidden');
  document.getElementById('install-helper-panel')?.classList.add('hidden');
});

createInstallUi();
showUpdateNoticeIfNeeded();

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register(serviceWorkerPath()).catch(() => {
      /* optional in dev */
    });
  });
}
