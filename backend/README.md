# KomikoID-MTL Backend API

Backend API Service untuk **KomikoID Chrome Extension** yang menyediakan layanan Machine Translation (MTL) otomatis, Hugging Face ZeroGPU OCR, AI Inpainting (LaMa), dan perenderan gambar panel komik (*Image Replacement*).

---

## Tech Stack

| Komponen | Teknologi | Keterangan |
| :--- | :--- | :--- |
| Framework | Laravel 11.x | PHP 8.2+ Backend Framework |
| OCR Utama | Hugging Face ZeroGPU | Gradio Cloud API (`rikza2-komiko-translator.hf.space`) |
| OCR Fallback | PaddleOCR / Tesseract | Microservice & binary lokal |
| Inpainting | LaMa (IOPaint) | Penghapusan teks pada balon komik |
| Translation | Google Translate / Ollama | Multi-tier fallback translation engine |
| Image Processing | Intervention Image (GD) | Manipulasi & teks overlay dinamis |

---

## Endpoint Utama Ekstensi

Prefix: `/api/v1/extension`

### 1. Health Check
- **Method**: `GET /api/v1/extension/health`
- **Deskripsi**: Mengecek status ketersediaan backend dan engine AI yang aktif (Hugging Face OCR, Google Vision, Translation Service).

### 2. Translate Page
- **Method**: `POST /api/v1/extension/translate-page`
- **Payload**:
  ```json
  {
    "image_base64": "data:image/jpeg;base64,...",
    "target_language": "id",
    "source_language": "auto"
  }
  ```
- **Response**: Mengembalikan data JSON yang berisi gambar hasil olahan dalam format data base64 serta daftar koordinat blok teks yang diterjemahkan.

---

## Deployment Cloud (Production)

Backend ini di-deploy secara otomatis ke cloud production menggunakan Docker dan blueprint `render.yaml`:
- **Database**: Supabase PostgreSQL (Session Pooler IPv4)
- **Persistent Storage Cache**: Backblaze B2 Object Storage
- Ekstensi Chrome langsung terhubung ke cloud backend secara otomatis tanpa perlu menjalankan server lokal.

---

## Lisensi

MIT License
