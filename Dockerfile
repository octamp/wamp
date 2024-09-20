FROM composer AS build

RUN mkdir -p /opt/wamp
WORKDIR /opt/wamp
COPY ./ /opt/wamp

RUN composer install --no-ansi --no-dev --no-interaction --no-plugins --no-progress --no-scripts --optimize-autoloader --ignore-platform-req=ext-openswoole

FROM openswoole/swoole:php8.2 AS main

RUN mkdir -p /opt/wamp
WORKDIR /opt/wamp
COPY --from=build /opt/wamp /opt/wamp

RUN ls -la

RUN chmod +x ./bin/server.php

ENTRYPOINT ["php"]
CMD ["./bin/server.php"]

EXPOSE 8080
