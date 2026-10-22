#!/usr/bin/env python3
"""Fail-closed scanner (plugin-local): Machine calls must use Vernal_Backend_API on /plugin/*.

Self-contained so plugin CI does not need a private checkout of vernal-agents-post-v0.
Keep in sync with backend-repo-git/scripts/check_wp_machine_route_contract.py intent.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

REQUEST_RE = re.compile(
    r"Vernal_Backend_API::request\s*\(\s*['\"]([^'\"]+)['\"]",
    re.MULTILINE,
)
BYPASS_RE = re.compile(
    r"(?:trailingslashit\s*\(\s*\$backend_url\s*\)|VERNAL_BACKEND_URL)\s*\.\s*['\"]([^'\"]+)['\"]",
    re.MULTILINE,
)
DYNAMIC_CONCAT = re.compile(
    r"(?:backend_url|VERNAL_BACKEND).*?\.\s*\$",
    re.MULTILINE | re.DOTALL,
)

IGNORE_DIRS = {"vendor", "lib", "node_modules", ".git", "ci"}
NON_MACHINE_PREFIXES = (
    "https://github.com",
    "http://",
    "https://api.wordpress",
)

# Minimal auth classification (mirror backend app.authz.paths for plugin purposes).
_PUBLIC_PREFIXES = ("/health", "/g/", "/q/", "/ics/", "/ar/")


def classify_auth(path: str) -> str:
    path = path.split("?")[0] or "/"
    if not path.startswith("/"):
        path = "/" + path
    if path.startswith("/plugin/"):
        return "plugin"
    if path in {"/", "/health"} or any(path.startswith(p) for p in _PUBLIC_PREFIXES):
        return "public"
    return "user"


def _load_exceptions(path: Path) -> list:
    if not path.is_file():
        return []
    data = json.loads(path.read_text(encoding="utf-8"))
    return list(data.get("exceptions") or [])


def _exception_allows(exceptions: list, detail: str) -> bool:
    for ex in exceptions:
        pat = (ex.get("destination_pattern") or "").strip()
        if not pat or "*" in pat:
            continue
        if pat in detail or detail.endswith(pat) or detail == pat:
            return True
    return False


def _php_files(plugin_root: Path) -> list[Path]:
    out = []
    includes = plugin_root / "includes"
    roots = [includes] if includes.is_dir() else [plugin_root]
    for base in roots:
        for p in base.rglob("*.php"):
            if any(part in IGNORE_DIRS for part in p.parts):
                continue
            out.append(p)
    return out


def _normalize_endpoint(raw: str) -> str:
    ep = raw.split("?")[0].strip().lstrip("/")
    if not ep.startswith("/"):
        ep = "/" + ep
    return ep


def main() -> int:
    here = Path(__file__).resolve().parent
    plugin_root_default = here.parent
    ap = argparse.ArgumentParser()
    ap.add_argument("--plugin-root", default=str(plugin_root_default))
    ap.add_argument(
        "--exceptions",
        default=str(here / "wp_machine_route_exceptions.json"),
    )
    args = ap.parse_args()
    plugin_root = Path(args.plugin_root)
    if not plugin_root.is_dir():
        print(f"FAIL: plugin root missing: {plugin_root}", file=sys.stderr)
        return 1

    exceptions = _load_exceptions(Path(args.exceptions))
    offenders: list[str] = []
    ok_endpoints: list[str] = []

    for path in _php_files(plugin_root):
        text = path.read_text(encoding="utf-8", errors="ignore")
        rel = path.relative_to(plugin_root).as_posix()

        for m in REQUEST_RE.finditer(text):
            ep = _normalize_endpoint(m.group(1))
            auth = classify_auth(ep)
            if auth not in ("plugin", "public"):
                msg = f"{rel}: helper calls JWT-only/product route {ep} (auth={auth})"
                if not _exception_allows(exceptions, ep):
                    offenders.append(msg)
            else:
                ok_endpoints.append(f"{rel}:{ep}")

        for m in BYPASS_RE.finditer(text):
            frag = m.group(1)
            if any(frag.startswith(p) for p in NON_MACHINE_PREFIXES):
                continue
            if "podcasts/retrofit" in frag or frag.startswith("podcasts/"):
                msg = (
                    f"{rel}: bypass Machine URL fragment '{frag}' "
                    "(use Vernal_Backend_API::request plugin/*)"
                )
                if not _exception_allows(exceptions, frag):
                    offenders.append(msg)

        if "podcasts/retrofit" in text and "Vernal_Backend_API::request" not in text:
            if re.search(r"wp_remote_(?:get|post|request)\s*\(", text):
                msg = (
                    f"{rel}: wp_remote_* to podcasts/retrofit without "
                    "Vernal_Backend_API::request"
                )
                if not _exception_allows(exceptions, rel):
                    offenders.append(msg)

        span = DYNAMIC_CONCAT.search(text)
        if span:
            snippet = text[span.start() : span.start() + 120].replace("\n", " ")
            if re.search(r"podcasts/|scheduled-posts", snippet) and "plugin/" not in snippet:
                msg = f"{rel}: unresolved dynamic Machine URL ({snippet[:80]}…)"
                if not _exception_allows(exceptions, snippet):
                    offenders.append(msg)

    if offenders:
        print("WP→Machine route contract FAIL:", file=sys.stderr)
        for o in sorted(set(offenders)):
            print(f"  - {o}", file=sys.stderr)
        return 1

    print(f"OK: {len(ok_endpoints)} helper endpoint refs; no bypasses")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
