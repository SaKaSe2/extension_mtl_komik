# Dokumentasi Teknis KomikoID Chrome Extension

Dokumentasi arsitektur dan alur kerja internal ekstensi KomikoID berbasis Chrome Manifest V3.

---

## 1. Arsitektur Komponen

```
[Web Page / Reader Komik]
         │
         ├──► content/content.js
         │      ├─ DOM scanner (mendeteksi tag <img> ukuran komik)
         │      ├─ Floating HUD (widget trigger terjemahan & progress)
         │      ├─ IntersectionObserver (auto-translate saat scroll)
         │      └─ In-Place DOM Replacement + Tombol Toggle RAW/MTL
         │
         ▼
[Browser Extension Core]
         │
         ├──► popup/ (popup.html, popup.js, popup.css)
         │      ├─ Pengaturan target language (id, en, es, pt)
         │      └─ Monitoring status server cloud (health check)
         │
         └──► background/service-worker.js
                ├─ Context menu ("Terjemahkan Komik Ini")
                ├─ CORS proxy fetcher (bypass hotlinking situs target)
                └─ HTTP bridge ke Backend Laravel (/api/v1/extension/translate-page)
```

---

## 2. Alur Eksekusi Terjemahan

1. **Deteksi Gambar**: `content.js` memfilter elemen gambar dengan ambang batas `width >= 250px` dan `height >= 350px` agar tidak memproses icon navigasi situs.
2. **Kirim Data Gambar**: URL gambar dikirim ke `service-worker.js`. Jika situs target mengaktifkan proteksi hotlink, gambar di-fetch langsung di background worker dan diubah ke format Base64.
3. **Panggilan API Backend**: Service worker melakukan HTTP POST ke `{BACKEND_URL}/api/v1/extension/translate-page`.
4. **Respon & Penimpaan Gambar**: Backend mengembalikan string Base64 gambar hasil inpainting dan render teks. Atribut `src` pada elemen `<img>` langsung diganti, dan disematkan tombol badge `MTL ID` untuk mempermudah perbandingan dengan gambar `RAW`.

---

## 3. Komunikasi Antar-Komponen (Message Passing)

| Action Name | Pengirim | Penerima | Kegunaan |
| :--- | :--- | :--- | :--- |
| `CHECK_BACKEND_HEALTH` | popup.js | service-worker.js | Mengecek ketersediaan API backend |
| `TRANSLATE_IMAGE_API` | content.js | service-worker.js | Meneruskan permintaan translate ke backend |
| `FETCH_IMAGE_AS_BASE64` | content.js | service-worker.js | Mengambil blob gambar jika terhalang CORS |
| `TRANSLATE_ALL_PAGES` | popup.js | content.js | Mentrigger terjemahan semua panel di chapter aktif |
| `RESET_ALL_PAGES` | popup.js | content.js | Mengembalikan semua panel ke gambar aslinya |
