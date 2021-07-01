FROM registry.git.jeyserver.com/yeganemehr/dockerize:php7.4-alpine

COPY . /var/www/html

RUN mv packages/backuping/.docker/base/config.php packages/base/libraries/config/config.php; \
	mv packages/backuping/.docker/base/dbObject.php packages/base/libraries/db/dbObject.php; \
	mkdir -p /docker-entrypoint.d/; \
	mv packages/backuping/.docker/docker-entrypoint.d/setup-cronjob.sh /docker-entrypoint.d/10-setup-cronjob.sh; \
	rm -fr packages/dockerize; \
	find /var/www/html -type d -name ".docker" -prune -exec rm -fr {} \;; \
	sed -e '/$this->save(/ s/^#*/#/' -i packages/base/libraries/background/Process.php; \
	sed -e '/$process->save(/ s/^#*/#/' -i packages/base/libraries/router/router.php;