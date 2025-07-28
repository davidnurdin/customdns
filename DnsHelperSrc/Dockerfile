FROM debian:stable-slim

RUN apt-get update &&apt-get install -y socat microsocks
RUN mkdir "/app"
COPY . /app
WORKDIR /app

ENTRYPOINT ["/app/run.sh"]
