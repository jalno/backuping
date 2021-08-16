#!/bin/sh

/bin/echo "[Backuping]: Check 'BACKUPING_BACKUP_CRON_EXPRESSION' exists?";
if [ -n "$BACKUPING_BACKUP_CRON_EXPRESSION" ]
then
	/bin/echo "[Backuping]: is exists, expression: ($BACKUPING_BACKUP_CRON_EXPRESSION), check is configured before?";

	if ! /bin/grep 'packages/backuping/processes/Backuping@backup' /etc/crontabs/root > /dev/null 2>&1
	then
		/bin/echo "[Backuping]: cronjob is not configured, configure backuping cronjob...";
		/bin/echo "$BACKUPING_BACKUP_CRON_EXPRESSION /usr/local/bin/php /var/www/html/index.php --process=packages/backuping/processes/Backuping@backup" | crontab -;
	else
		echo "[Backuping]: cronjob is configured, skip";
	fi

else
	/bin/echo "[Backuping]: not exists, skip";
fi
