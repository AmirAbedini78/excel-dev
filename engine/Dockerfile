FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY worker.py rag.py synthetic_data.py deep_safe.py agent_guard.py read_guard.py adaptive_router.py workflow_planner.py action_orchestrator.py ./
RUN mkdir -p /app/data

CMD ["python", "worker.py", "--config", "/app/config.json"]
