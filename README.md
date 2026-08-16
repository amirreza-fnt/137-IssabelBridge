# 137 Issabel Bridge — ضبط خودکار و ارتباط با میکروسرویس ثبت درخواست

> مطابق حرف کارفرما: **دامنه و دیتابیس جدا ندارد**. روی خود **ایزابل** نصب می‌شود و فقط به `137-request` + سرویس `files` HTTP می‌زند. لاگ جدا / Job جدا لازم نیست؛ تاریخچه در همان ثبت درخواست می‌ماند.

## جریان

```
سانترال Issabel (MixMonitor)
        │
        ▼
PHP bridge روی ایزابل
  1) prepare-upload + upload  →  files service  → fileId
  2) POST /api/v1/requests    →  137-request (channel=PhoneCall, X-Api-Key)
        │
        ▼
trackingCode  (کد یکتا 137-…)
```

| سناریو تسک | outcome | فایل‌ها |
|---|---|---|
| عدم پاسخ | `NO_ANSWER` | یک فایل ضبط شهروند |
| پاسخ (شهروند + اپراتور) | `ANSWERED` | یک یا دو فایل (in/out یا mixed) |
| رد/پاسخ از نرم‌افزار اپراتور | `call-control.php` → AMI Hangup / Redirect | — |

## نصب روی ایزابل

```bash
# روی سرور Issabel
sudo mkdir -p /var/www/html/137-bridge
sudo cp -r api lib agi dialplan config.example.php /var/www/html/137-bridge/
cd /var/www/html/137-bridge
sudo cp config.example.php config.php
sudo nano config.php   # آدرس‌ها، api_key، AMI، bridge_secret

sudo cp agi/137-submit-recording.agi /var/lib/asterisk/agi-bin/
sudo chmod +x /var/lib/asterisk/agi-bin/137-submit-recording.agi
sudo chown asterisk:asterisk /var/lib/asterisk/agi-bin/137-submit-recording.agi
```

Apache باید به `/var/www/html/137-bridge/api/` سرو بدهد (پیش‌فرض Issabel همین است).

### تنظیم `config.php`

| کلید | معنی |
|---|---|
| `files_base_url` | آدرس سرویس فایل از دید ایزابل (مثلاً `http://IP:6000`) |
| `request_base_url` | آدرس 137-request (مثلاً `http://IP:5006` یا `https://apiweb-137request.sabzevar.ir:5007`) |
| `api_key` | همان `dev-internal-key-137` / کلید Telephony |
| `monitor_dir` | معمولاً `/var/spool/asterisk/monitor` |
| `ami.*` | از `/etc/asterisk/manager.conf` |

ایزابل باید به پورت‌های files و request **شبکه‌ای** دسترسی داشته باشد (firewall).

## تست دستی

```bash
# عدم پاسخ
curl -s -X POST http://127.0.0.1/137-bridge/api/submit-recording.php \
  -H 'Content-Type: application/json' \
  -d '{
    "secret":"change-me-issabel-bridge",
    "outcome":"NO_ANSWER",
    "callerPhone":"09120000000",
    "files":["/var/spool/asterisk/monitor/TEST.wav"]
  }'

# پاسخ
curl -s -X POST http://127.0.0.1/137-bridge/api/submit-recording.php \
  -H 'Content-Type: application/json' \
  -d '{
    "secret":"change-me-issabel-bridge",
    "outcome":"ANSWERED",
    "callerPhone":"09120000000",
    "operatorExt":"1001",
    "files":["/path/citizen.wav","/path/operator.wav"]
  }'

# رد تماس از اپراتور
curl -s -X POST http://127.0.0.1/137-bridge/api/call-control.php \
  -H 'Content-Type: application/json' \
  -d '{"secret":"change-me-issabel-bridge","action":"reject","channel":"SIP/xxxx-00000001"}'
```

پاسخ موفق submit:

```json
{ "ok": true, "requestId": "...", "trackingCode": "137-14050525-000001", "outcome": "NO_ANSWER" }
```

## Dialplan

نمونه‌ها در `dialplan/extensions_custom.conf.sample` — باید با مسیر واقعی MixMonitor روی ایزابل شما منطبق شود.

## داده لازم از سرور ایزابل (اگر داری بفرست)

1. اگر فایل PHP قبلی روی ایزابل هست → همان را بفرست (کارفرما گفت شاید فقط دستکاری همان باشد)
2. مسیر واقعی ضبط‌ها (`ls /var/spool/asterisk/monitor | head`)
3. نمونه نام فایل MixMonitor برای یک تماس
4. بخش `[xxxxx]` از `/etc/asterisk/manager.conf` (user/secret AMI) — پسورد را در چت عمومی نگذار، در `config.php` محلی بگذار
5. از روی ایزابل: آیا `curl http://<request-host>:5006/health` و پورت files جواب می‌دهد؟
6. IP/URL نهایی که ایزابل باید به files و request بزند

## ارتباط با 137-request

- کانال: `PhoneCall`
- Auth: `X-Api-Key`
- فایل‌ها فقط به‌صورت `fileIds` (اول آپلود در files)
- کد یکتا: فیلد `trackingCode` در پاسخ 201
