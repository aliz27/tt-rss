#!/usr/bin/env php
<?php
	set_include_path(__DIR__ ."/include" . PATH_SEPARATOR .
		get_include_path());

	define('DISABLE_SESSIONS', true);

	chdir(__DIR__);

	require_once "autoload.php";


	if (php_sapi_name() != "cli") {
		header("Content-type: text/plain");
		printf("Please run this script using PHP CLI executable (you're using PHP SAPI: %s, PHP_EXECUTABLE is set to '%s')\n",
			php_sapi_name(), Config::get(Config::PHP_EXECUTABLE));
		exit(1);
	}

	Config::sanity_check();

	function make_stampfile(string $filename): bool {
		$fp = fopen(Config::get(Config::LOCK_DIRECTORY) . "/$filename", "w");

		if (flock($fp, LOCK_EX | LOCK_NB)) {
			fwrite($fp, time() . "\n");
			flock($fp, LOCK_UN);
			fclose($fp);
			return true;
		} else {
			return false;
		}
	}

	function cleanup_tags(int $days = 14, int $limit = 1000): int {
		$tags_deleted = 0;
		$limit_part = 500;

		while ($limit > 0) {
			$tags = ORM::for_table('ttrss_tags')
				->table_alias('t')
				->select('t.id')
				->join('ttrss_user_entries', ['ue.int_id', '=', 't.post_int_id'], 'ue')
				->join('ttrss_entries', ['e.id', '=', 'ue.ref_id'], 'e')
				->where_not_equal('ue.tag_cache', '')
				->where_raw("e.date_updated < NOW() - INTERVAL '$days day'")
				->limit($limit_part)
				->find_many();

			if (count($tags)) {
				ORM::for_table('ttrss_tags')
					->where_id_in(array_column($tags->as_array(), 'id'))
					->delete_many();

				$tags_deleted += ORM::get_last_statement()->rowCount();
			} else {
				break;
			}

			$limit -= $limit_part;
		}

		return $tags_deleted;
	}

	$pdo = Db::pdo();

	init_plugins();

	$options_map = [
		"feeds" => "update all pending feeds",
		"daemon" => "start single-process update daemon",
		"daemon-loop" => "",
		"update-feed:" => "",
		"send-digests" =>  "send pending email digests",
		"task:" => "",
		"cleanup-tags" => "perform maintenance on tags table",
		"quiet" => "don't output messages to stdout",
		"log:" => ["FILE", "log messages to FILE"],
		"log-level:" => ["N", "set log verbosity level (0-2)"],
		"pidlock:" => "",
		"update-schema::" => ["[force-yes]", "update database schema, optionally without prompting"],
		"force-update" => "mark all feeds as pending update",
		"gen-search-idx" => "generate basic PostgreSQL fulltext search index",
		"gen-encryption-key" => "generate an encryption key (ChaCha20-Poly1305)",
		"plugins-list" => "list installed plugins",
		"debug-feed:" => ["N", "update specified feed with debug output enabled"],
		"force-refetch" => "debug update: force refetch feed data",
		"force-rehash" => "debug update: force rehash articles",
		"opml-export:" => ["USER:FILE", "export OPML of USER to FILE"],
		"opml-import:" => ["USER:FILE", "import OPML for USER from FILE"],
		"user-list" => "list all users",
		"user-add:" => ["USER[:PASSWORD[:ACCESS_LEVEL=0]]", "add USER, prompts for password if unset"],
		"user-remove:" => ["USERNAME", "remove USER"],
		"user-check-password:" => ["USER:PASSWORD", "returns 0 if user has specified PASSWORD"],
		"user-set-password:" => ["USER:PASSWORD", "sets PASSWORD of specified USER"],
		"user-set-access-level:" => ["USER:LEVEL", "sets access LEVEL of specified USER"],
		"user-enable-api:" => ["USER:BOOL", "enables or disables API access of specified USER"],
		"user-exists:" => ["USER", "returns 0 if specified USER exists in the database"],
		"force-yes" => "assume 'yes' to all queries",
		"help" => "",
	];

	foreach (PluginHost::getInstance()->get_commands() as $command => $data) {
		$options_map[$command . $data["suffix"]] = [ $data["arghelp"], $data["description"] ];
	}

	$options = getopt("", array_keys($options_map));

	if ($options === false || count($options) == 0 || isset($options["help"]) ) {
		print "Tiny Tiny RSS CLI management tool\n";
		print "=================================\n";
		print "Options:\n\n";

		$options_help = [];

		foreach ($options_map as $option => $descr) {
			if (str_ends_with($option, ':'))
				$option = substr($option, 0, -1);

			$help_key = trim(sprintf("--%s %s",
								$option, is_array($descr) ? $descr[0] : ""));
			$help_value = is_array($descr) ? $descr[1] : $descr;

			if ($help_value)
				$options_help[$help_key] = $help_value;
		}

		$max_key_len = array_reduce(array_keys($options_help),
			function ($carry, $item) { $len = strlen($item); return $len > $carry ? strlen($item) : $carry; });

		foreach ($options_help as $option => $help_text) {
			printf("  %s %s\n", str_pad($option, $max_key_len + 5), $help_text);
		}

		exit(0);
	}

	if (!isset($options['daemon'])) {
		require_once "errorhandler.php";
	}

	if (!isset($options['update-schema']) && Config::is_migration_needed()) {
		die("Schema version is wrong, please upgrade the database (--update-schema).\n");
	}

	Debug::set_enabled(true);

	if (isset($options["log-level"])) {
	    Debug::set_loglevel(Debug::map_loglevel((int)$options["log-level"]));
    }

	if (isset($options["log"])) {
		Debug::set_quiet(isset($options['quiet']));
		Debug::set_logfile($options["log"]);
        Debug::log("Logging to " . $options["log"]);
    } else {
	    if (isset($options['quiet'])) {
			Debug::set_loglevel(Debug::LOG_DISABLED);
        }
    }

	if (!isset($options["daemon"])) {
		$lock_filename = "update.lock";
	} else {
		$lock_filename = "update_daemon.lock";
	}

	if (isset($options["task"])) {
		Debug::log("Using task id " . $options["task"]);
		$lock_filename = $lock_filename . "-task_" . $options["task"];
	}

	if (isset($options["pidlock"])) {
		$my_pid = $options["pidlock"];
		$lock_filename = "update_daemon-$my_pid.lock";
	}

	Debug::log("Lock: $lock_filename");

	$lock_handle = make_lockfile($lock_filename);

	if (isset($options["task"]) && isset($options["pidlock"])) {
		$waits = $options["task"] * 5;
		Debug::log("Waiting before update ($waits)...");
		sleep($waits);
	}

	// Try to lock a file in order to avoid concurrent update.
	if (!$lock_handle) {
		die("error: Can't create lockfile ($lock_filename). ".
			"Maybe another update process is already running.\n");
	}

	if (isset($options["force-update"])) {
		Debug::log("marking all feeds as needing update...");

		$pdo->query( "UPDATE ttrss_feeds SET
          last_update_started = '1970-01-01', last_updated = '1970-01-01'");
	}

	if (isset($options["feeds"])) {
		RSSUtils::update_daemon_common(Config::get(Config::DAEMON_FEED_LIMIT), $options);
		RSSUtils::housekeeping_common();

		PluginHost::getInstance()->run_hooks(PluginHost::HOOK_UPDATE_TASK, $options);
	}

	if (isset($options["daemon"])) {
		// @phpstan-ignore while.alwaysTrue (single process daemon will always run)
		while (true) {
			$quiet = (isset($options["quiet"])) ? "--quiet" : "";
			$log = isset($options['log']) ? '--log '.$options['log'] : '';
			$log_level = isset($options['log-level']) ? '--log-level '.$options['log-level'] : '';

			passthru(Config::get(Config::PHP_EXECUTABLE) . " " . $argv[0] ." --daemon-loop $quiet $log $log_level");

			// let's enforce a minimum spawn interval as to not forkbomb the host
			$spawn_interval = max(60, Config::get(Config::DAEMON_SLEEP_INTERVAL));

			Debug::log("Sleeping for $spawn_interval seconds...");
			sleep($spawn_interval);
		}
	}

	if (isset($options["update-feed"])) {
		try {

			if (!RSSUtils::update_rss_feed((int)$options["update-feed"], true))
				exit(100);

		} catch (PDOException $e) {
			Debug::log(sprintf("Exception while updating feed %d: %s (%s:%d)",
				$options["update-feed"], $e->getMessage(), $e->getFile(), $e->getLine()));

			Logger::log_error(E_USER_WARNING, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());

			exit(110);
		}
	}

	if (isset($options["daemon-loop"])) {
		if (!make_stampfile('update_daemon.stamp')) {
			Debug::log("warning: unable to create stampfile\n");
			exit(1);
		}

		RSSUtils::update_daemon_common(Config::get(Config::DAEMON_FEED_LIMIT), $options);

		if (!isset($options["pidlock"]) || $options["task"] == "0")
			RSSUtils::housekeeping_common();

		PluginHost::getInstance()->run_hooks(PluginHost::HOOK_UPDATE_TASK, $options);
	}

	if (isset($options["cleanup-tags"])) {
		$rc = cleanup_tags( 14, 50000);
		Debug::log("$rc tags deleted.\n");
	}

	if (isset($options["update-schema"])) {
		if (Config::is_migration_needed()) {

			if (!isset($options['force-yes']) && $options["update-schema"] != "force-yes") {
				Debug::log("Type 'yes' to continue.");

				if (read_stdin() != 'yes')
					exit(1);
			} else {
				Debug::log("Proceeding to update without confirmation.");
			}

			if (!isset($options["log-level"])) {
				Debug::set_loglevel(Debug::LOG_VERBOSE);
			}

			$migrations = Config::get_migrations();
			$rc = $migrations->migrate();

			exit($rc ? 0 : 1);

		} else {
			Debug::log("Database schema is already at latest version.");
		}
	}

	if (isset($options["gen-search-idx"])) {
		echo "Generating search index (stemming set to English)...\n";

		$count = ORM::for_table('ttrss_entries')
			->where_null('tsvector_combined')
			->count();

		$limit = 500;
		$processed = 0;

		print "Articles to process: $count (will limit to $limit).\n";

		$entries = ORM::for_table('ttrss_entries')
			->select_many('id', 'title', 'content')
			->where_null('tsvector_combined')
			->order_by_asc('id')
			->limit($limit)
			->find_many();

		$usth = $pdo->prepare("UPDATE ttrss_entries
          SET tsvector_combined = to_tsvector('english', ?) WHERE id = ?");

		while (true) {
			foreach ($entries as $entry) {
				$tsvector_combined = mb_substr(strip_tags($entry->title) . " " . \Soundasleep\Html2Text::convert($entry->content), 0, 900000);
				$usth->execute([$tsvector_combined, $entry->id]);
				$processed++;
			}

			print "Processed $processed articles...\n";

			if ($processed < $limit) {
				echo "All done.\n";
				break;
			}
		}
	}

	if (isset($options["gen-encryption-key"])) {
		echo "Generated encryption key: " . bin2hex(Crypt::generate_key()) . "\n";
	}

	if (isset($options["plugins-list"])) {
		$tmppluginhost = new PluginHost();
		$tmppluginhost->load_all($tmppluginhost::KIND_ALL);
		$enabled = array_map("trim", explode(",", Config::get(Config::PLUGINS)));

		echo "List of all available plugins:\n";

		foreach ($tmppluginhost->get_plugins() as $name => $plugin) {
			$about = $plugin->about();

			$status = $about[3] ? "system" : "user";

			if (in_array($name, $enabled)) $name .= "*";

			printf("%-50s %-10s v%.2f (by %s)\n%s\n\n",
				$name, $status, $about[0], $about[2], $about[1]);
		}

		echo "Plugins marked by * are currently enabled for all users.\n";
	}

	if (isset($options["debug-feed"])) {
		$feed = (int) $options["debug-feed"];

		if (isset($options["force-refetch"])) $_REQUEST["force_refetch"] = true;
		if (isset($options["force-rehash"])) $_REQUEST["force_rehash"] = true;

		Debug::set_loglevel(Debug::LOG_EXTENDED);

		$rc = RSSUtils::update_rss_feed($feed);

		exit($rc ? 0 : 1);
	}

	if (isset($options["send-digests"])) {
		Digest::send_headlines_digests();
	}

	if (isset($options["user-list"])) {
		$users = ORM::for_table('ttrss_users')
			->order_by_asc('id')
			->find_many();

		foreach ($users as $user) {
			printf ("%-4d\t%-15s\t%-20s\t%-20s\n",
				$user->id, $user->login, $user->full_name, $user->email);
		}
	}

	if (isset($options["opml-export"])) {
		list ($user, $filename) = explode(":", $options["opml-export"], 2);

		Debug::log("Exporting feeds of user $user to $filename as OPML...");

		if ($owner_uid = UserHelper::find_user_by_login($user)) {
			$opml = new OPML([]);

			$rc = $opml->opml_export($filename, $owner_uid, false, true, true);

			Debug::log($rc ? "Success." : "Failed.");
			exit($rc ? 0 : 1);
		} else {
			Debug::log("User not found: $user");
			exit(1);
		}
	}

	if (isset($options["opml-import"])) {
		list ($user, $filename) = explode(":", $options["opml-import"], 2);

		Debug::log("Importing feeds of user $user from OPML file $filename...");

		if ($owner_uid = UserHelper::find_user_by_login($user)) {
			$opml = new OPML([]);

			$rc = $opml->opml_import($owner_uid, $filename);

			Debug::log($rc ? "Success." : "Failed.");
			exit($rc ? 0 : 1);
		} else {
			Debug::log("User not found: $user");
			exit(1);
		}

	}

	if (isset($options["user-add"])) {
		list ($login, $password, $access_level) = explode(":", $options["user-add"], 3);

		$uid = UserHelper::find_user_by_login($login);

		if ($uid) {
			Debug::log("Error: User already exists: $login");
			exit(1);
		}

		if (!$access_level)
			$access_level = UserHelper::ACCESS_LEVEL_USER;

		if (!in_array($access_level, UserHelper::ACCESS_LEVELS)) {
			Debug::log("Error: Invalid access level value: $access_level");
			exit(1);
		}

		if (!$password) {
			Debug::log("Please enter password for user $login: ");
			$password = read_stdin();

			if (!$password) {
				Debug::log("Error: password may not be blank.");
				exit(1);
			}
		}

		Debug::log("Adding user $login with access level $access_level...");

		if (UserHelper::user_add($login, $password, $access_level)) {
			Debug::log("Success.");
		} else {
			Debug::log("Operation failed, check the logs for more information.");
			exit(1);
		}
	}

	if (isset($options["user-set-password"])) {
		list ($login, $password) = explode(":", $options["user-set-password"], 2);

		$uid = UserHelper::find_user_by_login($login);

		if (!$uid) {
			Debug::log("Error: User not found: $login");
			exit(1);
		}

		Debug::log("Changing password of user $login...");

		if (UserHelper::user_modify($uid, $password)) {
			Debug::log("Success.");
		} else {
			Debug::log("Operation failed, check the logs for more information.");
			exit(1);
		}
	}

	if (isset($options["user-set-access-level"])) {
		list ($login, $access_level) = explode(":", $options["user-set-access-level"], 2);

		$uid = UserHelper::find_user_by_login($login);

		if (!$uid) {
			Debug::log("Error: User not found: $login");
			exit(1);
		}

		if (!in_array($access_level, UserHelper::ACCESS_LEVELS)) {
			Debug::log("Error: Invalid access level value: $access_level");
			exit(1);
		}

		Debug::log("Changing access level of user $login...");

		if (UserHelper::user_modify($uid, '', UserHelper::map_access_level((int)$access_level))) {
			Debug::log("Success.");
		} else {
			Debug::log("Operation failed, check the logs for more information.");
			exit(1);
		}
	}

	if (isset($options["user-enable-api"])) {
		list ($login, $enable) = explode(":", $options["user-enable-api"], 2);

		$uid = UserHelper::find_user_by_login($login);
		$enable = Handler::_param_to_bool($enable);

		if (!$uid) {
			Debug::log("Error: User not found: $login");
			exit(1);
		}

		if ($enable) {
			Debug::log("Enabling API access for user $login...");
			$rc = Prefs::set(Prefs::ENABLE_API_ACCESS, true, $uid, null);
		} else {
			Debug::log("Disabling API access for user $login...");
			$rc = Prefs::set(Prefs::ENABLE_API_ACCESS, false, $uid, null);
		}

		if ($rc) {
			Debug::log("Success.");
		} else {
			Debug::log("Operation failed, check the logs for more information.");
			exit(1);
		}
	}

	if (isset($options["user-remove"])) {
		$login = $options["user-remove"];

		$uid = UserHelper::find_user_by_login($login);

		if (!$uid) {
			Debug::log("Error: User not found: $login");
			exit(1);
		}

		if (!isset($options['force-yes'])) {
			Debug::log("About to remove user $login. Type 'yes' to continue.");

			if (read_stdin() != 'yes')
				exit(1);
		}

		Debug::log("Removing user $login...");

		if (UserHelper::user_delete($uid)) {
			Debug::log("Success.");
		} else {
			Debug::log("Operation failed, check the logs for more information.");
			exit(1);
		}
	}

	if (isset($options["user-exists"])) {
		$login = $options["user-exists"];

		if (UserHelper::find_user_by_login($login))
			exit(0);
		else
			exit(1);
	}

	if (isset($options["user-check-password"])) {
		list ($login, $password) = explode(":", $options["user-check-password"], 2);

		$uid = UserHelper::find_user_by_login($login);

		if (!$uid) {
			Debug::log("Error: User not found: $login");
			exit(1);
		}

		$rc = UserHelper::user_has_password($uid, $password);

		exit($rc ? 0 : 1);
	}

	PluginHost::getInstance()->run_commands($options);

	if (file_exists(Config::get(Config::LOCK_DIRECTORY) . "/$lock_filename"))
		if (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN')
			fclose($lock_handle);
		unlink(Config::get(Config::LOCK_DIRECTORY) . "/$lock_filename");
?>
