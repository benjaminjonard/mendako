# Mendako ML inference service.
# Multi-arch target: linux/amd64, linux/arm64 (CPU baseline; RK3588/CM3588 validated).
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    MENDAKO_MODELS_DIR=/models

# Runtime libs:
#  - ffmpeg: video-frame extraction (used from Story 4.1; installed now for a stable image)
#  - libgomp1: required by onnxruntime
#  - curl: container HEALTHCHECK
RUN set -eux; \
    apt-get update -qq; \
    apt-get install -qqy --no-install-recommends \
        ffmpeg \
        libgomp1 \
        curl; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# Non-root user (mirrors the app image's PUID/PGID convention).
ARG PUID=1000
ARG PGID=1000
RUN groupadd --gid "$PGID" mendako \
    && useradd --uid "$PUID" --gid "$PGID" --no-create-home --shell /usr/sbin/nologin mendako

WORKDIR /app

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

# Bake the catalog models into the image (no runtime download). This layer depends only
# on the catalog + the download script, so it is COPIED before the rest of the app — that
# way ordinary app code changes don't bust this (expensive, multi-GB) layer. Download into
# a scratch HF cache, copy the final weights into /models, drop the cache in the same layer
# so only the real weights ship, then hand /models to the service user.
COPY app/__init__.py app/catalog.py ./app/
COPY download_models.py ./
RUN set -eux; \
    HF_HOME=/tmp/hf-cache python download_models.py; \
    rm -rf /tmp/hf-cache; \
    chown -R mendako:mendako /models

# The rest of the service code (cheap to rebuild; does not re-trigger the model download).
COPY app ./app

EXPOSE 8000

USER mendako

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl --fail http://localhost:8000/health || exit 1

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
