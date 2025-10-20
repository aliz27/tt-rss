<?php
class Af_Embed extends Plugin {
    function about() {
        return array(null,
            "Embeds media from various sources",
            "aliz");
    }

    function init($host) {
        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
    }

	function api_version() {
		return 2;
	}

    function hook_article_filter($article) {
        Debug::log("Af_Embed: Processing article with link: " . $article["link"], Debug::LOG_VERBOSE);

        if (str_contains($article["link"], "/bofh_")) {
            $res = UrlHelper::fetch([
		'url' => $article['link'],
		'useragent' => 'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; WOW64; Trident/6.0)',
    	    ]);

    	    $doc = new DOMDocument();

  	    if ($res && $doc->loadHTML($res)) {
		$xpath = new DOMXPath($doc);
		$remove = [];

		foreach($doc->getElementsByTagName('ul') as $node)
			$remove[] = $node;

		foreach($remove as $rem)
                	$rem->parentNode->removeChild($rem);

		$basenode = $xpath->query('(//div[@class="article_wrap"]/div[@class="centre_col"])')->item(0);

		if ($basenode) {
			$article["content"] = $doc->saveHTML($basenode);
		}
            }
        }

        if (str_contains($article["link"], "reddit.com/")) {
            Debug::log("Af_Embed: Processing Reddit link", Debug::LOG_VERBOSE);
            $res = UrlHelper::fetch([
                'url' => $article['link'].'.json',
                'useragent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0',
            ]);

            $post = json_decode($res, true);

            if (array_key_exists('post_hint', $post[0]['data']['children'][0]['data'])) {
                $post_hint = $post[0]['data']['children'][0]['data']['post_hint'] ?? '';
                Debug::log("Af_Embed: Found Reddit post hint: " . $post_hint, Debug::LOG_VERBOSE);
                $embedded = false;

                if ($post_hint == 'rich:video') {
                    Debug::log("Af_Embed: Found Reddit rich video post", Debug::LOG_VERBOSE);
                    $video_url = $post[0]['data']['children'][0]['data']['url_overridden_by_dest'] ?? '';
                    Debug::log("Af_Embed: Found Reddit rich video URL: $video_url", Debug::LOG_VERBOSE);
                    if ($video_url) {
                        $article["link"] = $video_url;
                        $article["enclosures"] = [];
                        $embedded = true;
                    } else {
                        Debug::log("Af_Embed: No rich video URL found in Reddit post" . var_dump($post[0]['data']['children'][0]['data']), Debug::LOG_VERBOSE);
                    }
                }

                if ($post_hint == 'hosted:video') {
                    Debug::log("Af_Embed: Found Reddit hosted video post", Debug::LOG_VERBOSE);
                    $video_url = $post[0]['data']['children'][0]['data']['secure_media']['reddit_video']['fallback_url'] ?? '';
                    $preview = $post[0]['data']['children'][0]['data']['preview']['images'][0]['source']['url'] ?? '';
                    Debug::log("Af_Embed: Found Reddit hosted video URL: $video_url", Debug::LOG_VERBOSE);
                    if ($video_url) {
                        $article["content"] = "<video controls><source src=\"$video_url\" type=\"video/mp4\" poster=\"$preview\"></video>";
                        $article["enclosures"] = [];
                        $embedded = true;
                    } else {
                        Debug::log("Af_Embed: No hosted video URL found in Reddit post" . var_dump($post[0]['data']['children'][0]['data']), Debug::LOG_VERBOSE);
                    }
                }

                if ($post_hint == 'image') {
                    Debug::log("Af_Embed: Found Reddit image post", Debug::LOG_VERBOSE);
                    $image_url = $post[0]['data']['children'][0]['data']['url'] ?? '';
                    Debug::log("Af_Embed: Found Reddit image URL: $image_url", Debug::LOG_VERBOSE);
                    if ($image_url) {
                        $article["content"] = "<img src=\"$image_url\" alt=\"Reddit Image\">";
                        $article["enclosures"] = [];
                        $embedded = true;
                    } else {
                        Debug::log("Af_Embed: No image URL found in Reddit post" . var_dump($post[0]['data']['children'][0]['data']), Debug::LOG_VERBOSE);
                    }
                }

                if ($post_hint == 'link') {
                    Debug::log("Af_Embed: Found Reddit link post", Debug::LOG_VERBOSE);
                    $preview = $post[0]['data']['children'][0]['data']['preview']['images'][0]['source']['url'] ?? '';
                    $article["content"] = "<img src=\"$preview\" alt=\"Reddit Image\">";
                    $article["content"] .= "<p><a href=\"" . $post[0]['data']['children'][0]['data']['url'] . "\">" . $post[0]['data']['children'][0]['data']['url'] . "</a></p>";
                }
                
                if (array_key_exists('selftext_html', $post[0]['data']['children'][0]['data']) && $embedded) {
                    $selftext_html = $post[0]['data']['children'][0]['data']['selftext_html'] ?? '';
                    Debug::log("Af_Embed: Found Reddit selftext HTML: $selftext_html", Debug::LOG_VERBOSE);
                    if ($selftext_html) {
                        $article["content"] .= htmlspecialchars_decode($selftext_html);
                    }
                }
            }
            elseif (array_key_exists('is_gallery', $post[0]['data']['children'][0]['data']) && $post[0]['data']['children'][0]['data']['is_gallery'] == 'true') {
                Debug::log("Af_Embed: Found gallery", Debug::LOG_VERBOSE);
                $article["content"] = "";
                $article["enclosures"] = [];

                foreach ($post[0]['data']['children'][0]['data']['gallery_data']['items'] as $gallery_item) {
                        $_url = $post[0]['data']['children'][0]['data']['media_metadata'][$gallery_item['media_id']]['s']['u'];
                        Debug::log("Af_Embed: Gallery image: $_url", Debug::LOG_VERBOSE);
                        $article["content"] .= "<img src=\"$_url\" alt=\"Reddit Image\">";
                }
            }
            elseif (array_key_exists('url', $post[0]['data']['children'][0]['data'])) {
                $url = $post[0]['data']['children'][0]['data']['url'] ?? '';
                Debug::log("Af_Embed: Found URL: $url", Debug::LOG_VERBOSE);
                if (str_ends_with($url, '.jpg') || str_ends_with($url, '.png') || str_ends_with($url, '.gif') || str_ends_with($url, '.jpeg')) {
                        $article["content"] = "<img src=\"$url\" alt=\"Reddit Image\">";
                        $article["enclosures"] = [];
                        $embedded = true;
                } else {
                    Debug::log("Af_Embed: No URL found in Reddit post", Debug::LOG_VERBOSE);
                }
            }
        }

        if ($vid_id = UrlHelper::url_to_youtube_vid($article["link"])) {
            Debug::log("Af_Embed: Found YouTube video ID: $vid_id", Debug::LOG_VERBOSE);
            $enclosure = new FeedEnclosure();
            $enclosure->link = "https://img.youtube.com/vi/$vid_id/hqdefault.jpg";
            $enclosure->type = "image/jpeg";
//            $article["enclosures"] = array();
            array_push($article["enclosures"], $enclosure);
       }
//        print_r($article);
        return $article;
    }
}
