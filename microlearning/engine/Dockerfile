FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1 HF_HOME=/models
WORKDIR /srv
RUN apt-get update && apt-get install -y --no-install-recommends libgomp1 libsndfile1 \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --create-home speech \
    && install -d -o speech -g speech /models
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY documents.py reference_audio.py server.py synthesis.py ./
USER speech
EXPOSE 8010
CMD ["python", "-m", "uvicorn", "server:app", "--host", "0.0.0.0", "--port", "8010", "--workers", "1"]
