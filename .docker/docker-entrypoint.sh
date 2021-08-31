#!/bin/sh

BACKUPING_PHP_BIN=/usr/local/bin/php
BACKUPING_BACKUPING_INDEX_FILE=/var/www/html/index.php
BACKUPING_BACKUPING_PROCESS=packages/backuping/processes/Backuping

if [ -z "${BACKUPING_ENTRYPOINT_QUIET_LOGS:-}" ]; then
    exec 3>&1
else
    exec 3>/dev/null
fi

if [ "$1" = "start" ]
then
	if /usr/bin/find "/docker-entrypoint.d/" -mindepth 1 -maxdepth 1 -type f -print -quit 2>/dev/null | read v;
	then
		/bin/echo >&3 "[Backuping]: /docker-entrypoint.d/ is not empty, will attempt to perform configuration"

		/bin/echo >&3 "[Backuping]: Looking for shell scripts in /docker-entrypoint.d/"
		find "/docker-entrypoint.d/" -follow -type f -print | sort -V | while read -r f; do
			case "$f" in
				*.sh)
					if [ -x "$f" ]; then
						/bin/echo >&3 "[Backuping]: Launching $f";
						"$f"
					else
						# warn on shell scripts without exec bit
						/bin/echo >&3 "[Backuping]: Ignoring $f, not executable";
					fi
					;;
				*) /bin/echo >&3 "[Backuping]: Ignoring $f";;
			esac
		done

		/bin/echo >&3 "[Backuping]: Configuration complete; ready for start up"
	else
		/bin/echo >&3 "[Backuping]: No files found in /docker-entrypoint.d/, skipping configuration"
	fi

	exec /usr/sbin/crond -f
else
	exec $@
fi
