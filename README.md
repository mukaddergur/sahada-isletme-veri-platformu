# Sahada

Sahada, Google Maps arama URL’sinden işletme verisi toplayan ve analiz eden full-stack bir staj / eğitim platformudur.

Kullanıcı Maps’te arama yapar, sonuç sayfasının bağlantısını yapıştırır ve taramayı başlatır. Sistem OpenStreetMap Overpass üzerinden gerçek POI kayıtlarını (`osm_` kimlikleriyle) getirir; Google HTML scrape kullanılmaz. Sahte katalog / demo fallback varsayılan olarak kapalıdır. Google Places ve OpenAI anahtarları opsiyoneldir; gerçek değerler repoya eklenmez.

Toplanan alanlar arasında telefon, website, e-posta, adres ve konum bulunur. Aynı kategoride 1 km içindeki rakip yoğunluğu hesaplanır, rakip listesi sunulur ve semt bazlı fırsat mesajları üretilir. Arayüzde şehir / puan (1–5) / rakip filtreleri, Tablo–Harita sekmeleri, Türkiye genel haritası ve Excel dışa aktarım yer alır.

## Teknik yapı

| Katman | Teknoloji |
|--------|-----------|
| Arayüz | Next.js 15, TypeScript |
| API | Laravel 12, Sanctum |
| Veri | OpenStreetMap Overpass, Nominatim |
| Veritabanı | SQLite (yerel) / MySQL (Docker) |
| Harita | Leaflet |

Yerelde kuyruk senkron çalışır (`QUEUE_CONNECTION=sync`). Docker ortamında MySQL, Redis, API ve worker birlikte çalıştırılabilir.

## Kurulum

**Backend**

```powershell
cd backend
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

**Frontend**

```powershell
cd frontend
npm install
copy .env.example .env.local
npm run dev
```

`.env.example` boş şablondur. Kopyaladığınız `.env.local` içine API adresi ve seed hesap bilgilerinizi yazın; `.env.local` Git’e eklenmez.

- Uygulama: http://127.0.0.1:3000
- API: http://127.0.0.1:8000

Giriş bilgileri, API anahtarları ve diğer gizliler bu belgede yayınlanmaz.

## Kullanım akışı

1. Ana sayfada Google Maps butonu ile Maps’e gidin; arama yapın (`/maps/search/...`).
2. Sonuç URL’sini Sahada arama kutusuna yapıştırın.
3. Başlat’a basın; tarama sonuçları ayrı bir tam sayfada açılır.
4. Canlı tarama durumunu, işletme listesini, filtreleri ve harita görünümünü inceleyin.
5. Ana sayfa’ya dönerek Türkiye haritası ve kullanım rehberine bakabilirsiniz.
6. Gerektiğinde Excel indirin.

Ana sayfada örnek işletme kartları, özellik özeti, istatistik çubuğu ve tıklanabilir Türkiye haritası (yakınlaştırma destekli) bulunur.

## Depo yapısı

```
backend/     Laravel API, tarama ve analiz servisleri
frontend/    Next.js arayüz
crawler/     Opsiyonel Python yardımcı servisi
```

## Güvenlik notu

Repoda takip edilen dosyalarda gerçek API anahtarı bulunmaz. `GOOGLE_PLACES_API_KEY` ve `OPENAI_API_KEY` yalnızca boş şablon olarak `.env.example` içinde yer alır. Seed kullanıcıları veritabanı seeder ile oluşturulur; parolalar yalnızca yerel ortamda tutulmalıdır.

Bu proje eğitim ve staj amaçlı bir prototiptir. Toplanan telefon ve e-posta bilgileri kişisel veri olabilir; KVKK bilinciyle kullanılmalıdır.
