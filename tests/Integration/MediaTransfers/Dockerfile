FROM repro-multiunit-backend-quality:php83
USER root
RUN apt-get update && apt-get install -y --no-install-recommends nginx python3 && rm -rf /var/lib/apt/lists/*
WORKDIR /app
ENTRYPOINT ["sh", "/source/tests/Integration/MediaTransfers/run.sh"]
