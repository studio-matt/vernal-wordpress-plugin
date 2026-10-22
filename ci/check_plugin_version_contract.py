#!/usr/bin/env python3
"""Require plugin Version header / constant alignment and 1.5.31+ identity-drift floor."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MAIN = ROOT / "vernal-contentum.php"


def main() -> int:
    if not MAIN.is_file():
        print(f"FAIL: missing {MAIN}", file=sys.stderr)
        return 1
    text = MAIN.read_text(encoding="utf-8")
    header = re.search(r"^\s*\*\s*Version:\s*([0-9.]+)", text, re.M)
    const = re.search(
        r"define\(\s*'VERNAL_CONTENTUM_VERSION'\s*,\s*'([0-9.]+)'\s*\)", text
    )
    if not header or not const:
        print("FAIL: could not parse Version header / VERNAL_CONTENTUM_VERSION", file=sys.stderr)
        return 1
    if header.group(1) != const.group(1):
        print(
            f"FAIL: Version header {header.group(1)} != constant {const.group(1)}",
            file=sys.stderr,
        )
        return 1
    parts = [int(x) for x in header.group(1).split(".")]
    if parts < [1, 5, 31]:
        print(
            f"FAIL: plugin version {header.group(1)} below 1.5.31 identity-drift floor",
            file=sys.stderr,
        )
        return 1
    print(f"OK: plugin version {header.group(1)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
