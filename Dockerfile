FROM php:8.4-cli

RUN mkdir /app
WORKDIR /app

# copy all files from the current directory to /app in the container
COPY . /app

ENTRYPOINT ["/usr/local/bin/php","start.php"]

