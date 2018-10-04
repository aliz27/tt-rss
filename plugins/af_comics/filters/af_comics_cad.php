<?php
class Af_Comics_Cad extends Af_ComicFilter {

	function supported() {
		return array("Ctrl+Alt+Del");
	}

	function process(&$article) {
		$owner_uid = $article["owner_uid"];

		if (strpos($article["link"], "cad-comic.com/sillies/") !== FALSE) {
			$doc = new DOMDocument();
			@$doc->loadHTML(fetch_file_contents($article["link"]));

			$basenode = false;

			if ($doc) {
				$xpath = new DOMXPath($doc);
				$basenode = $xpath->query('(//img[contains(@src, "/comics/sillies-")])')->item(0);

				if ($basenode) {
					$article["content"] = $doc->saveXML($basenode);
				} else {
					$article["failed"] = true;
				}
			}
		}
		if (strpos($article["link"], "cad-comic.com/cad/") !== FALSE) {
			if (strpos($article["title"], "News:") === FALSE) {

				global $fetch_last_error_content;

				$doc = new DOMDocument();

				$res = fetch_file_contents($article["link"], false, false, false,
					false, false, 0,
					"Mozilla/5.0 (Windows NT 6.1; WOW64; rv:50.0) Gecko/20100101 Firefox/50.0");

				if (!$res && $fetch_last_error_content)
					$res = $fetch_last_error_content;

				if (@$doc->loadHTML($res)) {
					$xpath = new DOMXPath($doc);
					$basenode = $xpath->query('//div[@class="comicpage"]/a/img')->item(0);

					if ($basenode) {
						$article["content"] = $doc->saveXML($basenode);
					} else {
						$article["failed"] = true;
					}
				}

			}

			return true;
		}

		return false;
	}
}
