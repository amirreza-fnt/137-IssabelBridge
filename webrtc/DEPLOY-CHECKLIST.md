# استقرار WebRTC کارتابل (چک‌لیست)

بدون خراب کردن SIP/2001 و ضبط فعلی.

## ایزابل `192.168.1.70`

```bash
cd /tmp/137-IssabelBridge && git pull

# 1) PJSIP 2101 + WSS — جزئیات: webrtc/INSTALL.md
sudo mkdir -p /etc/asterisk/keys
# cert را تنظیم کنید سپس:
sudo tee -a /etc/asterisk/pjsip_custom.conf < webrtc/pjsip-2101.conf.sample
# http.conf / rtp.conf مطابق webrtc/http-wss.conf.sample
asterisk -rx "module reload res_pjsip.so"
asterisk -rx "pjsip show endpoint 2101"
asterisk -rx "pjsip show transports"

# 2) Dialplan جواب کارتابل (PJSIP برای 2101، SIP برای بقیه)
cd dialplan && sudo python3 install-137-overrides.py
asterisk -rx "dialplan reload"
asterisk -rx "dialplan show 2101@137-kartabl-answer"
```

فایروال: TCP `8089` + UDP RTP range.

## سرور request `.12`

```bash
cd /opt/137-request-src && git pull
sudo systemctl stop requestservice
dotnet publish src/RequestService.Api/RequestService.Api.csproj -c Release -o /opt/requestservice

# Telephony در appsettings (Development یا Production) باید شامل باشد:
# DefaultAgentExten=2101
# WssUrl=wss://192.168.1.70:8089/ws
# SipDomain=192.168.1.70
# SipUsername=2101
# SipPassword=همان رمز pjsip 2101

sudo chown -R requestservice:requestservice /opt/requestservice
sudo systemctl start requestservice

curl -s http://127.0.0.1:5006/api/v1/phone-calls/config -H "X-Api-Key: dev-internal-key-137"
```

باید `webrtc.enabled: true` باشد.

## تست

1. کارتابل را با **HTTPS** باز کنید (نه http://192.168.1.12:5006).
2. اجازه میکروفون بدهید → وضعیت «WebRTC: آماده».
3. زنگ ۱۳۷ → ۲ → در کارتابل «جواب در کارتابل».
4. صحبت کنید → قطع → ردیف + صوت در سابقه.

Fallback: MicroSIP روی 2001 هنوز کار می‌کند اگر لازم شد.
