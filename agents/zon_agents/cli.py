"""`zon-agents serve` — the HTTP service · `zon-agents bootstrap [--recreate]` — the Qdrant collections."""

import argparse
import asyncio

import uvicorn

from zon_agents import logs
from zon_agents.config import Settings
from zon_agents.main import create_app
from zon_agents.services import build_store


async def _bootstrap(settings: Settings, recreate: bool) -> None:
    store = build_store(settings)
    try:
        created = await store.bootstrap(recreate=recreate)
    finally:
        await store.aclose()
    logs.event("bootstrap", created=created, recreate=recreate)
    if recreate:
        logs.event("bootstrap.next", hint="collections are empty: run `php artisan zon:reindex --force` in the backend")


def main() -> None:
    parser = argparse.ArgumentParser(prog="zon-agents")
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("serve", help="run the HTTP service (one worker) on HOST:PORT")
    bootstrap = commands.add_parser(
        "bootstrap", help="create the Qdrant collections and payload indexes (safe to repeat)"
    )
    bootstrap.add_argument("--recreate", action="store_true", help="drop and create again — then reindex everything")
    args = parser.parse_args()

    settings = Settings()  # values come from the environment / .env
    logs.configure(settings.log_level)
    if args.command == "bootstrap":
        asyncio.run(_bootstrap(settings, args.recreate))
        return
    uvicorn.run(create_app(settings), host=settings.host, port=settings.port, log_config=None, access_log=False)


if __name__ == "__main__":
    main()
