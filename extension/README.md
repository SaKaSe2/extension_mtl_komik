# KomikoID - Comic MTL Translator (Chrome Extension)

Ekstensi browser berbasis **Manifest V3** untuk menerjemahkan komik, manga, dan manhwa secara otomatis langsung di halaman web melalui teknologi **Image Replacement** dan integrasi Backend AI (Hugging Face ZeroGPU OCR).

---

## Fitur Utama

- **In-Page Image Replacement**: Mengganti teks balon komik langsung di halaman web tanpa membuka tab baru.
- **Toggle Gambar RAW / MTL**: Menyediakan tombol badge interaktif di setiap panel komik untuk berganti tampilan antara gambar terjemahan dan gambar asli.
- **Floating HUD**: Panel kontrol melayang di pojok kanan bawah halaman untuk trigger terjemahan satu chapter dan melihat progress.
- **Auto-Translate on Scroll**: Menerjemahkan panel komik secara otomatis saat halaman digulir (menggunakan `IntersectionObserver`).
- **Shortcut Cepat**: Tekan tombol pintas `Alt + T` untuk langsung memulai terjemahan halaman aktif.
- **Bypass Hotlinking/CORS**: Pengambilan gambar dilakukan via background service worker untuk menghindari proteksi hotlink situs target.

---

## Struktur Folder Ekstensi

```
extension/
├── manifest.json              # Konfigurasi Manifest V3
├── background/
│   └── service-worker.js      # Background worker, API bridge, context menu
├── content/
│   ├── content.js             # Deteksi panel komik, Floating HUD, DOM replacement
│   └── content.css            # Styling badge, HUD, spinner loading
├── popup/
│   ├── popup.html             # Tampilan menu ekstensi
│   ├── popup.css              # Styling popup
│   └── popup.js               # Kontrol pengaturan & health check
├── icons/                     # Icon ekstensi (16px, 48px, 128px)
└── document/                  # Dokumentasi teknis ekstensi
```

---

## Panduan Instalasi (Development Mode)

1. Buka browser Google Chrome atau browser berbasis Chromium (Edge, Brave).
2. Akses halaman manajemen ekstensi di URL: `chrome://extensions`.
3. Aktifkan **Developer mode** di pojok kanan atas.
4. Klik tombol **Load unpacked** di pojok kiri atas.
5. Pilih folder `extension`.
6. Ekstensi KomikoID kini aktif dan muncul di toolbar browser.

---

## Penggunaan Ekstensi

1. Ekstensi sudah otomatis terhubung ke cloud AI backend.
2. Klik ikon ekstensi KomikoID pada toolbar browser untuk membuka popup.
3. Status indikator akan otomatis berwarna hijau (**Online**) dan langsung siap digunakan.

---

## Lisensi

MIT License
