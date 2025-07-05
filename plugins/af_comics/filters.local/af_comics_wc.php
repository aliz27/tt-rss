<?php
class Af_Comics_Wc extends Af_ComicFilter {

	function supported() {
		return array("Work Chronicles");
	}

	function process(&$article) {
		if (str_contains($article["guid"], "workchronicles.substack.com")) {
			if (str_contains($article["title"], "(comic) ")) {
				$article["title"] = str_replace("(comic) ", "", $article["title"]);
				return true;
			}
		}

		return false;
	}
}
