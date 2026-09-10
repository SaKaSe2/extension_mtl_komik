/**
 * KomikoID Chrome Extension - Background Service Worker (Manifest V3)
 */

const DEFAULT_SETTINGS = {
  backendUrl: 'https://komikoid-backend.onrender.com',
  targetLanguage: 'id',
  sourceLanguage: 'auto',
  autoTranslate: false,
  imageReplacement: true,
};

// Prefix key cache di storage
const CACHE_PREFIX = 'komiko_img_';
const CACHE_INDEX_KEY = 'komiko_cache_index';
const CACHE_MAX_ENTRIES = 150;

// Initialize extension on install or update
chrome.runtime.onInstalled.addListener(() => {
  chrome.storage.local.get(Object.keys(DEFAULT_SETTINGS), (stored) => {
    // Migrasi otomatis jika storage lama masih menyimpan URL localhost
    if (stored.backendUrl && (stored.backendUrl.includes('localhost') || stored.backendUrl.includes('127.0.0.1'))) {
      stored.backendUrl = DEFAULT_SETTINGS.backendUrl;
    }
    const updated = { ...DEFAULT_SETTINGS, ...stored };
    chrome.storage.local.set(updated);
  });

  // Context menu untuk klik kanan pada gambar
  chrome.contextMenus.create({
    id: 'komiko-translate-image',
    title: 'Terjemahkan Komik Ini (KomikoID)',
    contexts: ['image'],
  });
});

// Handle klik context menu
chrome.contextMenus.onClicked.addListener((info, tab) => {
  if (info.menuItemId === 'komiko-translate-image' && tab?.id) {
    chrome.tabs.sendMessage(tab.id, {
      action: 'TRANSLATE_SPECIFIC_IMAGE',
      srcUrl: info.srcUrl,
    }).catch(() => {
      // Content script belum siap, abaikan
    });
  }
});

// Message listener dari popup dan content scripts
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === 'TRANSLATE_IMAGE_API') {
    handleTranslateApi(request.payload)
      .then((data) => sendResponse({ success: true, data }))
      .catch((err) => sendResponse({ success: false, error: err.message }));
    return true;
  }

  if (request.action === 'CHECK_BACKEND_HEALTH') {
    checkBackendHealth(request.backendUrl)
      .then((data) => sendResponse({ success: true, data }))
      .catch((err) => sendResponse({ success: false, error: err.message }));
    return true;
  }

  if (request.action === 'FETCH_IMAGE_AS_BASE64') {
    fetchImageAsBase64(request.url)
      .then((base64) => sendResponse({ success: true, base64 }))
      .catch((err) => sendResponse({ success: false, error: err.message }));
    return true;
  }

  if (request.action === 'CHECK_IMAGE_CACHE') {
    getCachedTranslation(request.imageUrl)
      .then((cached) => sendResponse({ success: true, cached }))
      .catch(() => sendResponse({ success: true, cached: null }));
    return true;
  }
});

// =====================================================================
// Cache Management
// =====================================================================

/**
 * Buat cache key dari URL gambar — pakai URL lengkap sebagai identifier unik
 */
function makeCacheKey(imageUrl) {
  // Sederhanakan: ambil path + query saja, buang domain biar lebih ringkas
  try {
    const u = new URL(imageUrl);
    // Hash sederhana: gabung pathname + search, encode ke base key
    const raw = u.pathname + u.search;
    // Buat string key yang aman untuk storage key
    return CACHE_PREFIX + btoa(raw).replace(/[/+=]/g, '_').slice(0, 80);
  } catch {
    return CACHE_PREFIX + btoa(imageUrl.slice(-100)).replace(/[/+=]/g, '_').slice(0, 80);
  }
}

/**
 * Ambil hasil terjemahan dari cache lokal.
 * Return null kalau tidak ada.
 */
async function getCachedTranslation(imageUrl) {
  const key = makeCacheKey(imageUrl);
  return new Promise((resolve) => {
    chrome.storage.local.get(key, (result) => {
      resolve(result[key] || null);
    });
  });
}

/**
 * Simpan hasil terjemahan ke cache lokal.
 * Jalankan LRU eviction kalau sudah melebihi CACHE_MAX_ENTRIES.
 */
async function saveCachedTranslation(imageUrl, translatedBase64) {
  const key = makeCacheKey(imageUrl);

  // Update index dulu
  const index = await getCacheIndex();
  const now = Date.now();

  // Kalau key sudah ada, update timestamp-nya
  const existingIdx = index.findIndex((e) => e.key === key);
  if (existingIdx >= 0) {
    index[existingIdx].ts = now;
  } else {
    index.push({ key, ts: now });
  }

  // Eviction: hapus entri yang paling lama kalau sudah over limit
  if (index.length > CACHE_MAX_ENTRIES) {
    index.sort((a, b) => a.ts - b.ts);
    const toRemove = index.splice(0, index.length - CACHE_MAX_ENTRIES);
    const keysToRemove = toRemove.map((e) => e.key);
    await new Promise((resolve) => chrome.storage.local.remove(keysToRemove, resolve));
  }

  // Simpan data gambar dan index yang sudah diupdate
  await new Promise((resolve) => {
    chrome.storage.local.set(
      { [key]: translatedBase64, [CACHE_INDEX_KEY]: index },
      resolve
    );
  });
}

