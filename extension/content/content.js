/**
 * KomikoID Chrome Extension - Content Script
 * Scans manga/comic images, handles floating HUD, and manages AI Image Replacement.
 */

(() => {
  let isTranslating = false;
  let translatedCount = 0;
  let autoTranslateEnabled = false;
  let targetLang = 'id';
  let sourceLang = 'auto';

  // Initialize config dari storage, lalu langsung jalankan auto-translate kalau aktif
  chrome.storage.local.get(
    {
      autoTranslate: false,
      targetLanguage: 'id',
      sourceLanguage: 'auto',
    },
    (items) => {
      autoTranslateEnabled = items.autoTranslate;
      targetLang = items.targetLanguage;
      sourceLang = items.sourceLanguage;

      if (autoTranslateEnabled) {
        // Tunda sedikit biar DOM halaman reader selesai render dulu
        setTimeout(() => startAutoTranslateQueue(), 1500);
      }
    }
  );

  /**
   * Cek apakah elemen gambar valid sebagai strip/panel komik
   */
  function isComicImage(img) {
    if (!img) return false;

    const width = img.naturalWidth || img.clientWidth || img.width || 0;
    const height = img.naturalHeight || img.clientHeight || img.height || 0;
    const src = img.currentSrc || img.src || '';

    if (!src || src.startsWith('data:image/svg') || src.includes('.svg')) return false;
    const lowerSrc = src.toLowerCase();
    if (lowerSrc.includes('avatar') || lowerSrc.includes('logo') || lowerSrc.includes('icon') || lowerSrc.includes('badge')) {
      return false;
    }

    // Strip komik webtoon minimal lebar 350px dan tinggi 300px
    if (width < 350 || height < 300) return false;

    // Banner iklan horizontal biasanya rasio lebar:tinggi > 2.2
    if (width / height > 2.2) return false;

    const style = window.getComputedStyle(img);
    if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
      return false;
    }

    // Hindari gambar di dalam kontainer navigasi, sidebar, komentar, rekomendasi, atau video player
    const nonComicContainer = img.closest(
      'header, nav, footer, aside, .comment, .comments, .sidebar, .recommendation, .recommended, .related, .modal, [class*="player"], [class*="nav"], [class*="banner"], [id*="player"]'
    );
    if (nonComicContainer) return false;

    return true;
  }

  /**
   * Temukan semua kandidat gambar komik di halaman (urutan DOM).
   * Juga scan atribut lazy-load (data-src, data-lazy-src) untuk panel yang belum di-scroll.
   */
  function findComicImages() {
    const allImages = Array.from(document.querySelectorAll('img'));

    // Trigger lazy-load: pindahkan data-src ke src kalau belum ada src aslinya
    allImages.forEach((img) => {
      const lazySrc = img.dataset.src || img.dataset.lazySrc || img.dataset.original || img.dataset.url;
      if (lazySrc && !img.src.startsWith('http')) {
        img.src = lazySrc;
      }
    });

    return allImages.filter(isComicImage);
  }

  /**
   * Urutkan gambar berdasarkan posisi di halaman (atas ke bawah).
   * Yang di viewport dikerjakan duluan supaya user bisa langsung baca.
   */
  function getPrioritizedImages() {
    const images = findComicImages();
    const windowHeight = window.innerHeight || document.documentElement.clientHeight;

    const inViewport = [];
    const belowViewport = [];
    const aboveViewport = [];

    images.forEach((img) => {
      const rect = img.getBoundingClientRect();
      if (rect.bottom > 0 && rect.top < windowHeight) {
        inViewport.push({ img, top: rect.top });
      } else if (rect.top >= windowHeight) {
        belowViewport.push({ img, top: rect.top });
      } else {
        aboveViewport.push({ img, bottom: rect.bottom });
      }
    });

    inViewport.sort((a, b) => a.top - b.top);
    belowViewport.sort((a, b) => a.top - b.top);
    aboveViewport.sort((a, b) => b.bottom - a.bottom);

    return [
      ...inViewport.map((item) => item.img),
      ...belowViewport.map((item) => item.img),
      ...aboveViewport.map((item) => item.img),
    ];
  }

  /**
   * Inject atau update Floating HUD
   */
  function injectFloatingHUD() {
    if (document.getElementById('komiko-hud')) return;

    const images = findComicImages();
    if (images.length === 0) return;

    const hud = document.createElement('div');
    hud.id = 'komiko-hud';
    hud.className = 'komiko-floating-hud';
    hud.innerHTML = `
      <div class="komiko-hud-brand" title="Buka Pengaturan KomikoID">
        <div class="komiko-hud-logo">K</div>
        <span class="komiko-hud-title">KomikoID</span>
      </div>
      <button class="komiko-hud-btn" id="komiko-btn-translate">
        <span>⚡</span>
        <span id="komiko-btn-text">Translate</span>
      </button>
      <span class="komiko-hud-progress" id="komiko-progress" style="display: none;">0/0</span>
    `;

    document.body.appendChild(hud);

    document.getElementById('komiko-btn-translate').addEventListener('click', () => {
      translateAllPages();
    });
  }

  /**
   * Translate a single image via Image Replacement.
   * Cek cache lokal terlebih dahulu — kalau ada langsung pakai, tidak perlu ke backend.
   */
  async function translateSingleImage(img) {
    if (!img || img.dataset.komikoProcessing === 'true' || img.dataset.komikoTranslated === 'true') {
      return;
    }

    img.dataset.komikoProcessing = 'true';

    const srcUrl = img.currentSrc || img.src;

    // Wrap image dan pasang loading overlay
    let wrapper = img.parentElement;
    if (!wrapper || !wrapper.classList.contains('komiko-image-wrapper')) {
      wrapper = document.createElement('div');
      wrapper.className = 'komiko-image-wrapper';
      wrapper.style.width = `${img.clientWidth || img.naturalWidth}px`;
      img.parentNode.insertBefore(wrapper, img);
      wrapper.appendChild(img);
    }

    const overlay = document.createElement('div');
    overlay.className = 'komiko-loading-overlay';
    overlay.innerHTML = `
      <div class="komiko-spinner"></div>
      <div class="komiko-loading-text">Menerjemahkan Gambar...</div>
    `;
    wrapper.appendChild(overlay);

    try {
      // Cek cache lokal terlebih dahulu (via service worker)
      const cacheResult = await new Promise((resolve) => {
        chrome.runtime.sendMessage(
          { action: 'CHECK_IMAGE_CACHE', imageUrl: srcUrl },
          (res) => resolve(res?.cached || null)
        );
      });

      let translatedSrc = null;

      if (cacheResult) {
        // Cache hit — pakai langsung tanpa loading lama
        translatedSrc = cacheResult;
      } else {
        // Cache miss — kirim ke backend
        const response = await new Promise((resolve, reject) => {
          chrome.runtime.sendMessage(
            {
              action: 'TRANSLATE_IMAGE_API',
              payload: {
                imageUrl: srcUrl,
                targetLanguage: targetLang,
                sourceLanguage: sourceLang,
              },
            },
            (res) => {
              if (chrome.runtime.lastError) {
                return reject(new Error(chrome.runtime.lastError.message));
              }
              if (!res || !res.success) {
                return reject(new Error(res?.error || 'Gagal menerjemahkan'));
              }
              resolve(res.data);
            }
          );
        });

        translatedSrc = response?.translated_image || null;
      }

      // Terapkan gambar hasil terjemahan ke DOM
      if (translatedSrc) {
        img.dataset.komikoOriginalSrc = srcUrl;
        img.dataset.komikoTranslatedSrc = translatedSrc;
        img.src = translatedSrc;
        img.dataset.komikoTranslated = 'true';
        translatedCount++;

        // Badge MTL untuk toggle gambar asli vs terjemahan
        let badge = wrapper.querySelector('.komiko-badge');
        if (!badge) {
          badge = document.createElement('div');
          badge.className = 'komiko-badge';
          badge.textContent = 'MTL ID';
          badge.title = 'Klik untuk melihat gambar asli';
          badge.addEventListener('click', (e) => {
            e.stopPropagation();
            if (img.src === img.dataset.komikoTranslatedSrc) {
              img.src = img.dataset.komikoOriginalSrc;
              badge.textContent = 'RAW';
              badge.style.borderColor = '#f43f5e';
            } else {
              img.src = img.dataset.komikoTranslatedSrc;
              badge.textContent = 'MTL ID';
              badge.style.borderColor = 'rgba(99, 102, 241, 0.6)';
            }
          });
          wrapper.appendChild(badge);
        }
      }
    } catch (err) {
      console.error('[KomikoID] Error translating image:', err);
    } finally {
      img.dataset.komikoProcessing = 'false';
      if (overlay && overlay.parentNode) {
        overlay.style.opacity = '0';
        setTimeout(() => overlay.remove(), 300);
      }
    }
  }

  /**
   * Translate semua gambar komik di halaman secara berurutan.
   * Diprioritaskan dari viewport ke bawah agar panel yang sedang dibaca selesai duluan.
   */
  async function translateAllPages() {
    if (isTranslating) return;
    isTranslating = true;

    const images = getPrioritizedImages();
    const totalCount = images.length;
    const progressEl = document.getElementById('komiko-progress');
    const btnText = document.getElementById('komiko-btn-text');

    if (progressEl) {
      progressEl.style.display = 'inline';
      progressEl.textContent = `0/${totalCount}`;
    }
    if (btnText) btnText.textContent = 'Proses...';

    let completed = 0;
    for (const img of images) {
      if (img.dataset.komikoTranslated !== 'true') {
        await translateSingleImage(img);
      }
      completed++;
      if (progressEl) progressEl.textContent = `${completed}/${totalCount}`;
    }

    if (btnText) btnText.textContent = 'Selesai ✓';
    setTimeout(() => {
      if (btnText) btnText.textContent = 'Translate';
      if (progressEl) progressEl.style.display = 'none';
      isTranslating = false;
    }, 2000);
  }

  /**
   * Auto-translate semua gambar chapter saat halaman dibuka.
   * Dipanggil otomatis kalau autoTranslate aktif di settings.
   * Proses background — user tetap bisa baca panel atas yang sudah selesai.
   */
  async function startAutoTranslateQueue() {
    // Inject HUD dulu biar user bisa pantau progress
    injectFloatingHUD();

    const images = getPrioritizedImages();
    if (images.length === 0) {
      // Coba lagi sebentar kalau gambar belum muncul (lazy load belum selesai)
      setTimeout(() => startAutoTranslateQueue(), 2000);
      return;
    }

    if (isTranslating) return;
    isTranslating = true;

    const progressEl = document.getElementById('komiko-progress');
    const btnText = document.getElementById('komiko-btn-text');
    const totalCount = images.length;

    if (progressEl) {
      progressEl.style.display = 'inline';
      progressEl.textContent = `0/${totalCount}`;
    }
    if (btnText) btnText.textContent = 'Auto...';

    let completed = 0;
    for (const img of images) {
      if (img.dataset.komikoTranslated !== 'true') {
        await translateSingleImage(img);
      }
      completed++;
      if (progressEl) progressEl.textContent = `${completed}/${totalCount}`;
    }

    if (btnText) btnText.textContent = 'Selesai ✓';
    setTimeout(() => {
      if (btnText) btnText.textContent = 'Translate';
      if (progressEl) progressEl.style.display = 'none';
      isTranslating = false;
    }, 3000);
  }

  /**
   * Reset semua gambar ke versi asli
   */
  function resetAllPages() {
    const images = document.querySelectorAll('img[data-komiko-original-src]');
    images.forEach((img) => {
      img.src = img.dataset.komikoOriginalSrc;
      delete img.dataset.komikoTranslated;
      const wrapper = img.parentElement;
      if (wrapper && wrapper.classList.contains('komiko-image-wrapper')) {
        const badge = wrapper.querySelector('.komiko-badge');
        if (badge) badge.remove();
      }
    });
    translatedCount = 0;
  }

  // Handle messages dari popup / background
  chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === 'GET_PAGE_STATS') {
      const images = findComicImages();
      sendResponse({
        total: images.length,
        translated: translatedCount,
      });
      return true;
    }

    if (request.action === 'TRANSLATE_ALL_PAGES') {
      if (request.targetLanguage) targetLang = request.targetLanguage;
      if (request.sourceLanguage) sourceLang = request.sourceLanguage;
      translateAllPages().then(() => {
        sendResponse({ success: true, translated: translatedCount });
      });
      return true;
    }

    if (request.action === 'RESET_ALL_PAGES') {
      resetAllPages();
      sendResponse({ success: true });
      return true;
    }

    if (request.action === 'TRANSLATE_SPECIFIC_IMAGE' && request.srcUrl) {
      const targetImg = Array.from(document.querySelectorAll('img')).find(
        (img) => img.src === request.srcUrl || img.currentSrc === request.srcUrl
      );
      if (targetImg) {
        translateSingleImage(targetImg);
      }
      sendResponse({ success: true });
      return true;
    }

    if (request.action === 'UPDATE_CONFIG' && request.config) {
      if (typeof request.config.autoTranslate === 'boolean') {
        autoTranslateEnabled = request.config.autoTranslate;
        if (autoTranslateEnabled) {
          startAutoTranslateQueue();
        }
      }
      sendResponse({ success: true });
      return true;
    }

    // Abaikan action yang tidak dikenal agar tidak throw unchecked lastError
    return false;
  });

  // Shortcut: Alt + T
  window.addEventListener('keydown', (e) => {
    if (e.altKey && (e.key === 't' || e.key === 'T')) {
      e.preventDefault();
      translateAllPages();
    }
  });

  // Inject HUD setelah halaman siap
  setTimeout(injectFloatingHUD, 1200);
})();
