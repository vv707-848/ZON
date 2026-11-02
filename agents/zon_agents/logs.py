"""One JSON line per event on stdout — ids, timings and token counts, never the text of a request (§13).

`request_id_var` holds the X-Request-Id Laravel sent (its trace id), so every line of one call carries it.
"""

import json
import logging
import sys
from contextvars import ContextVar
from datetime import UTC, datetime
from typing import Any

request_id_var: ContextVar[str | None] = ContextVar("request_id", default=None)

log = logging.getLogger("zon_agents")


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        line: dict[str, Any] = {
            "at": datetime.fromtimestamp(record.created, UTC).isoformat(timespec="milliseconds"),
            "level": record.levelname.lower(),
            "event": record.getMessage(),
            **getattr(record, "fields", {}),
        }
        if record.exc_info:
            line["error"] = self.formatException(record.exc_info).splitlines()[-1]
        return json.dumps(line, ensure_ascii=False, default=str)


def configure(level: str) -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(JsonFormatter())
    log.handlers = [handler]
    log.setLevel(level)
    log.propagate = False


def event(name: str, level: int = logging.INFO, *, exc_info: bool = False, **fields: Any) -> None:
    """The request id is read when the event happens (not when the line is formatted)."""
    log.log(level, name, exc_info=exc_info, extra={"fields": {"request_id": request_id_var.get(), **fields}})
