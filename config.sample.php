<?php

// Add this config index to your config
["packages.backuping.config" => array(
	"sources" => array(
		array(
			"id" => "my-project-backup",
			"type" => function(?array $options) {
				return new \packages\backuping\backupables\Directory();
			},
			"options" => array(
				"directory" => function() {
					return new \packages\base\IO\Directory\Local("/home/jeyserver");
				},
				"exclude" => array(
					".git",
					".htaccess",
					"plugins",
					"webserver/packages/**/node_modules",
					"webserver/packages/base/storage/private/cache",
				),
			),
		),
		array(
			"id" => "my-mysql-backup",
			"type" => function (?array $options = null) {
				return new \packages\backuping\backupables\MySQL();
			},
			"options" => array(
				"host" => "localhost",
				"port" => 3306,
				"username" => "root",
				"password" => "jeyserver",
				"seprate" => true, // backup each database to seprate file
				"gzip" => false, // use gzip for compress if gzip command is exists!
				"only" => array( // backup only this database, if you pass only, the 'exclude' will ignored
					"jeyserver_develop", // backup jeyserver_develop completely
					"mysql" => array(
						"general_log" // only backup "general_log" table from 'mysql' DB
					),
				),
				"exclude" => array( // exclude db's or only some tables from a db
					"information_schema", // exclude 'information_schema' db completely
					"mysql", // exclude 'information_schema' db completely
					"performance_schema", // exclude 'information_schema' db completely
					"my_db" => array( // only exclude the 'my_test_table_0_to_exclude' and 'my_test_table_1_to_exclude' table from 'my_db' db and backup other tables
						"my_test_table_0_to_exclude",
						"my_test_table_1_to_exclude"
					),
				),
			),
		),
	),
	"destinations" => array(
		array(
			"id" => "local-hosni",
			"directory" => "local",
			"lifetime" => null, // days that backup will saved, pass null to make backup save for ever
			"options" => array(
				"path" => "/tmp/backupstg",
			),
		),
		array(
			"id" => "s157-hosni-ftp",
			"directory" => function(?array $options) {
				$obj = new \packages\base\IO\Directory\Ftp("backuping-backups");
				$obj->setDriver(new \packages\base\IO\drivers\FTP(array(
					"host" => $options["host"],
					"port" => $options["port"],
					"username" => $options["username"],
					"password" => $options["password"],
					"passive" => $options["passive"],
				)));
				return $obj;
			},
			"lifetime" => 30, // days that backup will saved
			"options" => array(
				"host" => "FTP_HOST",
				"port" => 21,
				"username" => "FTP_USERNAME",
				"password" => "FTP_PASSWORD",
				"passive" => true,
			),
		),
	),
	// you can skip pass report key to skip reporting
	'report' => array(
		'subject' => 'Backup of something',
		'sender' => array(
			'type' => "mail", // or "smtp"
			'options' => "smtp" ? array(
				'host' => "host_address",
				'port' => 25,
				'smtp_auth' => true or false,
				'username' => "username",
				'password' => "password",
				'auth_type' => "auth_type, like PLAIN",
			) : array(),
			'from' => array(
				'address' => "reporter@ssh2.ir",
				'name' => "Backup Reporter",
			),
		),
		'receivers' => array(
			array(
				'name' => 'yeganemehr',
				'mail' => 'yeganemehr@jeyserver.com',
			),
			array(
				'name' => 'abedi',
				'mail' => 'abedi@jeyserver.com',
			),
			array(
				'name' => 'hosni',
				'mail' => 'hosni@jeyserver.com',
			),
		)
	),
)];
