#!/usr/bin/env python3
"""Rewrite [137-kartabl-answer] and [ivr-2] digit overrides in Issabel override conf."""
from __future__ import print_function
import os
import re

OVERRIDE = "/etc/asterisk/extensions_override_issabelpbx.conf"
SAMPLE_DIR = os.path.dirname(os.path.abspath(__file__))

def load_sample(name):
    path = os.path.join(SAMPLE_DIR, name)
    with open(path, "r") as f:
        text = f.read()
    # keep from first context header
    m = re.search(r"(?=^\[)", text, re.M)
    return text[m.start():].strip() + "\n\n" if m else text.strip() + "\n\n"

def strip_context(src, ctx):
    # remove [ctx] ... until next [something] or EOF
    return re.sub(
        r"(?ms)^\[%s\][^\[]*" % re.escape(ctx),
        "",
        src,
    )

def main():
    if not os.path.isfile(OVERRIDE):
        open(OVERRIDE, "a").close()
    with open(OVERRIDE, "r") as f:
        cur = f.read()

    cur = strip_context(cur, "137-kartabl-answer")
    # only strip digit overrides we own inside ivr-2? safer: append full [ivr-2] digits
    # FreePBX merges by extension priority — override file wins for same exten.
    # Remove previous injected ivr-2 block if marked
    cur = re.sub(r"(?ms)^; --- 137 ivr-2 options start ---.*?^; --- 137 ivr-2 options end ---\s*", "", cur)

    kartabl = load_sample("kartabl-answer.conf.sample")
    ivr = load_sample("ivr-2-137-options.conf.sample")
    ivr_wrapped = "; --- 137 ivr-2 options start ---\n" + ivr + "; --- 137 ivr-2 options end ---\n"

    out = cur.rstrip() + "\n\n" + kartabl + ivr_wrapped
    with open(OVERRIDE, "w") as f:
        f.write(out)
    print("Updated", OVERRIDE)

if __name__ == "__main__":
    main()
