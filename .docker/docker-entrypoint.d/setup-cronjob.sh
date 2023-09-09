#!/bin/sh

/bin/echo "[Backuping]: Check 'BACKUPING_BACKUP_CRON_EXPRESSION' exists?";
if [ -n "$BACKUPING_BACKUP_CRON_EXPRESSION" ]
then
	/bin/echo "[Backuping]: is exists, expression: ($BACKUPING_BACKUP_CRON_EXPRESSION), check is configured before?";

	if ! /bin/grep '/bin/backuping backup' /etc/crontabs/root > /dev/null 2>&1
	then
		/bin/echo "[Backuping]: cronjob is not configured, configure backuping cronjob...";
		/bin/echo "$BACKUPING_BACKUP_CRON_EXPRESSION /bin/backuping backup --vv" | crontab -;
	else
		echo "[Backuping]: cronjob is configured, skip";
	fi

else
	/bin/echo "[Backuping]: not exists, skip";
fi
