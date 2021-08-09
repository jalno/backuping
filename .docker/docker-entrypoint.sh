#!/bin/sh

BACKUPING_PHP_BIN=/usr/local/bin/php
BACKUPING_BACKUPING_INDEX_FILE=/var/www/html/index.php
BACKUPING_BACKUPING_PROCESS=packages/backuping/processes/Backuping

if [ "$1" = "start" ] 
then
	/bin/echo -e "[Backuping]: action is: '$1'";
	export > /backuping-env.save

	if [ -n "$BACKUPING_BACKUP_CRON_EXPRESSION" ]; then
		echo "[Backuping]: Setup cronjob"
		echo "$BACKUPING_BACKUP_CRON_EXPRESSION source /backuping-env.save && $BACKUPING_PHP_BIN $BACKUPING_BACKUPING_INDEX_FILE --process=$BACKUPING_BACKUPING_PROCESS@backup ${BACKUPING_BACKUP_CRON_VERBOSE:+--verbose}";
		/usr/sbin/crond -f -l 4
	fi

elif [ "$1" = "backup" ] || [ "$1" = "restore" ] || [ "$1" = "cleanup" ]
then
	/bin/echo -e "[Backuping]: action is: '$1'";
	eval "$BACKUPING_PHP_BIN $BACKUPING_BACKUPING_INDEX_FILE --process=$BACKUPING_BACKUPING_PROCESS@$1"
else
	eval $1
fi

