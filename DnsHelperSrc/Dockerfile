FROM debian:stable-slim

RUN apt-get update &&apt-get install -y socat microsocks
RUN mkdir "/app"
COPY . /app
WORKDIR /app
HEALTHCHECK --interval=20s --timeout=10s --start-period=10s --retries=3 CMD ["/app/healthcheck.sh"]

RUN chmod +x /app/run.sh
RUN chmod +x /app/healthcheck.sh


ENTRYPOINT ["/app/run.sh"]
