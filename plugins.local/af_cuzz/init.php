<?php
class Af_Cuzz extends Plugin {
    function about() {
        return array(null,
            "Resolves cuzz.cazooka.se links",
            "aliz");
    }

    function init($host) {
        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
    }

	function api_version() {
		return 2;
	}

    function hook_article_filter($article) {
        if (str_contains($article["link"], "cuzz.cazooka.se/open.php")) {
            $res = UrlHelper::fetch([
                'url' => $article['link'],
                'useragent' => 'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; WOW64; Trident/6.0)',
            ]);
            $doc = new DOMDocument();

            if ($res && $doc->loadHTML($res)) {
                $xpath = new DOMXPath($doc);

                $scripts = $xpath->query("//script");
                foreach ($scripts as $script) {
                    if (preg_match('/window.location = \'(.*)\'/', $script->nodeValue, $matches)) {
                        Debug::log("Af_Cuzz: New link: ".$matches[1], Debug::LOG_VERBOSE);
                        $article["link"] = $matches[1];
                    }
                }
            }
        }

        return $article;
    }
}