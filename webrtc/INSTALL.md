# نصب WebRTC داخلی 2101 روی Issabel (بدون خراب کردن SIP/2001)

هدف: کارتابل با JsSIP روی `wss://192.168.1.70:8089/ws` به داخلی **2101** رجیستر شود.
داخلی **2001** / MicroSIP / صف / ضبط دست نمی‌خورند.

## پیش‌نیاز
- Asterisk 18 با `res_pjsip` و `res_http_websocket`
- گواهی TLS (همان HTTPS پنل ایزابل یا self-signed در `/etc/asterisk/keys/`)

```bash
# اگر کلید Asterisk ندارید، از cert پنل کپی کنید یا بسازید:
sudo mkdir -p /etc/asterisk/keys
# مثال: کپی از cert آپاچی Issabel (مسیر را با ls پیدا کنید)
# sudo cp /etc/httpd/pki/.../localhost.crt /etc/asterisk/keys/asterisk.crt
# sudo cp /etc/httpd/pki/.../localhost.key /etc/asterisk/keys/asterisk.key
sudo chown asterisk:asterisk /etc/asterisk/keys/asterisk.*
```

## ۱) PJSIP endpoint

```bash
cd /tmp/137-IssabelBridge && git pull
sudo tee -a /etc/asterisk/pjsip_custom.conf < webrtc/pjsip-2101.conf.sample
# مطمئن شو pjsip.conf یا issabel اینکلود می‌کند: #include pjsip_custom.conf
grep -n 'pjsip_custom' /etc/asterisk/pjsip*.conf /etc/asterisk/pjsip.conf 2>/dev/null
```

رمز `Pass2101WebRtc!` را عوض کنید و همان را در Telephony کارتابل بگذارید.

## ۲) HTTP / RTP

`http.conf` و `rtp.conf` را مطابق `http-wss.conf.sample` فعال کنید (icesupport=true).

```bash
asterisk -rx "module reload res_http_websocket.so"
asterisk -rx "module reload res_pjsip.so"
asterisk -rx "pjsip show transports"
asterisk -rx "pjsip show endpoint 2101"
```

> **توجه:** دستور را داخل bash این‌طور نزن: `pjsip show endpoint 2101`  
> درستش با Asterisk CLI است: `asterisk -rx "pjsip show endpoint 2101"`

باید `transport-wss` روی `:8089` دیده شود.

## ۳) فایروال

```bash
firewall-cmd --permanent --add-port=8089/tcp
firewall-cmd --permanent --add-port=10000-20000/udp
firewall-cmd --reload
```

## ۴) Dialplan جواب کارتابل

```bash
cd /tmp/137-IssabelBridge/dialplan
sudo python3 install-137-overrides.py
asterisk -rx "dialplan reload"
asterisk -rx "dialplan show 2101@137-kartabl-answer"
```

باید `Dial(PJSIP/2101,...)` برای 2101 و همچنان `Dial(SIP/...)` برای 2001 باشد.

## ۵) تست سریع

از مرورگر روی **HTTPS** کارتابل: وضعیت باید «WebRTC آماده» شود.
```bash
asterisk -rx "pjsip show contacts" | grep 2101
```

## ۶) اگر وضعیت «قطع از WSS» ماند (در حالی که curl از سرور 426 می‌دهد)

علت رایج: کارتابل از دامنه عمومی (`https://apiweb-...`) باز شده و مرورگر WSS مستقیم به `issabel.local` / `192.168.1.70` را به‌خاطر **Private Network Access** قطع می‌کند. `pjsip show contacts` هم `no-contact` می‌ماند.

**راه‌حل سریع (بدون تغییر nginx):** کارتابل را از LAN باز کنید، مثلاً  
`https://192.168.1.12:5007/kartabl/`  
(اگر هشدار گواهی آمد Accept کنید). Chrome را بعد از نصب root cert کاملاً ببندید و باز کنید.

**راه‌حل پایدار:** روی `.12` در nginx، `location /asterisk-wss/` را از `deploy/nginx.conf` ریپو `137-request` اضافه کنید، سپس:
```bash
# روی .12 — WssUrl را به پروکسی هم‌مبدأ بگذارید
# wss://apiweb-137request.sabzevar.ir:5007/asterisk-wss/ws
nginx -t && systemctl reload nginx
```
و در `appsettings` / env سرویس درخواست:
`Telephony__WssUrl=wss://apiweb-137request.sabzevar.ir:5007/asterisk-wss/ws`

## ۷) صدا نمی‌آید (بوق بعد از آهنگ صف) — ICE / media_address

سیگنال OK ولی RTP قطع است اگر `rtp.conf` خالی از ICE باشد یا `media_address` خالی باشد.

```bash
# بکاپ
cp -a /etc/asterisk/rtp.conf /etc/asterisk/rtp.conf.bak-$(date +%Y%m%d)

# اگر [general] هست این خطوط را داخلش بگذار؛ وگرنه کل فایل را بساز:
python3 << 'PY'
from pathlib import Path
p = Path("/etc/asterisk/rtp.conf")
t = p.read_text(encoding="utf-8", errors="replace") if p.exists() else ""
need = [
    ("icesupport", "icesupport=yes"),
    ("stunaddr", "stunaddr=stun.l.google.com:19302"),
    ("rtpstart", "rtpstart=10000"),
    ("rtpend", "rtpend=20000"),
]
if "[general]" not in t:
    t = "[general]\n" + "\n".join(x[1] for x in need) + "\n" + t
else:
    for key, line in need:
        if key not in t:
            t = t.replace("[general]", "[general]\n" + line, 1)
p.write_text(t, encoding="utf-8")
print(p.read_text())
PY

asterisk -rx "module reload res_rtp_asterisk.so"

# media_address روی endpoint 2101
grep -n "type=endpoint" -A30 /etc/asterisk/pjsip_custom.conf | head -40
# داخل بلوک endpoint 2101 این دو خط را اضافه کن (با vi یا python):
# media_address=192.168.1.70
# bind_rtp_to_media_address=yes

python3 << 'PY'
from pathlib import Path
path = Path("/etc/asterisk/pjsip_custom.conf")
t = path.read_text(encoding="utf-8", errors="replace")
# فقط داخل اولین بلوک 2101 type=endpoint تزریق کن اگر نیست
import re
def patch_endpoint(text):
    # پیدا کردن [2101] ... type=endpoint تا قبل از بلوک بعدی [
    parts = re.split(r'(?m)^(?=\[)', text)
    out = []
    for part in parts:
        if re.match(r'^\[2101\]', part) and re.search(r'(?m)^type\s*=\s*endpoint\s*$', part):
            if "media_address=" not in part:
                part = part.rstrip() + "\nmedia_address=192.168.1.70\nbind_rtp_to_media_address=yes\n\n"
            elif "bind_rtp_to_media_address=" not in part:
                part = part.rstrip() + "\nbind_rtp_to_media_address=yes\n\n"
        out.append(part)
    return "".join(out)
path.write_text(patch_endpoint(t), encoding="utf-8")
print("patched", path)
PY

asterisk -rx "module reload res_pjsip.so"
asterisk -rx "pjsip show endpoint 2101" | grep -iE 'media_address|bind_rtp|ice_support|webrtc'
grep -nE 'icesupport|stunaddr|rtpstart|rtpend' /etc/asterisk/rtp.conf
```

فایروال Issabel اغلب خاموش است (`FirewallD is not running`) — اگر `iptables` هم باز است مشکل نیست.
بدون `nano`: از `vi` یا همین `python3` استفاده کن.
