/**
 * KomikoID Chrome Extension - Popup Controller
 */

document.addEventListener('DOMContentLoaded', async () => {
  const statusIndicator = document.getElementById('statusIndicator');
  const statusText = document.getElementById('statusText');
  const btnTranslateAll = document.getElementById('btnTranslateAll');
  const btnResetAll = document.getElementById('btnResetAll');
  const statPageCount = document.getElementById('statPageCount');
  const statTranslatedCount = document.getElementById('statTranslatedCount');
  const targetLanguage = document.getElementById('targetLanguage');
  const sourceLanguage = document.getElementById('sourceLanguage');
  const toggleAutoTranslate = document.getElementById('toggleAutoTranslate');

  // 1. Load saved settings from chrome.storage
  const settings = await chrome.storage.local.get({
    targetLanguage: 'id',
    sourceLanguage: 'auto',
    autoTranslate: false,
  });

  targetLanguage.value = settings.targetLanguage;
  sourceLanguage.value = settings.sourceLanguage;
  toggleAutoTranslate.checked = settings.autoTranslate;

  // 2. Check Backend Health
  async function checkHealth() {
    statusIndicator.className = 'status-indicator';
    statusText.textContent = 'Menghubungkan...';

    chrome.runtime.sendMessage(
      { action: 'CHECK_BACKEND_HEALTH' },
      (response) => {
        if (chrome.runtime.lastError || !response || !response.success) {
          statusIndicator.className = 'status-indicator error';
          statusText.textContent = 'Offline';
        } else {
          statusIndicator.className = 'status-indicator connected';
          statusText.textContent = 'Online';
        }
      }
    );
  }

  checkHealth();
  statusIndicator.addEventListener('click', () => checkHealth());

  // 3. Query active tab for detected comic images
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (tab?.id) {
    chrome.tabs.sendMessage(tab.id, { action: 'GET_PAGE_STATS' }, (res) => {
      if (chrome.runtime.lastError || !res) {
        statPageCount.textContent = '0';
        statTranslatedCount.textContent = '0';
        return;
      }
      statPageCount.textContent = res.total || '0';
      statTranslatedCount.textContent = res.translated || '0';
    });
  }

  // 4. Event Listeners for settings
  targetLanguage.addEventListener('change', () => {
    chrome.storage.local.set({ targetLanguage: targetLanguage.value });
  });

  sourceLanguage.addEventListener('change', () => {
    chrome.storage.local.set({ sourceLanguage: sourceLanguage.value });
  });

  toggleAutoTranslate.addEventListener('change', () => {
    chrome.storage.local.set({ autoTranslate: toggleAutoTranslate.checked });
    if (tab?.id) {
      chrome.tabs.sendMessage(tab.id, {
        action: 'UPDATE_CONFIG',
        config: { autoTranslate: toggleAutoTranslate.checked },
      }, () => {
        void chrome.runtime.lastError;
      });
    }
  });

  // 5. Action Buttons
  btnTranslateAll.addEventListener('click', async () => {
    if (!tab?.id) return;
    btnTranslateAll.disabled = true;
    btnTranslateAll.innerHTML = '<span class="btn-label">Memproses...</span>';

    chrome.tabs.sendMessage(
      tab.id,
      {
        action: 'TRANSLATE_ALL_PAGES',
        targetLanguage: targetLanguage.value,
        sourceLanguage: sourceLanguage.value,
      },
      (res) => {
        btnTranslateAll.disabled = false;
        btnTranslateAll.innerHTML = '<span class="btn-icon">⚡</span><span class="btn-label">Terjemahkan Chapter Ini</span>';
        if (chrome.runtime.lastError) return;
        if (res) {
          statTranslatedCount.textContent = res.translated || statTranslatedCount.textContent;
        }
      }
    );
  });

  btnResetAll.addEventListener('click', async () => {
    if (!tab?.id) return;
    chrome.tabs.sendMessage(tab.id, { action: 'RESET_ALL_PAGES' }, (res) => {
      if (chrome.runtime.lastError) return;
      if (res) {
        statTranslatedCount.textContent = '0';
      }
    });
  });
});
