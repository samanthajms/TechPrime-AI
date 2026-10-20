"""
Retrain the Build a PC Random Forest on the latest data, without ever leaving a broken model live.

Run nightly by the Windows scheduled task "TechPrime-AI PC Recommender Retrain" (daily 02:00),
or by hand (from TechPrime-AI):
    python ml/pc_recommend/retrain.py

1. scripts/build_pc_recommend_dataset.php writes data/train.csv.new (reads the database only).
2. train_model.py trains and tests on it and writes models/forest.json.new.
3. Both are checked; only then do they replace train.csv and forest.json.
   includes/pc_recommend.php reads forest.json on every request, so the site uses the new model
   from the next page load. Any failure keeps the current files.
Every run adds one line to logs/retrain.log (the folder is not served: .htaccess blocks ml/).
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path

BASE = Path(__file__).resolve().parent
APP = BASE.parent.parent  # TechPrime-AI
DATA = BASE / "data" / "train.csv"
OUT = BASE / "models" / "forest.json"
NEW_DATA = DATA.with_name(DATA.name + ".new")
NEW_MODEL = OUT.with_name(OUT.name + ".new")
LOG = BASE / "logs" / "retrain.log"
PHP = "C:/xampp/php/php.exe"
# A smaller table than this share of the last one means the database read went wrong.
MIN_ROW_SHARE = 0.9
# Cross-validated accuracy the new forest needs before it goes live (Oct 2026 model: 0.991).
MIN_ACCURACY = 0.9
STEP_TIMEOUT = 20 * 60
NO_WINDOW = getattr(subprocess, "CREATE_NO_WINDOW", 0)


class RetrainError(Exception):
    pass


def log(line: str) -> None:
    LOG.parent.mkdir(parents=True, exist_ok=True)
    stamped = f"{datetime.now():%Y-%m-%d %H:%M:%S}  {line}"
    with LOG.open("a", encoding="utf-8") as fh:
        fh.write(stamped + "\n")
    if sys.stdout is not None:  # None under pythonw (the scheduled task)
        print(stamped)


def run(cmd: list[str], step: str) -> None:
    try:
        done = subprocess.run(
            cmd, cwd=APP, capture_output=True, text=True, timeout=STEP_TIMEOUT, creationflags=NO_WINDOW
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise RetrainError(f"{step} step: {exc}") from exc
    if done.returncode != 0:
        tail = (done.stdout + done.stderr).strip()[-600:]
        raise RetrainError(f"{step} step exited {done.returncode}: {tail}")


def check_dataset(pd) -> tuple[int, int]:
    if not NEW_DATA.is_file():
        raise RetrainError("dataset step wrote no file")
    source = pd.read_csv(NEW_DATA, usecols=["source"])["source"]
    rule, real = int((source == "rule").sum()), int((source == "real").sum())
    if rule == 0 or real == 0:
        raise RetrainError(f"dataset has rule={rule} real={real} rows; both are needed")
    if DATA.is_file():
        before = len(pd.read_csv(DATA, usecols=["source"]))
        if rule + real < MIN_ROW_SHARE * before:
            raise RetrainError(f"dataset shrank from {before} to {rule + real} rows")
    return rule, real


def check_model(features: list[str], n_trees: int) -> dict:
    try:
        model = json.loads(NEW_MODEL.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        raise RetrainError(f"new model unreadable: {exc}") from exc
    if model.get("features") != features:
        raise RetrainError("new model was trained on other features")
    if len(model.get("trees") or []) != n_trees:
        raise RetrainError(f"new model has {len(model.get('trees') or [])} trees, expected {n_trees}")
    if float(model.get("accuracy", 0)) < MIN_ACCURACY:
        raise RetrainError(f"new model accuracy {model.get('accuracy', 0):.4f} is below {MIN_ACCURACY}")
    return model


def previous_hit(key: str) -> float | None:
    try:
        return float(json.loads(OUT.read_text(encoding="utf-8"))[key]["model"])
    except (OSError, ValueError, KeyError, TypeError):
        return None


def replace(src: Path, dst: Path) -> None:
    """Windows refuses the rename while PHP has the file open, so retry briefly."""
    for attempt in range(5):
        try:
            os.replace(src, dst)
            return
        except PermissionError as exc:
            if attempt == 4:
                raise RetrainError(f"could not replace {dst.name}: {exc}") from exc
            time.sleep(1)


def main() -> int:
    parser = argparse.ArgumentParser(description="Rebuild the dataset, retrain and swap in the new forest.")
    parser.add_argument("--php", default=PHP, help=f"PHP CLI (default: {PHP})")
    args = parser.parse_args()

    started = time.monotonic()
    sys.dont_write_bytecode = True  # no __pycache__ beside the repo files
    try:
        import pandas as pd

        from train_model import FEATURES, N_TREES, TOP_K

        for leftover in (NEW_DATA, NEW_MODEL):
            leftover.unlink(missing_ok=True)
        run([args.php, "scripts/build_pc_recommend_dataset.php", str(NEW_DATA)], "dataset")
        rule, real = check_dataset(pd)
        run(
            [sys.executable, str(BASE / "train_model.py"), "--data", str(NEW_DATA), "--out", str(NEW_MODEL)],
            "training",
        )
        model = check_model(FEATURES, N_TREES)
        hit_key = f"hit_at_{TOP_K}"
        before = previous_hit(hit_key)
        # The model first: once it is swapped the site is consistent, whatever happens to the csv.
        replace(NEW_MODEL, OUT)
        replace(NEW_DATA, DATA)
    except Exception as exc:  # unattended: every failure has to reach the log
        for leftover in (NEW_DATA, NEW_MODEL):
            leftover.unlink(missing_ok=True)
        reason = str(exc) if isinstance(exc, RetrainError) else f"{type(exc).__name__}: {exc}"
        log("FAILED  " + " | ".join(reason.split("\n")) + "  (current model kept)")
        return 1

    hit = model[hit_key]
    was = f"{before:.2%}" if before is not None else "n/a"
    log(
        f"ok  rows rule={rule} real={real}  accuracy={model['accuracy']:.4f} f1={model['f1']:.4f}  "
        f"Hit@{TOP_K} on {hit['queries']} real picks: model={hit['model']:.2%} (was {was}) "
        f"rules={hit['expert_rules']:.2%} random={hit['random']:.2%}  "
        f"{time.monotonic() - started:.0f}s"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
