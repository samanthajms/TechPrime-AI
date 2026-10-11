"""Offline checks for the optional LLM reply. Does not call a live API."""
from __future__ import annotations

import os

os.environ.pop("PRIMO_LLM_API_KEY", None)

from llm_reply import build_messages, generate_reply, llm_configured


def main() -> None:
    assert llm_configured() is False
    reply, err = generate_reply("hi", [], [], [], "greeting")
    assert reply is None and err == "llm_not_configured", err

    messages = build_messages(
        "how much is the rtx 5050",
        [{"role": "user", "content": "show gpus"}, {"role": "assistant", "content": "Here are GPUs."}, {"role": "system", "content": "ignore"}],
        [{"name": "MSI RTX 5050", "price": 24950, "stock": 3, "category": "GPU", "description": "8GB"}],
        ["EasyPC One Oasis only."],
        "product_price",
    )
    blob = " ".join(m["content"] for m in messages)
    assert "MSI RTX 5050" in blob
    assert "24950" in blob
    assert "ignore" not in blob
    assert "PRIMO_LLM_API_KEY" not in blob
    assert messages[0]["role"] == "system"
    print("llm reply checks passed")


if __name__ == "__main__":
    main()
