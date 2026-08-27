#!/usr/bin/env python3
"""Install/replace the 137 dialplan override block on Issabel."""
from __future__ import print_function
import os
import re

OVERRIDE = "/etc/asterisk/extensions_override_issabelpbx.conf"
SAMPLE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "137-full-overrides.conf.sample")
MARK_A = "; --- 137 FULL OVERRIDES START ---"
MARK_B = "; --- 137 FULL OVERRIDES END ---"


def main():
    with open(SAMPLE, "r") as f:
        block = f.read().strip() + "\n"

    wrapped = MARK_A + "\n" + block + MARK_B + "\n"

    if not os.path.isfile(OVERRIDE):
        open(OVERRIDE, "w").close()

    with open(OVERRIDE, "r") as f:
        cur = f.read()

    # Remove previous 137 managed blocks / old contexts we used to inject
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
    for ctx in (
        "137-kartabl-answer",
        "137-hangup-submit",
        "137-q-hangup",
    ):
        cur = re.sub(r"(?ms)^\[%s\][^\[]*" % re.escape(ctx), "", cur)

    out = cur.rstrip() + "\n\n" + wrapped
    with open(OVERRIDE, "w") as f:
        f.write(out)
    print("Updated", OVERRIDE)
    print("Next: asterisk -rx \"dialplan reload\"")
    print("Verify: asterisk -rx \"dialplan show 137-hangup-submit\"")
    print("         asterisk -rx \"dialplan show 8002@ext-queues\" | head -40")


if __name__ == "__main__":
    main()
