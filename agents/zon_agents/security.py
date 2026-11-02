"""Shared-secret checks, in constant time."""

import hashlib
import hmac
import time


def key_matches(given: str | None, expected: str) -> bool:
    return given is not None and hmac.compare_digest(given.encode(), expected.encode())


def signature_valid(secret: str, timestamp: str | None, body: bytes, signature: str | None, max_skew: int) -> bool:
    """X-Signature = "sha256=" + hex(HMAC_SHA256(secret, X-Timestamp + "." + body)), within ±max_skew seconds."""
    if timestamp is None or signature is None or not timestamp.isdigit():
        return False
    if abs(time.time() - int(timestamp)) > max_skew:
        return False
    expected = "sha256=" + hmac.new(secret.encode(), timestamp.encode() + b"." + body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature)
