"""Shared text preprocessing for Primo SVM intent classification."""
from __future__ import annotations

import re

_SPACE_RE = re.compile(r"\s+")
# Keep letters, digits, spaces, and useful tech punctuation
_PUNCT_RE = re.compile(r"[^\w\s+#.\-_/]", re.UNICODE)

# Expand common conversational contractions (after lowercasing).
# Tech tokens (rtx, gpu, am4, …) are unchanged by this map.
_CONTRACTIONS = (
    (re.compile(r"\bi'm\b"), "i am"),
    (re.compile(r"\bi'll\b"), "i will"),
    (re.compile(r"\bi'd\b"), "i would"),
    (re.compile(r"\bi've\b"), "i have"),
    (re.compile(r"\byou're\b"), "you are"),
    (re.compile(r"\byou'll\b"), "you will"),
    (re.compile(r"\byou've\b"), "you have"),
    (re.compile(r"\bwe're\b"), "we are"),
    (re.compile(r"\bthey're\b"), "they are"),
    (re.compile(r"\bthat's\b"), "that is"),
    (re.compile(r"\bwhat's\b"), "what is"),
    (re.compile(r"\bwhere's\b"), "where is"),
    (re.compile(r"\bwho's\b"), "who is"),
    (re.compile(r"\bhow's\b"), "how is"),
    (re.compile(r"\bit's\b"), "it is"),
    (re.compile(r"\bdon't\b"), "do not"),
    (re.compile(r"\bdoesn't\b"), "does not"),
    (re.compile(r"\bdidn't\b"), "did not"),
    (re.compile(r"\bcan't\b"), "cannot"),
    (re.compile(r"\bcouldn't\b"), "could not"),
    (re.compile(r"\bwon't\b"), "will not"),
    (re.compile(r"\bwouldn't\b"), "would not"),
    (re.compile(r"\bshouldn't\b"), "should not"),
    (re.compile(r"\bisn't\b"), "is not"),
    (re.compile(r"\baren't\b"), "are not"),
    (re.compile(r"\bwasn't\b"), "was not"),
    (re.compile(r"\bweren't\b"), "were not"),
    (re.compile(r"\bhaven't\b"), "have not"),
    (re.compile(r"\bhasn't\b"), "has not"),
    (re.compile(r"\blet's\b"), "let us"),
)


def preprocess_text(text: str) -> str:
    """Lowercase, expand light contractions, clean punctuation, normalize spaces."""
    if text is None:
        return ""
    t = str(text).strip().lower()
    t = (
        t.replace("'", "'")
        .replace("'", "'")
        .replace("`", "'")
        .replace(""", '"')
        .replace(""", '"')
        .replace("–", "-")
        .replace("—", "-")
    )
    for pattern, repl in _CONTRACTIONS:
        t = pattern.sub(repl, t)
    t = _PUNCT_RE.sub(" ", t)
    t = _SPACE_RE.sub(" ", t).strip()
    return t


def tokenize_hint(text: str) -> list[str]:
    """Optional helper for handlers (not used by TF-IDF directly)."""
    return [w for w in preprocess_text(text).split() if w]
