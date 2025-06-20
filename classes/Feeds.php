<?php
require_once "colors.php";

class Feeds extends Handler_Protected {
	/** special feed for archived articles */
	const FEED_ARCHIVED = 0;

	/** special feed for starred articles */
	const FEED_STARRED = -1;

	/** special feed for published articles */
	const FEED_PUBLISHED = -2;

	/** special feed for archived articles */
	const FEED_FRESH = -3;

	/** special feed for all articles */
	const FEED_ALL = -4;

	/**
	 * -5 was FEED_DASHBOARD, intended to be used when there
	 * was nothing to show, but the related code was unused
	 */

	/** special feed for recently read articles */
	const FEED_RECENTLY_READ = -6;

	/** special feed for error scenarios (e.g. feed not found) */
	const FEED_ERROR = -7;

	/** special "category" for uncategorized articles */
	const CATEGORY_UNCATEGORIZED = 0;

	/** special category for "special" articles (e.g. Starred, Published, Archived, plugin-provided, etc.) */
	const CATEGORY_SPECIAL = -1;

	/** special category for labels */
	const CATEGORY_LABELS = -2;

	/** special category for all feeds, excluding virtual feeds (e.g. labels and such) */
	const CATEGORY_ALL_EXCEPT_VIRTUAL = -3;

	/** special category for all feeds, including virtual feeds (e.g. labels and such) */
	const CATEGORY_ALL = -4;

	const NEVER_GROUP_FEEDS = [ Feeds::FEED_RECENTLY_READ, Feeds::FEED_ARCHIVED ];
	const NEVER_GROUP_BY_DATE = [ Feeds::FEED_PUBLISHED, Feeds::FEED_STARRED, Feeds::FEED_FRESH ];

	function csrf_ignore(string $method): bool {
		$csrf_ignored = array("index");

		return array_search($method, $csrf_ignored) !== false;
	}

