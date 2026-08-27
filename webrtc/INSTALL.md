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
