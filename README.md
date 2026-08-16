# 137 Issabel Bridge — AGI drop-in for موجودی ایزابل

> کارفرما: دامنه/DB جدا ندارد. همان فایل‌های AGI روی ایزابل (`/var/lib/asterisk/agi-bin`) دستکاری می‌شوند.

## فایل‌های قدیمی پیدا‌شده روی Issabel (`192.168.1.70`)

| فایل | Exten / استفاده | قبلاً |
|---|---|---|
| `137record.php` | `138` | SOAP `AddVoiceMessageWithSend2` + `MessageFileBase64` |
| `137no_oprtator.php` | `140` | همان (بدون اپراتور) |
| `137queue.php` | Queue `8002` hangup | SOAP `AddAgentCall` + `AttachVoiceFilePath` |
| `137_Pay.php` | `139` | SOAP `Tracking` |

SOAP قدیمی: `http://192.168.1.42:8090/service/asterisk/asterisk.asmx`

## جایگزین جدید

همان نام فایل‌ها در `agi-bin/` + کتابخانه `137_bridge.php`:

1. `sox` → wav 16-bit  
2. `POST files/prepare-upload` + `upload` → `fileId`  
3. `POST /api/v1/requests` کانال `PhoneCall` → **`trackingCode`**  
4. پخش کد با `say_digits`

Dialplan فعلی **عوض نمی‌شود** (همان AGI نام‌ها).

## نصب روی ایزابل

```bash
# از لپ‌تاپ / گیت
cd /tmp
git clone https://github.com/amirreza-fnt/137-IssabelBridge.git
cd 137-IssabelBridge

# بکاپ فایل‌های فعلی
sudo cp /var/lib/asterisk/agi-bin/137record.php /var/lib/asterisk/agi-bin/137record.php.bak.$(date +%Y%m%d)
sudo cp /var/lib/asterisk/agi-bin/137no_oprtator.php /var/lib/asterisk/agi-bin/137no_oprtator.php.bak.$(date +%Y%m%d)
sudo cp /var/lib/asterisk/agi-bin/137queue.php /var/lib/asterisk/agi-bin/137queue.php.bak.$(date +%Y%m%d)
sudo cp /var/lib/asterisk/agi-bin/137_Pay.php /var/lib/asterisk/agi-bin/137_Pay.php.bak.$(date +%Y%m%d)

# کپی اسکریپت‌ها
sudo cp agi-bin/137_bridge.php agi-bin/137record.php agi-bin/137no_oprtator.php \
        agi-bin/137queue.php agi-bin/137_Pay.php \
        /var/lib/asterisk/agi-bin/

sudo cp agi-bin/137-bridge.config.example.php /etc/asterisk/137-bridge.php
sudo nano /etc/asterisk/137-bridge.php
# → files_base_url و request_base_url را به IP سرور AlmaLinux درست کن

sudo chmod +x /var/lib/asterisk/agi-bin/137*.php
sudo chown asterisk:asterisk /var/lib/asterisk/agi-bin/137*.php /etc/asterisk/137-bridge.php
```

### تست شبکه از روی ایزابل

```bash
curl -s http://192.168.1.12:5006/health
curl -s http://192.168.1.12:6000/health   # یا پورت واقعی files
```

اگر health نداد، firewall روی AlmaLinux را باز کن یا URL را در `137-bridge.php` اصلاح کن.

### تست تماس

- `138` / `140` → باید کد پیگیری خوانده شود  
- صف `8002` بعد از Hangup → در `asterisk -rvvv` لاگ `137queue tracking=...`

```bash
asterisk -rvvv
# یا
tail -f /var/log/asterisk/full | grep 137
```

## رابطه با میکروسرویس‌ها

| سرویس | نقش |
|---|---|
| **files** | ذخیره صوت |
| **137-request** | ساخت درخواست `PhoneCall` + `trackingCode` + لاگ |
| **137-Referral** | بعد از create در صورت bootstrap (سمت request) |

تغییر اجباری در کد request/files برای این تسک لازم نیست (مگر استعلام رهگیری `137_Pay` که فعلاً stub است تا `GET by-tracking-code` پیاده شود).

## نکته

`137record.php` فعلی روی سرور با `exit` وسط دیباگ قطع شده بود؛ نسخه ریپو آن را درست کرده و به REST وصل می‌کند.
