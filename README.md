# Warehouse API

Laravel 13, PHP 8.5, MySQL 8.0. Lokal ishga tushirish uchun Docker Desktop kerak.

## Ishga tushirish

```sh
cp .env.example .env
docker compose build
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose up -d
docker compose exec app php artisan migrate --seed
```

API: http://localhost:8088/api, sog‘liq tekshiruvi: http://localhost:8088/up.
Mavjud `.env` faylini qayta nusxalash shart emas. Baza Docker volume’da saqlanadi.
To‘xtatish: `docker compose down`.

Seeder provider=1, storage=1, client=1 va product=1,2 yaratadi. Qoldiq dastlab 0.
Mahsulot 1 narxi 20.00, mahsulot 2 narxi 25.00. Katalogni boshqarish CRUD’i topshiriqqa kirmagan;
namuna ma’lumotlari seeder orqali berilgan. Autentifikatsiya ham topshiriq doirasiga kirmaydi.
Muhit lokal foydalanish uchun sozlangan.

## Jadvallar va bog‘lanishlar

| Jadval | Vazifa |
| --- | --- |
| providers | Yetkazib beruvchilar |
| categories | parent_id orqali daraxt; faqat ildizda provider_id |
| products | Bitta category_id va amaldagi sotuv price |
| storages | Omborlar |
| clients | Xaridor kompaniyalar |
| batches | Bitta provider, bitta storage, purchased_at |
| batch_items | Partiyadagi mahsulot, xarid qty, cost va remaining_qty |
| orders | client_id va sold_at |
| order_items | Sotuvning partiya bo‘yicha taqsimoti, qty, narx nusxasi, refunded_qty |
| purchase_refunds | batch_item_id bo‘yicha qaytarilgan qty va sana |
| client_refunds | order_item_id bo‘yicha qaytarilgan qty va sana |

Bitta partiyada bir mahsulot bitta qatorda bo‘ladi. Bitta buyurtmada bir mahsulot
bir necha partiyadan olinishi mumkin, shuning uchun order_items’da bir necha taqsimot bo‘ladi.
Tashqi kalitlar tarixni tasodifan o‘chirishni cheklaydi. Laravelning users/cache/jobs jadvallari
standart karkasdan qolgan; biznes hisobi ulardan foydalanmaydi.

## API

Barcha POST so‘rovlar uchun `Content-Type: application/json`, `Accept: application/json` yuboring.
GET ro‘yxatlar `{"data": [...]}` formatida. Xato kiritish yoki yetmagan qoldiq: 422;
mavjud bo‘lmagan buyurtma/partiya: 404. POST muvaffaqiyatli bo‘lsa 201.

### POST /api/purchases

```json
{"provider_id":1,"storage_id":1,"products":[{"id":1,"qty":10,"cost":"10.00"},{"id":2,"qty":5,"cost":"15.00"}]}
```

Javob: `id` — partiya ID’si, `items` — batch_items qatorlari.
Provider mahsulot kategoriyasining ildiziga mos bo‘lishi tekshiriladi.

### POST /api/batches/{batch_id}/refunds

```json
{"products":[{"id":1,"qty":2}]}
```

`id` — mahsulot ID’si. Faqat shu partiyadagi mavjud qoldiq qaytariladi.
To‘liq qaytarish uchun barcha qolgan mahsulotlar va ularning qolgan miqdorini yuboring.
Har bir qaytarish joriy sanadagi alohida hodisa sifatida saqlanadi.

### GET /api/products

```json
{"data":[{"id":1,"name":"Ahmad Tea Earl Grey, 500g","category_name":"Black Tea","price":"20.00","qty":8}]}
```

Faqat qoldig‘i musbat mahsulotlar. Ixtiyoriy `?storage_id=1` filtri bor.
Narxlar MySQL DECIMAL qiymatlaridan satr sifatida qaytadi, qty esa integer.

### POST /api/orders

```json
{"client_id":1,"products":[{"id":1,"qty":3}]}
```

Frontend `batch_id` yubormaydi. Javobda `id` (buyurtma) va `items`
(partiyalarga taqsimotlar: id, batch_item_id, qty, price, refunded_qty) keladi.
Qoldiq yetmasa butun buyurtma bekor qilinadi; qisman sotuv saqlanmaydi.

### POST /api/orders/{order_id}/refunds

```json
{"items":[{"id":1,"qty":2}]}
```

Bu yerda `id` — buyurtma javobidagi **order_items.id**, mahsulot ID’si emas.
Shu orqali aynan qaysi xarid narxi va omborga mahsulot qaytarilishi aniqlanadi.
To‘liq qaytarish uchun har bir sotuv taqsimotining hali qaytarilmagan qty’sini yuboring.

### GET /api/storage/quantities?date=2026-10-21

Ixtiyoriy `&storage_id=1`. Javob:

```json
{"date":"2026-10-21","data":[{"storage_id":1,"id":1,"name":"Ahmad Tea Earl Grey, 500g","qty":7}]}
```

