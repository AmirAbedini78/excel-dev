# راه‌اندازی موتور AI محلی

این پوشه روی cPanel Deploy نمی‌شود. آن را روی هر سیستم Worker اجرا کنید.

## معماری اتصال

Worker از داخل سیستم شما با HTTPS به `ai_api.php` روی وب‌اپ وصل می‌شود. نیاز به Port Forwarding یا Public IP برای PC/Laptop نیست.

## پیش‌نیاز

- Python 3.11+ پیشنهاد می‌شود
- Ollama
- دسترسی HTTPS به ساب‌دامین
- Worker Token که از پنل «دستیار هوشمند» ساخته می‌شود

## 1. مدل محلی

برای شروع روی سخت‌افزار کم‌رم، یک مدل کوچک tool-capable انتخاب کنید. نمونه config فعلی:

```bash
ollama pull qwen3:1.7b
```

انتخاب نهایی باید با benchmark همان سیستم انجام شود. اندازه context و مدل بزرگ‌تر RAM و latency را زیاد می‌کند.

## 2. تنظیمات

```powershell
Copy-Item config.example.json config.json
```

`config.json`:

```json
{
  "server_url": "https://excel2.bcsrp.ir/ai_api.php",
  "worker_token": "aiw_...",
  "provider": "ollama",
  "ollama_url": "http://127.0.0.1:11434",
  "chat_model": "qwen3:1.7b",
  "capabilities": ["llm"],
  "poll_seconds": 8,
  "lease_seconds": 900,
  "max_tool_rounds": 8,
  "request_timeout_seconds": 900,
  "rag_enabled": false,
  "rag_db": "data/rag.sqlite3",
  "embedding_model": "embeddinggemma",
  "rag_top_k": 5
}
```

Token را Commit نکنید. `engine/config.json` در `.gitignore` است.

## 3. اجرا

```powershell
python worker.py --config config.json
```

یک Job برای تست:

```powershell
python worker.py --config config.json --once
```

روی سیستم دوم همین engine را با همان یا Token جدا اجرا کنید. `node_uid`، CPU، RAM، OS و مدل‌های Ollama به صورت خودکار گزارش می‌شوند.

## 4. RAG

برای index کردن فایل‌های متنی/Markdown/CSV/JSON:

```powershell
python rag.py index --db data/rag.sqlite3 --source C:\AccountingKnowledge --ollama http://127.0.0.1:11434 --model embeddinggemma
```

تست جستجو:

```powershell
python rag.py search --db data/rag.sqlite3 --query "شرایط صدور صورتحساب" --ollama http://127.0.0.1:11434 --model embeddinggemma
```

بعد در config:

```json
"rag_enabled": true
```

RAG فعلی foundation سبک برای آزمایش است. در production بزرگ‌تر vector store جدا اضافه می‌شود.

## 5. داده مصنوعی

برای ساخت Dataset بزرگ اما غیرواقعی:

```powershell
python synthetic_data.py --out data/synthetic --companies 20 --parties 1000 --items 500 --transactions 100000 --months 24 --seed 1405
```

هدف:

- load test
- anomaly prototype
- forecast pipeline test
- evaluation fixtures

داده مصنوعی معیار نهایی دقت مدل روی مشتری واقعی نیست.

## 6. دو سیستم فعلی

روی هر دو سیستم یک Worker اجرا شود. در فاز اول هر Worker یک Job سنگین را هم‌زمان اجرا می‌کند. به‌جای split کردن یک inference بین دو CPU، Queue کارها را بین Nodeها توزیع می‌کند. این روش برای شبکه و سخت‌افزار ناهمگون مقاوم‌تر است.

در فاز بعد Scheduler بر اساس capability، RAM، CPU، model و job resource hint تصمیم دقیق‌تری می‌گیرد.

## 7. تست اتصال

- در پنل AI یک Worker Token بسازید.
- Worker را اجرا کنید.
- Node باید در پنل Online دیده شود.
- یک درخواست Chat ثبت کنید.
- Job باید از `queued` به `leased/running/succeeded` برود.
- اگر Agent Tool تغییردهنده فراخوانی کند، Proposal باید در پنل منتظر تأیید بماند.

## 8. اجرای پیشنهادی با Docker Compose

برای Windows / Linux، اجرای موتور در Docker روش پیشنهادی MVP است. `Ollama` و `worker` در یک شبکه داخلی Compose اجرا می‌شوند و Worker با HTTPS به `ai_api.php` متصل می‌شود.

### تنظیم فایل محرمانه

```powershell
Copy-Item config.docker.example.json config.json
```

در `config.json` مقدار `worker_token` را قرار دهید. برای هر سیستم یک `node_uid` ثابت و متفاوت انتخاب کنید؛ مثال:

```json
"node_uid": "main-pc-01",
"node_name": "Main-PC-Accounting-AI"
```

### بالا آوردن Ollama و دریافت مدل

```powershell
docker compose up -d ollama
docker compose exec ollama ollama pull qwen3:1.7b
```

### ساخت و اجرای Worker

```powershell
docker compose up -d --build worker
```

### مشاهده وضعیت و لاگ

```powershell
docker compose ps
docker compose logs -f worker
```

### توقف

```powershell
docker compose stop worker ollama
```

### حذف Containerها بدون حذف مدل‌ها

```powershell
docker compose down
```

Volume `ollama_data` مدل‌ها را نگه می‌دارد. برای حذف کامل مدل‌های دانلودشده باید Volume را هم آگاهانه حذف کنید؛ در استفاده عادی `down -v` اجرا نکنید.

### فعال‌سازی RAG در مرحله بعد

ابتدا مدل embedding را دریافت کنید:

```powershell
docker compose exec ollama ollama pull embeddinggemma
```

سپس دانش متنی را داخل RAG index کنید و `rag_enabled` را `true` کنید. در اولین تست Agent، RAG را خاموش نگه می‌داریم تا Tool Calling و مسیر Approval مستقل تست شوند.
