#!/usr/bin/env python3
"""Poll GitHub check-runs until staging-required gates succeed on an exact SHA."""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time

WANTED = ("release-gate", "rc1-mysql-php")


def fetch_checks(repo: str, sha: str) -> dict[str, tuple[str, str | None]]:
    raw = subprocess.check_output(
        ["gh", "api", f"repos/{repo}/commits/{sha}/check-runs", "--paginate"],
        text=True,
    )
    # --paginate may return concatenated JSON objects; normalize.
    runs: list[dict] = []
    raw = raw.strip()
    if not raw:
        return {}
    try:
        data = json.loads(raw)
        runs = list(data.get("check_runs") or [])
    except json.JSONDecodeError:
        # Concatenated pages: split by }{ heuristic
        parts = raw.replace("}{", "}\n{").splitlines()
        for part in parts:
            try:
                data = json.loads(part)
            except json.JSONDecodeError:
                continue
            runs.extend(data.get("check_runs") or [])

    found: dict[str, tuple[str, str | None]] = {}
    for r in runs:
        name = r.get("name") or ""
        if name in WANTED:
            found[name] = (r.get("status") or "", r.get("conclusion"))
    return found


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--repo", required=True)
    ap.add_argument("--sha", required=True)
    ap.add_argument("--timeout-sec", type=int, default=2400)
    ap.add_argument("--poll-sec", type=int, default=30)
    args = ap.parse_args()

    if not os.environ.get("GH_TOKEN") and not os.environ.get("GITHUB_TOKEN"):
        print("REFUSED: GH_TOKEN/GITHUB_TOKEN required", file=sys.stderr)
        return 2

    deadline = time.time() + args.timeout_sec
    while time.time() < deadline:
        found = fetch_checks(args.repo, args.sha)
        print(json.dumps(found, indent=2), flush=True)
        if set(WANTED) <= set(found):
            if all(found[n][0] == "completed" and found[n][1] == "SUCCESS" for n in WANTED):
                print("REQUIRED_CHECKS_SUCCESS", flush=True)
                return 0
            failed = {
                n: found[n]
                for n in WANTED
                if found[n][0] == "completed" and found[n][1] != "SUCCESS"
            }
            if failed:
                print(json.dumps({"failed": failed}), flush=True)
                return 1
        time.sleep(args.poll_sec)

    print("TIMEOUT_WAITING_FOR_CHECKS", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
