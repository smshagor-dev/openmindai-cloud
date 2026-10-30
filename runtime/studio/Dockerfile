FROM python:3.12.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 OPENBLAS_NUM_THREADS=1 OMP_NUM_THREADS=1
WORKDIR /app
COPY runtime/studio/requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt && useradd --uid 10001 --create-home studio && mkdir -p /var/lib/openmind-studio && chown studio:studio /var/lib/openmind-studio
COPY runtime/studio /app/runtime/studio
USER 10001:10001
EXPOSE 8000
CMD ["python", "-m", "uvicorn", "runtime.studio.server:create_app", "--factory", "--host", "0.0.0.0", "--port", "8000", "--workers", "1", "--limit-concurrency", "16", "--timeout-keep-alive", "5"]
