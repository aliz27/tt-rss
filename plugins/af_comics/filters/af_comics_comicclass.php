<?php
class Af_Comics_ComicClass extends Af_ComicFilter {

	function supported() {
		return array("Loading Artist");
	}

	function process(&$article) {
		if (str_contains($article["guid"], "loadingartist.com/comic")) {

				// lol at people who block clients by user agent
				// oh noes my ad revenue Q_Q

				$res = UrlHelper::fetch([
					'url' => $article['link'],
					'useragent' => 'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; WOW64; Trident/6.0)',
				]);

				$doc = new DOMDocument();

				if ($res && $doc->loadHTML($res)) {
					$xpath = new DOMXPath($doc);
					$basenode = $xpath->query('//div[@class="main-image-container"]')->item(0);

					if ($basenode) {
						$article["content"] = $doc->saveHTML($basenode);
					}
				}

			 return true;
		}

		return false;
	}
}
