FROM registry.git.jeyserver.com/yeganemehr/dockerize:php7.4-alpine

COPY . /var/www/html

RUN apk --no-cache add mongodb-tools mariadb-client; \
	mv packages/backuping/.docker/backuping /bin/backuping; \
	mv packages/backuping/.docker/base/config.php packages/base/libraries/config/config.php; \
	mv packages/backuping/.docker/base/dbObject.php packages/base/libraries/db/dbObject.php; \
	mv packages/backuping/.docker/docker-entrypoint.d/ /docker-entrypoint.d; \
	mv packages/backuping/.docker/docker-entrypoint.sh /docker-entrypoint.sh; \
	rm -fr packages/dockerize; \
	find /var/www/html -type d -name ".docker" -prune -exec rm -fr {} \;; \
	sed -e '/$this->save(/ s/^#*/#/' -i packages/base/libraries/background/Process.php; \
	sed -e '/$process->save(/ s/^#*/#/' -i packages/base/libraries/router/router.php;

ENTRYPOINT [ "/docker-entrypoint.sh" ]
CMD [ "/docker-entrypoint.sh", "start" ]