/**
 * Ambil index cache (list key + timestamp) dari storage
 */
async function getCacheIndex() {
  return new Promise((resolve) => {
    chrome.storage.local.get(CACHE_INDEX_KEY, (result) => {
      resolve(result[CACHE_INDEX_KEY] || []);
    });
  });
}

// =====================================================================
// Core API Handler
// =====================================================================

/**
 * Ambil gambar terlebih dahulu dari browser (bypass hotlink protection),
 * cek cache dulu sebelum kirim ke backend.
 */
async function handleTranslateApi(payload) {
  const imageUrl = payload.imageUrl || null;

  // Cek cache dulu kalau ada URL gambar
  if (imageUrl) {
    const cached = await getCachedTranslation(imageUrl);
    if (cached) {
      // Cache hit — return langsung tanpa hit backend
      return { translated_image: cached, from_cache: true };
    }
  }

  const settings = await chrome.storage.local.get(DEFAULT_SETTINGS);
  let resolvedBackend = (payload.backendUrl || settings.backendUrl || DEFAULT_SETTINGS.backendUrl).replace(/\/+$/, '');
  if (resolvedBackend.includes('localhost') || resolvedBackend.includes('127.0.0.1')) {
    resolvedBackend = DEFAULT_SETTINGS.backendUrl;
    chrome.storage.local.set({ backendUrl: resolvedBackend });
  }
  const url = `${resolvedBackend}/api/v1/extension/translate-page`;

  const bodyData = {
    target_language: payload.targetLanguage || settings.targetLanguage || 'id',
    source_language: payload.sourceLanguage || settings.sourceLanguage || 'auto',
    return_base64: true,
  };

  if (payload.imageBase64) {
    // Sudah dalam bentuk base64, langsung pakai
    bodyData.image_base64 = payload.imageBase64;
  } else if (imageUrl) {
    // Fetch gambar di service worker terlebih dahulu agar melewati hotlink protection situs target
    try {
      const base64 = await fetchImageAsBase64(imageUrl);
      bodyData.image_base64 = base64;
    } catch (fetchErr) {
      // Fallback: kirim URL langsung ke backend jika fetch gagal
      bodyData.image_url = imageUrl;
    }
  } else {
    throw new Error('Data gambar (URL atau Base64) tidak ditemukan.');
  }

  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(bodyData),
  });

  const json = await response.json();
  if (!response.ok || !json.success) {
    throw new Error(json.message || `Backend error (status ${response.status})`);
  }

  // Simpan hasil ke cache kalau ada URL sumber gambarnya
  if (imageUrl && json.data?.translated_image) {
    saveCachedTranslation(imageUrl, json.data.translated_image).catch(() => {
      // Gagal cache tidak perlu throw — tidak kritis
    });
  }

  return json.data;
}

/**
 * Cek status health backend
 */
async function checkBackendHealth(customUrl) {
  const settings = await chrome.storage.local.get(DEFAULT_SETTINGS);
  let baseUrl = (customUrl || settings.backendUrl || DEFAULT_SETTINGS.backendUrl).replace(/\/+$/, '');
  if (baseUrl.includes('localhost') || baseUrl.includes('127.0.0.1')) {
    baseUrl = DEFAULT_SETTINGS.backendUrl;
    chrome.storage.local.set({ backendUrl: baseUrl });
  }
  const url = `${baseUrl}/api/v1/extension/health`;

  const response = await fetch(url, {
    method: 'GET',
    headers: { 'Accept': 'application/json' },
  });

  if (!response.ok) {
    throw new Error(`Server status: ${response.status}`);
  }

  return await response.json();
}

/**
 * Fetch gambar dari browser untuk bypass hotlink / Referer protection.
 * Service worker punya akses penuh ke URL external tanpa batasan CORS halaman.
 */
async function fetchImageAsBase64(imageUrl) {
  const response = await fetch(imageUrl, {
    headers: {
      'Accept': 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
    },
  });

  if (!response.ok) {
    throw new Error(`Gagal mengambil gambar: ${response.statusText}`);
  }

  const blob = await response.blob();
  if (typeof FileReader !== 'undefined') {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onloadend = () => resolve(reader.result);
      reader.onerror = reject;
      reader.readAsDataURL(blob);
    });
  }

  const buffer = await blob.arrayBuffer();
  const bytes = new Uint8Array(buffer);
  let binary = '';
  const len = bytes.byteLength;
  for (let i = 0; i < len; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  const base64 = btoa(binary);
  return `data:${blob.type || 'image/jpeg'};base64,${base64}`;
}
