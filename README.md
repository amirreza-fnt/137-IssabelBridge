# 137 Issabel Bridge — AGI drop-in for موجودی ایزابل

> کارفرما: دامنه/DB جدا ندارد. همان فایل‌های AGI روی ایزابل (`/var/lib/asterisk/agi-bin`) دستکاری می‌شوند.

## فایل‌های قدیمی پیدا‌شده روی Issabel (`192.168.1.70`)

| فایل | Exten / استفاده | قبلاً |
|---|---|---|
| `137record.php` | `138` | SOAP `AddVoiceMessageWithSend2` + `MessageFileBase64` |
| `137no_oprtator.php` | `140` | همان (بدون اپراتور) |
| `137queue.php` | بعد از پایان تماس صف `8002` | SOAP `AddAgentCall` + `AttachVoiceFilePath` |
| `137_Pay.php` | `139` | SOAP `Tracking` |

SOAP قدیمی: `http://192.168.1.42:8090/service/asterisk/asterisk.asmx`

## جایگزین جدید

همان نام فایل‌ها در `agi-bin/` + کتابخانه `137_bridge.php`:

1. `sox` → wav 16-bit  
2. `POST files/prepare-upload` + `upload` → `fileId`  
3. `POST /api/v1/requests` کانال `PhoneCall` → **`trackingCode`**  
4. پخش کد با `say_digits` (مسیر ویس‌میل)

**مهم:** `137queue.php` را به‌عنوان آرگومان AGI داخل `Queue()` نگذارید (آن لحظه «جواب» است و فایل ناقص آپلود می‌شود). بعد از برگشت از `Queue()` وقتی `QUEUESTATUS` خالی است اجرا شود — نمونه: `dialplan/queue-8002-hangup-agi.conf.sample`.

## HTTP API برای کارتابل (روی ایزابل)

Deploy پوشه `api/` + `lib/` + `config.php` زیر مثلاً `/var/www/html/137-bridge/`:

| Endpoint | نقش |
|---|---|
| `GET api/active-calls.php` | وضعیت صف / تماس زنده (AMI) |
| `POST api/call-control.php` | `answer` / `reject` / `hangup` |

سرویس `137-request` این‌ها را پروکسی می‌کند: `/api/v1/phone-calls/live` و `/api/v1/phone-calls/control`.

## WebRTC داخل کارتابل (داخلی 2101 — بدون MicroSIP)

مسیر کامل و ایمن (SIP/2001 دست نخورده): [`webrtc/INSTALL.md`](webrtc/INSTALL.md)

خلاصه:

1. PJSIP endpoint `2101` + WSS `:8089` از `webrtc/pjsip-2101.conf.sample`
2. `dialplan/install-137-overrides.py` → `Dial(PJSIP/2101)` در `[137-kartabl-answer]`
3. در `137-request` Telephony: `DefaultAgentExten=2101`, `WssUrl`, `SipPassword`
4. کارتابل را با **HTTPS** باز کنید

چک:

```bash
asterisk -rx "pjsip show endpoint 2101"
asterisk -rx "dialplan show 2101@137-kartabl-answer"
```

## نصب روی ایزابل

```bash
cd /tmp
git clone https://github.com/amirreza-fnt/137-IssabelBridge.git
cd 137-IssabelBridge

sudo cp /var/lib/asterisk/agi-bin/137*.php /var/lib/asterisk/agi-bin/bak.$(date +%Y%m%d)/ 2>/dev/null || true
sudo cp agi-bin/137_bridge.php agi-bin/137record.php agi-bin/137no_oprtator.php \
        agi-bin/137queue.php agi-bin/137_Pay.php \
        /var/lib/asterisk/agi-bin/

sudo test -f /etc/asterisk/137-bridge.php || sudo cp agi-bin/137-bridge.config.example.php /etc/asterisk/137-bridge.php
sudo chmod +x /var/lib/asterisk/agi-bin/137*.php
sudo chown asterisk:asterisk /var/lib/asterisk/agi-bin/137*.php
```

### Dialplan صف ۸۰۰۲ (اجباری برای فایل کامل)

در `/etc/asterisk/extensions_override_issabelpbx.conf` خط `Queue(...137queue.php...)` را مطابق `dialplan/queue-8002-hangup-agi.conf.sample` عوض کنید، بعد:

```bash
asterisk -rx "dialplan reload"
```

### تست

- `138` / `140` → کد پیگیری  
- صف `8002` بعد از Hangup → `137queue tracking=...` (بعد از قطع)

```bash
tail -f /var/log/asterisk/full | grep 137
```

## رابطه با میکروسرویس‌ها

| سرویس | نقش |
|---|---|
| **files** | ذخیره صوت |
| **137-request** | `PhoneCall` + کارتابل HTML |
| **137-Referral** | bootstrap ارجاع |
