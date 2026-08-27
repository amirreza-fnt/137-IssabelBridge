#!/usr/bin/env python3
"""Install 137 dialplan overrides — surgically replace qagi/qcall and MixMonitor post."""
from __future__ import print_function
import os
import re

OVERRIDE = "/etc/asterisk/extensions_override_issabelpbx.conf"
SAMPLE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "137-full-overrides.conf.sample")
MARK_A = "; --- 137 FULL OVERRIDES START ---"
MARK_B = "; --- 137 FULL OVERRIDES END ---"

QAGI = (
    "exten => 8002,n(qagi),"
    "Set(__MIXMON_POST=/usr/bin/php -q /var/lib/asterisk/agi-bin/137-on-record-end.php "
    "${UNIQUEID} ${CALLERID(num)})"
)
QCALL = (
    "exten => 8002,n(qcall),"
    "Queue(8002,${QOPTIONS},${QURL},${QAANNOUNCE},${QMAXWAIT},,,${QGOSUB},${QRULE},${QPOSITION})"
)

# Force MixMonitor on FreePBX/Issabel recq priority 2.
# IMPORTANT: use MONITOR_FILENAME (set in recq,1) — MIXMON_CALLFILENAME is often empty here.
RECMON = (
    "exten => recq,2,"
    "MixMonitor(${MONITOR_FILENAME}.wav,${MONITOR_OPTIONS},"
    "/usr/bin/php -q /var/lib/asterisk/agi-bin/137-on-record-end.php ${UNIQUEID} ${CALLERID(num)})"
)


def load_contexts_only(sample_text):
    """Keep only non-ext-queues contexts from sample (hangup/kartabl/ivr)."""
    # drop [ext-queues] section from sample; we patch qagi/qcall in-place instead
    text = re.sub(r"(?ms)^\[ext-queues\][^\[]*", "", sample_text)
    m = re.search(r"(?=^\[)", text, re.M)
    return (text[m.start():].strip() + "\n") if m else text.strip() + "\n"


def main():
    with open(SAMPLE, "r") as f:
        sample = f.read()

    if not os.path.isfile(OVERRIDE):
        open(OVERRIDE, "w").close()

    with open(OVERRIDE, "r") as f:
        cur = f.read()

    # Remove previous managed block
    cur = re.sub(
        r"(?ms)^; --- 137 FULL OVERRIDES START ---.*?^; --- 137 FULL OVERRIDES END ---\s*",
        "",
        cur,
    )
    cur = re.sub(
        r"(?ms)^; --- 137 ivr-2 options start ---.*?^; --- 137 ivr-2 options end ---\s*",
        "",
        cur,
    )
    for ctx in ("137-kartabl-answer", "137-hangup-submit", "137-q-hangup"):
        cur = re.sub(r"(?ms)^\[%s\][^\[]*" % re.escape(ctx), "", cur)

    # Ensure [ext-queues] exists in override so replacements stick
    if not re.search(r"(?m)^\[ext-queues\]", cur):
        cur = cur.rstrip() + "\n\n[ext-queues]\n"

    # Replace ALL qagi / qcall lines for 8002 (critical — previous append lost to earlier lines)
    if re.search(r"(?m)^exten\s*=>\s*8002,n\(qagi\),", cur):
        cur = re.sub(r"(?m)^exten\s*=>\s*8002,n\(qagi\),.*$", QAGI, cur)
    else:
        cur = re.sub(r"(?m)^(\[ext-queues\]\s*)$", r"\1\n" + QAGI, cur, count=1)

    if re.search(r"(?m)^exten\s*=>\s*8002,n\(qcall\),", cur):
        cur = re.sub(r"(?m)^exten\s*=>\s*8002,n\(qcall\),.*$", QCALL, cur)
    else:
        cur = re.sub(
            r"(?m)^(exten\s*=>\s*8002,n\(qagi\),.*$)",
            r"\1\n" + QCALL,
            cur,
            count=1,
        )

    # MixMonitor post-command override
    if not re.search(r"(?m)^\[sub-record-check\]", cur):
        cur = cur.rstrip() + "\n\n[sub-record-check]\n" + RECMON + "\n"
    elif re.search(r"(?m)^exten\s*=>\s*recq,2,", cur):
        cur = re.sub(r"(?m)^exten\s*=>\s*recq,2,.*$", RECMON, cur)
    else:
        cur = re.sub(
            r"(?m)^(\[sub-record-check\]\s*)$",
            r"\1\n" + RECMON,
            cur,
            count=1,
        )

    contexts = load_contexts_only(sample)
    wrapped = MARK_A + "\n" + contexts + MARK_B + "\n"
    out = cur.rstrip() + "\n\n" + wrapped

    with open(OVERRIDE, "w") as f:
        f.write(out)

    print("Updated", OVERRIDE)
    print("Check after reload:")
    print('  asterisk -rx "dialplan show 8002@ext-queues" | grep -E "qagi|qcall|MIXMON"')
    print('  asterisk -rx "dialplan show recq@sub-record-check" | head -20')


if __name__ == "__main__":
    main()
