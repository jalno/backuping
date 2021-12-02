<?php

// Add this config index to your config
["packages.backuping.config" => array(
	"options" => array(
		// the global option to cleanup backups after backup each source
		// you can override this for each source you want by pass 'cleanup_on_backup' key in source
		"cleanup_on_backup" => true,

		// how many backup should be kept of each source?
		// you can override this for each source by passing 'minimum_keeping_backups' in source array
		// this should be zero or bigger than it!
		"minimum_keeping_source_backups" => 1,
	),
	"sources" => array(
		array(
			"id" => "my-project-backup",
			"type" => function(?array $options) {
				return new \packages\backuping\backupables\Directory();
			},
			"cleanup_on_backup" => false,
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
			// "cleanup_on_backup" => true,
			// "minimum_keeping_backups" => 5,
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

		array(
			"id" => "my-mongodb-backup",
			"type" => function (?array $options = null) {
				return new \packages\backuping\backupables\MongoDB();
			},
			// "cleanup_on_backup" => true,
			// "minimum_keeping_backups" => 5,
			"options" => array(
				// the URI string for database in this format:
				//	mongodb://[username:password@]host1[:port1][,...hostN[:portN]][/[defaultauthdb][?options]]
				// you should use one of URI connection string or the set of: host, port, username, password
				"uri" => null,

				"host" => "localhost",
				"port" => 27017,
				"username" => "root",
				"password" => "YouStrongPassword",

				"gzip" => false, // use gzip for compress

				// You can pass this options to specify which databases to get backup
				// if you don't pass this options, or pass it as null or empty array, all databases and collection will be backuped
				// you can pass an database name to backup it completely, or pass it as key-array to backup special collections from a database
				"db" => array(
					/*
					"my_senstive_db", // backup my_senstive_db completely
					"my_another_senstive_db" => array(
						"my_first_collection_name_to_backup" // only backup "general_log" table from 'mysql' DB
					),
					*/
				),
				"excludeCollection" => array( // in case of exclude some collections from backup (same as mongodump command)

				),
				"excludeCollectionsWithPrefix" => array( // in case of exclude some collections with prefix (same as mongodump command)

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
			'options' => array(
				'host' => "host_address",
				'port' => 25,
				'smtp_auth' => true or false,
				'username' => "username",
				'password' => "password",
				'auth_type' => "auth_type, like PLAIN",
			), // or array()
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