Sana UTC bo‘yicha kun oxirigacha hisoblanadi (keyingi kun 00:00 dan oldingi hodisalar).
Oldin harakati bo‘lgan, qoldig‘i nol mahsulotlar ham qaytadi. Hali xarid qilinmaganlar qaytmaydi.
Kelajak sana hozirgacha ma’lum hodisalar bo‘yicha hisoblanadi, bashorat qilinmaydi.

### GET /api/batches/profit

```json
{"data":[{"batch_id":1,"profit":"10.00"}]}
```

Bu sotilgan mahsulotlarning amalga oshgan yalpi foydasi, pul oqimi emas:
`SUM((sotilgan qty - mijoz qaytargan qty) * (sotuv narxi - partiya xarid narxi))`.
Sotilmagan zaxira xarajati hali foydadan ayrilmaydi. Providerga qaytarish xarid narxida,
mijozga qaytarish esa asl sotuv narxida deb olinadi. Shu sabab providerga sotilmagan
mahsulotni qaytarish oldingi sotuv foydasini o‘zgartirmaydi. Yetkazish, soliq va komissiya
kiritilmagan. Hisob MySQL DECIMAL bilan bajariladi; PHP float bilan pul hisoblanmaydi.

## Algoritmlar va qabul qilingan qarorlar

- FIFO barcha omborlar bo‘yicha `purchased_at, batch_id, batch_item_id` tartibida ishlaydi.
  Birinchi partiyadan yetgancha olinadi, qolgan ehtiyoj keyingi partiyaga o‘tadi.
- Barcha o‘zgartirishlar tranzaksiyada. Avval product qatorlari ID tartibida `FOR UPDATE`
  bilan qulflanadi; keyin partiya/sotuv qatorlari tekshiriladi. Bir mahsulotga tegishli
  xarid, sotuv va qaytarishlar navbat bilan bajariladi. Deadlock bo‘lsa uch urinish bor.
- remaining_qty joriy qoldiqni tez olish uchun, refunded_qty esa takroriy mijoz
  qaytarishlarini tekshirish uchun saqlanadi. Hisob va tarix bir tranzaksiyada yangilanadi.
- Tarixiy qoldiq joriy remaining_qty’dan olinmaydi: xarid − sotuv − providerga
  qaytarish + mijozdan qaytarish sanalar bo‘yicha SQL UNION ALL va SUM bilan hisoblanadi.
- Mijoz qaytargan mahsulot yaroqli deb olinib, aynan asl ombor va partiyaga qaytadi;
  uni qayta sotish yoki providerga qaytarish mumkin. FIFO uchun asl xarid sanasi saqlanadi.
- Sotuv narxi products.price’dan olinib, order_items.price’da muzlatiladi. Keyingi
  katalog narxi o‘zgarishi eski foyda yoki refund narxiga ta’sir qilmaydi.
- Sanalarni server belgilaydi; orqaga sana bilan operatsiya yaratish yo‘q.
- Bir so‘rovda ko‘pi bilan 100 mahsulot/qator, qty 1..1000000. Takroriy mahsulot ID’lari rad qilinadi.
- Ro‘yxat va hisobotlar SQL’da guruhlanadi. Xaridda kategoriyalar bir marta, sotuvda
  kerakli qoldiqlar bitta so‘rov bilan olinadi. Har bir taqsimot uchun zarur UPDATE bajariladi.
- Qayta yuborilgan POST yangi amal hisoblanadi; idempotency kalitlari bu topshiriqqa qo‘shilmagan.

Asosiy kod: `app/Http/Controllers/InventoryController.php`.
Jadvallar: `database/migrations/*_create_inventory_tables.php`.
Endpointlar: `routes/api.php`.
Laravel tranzaksiya va qulf mexanizmi: https://laravel.com/docs/13.x/queries#pessimistic-locking

## Testlar

Testlar alohida **warehouse_test** MySQL bazasini tozalaydi. Asosiy warehouse bazasiga tegmaydi.
Bir marta test bazasini yarating (bu parollar faqat lokal Docker muhiti uchun):

```sh
docker compose exec mysql mysql -uroot -proot_local -e "CREATE DATABASE IF NOT EXISTS warehouse_test; GRANT ALL PRIVILEGES ON warehouse_test.* TO 'warehouse'@'%';"
docker compose exec app php artisan test --compact
```

Testlar FIFO, bir nechta ombor/partiya, atomarlik, qisman/to‘liq refundlar, ortiqcha refund,
narx o‘zgarishi, tarixiy qoldiq, qayta sotuv va alohida PHP jarayonlaridagi parallel
buyurtma/qaytarishlarni qamrab oladi.

Bu kod AI yordamida yozilgan. Topshiriqdagi AI cheklovi sabab uni mustaqil yozilgan deb
taqdim etmaslik kerak; topshiruvchi algoritmlarni o‘zi tushunishi va tushuntira olishi kerak.
