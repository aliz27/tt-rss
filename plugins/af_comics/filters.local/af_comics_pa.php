<?php
class Af_Comics_Pa extends Af_ComicFilter {

	function supported() {
		return array("Penny Arcade");
	}

	function process(&$article) {
		if (str_contains($article["guid"], "penny-arcade.com/comic")) {

				// lol at people who block clients by user agent
				// oh noes my ad revenue Q_Q

				$res = UrlHelper::fetch([
					'url' => $article['link'],
					'useragent' => 'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; WOW64; Trident/6.0)',
				]);

				$doc = new DOMDocument();

				if ($res && $doc->loadHTML($res)) {
					$xpath = new DOMXPath($doc);
					$basenode = $xpath->query('(//div[@class="comic-area"]/a/div[1]/img)')->item(0);

                    preg_match('/assets\.penny-arcade\.com\/comics\/panels\/([\d]+\-.+)\-p[\d]\.jpg/i',$basenode->getAttribute('src'),$a);
					$basenode->setAttribute('src', 'https://assets.penny-arcade.com/comics/'.$a[1].'.jpg');
					$basenode->removeAttribute('srcset');

					if ($basenode) {
						$article["content"] = $doc->saveHTML($basenode);
					}
				}

			 return true;
		}

		return false;
	}
}