	/**
	 * @return array{0: array<int, int>, 1: int, 2: int, 3: bool, 4: array<string, mixed>} $topmost_article_ids, $headlines_count, $feed, $disable_cache, $reply
	 */
	private function _format_headlines_list(int|string $feed, string $method, string $view_mode, int $limit, bool $cat_view,
					int $offset, string $override_order, bool $include_children, ?int $check_first_id = null,
					?bool $skip_first_id_check = false, ? string $order_by = ''): array {

		$profile = $_SESSION['profile'] ?? null;

		$disable_cache = false;

		$reply = [];
		$rgba_cache = [];
		$topmost_article_ids = [];

		if (!$offset) $offset = 0;
		if ($method == "undefined") $method = "";

		$method_split = explode(":", $method);

		if ($method == "ForceUpdate" && $feed > 0 && is_numeric($feed)) {
            $sth = $this->pdo->prepare("UPDATE ttrss_feeds
                            SET last_updated = '1970-01-01', last_update_started = '1970-01-01'
                            WHERE id = ?");
            $sth->execute([$feed]);
		}

		if ($method_split[0] == "MarkAllReadGR")  {
			$this->_catchup($method_split[1], false);
		}

		// FIXME: might break tag display?

		if (is_numeric($feed) && $feed > 0 && !$cat_view) {
			$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE id = ? LIMIT 1");
			$sth->execute([$feed]);

			if (!$sth->fetch()) {
				$reply['content'] = "<div align='center'>".__('Feed not found.')."</div>";
			}
		}

		$search = $_REQUEST["query"] ?? "";
		$search_language = $_REQUEST["search_language"] ?? ""; // PGSQL only

		if ($search) {
			$disable_cache = true;
		}

		$qfh_ret = [];

		if (!$cat_view && is_numeric($feed) && $feed < PLUGIN_FEED_BASE_INDEX && $feed > LABEL_BASE_INDEX) {
			$handler = PluginHost::getInstance()->get_feed_handler(
				PluginHost::feed_to_pfeed_id($feed));

			if ($handler) {
				$options = array(
					"limit" => $limit,
					"view_mode" => $view_mode,
					"cat_view" => $cat_view,
					"search" => $search,
					"override_order" => $override_order,
					"offset" => $offset,
					"owner_uid" => $_SESSION["uid"],
					"filter" => false,
					"since_id" => 0,
					"include_children" => $include_children,
					"order_by" => $order_by);

				$qfh_ret = $handler->get_headlines(PluginHost::feed_to_pfeed_id($feed),
					$options);
			}

		} else {

			$params = array(
				"feed" => $feed,
				"limit" => $limit,
				"view_mode" => $view_mode,
				"cat_view" => $cat_view,
				"search" => $search,
				"search_language" => $search_language,
				"override_order" => $override_order,
				"offset" => $offset,
				"include_children" => $include_children,
				"check_first_id" => $check_first_id,
				"skip_first_id_check" => $skip_first_id_check,
                "order_by" => $order_by
			);

			$qfh_ret = $this->_get_headlines($params);
		}

		$vfeed_group_enabled = Prefs::get(Prefs::VFEED_GROUP_BY_FEED, $_SESSION['uid'], $profile) &&
			!(in_array($feed, self::NEVER_GROUP_FEEDS) && !$cat_view);

		$result = $qfh_ret[0]; // this could be either a PDO query result or a -1 if first id changed
		$feed_title = $qfh_ret[1];
		$feed_site_url = $qfh_ret[2];
		$last_error = $qfh_ret[3];
		$last_updated = TimeHelper::make_local_datetime($qfh_ret[4]);
		$highlight_words = $qfh_ret[5];
		$reply['first_id'] = $qfh_ret[6];
		$reply['is_vfeed'] = $qfh_ret[7];
		$query_error_override = $qfh_ret[8];

		$reply['search_query'] = [$search, $search_language];
		$reply['vfeed_group_enabled'] = $vfeed_group_enabled;


		$plugin_menu_items = "";
		PluginHost::getInstance()->chain_hooks_callback(PluginHost::HOOK_HEADLINE_TOOLBAR_SELECT_MENU_ITEM2,
			function ($result) use (&$plugin_menu_items) {
				$plugin_menu_items .= $result;
			},
			$feed, $cat_view);

		$plugin_buttons = "";
		PluginHost::getInstance()->chain_hooks_callback(PluginHost::HOOK_HEADLINE_TOOLBAR_BUTTON,
			function ($result) use (&$plugin_buttons) {
				$plugin_buttons .= $result;
			},
			$feed, $cat_view);

		$reply['toolbar'] = [
			'site_url' => $feed_site_url,
			'title' => strip_tags($feed_title),
			'error' => $last_error,
			'last_updated' => $last_updated,
			'plugin_menu_items' => $plugin_menu_items,
			'plugin_buttons' => $plugin_buttons,
		];

		$reply['content'] = [];

		if ($offset == 0)
			PluginHost::getInstance()->chain_hooks_callback(PluginHost::HOOK_HEADLINES_BEFORE,
					function ($result) use (&$reply) {
						$reply['content'] .= $result;
					},
					$feed, $cat_view, $qfh_ret);

		$headlines_count = 0;

		if ($result instanceof PDOStatement) {
			while ($line = $result->fetch(PDO::FETCH_ASSOC)) {

				++$headlines_count;

				if (!Prefs::get(Prefs::SHOW_CONTENT_PREVIEW, $_SESSION['uid'], $profile)) {
					$line["content_preview"] = "";
				} else {
					$line["content_preview"] =  "&mdash; " . truncate_string(strip_tags($line["content"]), 250);

					$max_excerpt_length = 250;

					PluginHost::getInstance()->chain_hooks_callback(PluginHost::HOOK_QUERY_HEADLINES,
						function ($result) use (&$line) {
							$line = $result;
						},
						$line, $max_excerpt_length);
				}

				$id = $line["id"];

				// normalize archived feed
				if ($line['feed_id'] === null) {
					$line['feed_id'] = Feeds::FEED_ARCHIVED;
					$line["feed_title"] = __("Archived articles");
				}

				$feed_id = $line["feed_id"];

				if ($line["num_labels"] > 0) {
					$label_cache = $line["label_cache"];
					$labels = false;

					if ($label_cache) {
						$label_cache = json_decode($label_cache, true);

						if ($label_cache) {
							if (($label_cache["no-labels"] ?? 0) == 1)
								$labels = [];
							else
								$labels = $label_cache;
						}
					} else {
						$labels = Article::_get_labels($id);
					}

					$line["labels"] = $labels;
				} else {
					$line["labels"] = [];
				}

				if (count($topmost_article_ids) < 3) {
					array_push($topmost_article_ids, $id);
				}

				$line["feed_title"] = $line["feed_title"] ?? "";

				$button_doc = new DOMDocument();

				$line["buttons_left"] = "";
				PluginHost::getInstance()->chain_hooks_callback(PluginHost::HOOK_ARTICLE_LEFT_BUTTON,
					function ($result, $plugin) use (&$line, &$button_doc) {
						if ($result && $button_doc->loadXML($result)) {

							/** @var DOMElement|null $child */
							$child = $button_doc->firstChild;

							if ($child) {
								do {
									/** @var DOMElement|null $child */
									$child->setAttribute('data-plugin-name', get_class($plugin));
								} while ($child = $child->nextSibling);

								$line["buttons_left"] .= $button_doc->saveXML($button_doc->firstChild);
							}
						} else if ($result) {
							user_error(get_class($plugin) .
								" plugin: content provided in HOOK_ARTICLE_LEFT_BUTTON is not valid XML: " .
								Errors::libxml_last_error() . " $result", E_USER_WARNING);
						}
					},
					$line);

				$line["buttons"] = "";

				PluginHost::getInstance()->chain_hooks_callback(PluginHost::HOOK_ARTICLE_BUTTON,
					function ($result, $plugin) use (&$line, &$button_doc) {
						if ($result && $button_doc->loadXML($result)) {

							/** @var DOMElement|null $child */
							$child = $button_doc->firstChild;

							if ($child) {
								do {
									/** @var DOMElement|null $child */
									$child->setAttribute('data-plugin-name', get_class($plugin));
								} while ($child = $child->nextSibling);

								$line["buttons"] .= $button_doc->saveXML($button_doc->firstChild);
							}
						} else if ($result) {
							user_error(get_class($plugin) .
								" plugin: content provided in HOOK_ARTICLE_BUTTON is not valid XML: " .
								Errors::libxml_last_error() . " $result", E_USER_WARNING);
						}
					},
					$line);

				$line["content"] = Sanitizer::sanitize($line["content"],
					$line['hide_images'], null, $line["site_url"], $highlight_words, $line["id"]);

				if (!Prefs::get(Prefs::CDM_EXPANDED, $_SESSION['uid'], $profile)) {
					$line["cdm_excerpt"] = "<span class='collapse'>
						<i class='material-icons' onclick='return Article.cdmUnsetActive(event)'
								title=\"" . __("Collapse article") . "\">remove_circle</i></span>";

					if (Prefs::get(Prefs::SHOW_CONTENT_PREVIEW, $_SESSION['uid'], $profile)) {
						$line["cdm_excerpt"] .= "<span class='excerpt'>" . $line["content_preview"] . "</span>";
					}
				}

				if ($line["num_enclosures"] > 0) {
					$line["enclosures"] = Article::_format_enclosures($id,
						sql_bool_to_bool($line["always_display_enclosures"]),
						$line["content"],
						sql_bool_to_bool($line["hide_images"]));
				} else {
					$line["enclosures"] = [ 'formatted' => '', 'entries' => [] ];
				}

				$line["updated_long"] = TimeHelper::make_local_datetime($line["updated"]);
				$line["updated"] = TimeHelper::make_local_datetime($line["updated"], eta_min: true);

				$line['imported'] = T_sprintf("Imported at %s",
					TimeHelper::make_local_datetime($line['date_entered']));

				if ($line["tag_cache"])
					$tags = explode(",", $line["tag_cache"]);
				else
					$tags = [];

				$line["tags"] = $tags;

				//$line["tags"] = Article::_get_tags($line["id"], false, $line["tag_cache"]);

				$line['has_icon'] = self::_has_icon($feed_id);

			    //setting feed headline background color, needs to change text color based on dark/light
				$fav_color = $line['favicon_avg_color'] ?? false;

				require_once "colors.php";

				if (!isset($rgba_cache[$feed_id])) {
					if ($fav_color && $fav_color != 'fail') {
						$rgba_cache[$feed_id] = \Colors\_color_unpack($fav_color);
					} else {
						$rgba_cache[$feed_id] = \Colors\_color_unpack($this->_color_of($line['feed_title']));
					}
				}

				if (isset($rgba_cache[$feed_id])) {
				    $line['feed_bg_color'] = 'rgba(' . implode(",", $rgba_cache[$feed_id]) . ',0.3)';
				}

				PluginHost::getInstance()->chain_hooks_callback(PluginHost::HOOK_RENDER_ARTICLE_CDM,
					function ($result, $plugin) use (&$line) {
						$line = $result;
					},
					$line);

				$line['content'] = DiskCache::rewrite_urls($line['content']);

				/* we don't need those */

				foreach (["date_entered", "guid", "last_published", "last_marked", "tag_cache", "favicon_avg_color",
								"uuid", "label_cache", "yyiw", "num_enclosures"] as $k)
					unset($line[$k]);

				array_push($reply['content'], $line);
			}
		}

		if (!$headlines_count) {

			if ($result instanceof PDOStatement) {

				if ($query_error_override) {
					$message = $query_error_override;
				} else {
					$message = match ($view_mode) {
						'unread' => __('No unread articles found to display.'),
						'updated' => __('No updated articles found to display.'),
						'marked' => __('No starred articles found to display.'),
						default => $feed < LABEL_BASE_INDEX ?
							__('No articles found to display. You can assign articles to labels manually from article header context menu (applies to all selected articles) or use a filter.')
							: __('No articles found to display.'),
					};
				}

				if (!$offset && $message) {
					$reply['content'] = "<div class='whiteBox'>$message";

					$reply['content'] .= "<p><span class=\"text-muted\">";

					$sth = $this->pdo->prepare("SELECT SUBSTRING_FOR_DATE(MAX(last_updated), 1, 19) AS last_updated
						FROM ttrss_feeds WHERE owner_uid = ?");
					$sth->execute([$_SESSION['uid']]);
					$row = $sth->fetch();

					$last_updated = TimeHelper::make_local_datetime($row['last_updated']);

					$reply['content'] .= sprintf(__("Feeds last updated at %s"), $last_updated);

					$num_errors = ORM::for_table('ttrss_feeds')
						->where_not_equal('last_error', '')
						->where('owner_uid', $_SESSION['uid'])
						->where_gte('update_interval', 0)
						->count('id');

					if ($num_errors > 0) {
						$reply['content'] .= "<br/>";
						$reply['content'] .= "<a class=\"text-muted\" href=\"#\" onclick=\"CommonDialogs.showFeedsWithErrors(); return false\">" .
							__('Some feeds have update errors (click for details)') . "</a>";
					}
					$reply['content'] .= "</span></p></div>";

				}
			} else if (is_numeric($result) && $result == -1) {
				$reply['first_id_changed'] = true;
			}
		}

		return array($topmost_article_ids, $headlines_count, $feed, $disable_cache, $reply);
	}

	function catchupAll(): void {
		$sth = $this->pdo->prepare("UPDATE ttrss_user_entries SET
						last_read = NOW(), unread = false WHERE unread = true AND owner_uid = ?");
		$sth->execute([$_SESSION['uid']]);

		print json_encode(array("message" => "UPDATE_COUNTERS"));
	}

	function view(): void {
		$profile = $_SESSION['profile'] ?? null;

		$reply = array();

		$feed = $_REQUEST["feed"];
		$method = $_REQUEST["m"] ?? "";
		$view_mode = $_REQUEST["view_mode"] ?? "";
		$limit = 30;
		$cat_view = self::_param_to_bool($_REQUEST["cat"] ?? false);
		$next_unread_feed = $_REQUEST["nuf"] ?? 0;
		$offset = (int) ($_REQUEST["skip"] ?? 0);
		$order_by = $_REQUEST["order_by"] ?? "";
		$check_first_id = $_REQUEST["fid"] ?? 0;

		if (is_numeric($feed)) $feed = (int) $feed;

		$sth = false;
		if ($feed < LABEL_BASE_INDEX) {

			$label_feed = Labels::feed_to_label_id($feed);

			$sth = $this->pdo->prepare("SELECT id FROM ttrss_labels2 WHERE
							id = ? AND owner_uid = ?");
			$sth->execute([$label_feed, $_SESSION['uid']]);

		} else if (!$cat_view && is_numeric($feed) && $feed > 0) {

			$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE
							id = ? AND owner_uid = ?");
			$sth->execute([$feed, $_SESSION['uid']]);

		} else if ($cat_view && is_numeric($feed) && $feed > 0) {

			$sth = $this->pdo->prepare("SELECT id FROM ttrss_feed_categories WHERE
							id = ? AND owner_uid = ?");

			$sth->execute([$feed, $_SESSION['uid']]);
		}

		if ($sth && !$sth->fetch()) {
			print json_encode($this->_generate_error_feed(__("Feed not found.")));
			return;
		}

		Prefs::set(Prefs::_DEFAULT_VIEW_MODE, $view_mode, $_SESSION['uid'], $profile);
		Prefs::set(Prefs::_DEFAULT_VIEW_ORDER_BY, $order_by, $_SESSION['uid'], $profile);

		/* bump login timestamp if needed */
		if (time() - $_SESSION["last_login_update"] > 3600) {
			$user = ORM::for_table('ttrss_users')->find_one($_SESSION["uid"]);
			$user->last_login = Db::NOW();
			$user->save();

			$_SESSION["last_login_update"] = time();
		}

		if (!$cat_view && is_numeric($feed) && $feed > 0) {
			$sth = $this->pdo->prepare("UPDATE ttrss_feeds SET last_viewed = NOW()
							WHERE id = ? AND owner_uid = ?");
			$sth->execute([$feed, $_SESSION['uid']]);
		}

		$reply['headlines'] = [];

		list($override_order, $skip_first_id_check) = self::_order_to_override_query($order_by);

		$ret = $this->_format_headlines_list($feed, $method,
			$view_mode, $limit, $cat_view, $offset,
			$override_order, true, $check_first_id, $skip_first_id_check, $order_by);

		$headlines_count = $ret[1];
		$disable_cache = $ret[3];
		$reply['headlines'] = $ret[4];

		if (!$next_unread_feed)
			$reply['headlines']['id'] = $feed;
		else
			$reply['headlines']['id'] = $next_unread_feed;

		$reply['headlines']['is_cat'] = $cat_view;

		$reply['headlines-info'] = ["count" => (int) $headlines_count,
            						"disable_cache" => (bool) $disable_cache];

		// this is parsed by handleRpcJson() on first viewfeed() to set cdm expanded, etc
		$reply['runtime-info'] = RPC::_make_runtime_info();

		if (!empty($_REQUEST["debug"])) {
			print "\n*** HEADLINE DATA ***\n";

			print json_encode($reply, JSON_PRETTY_PRINT);
		} else {
			print json_encode($reply);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function _generate_error_feed(string $error): array {
		$reply = array();

		$reply['headlines']['id'] = Feeds::FEED_ERROR;
		$reply['headlines']['is_cat'] = false;

		$reply['headlines']['toolbar'] = '';
		$reply['headlines']['content'] = "<div class='whiteBox'>". $error . "</div>";

		$reply['headlines-info'] = array("count" => 0,
			"unread" => 0,
			"disable_cache" => true);

		return $reply;
	}

	function subscribeToFeed(): void {
		global $update_intervals;

		$local_update_intervals = $update_intervals;
		$local_update_intervals[0] .= sprintf(" (%s)", $update_intervals[Prefs::get(Prefs::DEFAULT_UPDATE_INTERVAL, $_SESSION['uid'])]);

		print json_encode([
			"cat_select" => \Controls\select_feeds_cats("cat"),
			"intervals" => [
				"update" => $local_update_intervals
			]
		]);
	}

	function search(): void {
		print json_encode([
			"show_language" => true,
			"show_syntax_help" => count(PluginHost::getInstance()->get_hooks(PluginHost::HOOK_SEARCH)) == 0,
			"all_languages" => Pref_Feeds::get_ts_languages(),
			"default_language" => Prefs::get(Prefs::DEFAULT_SEARCH_LANGUAGE, $_SESSION['uid'], $_SESSION['profile'] ?? null)
		]);
	}

	function opensite(): void {
		$feed = ORM::for_table('ttrss_feeds')
			->find_one((int)$_REQUEST['feed_id']);

		if ($feed) {
			$site_url = UrlHelper::validate($feed->site_url);

			if ($site_url) {
				header("Location: $site_url");
				return;
			}
		}

		header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
		print "Feed not found or has an empty site URL.";
	}

	function updatedebugger(): void {
		header("Content-type: text/html");

		$xdebug = isset($_REQUEST["xdebug"]) ? (int)$_REQUEST["xdebug"] : Debug::LOG_VERBOSE;

		if (!in_array($xdebug, Debug::ALL_LOG_LEVELS)) {
			$xdebug = Debug::LOG_VERBOSE;
		}

		Debug::set_enabled(true);
		Debug::set_loglevel((int)Debug::map_loglevel($xdebug));

		$feed_id = (int)$_REQUEST["feed_id"];
		$do_update = ($_REQUEST["action"] ?? "") == "do_update";
		$csrf_token = $_POST["csrf_token"];

		$sth = $this->pdo->prepare("SELECT id FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
		$sth->execute([$feed_id, $_SESSION['uid']]);

		if (!$sth->fetch()) {
			print "Access denied.";
			return;
		}
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<title>Feed Debugger</title>
			<style type='text/css'>
				@media (prefers-color-scheme: dark) {
					body {
						background : #222;
					}
				}
				body.css_loading * {
					display : none;
				}

				.feed-xml {
					color : green;
				}

				.log-timestamp {
					color : gray;
				}

				.log-timestamp::before {
					content: "["
				}

				.log-timestamp::after {
					content: "]"
				}

			</style>
			<script>
				dojoConfig = {
					async: true,
					cacheBust: "<?= get_scripts_timestamp(); ?>",
					packages: [
						{ name: "fox", location: "../../js" },
					]
				};
			</script>
			<?= javascript_tag("js/utility.js") ?>
			<?= javascript_tag("js/common.js") ?>
			<?= javascript_tag("lib/dojo/dojo.js") ?>
			<?= javascript_tag("lib/dojo/tt-rss-layer.js") ?>
			<?= Config::get_override_links() ?>
		</head>
		<body class="flat ttrss_utility feed_debugger css_loading">
		<script type="text/javascript">
			require(['dojo/parser', "dojo/ready", 'dijit/form/Button','dijit/form/CheckBox', 'fox/form/Select', 'dijit/form/Form',
				'dijit/form/Select','dijit/form/TextBox','dijit/form/ValidationTextBox'],function(parser, ready){
				ready(function() {
					parser.parse();
				});
			});
		</script>

			<div class="container">
				<h1>Feed Debugger: <?= "$feed_id: " . $this->_get_title($feed_id) ?></h1>
				<div class="content">
					<form method="post" action="" dojoType="dijit.form.Form">
						<?= \Controls\hidden_tag("op", "Feeds") ?>
						<?= \Controls\hidden_tag("method", "updatedebugger") ?>
						<?= \Controls\hidden_tag("csrf_token", $csrf_token) ?>
						<?= \Controls\hidden_tag("action", "do_update") ?>
						<?= \Controls\hidden_tag("feed_id", (string)$feed_id) ?>

						<fieldset>
							<label>
							<?= \Controls\select_hash("xdebug", $xdebug,
									[Debug::LOG_VERBOSE => "LOG_VERBOSE", Debug::LOG_EXTENDED => "LOG_EXTENDED"]);
							?></label>
						</fieldset>

						<fieldset>
							<label class="checkbox"><?= \Controls\checkbox_tag("force_refetch", isset($_REQUEST["force_refetch"])) ?> Force refetch</label>
						</fieldset>

						<fieldset class="narrow">
							<label class="checkbox"><?= \Controls\checkbox_tag("force_rehash", isset($_REQUEST["force_rehash"])) ?> Force rehash</label>
						</fieldset>

						<fieldset class="narrow">
							<label class="checkbox"><?= \Controls\checkbox_tag("dump_feed_xml", isset($_REQUEST["dump_feed_xml"])) ?> Dump feed XML</label>
						</fieldset>

						<?= \Controls\submit_tag("Continue") ?>
					</form>

					<hr>

					<pre><?php

					if ($do_update) {
						RSSUtils::update_rss_feed($feed_id, true, true);
					}

					?></pre>
				</div>
			</div>
		</body>
		</html>
		<?php

	}

	/**
	 * @param array<int, string> $search
	 */
	static function _catchup(string $feed_id_or_tag_name, bool $cat_view, ?int $owner_uid = null, string $mode = 'all', ?array $search = null): void {
		if (!$owner_uid) $owner_uid = $_SESSION['uid'];
		$profile = isset($_SESSION['uid']) && $owner_uid == $_SESSION['uid'] && isset($_SESSION['profile']) ? $_SESSION['profile'] : null;

		$pdo = Db::pdo();

		if (is_array($search) && $search[0]) {
			$search_qpart = "";

			PluginHost::getInstance()->chain_hooks_callback(PluginHost::HOOK_SEARCH,
				function ($result) use (&$search_qpart, &$search_words) {
					if (!empty($result)) {
						list($search_qpart, $search_words) = $result;
						return true;
					}
				},
				$search[0]);

			// fall back in case of no plugins
			if (empty($search_qpart)) {
				list($search_qpart, $search_words) = self::_search_to_sql($search[0], $search[1], $owner_uid, $profile);
			}
		} else {
			$search_qpart = "true";
		}

		$date_qpart = match ($mode) {
			'1day', '1week', '2week' => "date_entered < NOW() - INTERVAL '" . (int) substr($mode, 0, 1) . " " . substr($mode, 1) . "'",
			default => 'true',
		};

		if (is_numeric($feed_id_or_tag_name)) {
			$feed_id = (int) $feed_id_or_tag_name;

			if ($cat_view) {

				if ($feed_id >= 0) {

					if ($feed_id == Feeds::CATEGORY_UNCATEGORIZED) {
						$cat_qpart = "cat_id IS NULL";
					} else {
						$children = self::_get_child_cats($feed_id, $owner_uid);
						array_push($children, $feed_id);
						$children = array_map("intval", $children);

						$children = join(",", $children);

						$cat_qpart = "cat_id IN ($children)";
					}

					$sth = $pdo->prepare("UPDATE ttrss_user_entries
						SET unread = false, last_read = NOW() WHERE ref_id IN
							(SELECT id FROM
								(SELECT DISTINCT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id
									AND owner_uid = ? AND unread = true AND feed_id IN
										(SELECT id FROM ttrss_feeds WHERE $cat_qpart) AND $date_qpart AND $search_qpart) as tmp)");
					$sth->execute([$owner_uid]);

				} else if ($feed_id == Feeds::CATEGORY_LABELS) {

					$sth = $pdo->prepare("UPDATE ttrss_user_entries
						SET unread = false,last_read = NOW() WHERE (SELECT COUNT(*)
							FROM ttrss_user_labels2, ttrss_entries WHERE article_id = ref_id AND id = ref_id AND $date_qpart AND $search_qpart) > 0
							AND unread = true AND owner_uid = ?");
					$sth->execute([$owner_uid]);
				}

			} else if ($feed_id > 0) {

				$sth = $pdo->prepare("UPDATE ttrss_user_entries
					SET unread = false, last_read = NOW() WHERE ref_id IN
						(SELECT id FROM
							(SELECT DISTINCT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id
								AND owner_uid = ? AND unread = true AND feed_id = ? AND $date_qpart AND $search_qpart) as tmp)");
				$sth->execute([$owner_uid, $feed_id]);

			} else if ($feed_id < 0 && $feed_id > LABEL_BASE_INDEX) { // special, like starred

				if ($feed_id == Feeds::FEED_STARRED) {
					$sth = $pdo->prepare("UPDATE ttrss_user_entries
						SET unread = false, last_read = NOW() WHERE ref_id IN
							(SELECT id FROM
								(SELECT DISTINCT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id
									AND owner_uid = ? AND unread = true AND marked = true AND $date_qpart AND $search_qpart) as tmp)");
					$sth->execute([$owner_uid]);
				}

				if ($feed_id == Feeds::FEED_PUBLISHED) {
					$sth = $pdo->prepare("UPDATE ttrss_user_entries
						SET unread = false, last_read = NOW() WHERE ref_id IN
							(SELECT id FROM
								(SELECT DISTINCT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id
									AND owner_uid = ? AND unread = true AND published = true AND $date_qpart AND $search_qpart) as tmp)");
					$sth->execute([$owner_uid]);
				}

				if ($feed_id == Feeds::FEED_FRESH) {

					$intl = (int) Prefs::get(Prefs::FRESH_ARTICLE_MAX_AGE, $owner_uid, $profile);

					$sth = $pdo->prepare("UPDATE ttrss_user_entries
						SET unread = false, last_read = NOW() WHERE ref_id IN
							(SELECT id FROM
								(SELECT DISTINCT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id
									AND owner_uid = ? AND score >= 0 AND unread = true AND date_entered > NOW() - INTERVAL '$intl hour'
									AND $date_qpart AND $search_qpart) as tmp)");
					$sth->execute([$owner_uid]);
				}

				if ($feed_id == Feeds::FEED_ALL) {
					$sth = $pdo->prepare("UPDATE ttrss_user_entries
						SET unread = false, last_read = NOW() WHERE ref_id IN
							(SELECT id FROM
								(SELECT DISTINCT id FROM ttrss_entries, ttrss_user_entries WHERE ref_id = id
									AND owner_uid = ? AND unread = true AND $date_qpart AND $search_qpart) as tmp)");
					$sth->execute([$owner_uid]);
				}
			} else if ($feed_id < LABEL_BASE_INDEX) { // label

				$label_id = Labels::feed_to_label_id($feed_id);

				$sth = $pdo->prepare("UPDATE ttrss_user_entries
					SET unread = false, last_read = NOW() WHERE ref_id IN
						(SELECT id FROM
							(SELECT DISTINCT ttrss_entries.id FROM ttrss_entries, ttrss_user_entries, ttrss_user_labels2 WHERE ref_id = id
								AND label_id = ? AND ref_id = article_id
								AND owner_uid = ? AND unread = true AND $date_qpart AND $search_qpart) as tmp)");
				$sth->execute([$label_id, $owner_uid]);
			}
		} else { // tag
			$tag_name = $feed_id_or_tag_name;

			$sth = $pdo->prepare("UPDATE ttrss_user_entries
				SET unread = false, last_read = NOW() WHERE ref_id IN
					(SELECT id FROM
						(SELECT DISTINCT ttrss_entries.id FROM ttrss_entries, ttrss_user_entries, ttrss_tags WHERE ref_id = ttrss_entries.id
							AND post_int_id = int_id AND tag_name = ?
							AND ttrss_user_entries.owner_uid = ? AND unread = true AND $date_qpart AND $search_qpart) as tmp)");
			$sth->execute([$tag_name, $owner_uid]);
		}
	}

	/**
	 * @param int|string $feed feed id or tag name
	 * @throws PDOException
	 */
	static function _get_counters(int|string $feed, bool $is_cat = false, bool $unread_only = false, ?int $owner_uid = null): int {
		$n_feed = (int) $feed;
		$need_entries = false;

		$pdo = Db::pdo();

		if (!$owner_uid) $owner_uid = $_SESSION["uid"];
		$profile = isset($_SESSION['uid']) && $owner_uid == $_SESSION['uid'] && isset($_SESSION['profile']) ? $_SESSION['profile'] : null;

		if ($unread_only) {
			$unread_qpart = "unread = true";
		} else {
			$unread_qpart = "true";
		}

		$match_part = "";

		if ($is_cat) {
			return self::_get_cat_unread($n_feed, $owner_uid);
		} else if (is_numeric($feed) && $feed < PLUGIN_FEED_BASE_INDEX && $feed > LABEL_BASE_INDEX) { // virtual Feed
			$feed_id = PluginHost::feed_to_pfeed_id($feed);
			$handler = PluginHost::getInstance()->get_feed_handler($feed_id);
			return $handler ? $handler->get_unread($feed_id) : 0;
		} else if ($n_feed == Feeds::FEED_RECENTLY_READ) {
			return 0;
		// tags
		} else if ($feed != "0" && $n_feed == 0) {

			$sth = $pdo->prepare("SELECT SUM((SELECT COUNT(int_id)
				FROM ttrss_user_entries,ttrss_entries WHERE int_id = post_int_id
					AND ref_id = id AND $unread_qpart)) AS count FROM ttrss_tags
				WHERE owner_uid = ? AND tag_name = ?");

			$sth->execute([$owner_uid, $feed]);
			$row = $sth->fetch();

			// Handle 'SUM()' returning null if there are no results
			return $row["count"] ?? 0;

		} else if ($n_feed == Feeds::FEED_STARRED) {
			$match_part = "marked = true";
		} else if ($n_feed == Feeds::FEED_PUBLISHED) {
			$match_part = "published = true";
		} else if ($n_feed == Feeds::FEED_FRESH) {
			$intl = (int) Prefs::get(Prefs::FRESH_ARTICLE_MAX_AGE, $owner_uid, $profile);
			$match_part = "unread = true AND score >= 0 AND date_entered > NOW() - INTERVAL '$intl hour'";

			$need_entries = true;

		} else if ($n_feed == Feeds::FEED_ALL) {
			$match_part = "true";
		} else if ($n_feed >= 0) {

			if ($n_feed != Feeds::FEED_ARCHIVED) {
				$match_part = sprintf("feed_id = %d", $n_feed);
			} else {
				$match_part = "feed_id IS NULL";
			}

		} else if ($feed < LABEL_BASE_INDEX) {

			$label_id = Labels::feed_to_label_id($feed);

			return self::_get_label_unread($label_id, $owner_uid);
		}

		if ($match_part) {

			if ($need_entries) {
				$from_qpart = "ttrss_user_entries,ttrss_entries";
				$from_where = "ttrss_entries.id = ttrss_user_entries.ref_id AND";
			} else {
				$from_qpart = "ttrss_user_entries";
				$from_where = "";
			}

			$sth = $pdo->prepare("SELECT count(int_id) AS unread
				FROM $from_qpart WHERE
				$unread_qpart AND $from_where ($match_part) AND ttrss_user_entries.owner_uid = ?");
			$sth->execute([$owner_uid]);
			$row = $sth->fetch();

			return $row["unread"];

		} else {

			$sth = $pdo->prepare("SELECT COUNT(post_int_id) AS unread
				FROM ttrss_tags,ttrss_user_entries,ttrss_entries
				WHERE tag_name = ? AND post_int_id = int_id AND ref_id = ttrss_entries.id
				AND $unread_qpart AND ttrss_tags.owner_uid = ,");

			$sth->execute([$feed, $owner_uid]);
			$row = $sth->fetch();

			return $row["unread"];
		}
	}

	function add(): void {
		$feed = clean($_REQUEST['feed']);
		$cat = (int) clean($_REQUEST['cat'] ?? '');
		$need_auth = isset($_REQUEST['need_auth']);
		$login = $need_auth ? clean($_REQUEST['login']) : '';
		$pass = $need_auth ? clean($_REQUEST['pass']) : '';
		$update_interval = (int) clean($_REQUEST['update_interval'] ?? 0);

		$rc = Feeds::_subscribe($feed, $cat, $login, $pass, $update_interval);

		print json_encode(array("result" => $rc));
	}

	/**
	 * @return array<string, mixed> (code => Status code, message => error message if available)
	 *
	 *                 0 - OK, Feed already exists
	 *                 1 - OK, Feed added
	 *                 2 - Invalid URL
	 *                 3 - URL content is HTML, no feeds available
	 *                 4 - URL content is HTML which contains multiple feeds.
	 *                     Here you should call extractfeedurls in rpc-backend
	 *                     to get all possible feeds.
	 *                 5 - Couldn't download the URL content.
	 *                 6 - Content is an invalid XML.
	 *                 7 - Error while creating feed database entry.
	 *                 8 - Permission denied (ACCESS_LEVEL_READONLY).
	 */
	static function _subscribe(string $url, int $cat_id = 0, string $auth_login = '', string $auth_pass = '', int $update_interval = 0): array {

		$user = ORM::for_table("ttrss_users")->find_one($_SESSION['uid']);

		if ($user && $user->access_level == UserHelper::ACCESS_LEVEL_READONLY) {
			return ["code" => 8];
		}

		$url = UrlHelper::validate($url);

		if (!$url) return ["code" => 2];

		PluginHost::getInstance()->chain_hooks_callback(PluginHost::HOOK_PRE_SUBSCRIBE,
			/** @phpstan-ignore closure.unusedUse, closure.unusedUse, closure.unusedUse */
			function ($result) use (&$url, &$auth_login, &$auth_pass) {
				// arguments are updated inside the hook (if needed)
			},
			$url, $auth_login, $auth_pass);

		$contents = UrlHelper::fetch(['url' => $url, 'login' => $auth_login, 'pass' => $auth_pass]);

		PluginHost::getInstance()->chain_hooks_callback(PluginHost::HOOK_SUBSCRIBE_FEED,
			function ($result) use (&$contents) {
				$contents = $result;
			},
			$contents, $url, $auth_login, $auth_pass);

		if (empty($contents)) {
			if (preg_match("/cloudflare\.com/", UrlHelper::$fetch_last_error_content)) {
				UrlHelper::$fetch_last_error .= " (feed behind Cloudflare)";
			}

			return array("code" => 5, "message" => UrlHelper::$fetch_last_error);
		}

		if (str_contains(UrlHelper::$fetch_last_content_type, "html") && self::_is_html($contents)) {
			$feedUrls = self::_get_feeds_from_html($url, $contents);

			if (count($feedUrls) == 0) {
				return array("code" => 3);
			} else if (count($feedUrls) > 1) {
				return array("code" => 4, "feeds" => $feedUrls);
			}
			//use feed url as new URL
			$url = key($feedUrls);
		}

		$feed = ORM::for_table('ttrss_feeds')
			->where('feed_url', $url)
			->where('owner_uid', $_SESSION['uid'])
			->find_one();

		if ($feed) {
			return ["code" => 0, "feed_id" => $feed->id];
		} else {
			$feed = ORM::for_table('ttrss_feeds')->create();

			$feed->set([
				'owner_uid' => $_SESSION['uid'],
				'feed_url' => $url,
				'title' => "[Unknown]",
				'cat_id' => $cat_id ? $cat_id : null,
				'auth_login' => (string)$auth_login,
				'auth_pass' => (string)$auth_pass,
				'update_method' => 0,
				'update_interval' => $update_interval,
				'auth_pass_encrypted' => false,
			]);

			if ($feed->save()) {
				RSSUtils::update_basic_info($feed->id);
				return ["code" => 1, "feed_id" => (int) $feed->id];
			}

			return ["code" => 7];
		}
	}

	static function _get_icon_file(int $feed_id): string {
		$favicon_cache = DiskCache::instance('feed-icons');

		return $favicon_cache->get_full_path((string)$feed_id);
	}

	static function _get_icon_url(int $feed_id, string $fallback_url = "") : string {
		if (self::_has_icon($feed_id)) {
			$icon_url = Config::get_self_url() . "/public.php?" . http_build_query([
				'op' => 'feed_icon',
				'id' => $feed_id,
			]);

			return $icon_url;
		}

		return $fallback_url;
	}

	static function _has_icon(int $feed_id): bool {
		$favicon_cache = DiskCache::instance('feed-icons');

		return $favicon_cache->exists((string)$feed_id);
	}

	/**
	 * @return false|string false if the icon ID was unrecognized, otherwise, the icon identifier string
	 */
	static function _get_icon(int $id): false|string {
		return match ($id) {
			Feeds::FEED_ARCHIVED => 'archive',
			Feeds::FEED_STARRED => 'star',
			Feeds::FEED_PUBLISHED => 'rss_feed',
			Feeds::FEED_FRESH => 'whatshot',
			Feeds::FEED_ALL => 'inbox',
			Feeds::FEED_RECENTLY_READ => 'restore',
			default => $id < LABEL_BASE_INDEX ? 'label' : self::_get_icon_url($id),
		};
	}

	/**
	 * @return false|int false if the feed couldn't be found by URL+owner, otherwise the feed ID
	 */
	static function _find_by_url(string $feed_url, int $owner_uid): false|int {
		$feed = ORM::for_table('ttrss_feeds')
			->where('owner_uid', $owner_uid)
			->where('feed_url', $feed_url)
			->find_one();

		if ($feed) {
			return $feed->id;
		} else {
			return false;
		}
	}

	/**
	 * $owner_uid defaults to $_SESSION['uid']
	 *
	 * @return false|int false if the category/feed couldn't be found by title, otherwise its ID
	 */
	static function _find_by_title(string $title, bool $cat = false, int $owner_uid = 0): false|int {

		if ($cat) {
			$res = ORM::for_table('ttrss_feed_categories')
				->where('owner_uid', $owner_uid ? $owner_uid : $_SESSION['uid'])
				->where('title', $title)
				->find_one();
		} else {
			$res = ORM::for_table('ttrss_feeds')
				->where('owner_uid', $owner_uid ? $owner_uid : $_SESSION['uid'])
				->where('title', $title)
				->find_one();
		}

		if ($res) {
			return $res->id;
		} else {
			return false;
		}
	}

	static function _get_title(int|string $id, bool $cat = false): string {
		$pdo = Db::pdo();

		if ($cat) {
			return self::_get_cat_title($id);
		} else if ($id == Feeds::FEED_STARRED) {
			return __("Starred articles");
		} else if ($id == Feeds::FEED_PUBLISHED) {
			return __("Published articles");
		} else if ($id == Feeds::FEED_FRESH) {
			return __("Fresh articles");
		} else if ($id == Feeds::FEED_ALL) {
			return __("All articles");
		} else if ($id === Feeds::FEED_ARCHIVED) {
			return __("Archived articles");
		} else if ($id == Feeds::FEED_RECENTLY_READ) {
			return __("Recently read");
		} else if ($id < LABEL_BASE_INDEX) {

			$label_id = Labels::feed_to_label_id($id);

			$sth = $pdo->prepare("SELECT caption FROM ttrss_labels2 WHERE id = ?");
			$sth->execute([$label_id]);

			if ($row = $sth->fetch()) {
				return $row["caption"];
			} else {
				return "Unknown label ($label_id)";
			}

		} else if (is_numeric($id) && $id > 0) {

		    $sth = $pdo->prepare("SELECT title FROM ttrss_feeds WHERE id = ?");
		    $sth->execute([$id]);

		    if ($row = $sth->fetch()) {
				return $row["title"];
			} else {
				return "Unknown feed ($id)";
			}

		} else {
			return "$id";
		}
	}

	// only real cats
	static function _get_cat_marked(int $cat, int $owner_uid = 0): int {

		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

		$pdo = Db::pdo();

		if ($cat >= 0) {

			$sth = $pdo->prepare("SELECT SUM(CASE WHEN marked THEN 1 ELSE 0 END) AS marked
				FROM ttrss_user_entries
				WHERE feed_id IN (SELECT id FROM ttrss_feeds
                    WHERE (cat_id = :cat OR (:cat IS NULL AND cat_id IS NULL))
					AND owner_uid = :uid)
				AND owner_uid = :uid");

			$sth->execute(["cat" => $cat ? $cat : null, "uid" => $owner_uid]);

			if ($row = $sth->fetch()) {
				return (int) $row["marked"];
			}
		}
		return 0;
	}

	static function _get_cat_unread(int $cat, int $owner_uid = 0): int {

		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

		$pdo = Db::pdo();

		if ($cat >= 0) {

			$sth = $pdo->prepare("SELECT SUM(CASE WHEN unread THEN 1 ELSE 0 END) AS unread
				FROM ttrss_user_entries
				WHERE feed_id IN (SELECT id FROM ttrss_feeds
					WHERE (cat_id = :cat OR (:cat IS NULL AND cat_id IS NULL))
					AND owner_uid = :uid)
				AND owner_uid = :uid");

			$sth->execute(["cat" => $cat ? $cat : null, "uid" => $owner_uid]);

			if ($row = $sth->fetch()) {
				return (int) $row["unread"];
			}
		} else if ($cat == Feeds::CATEGORY_SPECIAL) {
			return 0;
		} else if ($cat == Feeds::CATEGORY_LABELS) {

			$sth = $pdo->prepare("SELECT COUNT(DISTINCT article_id) AS unread
				FROM ttrss_user_entries ue, ttrss_user_labels2 l
				WHERE article_id = ref_id AND unread IS true AND ue.owner_uid = :uid");

			$sth->execute(["uid" => $owner_uid]);

			if ($row = $sth->fetch()) {
				return (int) $row["unread"];
			}
		}

		return 0;
	}

	// only accepts real cats (>= 0)
	static function _get_cat_children_unread(int $cat, int $owner_uid = 0): int {
		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

		$pdo = Db::pdo();

		$sth = $pdo->prepare("SELECT id FROM ttrss_feed_categories WHERE parent_cat = ?
				AND owner_uid = ?");
		$sth->execute([$cat, $owner_uid]);

		$unread = 0;

		while ($line = $sth->fetch()) {
			$unread += self::_get_cat_unread($line["id"], $owner_uid);
			$unread += self::_get_cat_children_unread($line["id"], $owner_uid);
		}

		return $unread;
	}

	static function _get_global_unread(int $user_id = 0): int {

		if (!$user_id) $user_id = $_SESSION["uid"];

		$pdo = Db::pdo();

		$sth = $pdo->prepare("SELECT SUM(CASE WHEN unread THEN 1 ELSE 0 END) AS count
			FROM ttrss_user_entries ue
			WHERE ue.owner_uid = ?");

		$sth->execute([$user_id]);
		$row = $sth->fetch();

		// Handle 'SUM()' returning null if there are no articles/results (e.g. admin user with no feeds)
		return $row["count"] ?? 0;
	}

	static function _get_cat_title(int $cat_id): string {
		switch ($cat_id) {
			case Feeds::CATEGORY_UNCATEGORIZED:
				return __("Uncategorized");
			case Feeds::CATEGORY_SPECIAL:
				return __("Special");
			case Feeds::CATEGORY_LABELS:
				return __("Labels");
			default:
				$cat = ORM::for_table('ttrss_feed_categories')
					->find_one($cat_id);

				if ($cat) {
					return $cat->title;
				} else {
					return "UNKNOWN";
				}
		}
	}

	private static function _get_label_unread(int $label_id, ?int $owner_uid = null): int {
		if (!$owner_uid) $owner_uid = $_SESSION["uid"];

		$pdo = Db::pdo();

		$sth = $pdo->prepare("SELECT COUNT(ref_id) AS unread FROM ttrss_user_entries, ttrss_user_labels2
			WHERE owner_uid = ? AND unread = true AND label_id = ? AND article_id = ref_id");

		$sth->execute([$owner_uid, $label_id]);

		if ($row = $sth->fetch()) {
			return $row["unread"];
		} else {
			return 0;
		}
	}

	/**
	 * @param array<string, mixed> $params
	 * @return array<int, mixed> $result, $feed_title, $feed_site_url, $last_error, $last_updated, $highlight_words, $first_id, $is_vfeed, $query_error_override
	 */
	static function _get_headlines($params): array {
		$pdo = Db::pdo();

		// WARNING: due to highly dynamic nature of this query its going to quote parameters
		// right before adding them to SQL part

		$feed = $params["feed"];
		$limit = $params["limit"] ?? 30;
		$view_mode = $params["view_mode"];
		$cat_view = $params["cat_view"] ?? false;
		$search = $params["search"] ?? false;
		$search_language = $params["search_language"] ?? "";
		$override_order = $params["override_order"] ?? false;
		$offset = $params["offset"] ?? 0;
		$owner_uid = $params["owner_uid"] ?? $_SESSION["uid"];
		$profile = isset($_SESSION["uid"]) && $owner_uid == $_SESSION["uid"] && isset($_SESSION["profile"]) ? $_SESSION["profile"] : null;
		$since_id = $params["since_id"] ?? 0;
		$include_children = $params["include_children"] ?? false;
		$ignore_vfeed_group = $params["ignore_vfeed_group"] ?? false;
		$override_strategy = $params["override_strategy"] ?? false;
		$override_vfeed = $params["override_vfeed"] ?? false;
		$start_ts = $params["start_ts"] ?? false;
		$check_first_id = $params["check_first_id"] ?? false;
		$skip_first_id_check = $params["skip_first_id_check"] ?? false;
		//$order_by = $params["order_by"] ?? false;

		$ext_tables_part = "";
		$limit_query_part = "";
		$query_error_override = "";

		$search_words = [];

		if ($search) {
			$search_query_part = "";

			PluginHost::getInstance()->chain_hooks_callback(PluginHost::HOOK_SEARCH,
				function ($result) use (&$search_query_part, &$search_words) {
					if (!empty($result)) {
						list($search_query_part, $search_words) = $result;
						return true;
					}
				},
				$search);

			// fall back in case of no plugins
			if (!$search_query_part) {
				list($search_query_part, $search_words) = self::_search_to_sql($search, $search_language, $owner_uid, $profile);
			}

			$test_sth = $pdo->prepare("select $search_query_part
				FROM ttrss_entries, ttrss_user_entries WHERE id = ref_id limit 1");

			try {
				$test_sth->execute();
			} catch (PDOException $e) {
				// looks like tsquery syntax is invalid
				$search_query_part = "false";

				$query_error_override = T_sprintf("Incorrect search syntax: %s.", implode(" ", $search_words));
			}

			$search_query_part .= " AND ";
		} else {
			$search_query_part = "";
		}

		if ($since_id) {
			$since_id_part = "ttrss_entries.id > ".$pdo->quote($since_id)." AND ";
		} else {
			$since_id_part = "";
		}

		$view_query_part = "";

		if ($view_mode == "adaptive") {
			if ($search) {
				$view_query_part = " ";
			} else if ($feed != -1) {
				// not Feeds::FEED_STARRED or Feeds::CATEGORY_SPECIAL

				$unread = Feeds::_get_counters($feed, $cat_view, true);

				if ($cat_view && $feed > 0 && $include_children)
					$unread += self::_get_cat_children_unread($feed);

				if ($unread > 0) {
					$view_query_part = " unread = true AND ";
				}
			}
		}

		if ($view_mode == "marked") {
			$view_query_part = " marked = true AND ";
		}

		if ($view_mode == "has_note") {
			$view_query_part = " (note IS NOT NULL AND note != '') AND ";
		}

		if ($view_mode == "published") {
			$view_query_part = " published = true AND ";
		}

		if ($view_mode == "unread" && $feed != Feeds::FEED_RECENTLY_READ) {
			$view_query_part = " unread = true AND ";
		}

		if ($limit > 0) {
			$limit_query_part = "LIMIT " . (int)$limit;
		}

		$allow_archived = false;

		$vfeed_query_part = "";

		/* tags */
		if (!is_numeric($feed)) {
			$query_strategy_part = "true";
			$vfeed_query_part = "(SELECT title FROM ttrss_feeds WHERE
					id = feed_id) as feed_title,";
		} else if ($feed > 0) {

			if ($cat_view) {

				if ($feed > 0) {
					if ($include_children) {
						# sub-cats
						$subcats = self::_get_child_cats($feed, $owner_uid);
						array_push($subcats, $feed);
						$subcats = array_map("intval", $subcats);

						$query_strategy_part = "cat_id IN (".
							implode(",", $subcats).")";

					} else {
						$query_strategy_part = "cat_id = " . $pdo->quote((string)$feed);
					}

				} else {
					$query_strategy_part = "cat_id IS NULL";
				}

				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";

			} else {
				$query_strategy_part = "feed_id = " . $pdo->quote((string)$feed);
			}
		} else if ($feed == Feeds::FEED_ARCHIVED && !$cat_view) { // archive virtual feed
			$query_strategy_part = "feed_id IS NULL";
			$allow_archived = true;
		} else if ($feed == Feeds::CATEGORY_UNCATEGORIZED && $cat_view) { // uncategorized
			$query_strategy_part = "cat_id IS NULL AND feed_id IS NOT NULL";
			$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
		} else if ($feed == -1) { // starred virtual feed, Feeds::FEED_STARRED or Feeds::CATEGORY_SPECIAL
			$query_strategy_part = "marked = true";
			$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
			$allow_archived = true;

			if (!$override_order) {
				$override_order = "last_marked DESC, date_entered DESC, updated DESC";
			}

		} else if ($feed == -2) { // published virtual feed (Feeds::FEED_PUBLISHED) OR labels category (Feeds::CATEGORY_LABELS)

			if (!$cat_view) {
				$query_strategy_part = "published = true";
				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
				$allow_archived = true;

				if (!$override_order) {
					$override_order = "last_published DESC, date_entered DESC, updated DESC";
				}

			} else {
				$vfeed_query_part = "ttrss_feeds.title AS feed_title,";

				$ext_tables_part = "ttrss_labels2,ttrss_user_labels2,";

				$query_strategy_part = "ttrss_labels2.id = ttrss_user_labels2.label_id AND
						ttrss_user_labels2.article_id = ref_id";

			}
		} else if ($feed == Feeds::FEED_RECENTLY_READ) { // recently read
			$query_strategy_part = "unread = false AND last_read IS NOT NULL AND
				last_read > NOW() - INTERVAL '1 day'";

			$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
			$allow_archived = true;
			$ignore_vfeed_group = true;

			if (!$override_order) $override_order = "last_read DESC";

		} else if ($feed == Feeds::FEED_FRESH) { // fresh virtual feed
			$intl = (int) Prefs::get(Prefs::FRESH_ARTICLE_MAX_AGE, $owner_uid, $profile);

			$query_strategy_part = "unread = true AND score >= 0 AND
				date_entered > NOW() - INTERVAL '$intl hour'";

			$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
		} else if ($feed == Feeds::FEED_ALL) { // all articles virtual feed
			$allow_archived = true;
			$query_strategy_part = "true";
			$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
		} else if ($feed <= LABEL_BASE_INDEX) { // labels
			$label_id = Labels::feed_to_label_id($feed);

			$query_strategy_part = "label_id = $label_id AND
					ttrss_labels2.id = ttrss_user_labels2.label_id AND
					ttrss_user_labels2.article_id = ref_id";

			$vfeed_query_part = "ttrss_feeds.title AS feed_title,";
			$ext_tables_part = "ttrss_labels2,ttrss_user_labels2,";
			$allow_archived = true;

		} else {
			$query_strategy_part = "true";
		}

		$order_by = "score DESC, date_entered DESC, updated DESC";

		if ($override_order) {
			$order_by = $override_order;
		}

		if ($override_strategy) {
			$query_strategy_part = $override_strategy;
		}

		if ($override_vfeed) {
			$vfeed_query_part = $override_vfeed;
		}

		$feed_site_url = "";
		$last_error = "";
		$last_updated = "";

		if ($search) {
			$feed_title = T_sprintf("Search results: %s", $search);
		} else {
			if ($cat_view) {
				$feed_title = self::_get_cat_title($feed);
			} else {
				if (is_numeric($feed) && $feed > 0) {
					$ssth = $pdo->prepare("SELECT title,site_url,last_error,last_updated
							FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
					$ssth->execute([$feed, $owner_uid]);
					$row = $ssth->fetch();

					$feed_title = $row["title"];
					$feed_site_url = $row["site_url"];
					$last_error = $row["last_error"];
					$last_updated = $row["last_updated"];
				} else {
					$feed_title = self::_get_title($feed);
				}
			}
		}

		$content_query_part = "content, ";

		if ($limit_query_part) {
			$offset_query_part = "OFFSET " . (int)$offset;
		} else {
			$offset_query_part = "";
		}

		if ($start_ts) {
			$start_ts_formatted = date("Y/m/d H:i:s", strtotime($start_ts));
			$start_ts_query_part = "date_entered >= '$start_ts_formatted' AND";
		} else {
			$start_ts_query_part = "";
		}

		$first_id = 0;

		$yyiw_qpart = "to_char(date_entered, 'IYYY-IW') AS yyiw";

		if (is_numeric($feed)) {
			// proper override_order applied above
			if ($vfeed_query_part && !$ignore_vfeed_group
				&& Prefs::get(Prefs::VFEED_GROUP_BY_FEED, $owner_uid, $profile)) {

				if (!(in_array($feed, self::NEVER_GROUP_BY_DATE) && !$cat_view)) {
					$yyiw_desc = $order_by == "date_reverse" ? "" : "desc";
					$yyiw_order_qpart = "yyiw $yyiw_desc, ";
				} else {
					$yyiw_order_qpart = "";
				}

				if (!$override_order) {
					$order_by = "$yyiw_order_qpart ttrss_feeds.title, $order_by";
				} else {
					$order_by = "$yyiw_order_qpart ttrss_feeds.title, $override_order";
				}
			}

			if (!$allow_archived) {
				$from_qpart = "{$ext_tables_part}ttrss_entries LEFT JOIN ttrss_user_entries ON (ref_id = ttrss_entries.id), ttrss_feeds";
				$feed_check_qpart = "ttrss_user_entries.feed_id = ttrss_feeds.id AND";

			} else {
				$from_qpart = "{$ext_tables_part}ttrss_entries LEFT JOIN ttrss_user_entries ON (ref_id = ttrss_entries.id)
						LEFT JOIN ttrss_feeds ON (feed_id = ttrss_feeds.id)";
				$feed_check_qpart = "";
			}

			if ($vfeed_query_part) $vfeed_query_part .= "favicon_avg_color,";

			$first_id_query_strategy_part = $query_strategy_part;

			if ($feed == Feeds::FEED_FRESH)
				$first_id_query_strategy_part = "true";

			$distinct_columns = str_replace("desc", "", strtolower($order_by));
			$distinct_qpart = "DISTINCT ON (id, $distinct_columns)";

			// except for Labels category
			if (Prefs::get(Prefs::HEADLINES_NO_DISTINCT, $owner_uid, $profile)
				&& !($feed == Feeds::CATEGORY_LABELS && $cat_view)) {
				$distinct_qpart = "";
			}

			if (!$search && !$skip_first_id_check) {
				// if previous topmost article id changed that means our current pagination is no longer valid
				$query = "SELECT
							ttrss_entries.id,
							date_entered,
							$yyiw_qpart,
							guid,
							ttrss_entries.title,
							ttrss_feeds.title,
							updated,
							score,
							marked,
							published,
							last_marked,
							last_published,
							last_read
						FROM
							$from_qpart
						WHERE
						$feed_check_qpart
						ttrss_user_entries.owner_uid = ".$pdo->quote($owner_uid)." AND
						$search_query_part
						$start_ts_query_part
						$since_id_part
						date_entered >= NOW() - INTERVAL '1 hour' AND
						$first_id_query_strategy_part ORDER BY $order_by LIMIT 1";

				if (!empty($_REQUEST["debug"])) {
					print "\n*** FIRST ID QUERY ***\n$query\n";
				}

				$res = $pdo->query($query);

				if (!empty($res) && $row = $res->fetch()) {
					$first_id = (int)$row["id"];

					if ($offset > 0 && $first_id && $check_first_id && $first_id != $check_first_id) {
						return array(-1, $feed_title, $feed_site_url, $last_error, $last_updated, $search_words, $first_id, $vfeed_query_part != "", $query_error_override);
					}
				}
			}

			$query = "SELECT $distinct_qpart
						ttrss_entries.id AS id,
						date_entered,
						$yyiw_qpart,
						guid,
						ttrss_entries.title,
						updated,
						label_cache,
						tag_cache,
						always_display_enclosures,
						site_url,
						note,
						num_comments,
						comments,
						int_id,
						uuid,
						lang,
						hide_images,
						unread,feed_id,marked,published,link,last_read,
						last_marked, last_published,
						$vfeed_query_part
						$content_query_part
						author,score,
						(SELECT count(label_id) FROM ttrss_user_labels2 WHERE article_id = ttrss_entries.id) AS num_labels,
						(SELECT count(id) FROM ttrss_enclosures WHERE post_id = ttrss_entries.id) AS num_enclosures
					FROM
						$from_qpart
					WHERE
					$feed_check_qpart
					ttrss_user_entries.owner_uid = ".$pdo->quote($owner_uid)." AND
					$search_query_part
					$start_ts_query_part
					$view_query_part
					$since_id_part
					$query_strategy_part ORDER BY $order_by
					$limit_query_part $offset_query_part";

			//if ($_REQUEST["debug"]) print $query;

			if (!empty($_REQUEST["debug"])) {
				print "\n*** HEADLINES QUERY ***\n$query\n";
			}

			$res = $pdo->query($query);

		} else {
			// browsing by tag

			if (Prefs::get(Prefs::HEADLINES_NO_DISTINCT, $owner_uid, $profile)) {
				$distinct_qpart = "";
			} else {
				$distinct_columns = str_replace("desc", "", strtolower($order_by));
				$distinct_qpart = "DISTINCT ON (id, $distinct_columns)";
			}

			$query = "SELECT $distinct_qpart
							ttrss_entries.id AS id,
							date_entered,
							$yyiw_qpart,
							guid,
							ttrss_entries.title,
							updated,
							label_cache,
							tag_cache,
							always_display_enclosures,
							site_url,
							note,
							num_comments,
							comments,
							int_id,
							uuid,
							lang,
							hide_images,
							unread,feed_id,marked,published,link,last_read,
							last_marked, last_published,
							$since_id_part
							$vfeed_query_part
							$content_query_part
							author, score,
							(SELECT count(label_id) FROM ttrss_user_labels2 WHERE article_id = ttrss_entries.id) AS num_labels,
							(SELECT count(id) FROM ttrss_enclosures WHERE post_id = ttrss_entries.id) AS num_enclosures
						FROM ttrss_entries,
							ttrss_user_entries LEFT JOIN ttrss_feeds ON (ttrss_feeds.id = ttrss_user_entries.feed_id),
							ttrss_tags
						WHERE
							ref_id = ttrss_entries.id AND
							ttrss_user_entries.owner_uid = ".$pdo->quote($owner_uid)." AND
							post_int_id = int_id AND
							tag_name = ".$pdo->quote($feed)." AND
							$view_query_part
							$search_query_part
							$start_ts_query_part
							$query_strategy_part ORDER BY $order_by
							$limit_query_part $offset_query_part";

			//if ($_REQUEST["debug"]) print $query;

			if (!empty($_REQUEST["debug"])) {
				print "\n*** TAGS QUERY ***\n$query\n";
			}

			$res = $pdo->query($query);
		}

		return array($res, $feed_title, $feed_site_url, $last_error, $last_updated, $search_words, $first_id, $vfeed_query_part != "", $query_error_override);
	}

	/**
	 * @return array<int, int>
	 */
	static function _get_parent_cats(int $cat, int $owner_uid): array {
		$rv = array();

		$pdo = Db::pdo();

		$sth = $pdo->prepare("SELECT parent_cat FROM ttrss_feed_categories
			WHERE id = ? AND parent_cat IS NOT NULL AND owner_uid = ?");
		$sth->execute([$cat, $owner_uid]);

		while ($line = $sth->fetch()) {
			$cat = (int) $line["parent_cat"];
			array_push($rv, $cat, ...self::_get_parent_cats($cat, $owner_uid));
		}

		return $rv;
	}

	/**
	 * @return array<int, int>
	 */
	static function _get_child_cats(int $cat, int $owner_uid): array {
		$rv = array();

		$pdo = Db::pdo();

		$sth = $pdo->prepare("SELECT id FROM ttrss_feed_categories
			WHERE parent_cat = ? AND owner_uid = ?");
		$sth->execute([$cat, $owner_uid]);

		while ($line = $sth->fetch()) {
			array_push($rv, $line["id"], ...self::_get_child_cats($line["id"], $owner_uid));
		}

		return $rv;
	}

	/**
	 * @param array<int, int> $feeds
	 * @return array<int, int>
	 */
	static function _cats_of(array $feeds, int $owner_uid, bool $with_parents = false): array {
		if (count($feeds) == 0)
			return [];

		$pdo = Db::pdo();

		$feeds_qmarks = arr_qmarks($feeds);

		$sth = $pdo->prepare("SELECT DISTINCT cat_id, fc.parent_cat FROM ttrss_feeds f LEFT JOIN ttrss_feed_categories fc
				ON (fc.id = f.cat_id)
				WHERE f.owner_uid = ? AND f.id IN ($feeds_qmarks)");
		$sth->execute([$owner_uid, ...$feeds]);

		$rv = [];

		if ($row = $sth->fetch()) {
			$cat_id = (int) $row["cat_id"];
			$rv[] = $cat_id;
			array_push($rv, (int)$row["cat_id"]);

			if ($with_parents && $row["parent_cat"]) {
				array_push($rv, ...self::_get_parent_cats($cat_id, $owner_uid));
			}
		}

		$rv = array_unique($rv);

		return $rv;
	}

	// returns Uncategorized as 0
	static function _cat_of(int $feed) : int {
		$feed = ORM::for_table('ttrss_feeds')->find_one($feed);

		if ($feed) {
			return (int)$feed->cat_id;
		} else {
			return -1;
		}
	}

	private function _color_of(string $name): string {
		$colormap = [ "#1cd7d7","#d91111","#1212d7","#8e16e5","#7b7b7b",
			"#39f110","#0bbea6","#ec0e0e","#1534f2","#b9e416",
			"#479af2","#f36b14","#10c7e9","#1e8fe7","#e22727" ];

		$sum = 0;

		for ($i = 0; $i < strlen($name); $i++) {
			$sum += ord($name[$i]);
		}

		$sum %= count($colormap);

		return $colormap[$sum];
	}

	/**
	 * @return array<string, string> array of feed URL -> feed title
	 */
	private static function _get_feeds_from_html(string $url, string $content): array {
		$url = UrlHelper::validate($url);
		$baseUrl = substr($url, 0, strrpos($url, '/') + 1);

		$feedUrls = [];

		$doc = new DOMDocument();
		if (@$doc->loadHTML($content)) {
			$xpath = new DOMXPath($doc);
			$entries = $xpath->query('/html/*[self::head or self::body]/link[@rel="alternate" and '.
				'(contains(@type,"rss") or contains(@type,"atom"))]|/html/*[self::head or self::body]/link[@rel="feed"]');

			/** @var DOMElement|null $entry */
			foreach ($entries as $entry) {
				if ($entry->hasAttribute('href')) {
					$title = $entry->getAttribute('title');
					if ($title == '') {
						$title = $entry->getAttribute('type');
					}
					$feedUrl = UrlHelper::rewrite_relative($baseUrl, $entry->getAttribute('href'));
					$feedUrls[$feedUrl] = $title;
				}
			}
		}
		return $feedUrls;
	}

	static function _is_html(string $content): bool {
		return preg_match("/<html|DOCTYPE html/i", substr($content, 0, 8192)) !== 0;
	}

	static function _remove_cat(int $id, int $owner_uid): void {
		$cat = ORM::for_table('ttrss_feed_categories')
			->where('owner_uid', $owner_uid)
			->find_one($id);

		if ($cat)
			$cat->delete();
	}

	static function _add_cat(string $title, int $owner_uid, ?int $parent_cat = null, int $order_id = 0): bool {

		$cat = ORM::for_table('ttrss_feed_categories')
			->where('owner_uid', $owner_uid)
			->where('parent_cat', $parent_cat)
			->where('title', $title)
			->find_one();

		if (!$cat) {
			$cat = ORM::for_table('ttrss_feed_categories')->create();

			$cat->set([
				'owner_uid' => $owner_uid,
				'parent_cat' => $parent_cat,
				'order_id' => $order_id,
				'title' => $title,
			]);

			return $cat->save();
		}

		return false;
	}

	static function _clear_access_keys(int $owner_uid): void {
		ORM::for_table('ttrss_access_keys')
			->where('owner_uid', $owner_uid)
			->delete_many();
	}

	/**
	 * @param string $feed_id may be a feed ID or tag
	 *
	 * @see Handler_Public#generate_syndicated_feed()
	 */
	static function _update_access_key(string $feed_id, bool $is_cat, int $owner_uid): ?string {
		ORM::for_table('ttrss_access_keys')
			->where('owner_uid', $owner_uid)
			->where('feed_id', $feed_id)
			->where('is_cat', $is_cat)
			->delete_many();

		return self::_get_access_key($feed_id, $is_cat, $owner_uid);
	}

	/**
	 * @param string $feed_id may be a feed ID or tag
	 *
	 * @see Handler_Public#generate_syndicated_feed()
	 */
	static function _get_access_key(string $feed_id, bool $is_cat, int $owner_uid): ?string {
		$key = ORM::for_table('ttrss_access_keys')
			->where('owner_uid', $owner_uid)
			->where('feed_id', $feed_id)
			->where('is_cat', $is_cat)
			->find_one();

		if ($key) {
			return $key->access_key;
		}

		$key = ORM::for_table('ttrss_access_keys')->create();

		$key->owner_uid = $owner_uid;
		$key->feed_id = $feed_id;
		$key->is_cat = $is_cat;
		$key->access_key = uniqid_short();

		if ($key->save()) {
			return $key->access_key;
		}

		return null;
	}

	static function _purge(int $feed_id, int $purge_interval): ?int {

		if (!$purge_interval) $purge_interval = self::_get_purge_interval($feed_id);

		$pdo = Db::pdo();
		$rows_deleted = 0;

		$sth = $pdo->prepare("SELECT owner_uid FROM ttrss_feeds WHERE id = ?");
		$sth->execute([$feed_id]);

		if ($row = $sth->fetch()) {
			$owner_uid = $row["owner_uid"];


			if (Config::get(Config::FORCE_ARTICLE_PURGE) != 0) {
				Debug::log("purge_feed: FORCE_ARTICLE_PURGE is set, overriding interval to " . Config::get(Config::FORCE_ARTICLE_PURGE), Debug::LOG_VERBOSE);
				$purge_unread = true;
				$purge_interval = Config::get(Config::FORCE_ARTICLE_PURGE);
			} else {
				$purge_unread = Prefs::get(Prefs::PURGE_UNREAD_ARTICLES, $owner_uid);
			}

			$purge_interval = (int) $purge_interval;

			Debug::log("purge_feed: interval $purge_interval days for feed $feed_id, owner: $owner_uid, purge unread: $purge_unread", Debug::LOG_VERBOSE);

			if ($purge_interval <= 0) {
				Debug::log("purge_feed: purging disabled for this feed, nothing to do.", Debug::LOG_VERBOSE);
				return null;
			}

			if (!$purge_unread)
				$query_limit = " unread = false AND ";
			else
				$query_limit = "";

			$sth = $pdo->prepare("DELETE FROM ttrss_user_entries
				USING ttrss_entries
				WHERE ttrss_entries.id = ref_id AND
				marked = false AND
				feed_id = ? AND
				$query_limit
				ttrss_entries.date_updated < NOW() - INTERVAL '$purge_interval days'");
			$sth->execute([$feed_id]);

			$rows_deleted = $sth->rowCount();

			Debug::log("purge_feed: deleted $rows_deleted articles.", Debug::LOG_VERBOSE);

		} else {
			Debug::log("purge_feed: owner of $feed_id not found", Debug::LOG_VERBOSE);
		}

		return $rows_deleted;
	}

	private static function _get_purge_interval(int $feed_id): int {
		$feed = ORM::for_table('ttrss_feeds')->find_one($feed_id);

		if ($feed) {
			if ($feed->purge_interval != 0)
				return $feed->purge_interval;
			else
				return Prefs::get(Prefs::PURGE_OLD_DAYS, $feed->owner_uid);
		} else {
			return -1;
		}
	}

	/**
	 * @return array{0: string, 1: array<int, string>} [$search_query_part, $search_words]
	 */
	private static function _search_to_sql(string $search, string $search_language, int $owner_uid, ?int $profile): array {
		// Modify the search string so that 'keyword:"foo bar"' becomes '"keyword:foo bar"'.
		// This is needed so potential command pairs are grouped correctly.
		$search_csv_str = preg_replace('/(-?\w+)\:"(\w+)/', '"$1:$2', trim($search));

		// '-"hello world"' --> '"-hello world"' so negated phrases work
		$search_csv_str = preg_replace('/-"([^"]+?")/', '"-$1', $search_csv_str);

		// $keywords will be an array like ['"title:hello world"', 'some', 'words']
		$keywords = str_getcsv($search_csv_str, ' ', '"', '');

		$query_keywords = array();
		$search_words = array();
		$search_query_leftover = array();

		$pdo = Db::pdo();

		/** @var string $k a keyword pair (not yet split) or standalone value */
		foreach ($keywords as $k) {
			if (str_starts_with($k, "-")) {
				$k = substr($k, 1);
				$not = "NOT";
			} else {
				$not = "";
			}

			$k = trim($k);

			$keyword_pair = explode(':', mb_strtolower($k), 2);
			$keyword_name = $keyword_pair[0];
			$keyword_value = empty($keyword_pair[1]) ? '' : trim($keyword_pair[1]);

			// NOTE: If there's a keyword match but no keyword value we fall back to doing
			// a search in article title and content.
			switch ($keyword_name) {
				case "title":
					if ($keyword_value) {
						array_push($query_keywords, "($not (LOWER(ttrss_entries.title) LIKE ".
							$pdo->quote("%{$keyword_value}%") ."))");
					} else {
						array_push($query_keywords, "(UPPER(ttrss_entries.title) $not LIKE UPPER(".$pdo->quote("%$k%").")
								OR UPPER(ttrss_entries.content) $not LIKE UPPER(".$pdo->quote("%$k%")."))");
						array_push($search_words, $k);
					}
					break;
				case "author":
					if ($keyword_value) {
						array_push($query_keywords, "($not (LOWER(author) LIKE ".
							$pdo->quote("%{$keyword_value}%")."))");
					} else {
						array_push($query_keywords, "(UPPER(ttrss_entries.title) $not LIKE UPPER(".$pdo->quote("%$k%").")
								OR UPPER(ttrss_entries.content) $not LIKE UPPER(".$pdo->quote("%$k%")."))");
						array_push($search_words, $k);
					}
					break;
				case "note":
					if ($keyword_value) {
						if ($keyword_value == "true")
							array_push($query_keywords, "($not (note IS NOT NULL AND note != ''))");
						else if ($keyword_value == "false")
							array_push($query_keywords, "($not (note IS NULL OR note = ''))");
						else
							array_push($query_keywords, "($not (LOWER(note) LIKE ".
								$pdo->quote("%{$keyword_value}%")."))");
					} else {
						array_push($query_keywords, "(UPPER(ttrss_entries.title) $not LIKE UPPER(".$pdo->quote("%$k%").")
								OR UPPER(ttrss_entries.content) $not LIKE UPPER(".$pdo->quote("%$k%")."))");
						if (!$not) array_push($search_words, $k);
					}
					break;
				case "star":
					if ($keyword_value) {
						if ($keyword_value == "true")
							array_push($query_keywords, "($not (marked = true))");
						else
							array_push($query_keywords, "($not (marked = false))");
					} else {
						array_push($query_keywords, "(UPPER(ttrss_entries.title) $not LIKE UPPER(".$pdo->quote("%$k%").")
								OR UPPER(ttrss_entries.content) $not LIKE UPPER(".$pdo->quote("%$k%")."))");
						if (!$not) array_push($search_words, $k);
					}
					break;
				case "pub":
					if ($keyword_value) {
						if ($keyword_value == "true")
							array_push($query_keywords, "($not (published = true))");
						else
							array_push($query_keywords, "($not (published = false))");
					} else {
						array_push($query_keywords, "(UPPER(ttrss_entries.title) $not LIKE UPPER('%$k%')
								OR UPPER(ttrss_entries.content) $not LIKE UPPER(".$pdo->quote("%$k%")."))");
						if (!$not) array_push($search_words, $k);
					}
					break;
				case "label":
					if ($keyword_value) {
						$label_id = Labels::find_id($keyword_value, $owner_uid);

						if ($label_id) {
							array_push($query_keywords, "($not
								(ttrss_entries.id IN (
									SELECT article_id FROM ttrss_user_labels2 WHERE
										label_id = $label_id)))");
						} else {
							array_push($query_keywords, "(false)");
						}
					} else {
						array_push($query_keywords, "(UPPER(ttrss_entries.title) $not LIKE UPPER(".$pdo->quote("%$k%").")
								OR UPPER(ttrss_entries.content) $not LIKE UPPER(".$pdo->quote("%$k%")."))");
						if (!$not) array_push($search_words, $k);
					}
					break;
				case "unread":
					if ($keyword_value) {
						if ($keyword_value == "true")
							array_push($query_keywords, "($not (unread = true))");
						else
							array_push($query_keywords, "($not (unread = false))");

					} else {
						array_push($query_keywords, "(UPPER(ttrss_entries.title) $not LIKE UPPER(".$pdo->quote("%$k%").")
								OR UPPER(ttrss_entries.content) $not LIKE UPPER(".$pdo->quote("%$k%")."))");
						if (!$not) array_push($search_words, $k);
					}
					break;
				default:
					// @{date} handling
					if (str_starts_with($k, "@")) {
						$user_tz_string = Prefs::get(Prefs::USER_TIMEZONE, $owner_uid);
						$orig_ts = strtotime(substr($k, 1));
						$k = date("Y-m-d", TimeHelper::convert_timestamp($orig_ts, $user_tz_string, 'UTC'));

						array_push($query_keywords, "(SUBSTRING_FOR_DATE(updated,1,LENGTH(".$pdo->quote($k).")) $not = ".$pdo->quote($k).")");
					} else {
						// treat as leftover text

						$k = mb_strtolower($k);

						// A hacky way for phrases (e.g. "hello world") to get through PDO quoting.
						// Term '"foo bar baz"' becomes '(foo <-> bar <-> baz)' ("<->" meaning "immediately followed by").
						if (preg_match('/\s+/', $k))
							$k = '(' . preg_replace('/\s+/', ' <-> ', $k) . ')';

						array_push($search_query_leftover, $not ? "!$k" : $k);

						if (!$not) array_push($search_words, $k);
					}
			}
		}

		if (count($search_query_leftover) > 0) {

			// if there's no joiners consider this a "simple" search and
			// concatenate everything with &, otherwise don't try to mess with tsquery syntax
			if (preg_match("/[&|]/", implode(" " , $search_query_leftover))) {
				$tsquery = $pdo->quote(implode(" ", $search_query_leftover));
			} else {
				$tsquery = $pdo->quote(implode(" & ", $search_query_leftover));
			}

			$search_language = $pdo->quote(mb_strtolower($search_language ?: Prefs::get(Prefs::DEFAULT_SEARCH_LANGUAGE, $owner_uid, $profile)));

			array_push($query_keywords,
				"(tsvector_combined @@ to_tsquery($search_language, $tsquery))");
		}

		if (count($query_keywords) > 0)
			$search_query_part = implode("AND ", $query_keywords);
		else
			$search_query_part = "false";

		if (!empty($_REQUEST["debug"])) {
			print "\n*** SEARCH_TO_SQL ***\n";
			print "QUERY: $search_query_part\n";
			print "WORDS: " . json_encode($search_words) . "\n";
		}

		return array($search_query_part, $search_words);
	}

	/**
	 * @return array{0: string, 1: bool}
	 */
	static function _order_to_override_query(string $order): array {
		$query = "";
		$skip_first_id = false;

		PluginHost::getInstance()->chain_hooks_callback(PluginHost::HOOK_HEADLINES_CUSTOM_SORT_OVERRIDE,
			function ($result) use (&$query, &$skip_first_id) {
				list ($query, $skip_first_id) = $result;

				// run until first hard match
				return !empty($query);
			},
			$order);

		if (is_string($query) && $query !== "") {
			return [$query, $skip_first_id];
		}

		switch ($order) {
			case "title":
				$query = "ttrss_entries.title, date_entered, updated";
				break;
			case "date_reverse":
				$query = "updated";
				$skip_first_id = true;
				break;
			case "feed_dates":
				$query = "updated DESC";
				break;
		}

		return [$query, $skip_first_id];
	}


	/** decrypts encrypted feed password if possible (key is available and data is a base64-encoded serialized object)
	 *
	 * @param $auth_pass possibly encrypted feed password
	 *
	 * @return string plaintext representation of an encrypted feed password if encrypted or plaintext password otherwise
	 * */
	static function decrypt_feed_pass(string $auth_pass) : string {
		$key = Config::get(Config::ENCRYPTION_KEY);

		if ($auth_pass && $key) {
			$auth_pass_serialized = @base64_decode($auth_pass);

			if ($auth_pass_serialized) {
				$unserialized_data = @unserialize($auth_pass_serialized);

				if ($unserialized_data !== false)
					return Crypt::decrypt_string($unserialized_data);
			}
		}

		return $auth_pass;
	}

}

