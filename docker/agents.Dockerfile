# The agents service for local development (compose.yaml): Python 3.14 and the locked packages (uv.lock). The
# model and the embedder run on the AI node (RunPod), not here: without them the service answers, says so in
# /agents/health, and Laravel takes its fallback paths — as production does when the node is down.
FROM python:3.14-slim

COPY --from=ghcr.io/astral-sh/uv:0.13.6 /uv /usr/local/bin/uv
ENV UV_PROJECT_ENVIRONMENT=/opt/venv UV_LINK_MODE=copy PATH=/opt/venv/bin:$PATH
WORKDIR /app/agents
COPY agents/pyproject.toml agents/uv.lock ./
RUN uv sync --locked --no-dev --no-install-project
CMD ["sh", "-c", "uv sync --locked --no-dev && zon-agents bootstrap && exec zon-agents serve"]
