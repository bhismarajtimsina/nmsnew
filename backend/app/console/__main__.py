"""python -m app.console   - serve the console gateway (Plan 38)."""
from __future__ import annotations

import os

import uvicorn

from app.console.gateway import create_app
from app.core.logging import configure_logging


def main() -> None:
    configure_logging()
    uvicorn.run(create_app(), host=os.getenv("CONSOLE_GATEWAY_HOST", "0.0.0.0"), port=int(os.getenv("CONSOLE_GATEWAY_PORT", "8010")),
                proxy_headers=True, forwarded_allow_ips=os.getenv("FORWARDED_ALLOW_IPS", ""))


if __name__ == "__main__":
    main()
