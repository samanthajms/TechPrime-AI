"""
Add reviewed shopper questions to the training data.

Input: the CSV from `php scripts/primo_export_unanswered.php`, with the "intent" column filled in by a
person. Rows with an empty intent or "skip" are ignored. A row is also left out when it

  * names an intent Primo does not have (typo in the review),
  * is already in data/intents_train.csv, or
  * is too close to a sentence in data/intents_test_hard.csv (that set must stay unseen, or its
    score stops meaning anything).

Usage (from this directory, with venv active):
    python add_reviewed.py C:\\Users\\you\\primo_review_2026-11-01.csv            # preview only
    python add_reviewed.py C:\\Users\\you\\primo_review_2026-11-01.csv --write    # append to training
Then: python train_model.py && python test_intents.py   (compare with BASELINE.md before deploying)
"""
from __future__ import annotations

import csv
import re
import sys
from pathlib import Path

import pandas as pd
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.metrics.pairwise import cosine_similarity

from preprocess import preprocess_text

BASE_DIR = Path(__file__).resolve().parent
TRAIN_PATH = BASE_DIR / "data" / "intents_train.csv"
HARD_PATH = BASE_DIR / "data" / "intents_test_hard.csv"
TEST_SIMILARITY_LIMIT = 0.80   # character n-gram cosine to any hard-test sentence
MASKS = re.compile(r"\[(?:email|number)\]")   # placeholders written by primo_redact() in primo_chat.php


def main() -> None:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not args:
        raise SystemExit(__doc__)
    write = "--write" in sys.argv
    review = pd.read_csv(args[0], dtype=str, keep_default_na=False, encoding="utf-8-sig")
    if "text" not in review.columns or "intent" not in review.columns:
        raise SystemExit("The review file needs 'text' and 'intent' columns.")

    train = pd.read_csv(TRAIN_PATH)
    hard = pd.read_csv(HARD_PATH)
    intents = set(train["intent"].astype(str).str.strip())
    have = set(train["text"].astype(str).map(preprocess_text))
    hard_clean = hard["text"].astype(str).map(preprocess_text).tolist()

    candidates = []
    skipped = []
    for _, row in review.iterrows():
        intent = row["intent"].strip()
        text = MASKS.sub(" ", row["text"]).strip()
        if intent == "" or intent.lower() == "skip":
            continue
        if intent not in intents:
            skipped.append((row["text"], intent, "unknown intent"))
            continue
        if preprocess_text(text) == "":
            skipped.append((row["text"], intent, "empty after cleaning"))
            continue
        candidates.append((text, intent))

    added = []
    if candidates:
        vec = TfidfVectorizer(analyzer="char_wb", ngram_range=(3, 5)).fit(
            hard_clean + [preprocess_text(t) for t, _ in candidates]
        )
        hard_matrix = vec.transform(hard_clean)
        for text, intent in candidates:
            clean = preprocess_text(text)
            if clean in have:
                skipped.append((text, intent, "already in training"))
                continue
            sims = cosine_similarity(vec.transform([clean]), hard_matrix)[0]
            j = int(sims.argmax())
            if sims[j] >= TEST_SIMILARITY_LIMIT:
                skipped.append((text, intent, f'too close to hard-test "{hard["text"][j]}" ({sims[j]:.2f})'))
                continue
            have.add(clean)
            added.append((text, intent))

    for text, intent in added:
        print(f"  ADD   [{intent}] {text}")
    for text, intent, why in skipped:
        print(f"  SKIP  [{intent}] {text} - {why}")
    if not write:
        print(f"\nPreview: {len(added)} rows would be added, {len(skipped)} skipped. Run again with --write to append.")
        return
    with TRAIN_PATH.open("a", encoding="utf-8", newline="") as f:
        writer = csv.writer(f, lineterminator="\r\n")   # intents_train.csv uses CRLF
        for text, intent in added:
            writer.writerow([text, intent])
    print(f"\nAppended {len(added)} rows to {TRAIN_PATH.name} ({len(skipped)} skipped).")
    print("Next: python train_model.py && python test_intents.py")


if __name__ == "__main__":
    main()
