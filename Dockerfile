FROM php:cli-alpine3.13

ARG CI_SERVER_HOST=https://git.jeyserver.com

RUN pecl channel-update pecl.php.net && \
	apk add --no-cache mariadb-client zlib bzip2 libzip git && \
	apk add --no-cache --virtual .build-deps \
		zlib-dev \
		openssl-dev \
		bzip2-dev \
		libzip-dev \
		zip && \
	docker-php-ext-install zip mysqli && \
	git clone --depth 1 ${CI_SERVER_HOST}/jalno/base.git /app && \
	cd /app/packages && \
	git clone --depth 1 ${CI_SERVER_HOST}/yeganemehr/PhpParser.git && \
	# git clone --depth 1 ${CI_SERVER_HOST}/hosni/phpmailer.git && \
	git clone -b develop ${CI_SERVER_HOST}/hosni/phpmailer.git && \
	find /app -type d -name ".git" -prune -exec rm -fr {} \; && \
	apk del git && \
	rm -f /app/packages/base/libraries/config/config.php /app/packages/base/libraries/db/dbObject.php && \
	sed -e '/$this->save(/ s/^#*/#/' -i /app/packages/base/libraries/background/Process.php && \
	sed -e '/$process->save(/ s/^#*/#/' -i /app/packages/base/libraries/router/router.php && \
	chown -R www-data:www-data /app;

COPY --chown=www-data:www-data . /app/packages/backuping
COPY --chown=www-data:www-data .docker/base/config.php /app/packages/base/libraries/config/
COPY --chown=www-data:www-data .docker/base/dbObject.php /app/packages/base/libraries/db/dbObject.php

WORKDIR /app

ENTRYPOINT ["/app/packages/backuping/.docker/docker-entrypoint.php"]

CMD [ "start" ]
