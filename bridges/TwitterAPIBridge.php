<?php
class TwitterAPIBridge extends BridgeAbstract {
	const NAME = 'Twitter API Bridge';
	const URI = 'https://github.com/fulmeek';
	const DESCRIPTION = 'Stay tuned on Twitter';
	const MAINTAINER = 'fulmeek';
	const PARAMETERS = array(array(
		'user' => array(
			'name' => 'Screen Name',
			'type' => 'text',
			'required' => true
		),
		'replies' => array(
			'name' => 'Include Replies',
			'type' => 'checkbox'
		),
		'retweets' => array(
			'name' => 'Include Retweets',
			'type' => 'checkbox'
		)
	));

	const API_ENDPOINT = 'https://api.twitter.com/';
	const API_BASELINK = 'https://twitter.com/';
	const API_STATUSLINK = 'https://twitter.com/i/web/status/';
	const API_HASHTAGLINK = 'https://twitter.com/hashtag/';
	const MAX_TITLE = 64;

	private $apiToken;

	private $author;
	private $title;
	private $link;
	private $description;

	public function collectData() {
		$this->getApiConfig();

		$user = $this->getInput('user');

		if (empty($this->apiToken)) {
			returnServerError('API credentials required, please see configuration');
		}
		if (empty($user)) {
			returnServerError('Please provide a screen name');
		}

		$apiParams = array('1.1/statuses/user_timeline.json?count=150', 'tweet_mode=extended');
		$apiParams[] = 'screen_name=' . $user;
		$apiParams[] = 'exclude_replies=' . ((!empty($this->getInput('replies'))) ? 'false' : 'true');
		$apiParams[] = 'include_rts=' . ((!empty($this->getInput('retweets'))) ? 'true' : 'false');

		$data = json_decode(getContents(self::API_ENDPOINT . implode('&', $apiParams),
			array('Authorization: Bearer ' . $this->apiToken)
		));
		if (empty($data)) {
			returnServerError('Unable to fetch data');
		}

		foreach ($data as $item) {
			if (empty($this->title) && isset($item->user)) {
				$this->title = $item->user->name;
				if (!empty($item->user->description)) {
					$this->description = $this->hypertextize($this->htmlize($item->user->description));
				}
				if (!empty($item->user->profile_image_url_https)) {
					$this->logo = $item->user->profile_image_url_https;
				} elseif (!empty($item->user->profile_image_url)) {
					$this->logo = $item->user->profile_image_url;
				}
			}
			$insert = array();

			$insert['id'] = sha1($item->id);
			$insert['timestamp'] = strtotime($item->created_at);
			if ($insert['timestamp'] === false) {
				$insert['timestamp'] = time();
			}
			if (!empty($item->source)) {
				$insert['author'] = html_entity_decode(strip_tags($item->source));
			}

			if (!empty($item->full_text)) {
				$insert['content'] = html_entity_decode($item->full_text);
			} else {
				$insert['content'] = html_entity_decode($item->text);
			}

			if (!empty($item->id_str)) {
				$insert['uri'] = self::API_STATUSLINK . $item->id_str;
			}

			$urls = array();
			if (isset($item->entities->urls) && count($item->entities->urls) > 0) {
				foreach ($item->entities->urls as $entry) {
					$urls[$entry->url] = $entry->expanded_url;
				}
			}
			$image = null;
			if (isset($item->entities->media) && count($item->entities->media) > 0) {
				foreach ($item->entities->media as $entry) {
					if (isset($entry->media_url_https)) {
						$urls[$entry->url] = $entry->media_url_https;
						if ($image === null && isset($entry->type)) {
							switch ($entry->type) {
								case 'photo':
								case 'video':
									$image = $entry->media_url_https;
									break;
							}
						}
					} elseif (isset($entry->media_url)) {
						$urls[$entry->url] = $entry->media_url;
						if ($image === null && isset($entry->type)) {
							switch ($entry->type) {
								case 'photo':
								case 'video':
									$image = $entry->media_url;
									break;
							}
						}
					} elseif (isset($entry->expanded_url)) {
						$urls[$entry->url] = $entry->expanded_url;
					}
				}
			}

			// Some notes about the item title:
			// We only need the first line of the content, excluding any
			// given URLs and hashtags.

			$insert['title'] = trim(strtok(strip_tags($insert['content']), "\n"));

			if (count($urls) > 0) {
				$cleared = array();
				foreach ($urls as $short => $expanded) {
					$cleared[$short] = '';
				}

				$insert['title'] = strtr($insert['title'], $cleared);
				$insert['content'] = strtr($insert['content'], $urls);
			}

			if (empty($insert['title'])) {
				$insert['title'] = $this->title;
			}

			$insert['content'] = self::hypertextize(self::htmlize($insert['content']));

			if (!empty($image)) {
				$insert['content'] = '<p><img src="' . $image . '" /></p>' . $insert['content'];
			}

			$this->items[] = $insert;
		}

		$this->link = self::API_BASELINK . $user;
	}

	public function getName(){
		if (!empty($this->title)) {
			return $this->title . ' - Twitter';
		}
		return self::NAME;
	}

	public function getURI(){
		return (!empty($this->link)) ? $this->link : self::URI;
	}

	public function getIcon(){
		return self::API_BASELINK . '/favicon.ico';
	}

	public function getDescription(){
		$description = (!empty($this->description)) ? $this->description : self::DESCRIPTION;

		$apiKey = $this->getConfig('api_key');
		$apiSecret = $this->getConfig('api_secret');

		if (empty($apiKey) || empty($apiSecret)) {
			$description .= '<p>- Twitter API credentials required, please see configuration. -</p>';
		}

		return $description;
	}

	private function getApiConfig() {
		$cacheFactory = new CacheFactory();
		$cacheFactory->setWorkingDir(PATH_LIB_CACHES);
		$cache = $cacheFactory->create(Configuration::getConfig('cache', 'type'));
		$cache->setScope(get_called_class());
		$cache->setKey(['token']);
		$this->apiToken = $cache->loadData();

		if (empty($this->token)) {
			$apiKey = $this->getConfig('api_key');
			$apiSecret = $this->getConfig('api_secret');

			if (empty($apiKey) || empty($apiSecret)) {
				returnServerError('Missing credentials, please see configuration');
			}

			$credentials = rawurlencode($apiKey) . ':' . rawurlencode($apiSecret);
			$response = getContents(self::API_ENDPOINT . 'oauth2/token', array(
				'Authorization: Basic ' . base64_encode($credentials)
			), array(
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => array(
					'grant_type' => 'client_credentials'
				)
			));

			$data = json_decode($response);
			if (is_object($data) && isset($data->token_type) && $data->token_type == 'bearer' && !empty($data->access_token)) {
				$this->apiToken = $data->access_token;
				$cache->saveData($this->apiToken);
			} else {
				returnServerError('Unable to authenticate, please see configuration');
			}
		}
	}

	private function htmlize($str) {
		if (!is_string($str)) {
			return '';
		}
		if (html_entity_decode(strip_tags($str), ENT_QUOTES) != $str) {
			return $str;
		}

		return nl2br(htmlentities($str, ENT_QUOTES, 'UTF-8'));
	}

	private function hypertextize($str) {
		$html = $str;
		$words = preg_split('/[\s,]+|\<br[ \/]*\>+/', $html);
		$replace = array();

		foreach ($words as $v) {
			$word = html_entity_decode($v, ENT_QUOTES, 'UTF-8');
			$word = trim($word, "\'\"\.\x00..\x1F\x7B..\xFF");
			$word = htmlentities($word, ENT_QUOTES, 'UTF-8');
			if ((strpos($word, '@') === false) &&
				(strpos($word, '[at]') === false) &&
				(strpos($word, '(at)') === false) &&
				preg_match('/\:\/\/|[0-9a-zA-Z]{3,}\.[a-zA-Z]{2,3}/', $word)
			) {
				$url = $word;
				if (strpos($url, '://') === false) {
					$url = 'http://' . $url;
				}
				$hash = hash('crc32', $url);
				$html = preg_replace('/' . preg_quote($word, '/') . '/', '%%' . $hash . '%%', $html, 1);
				$replace['%%' . $hash . '%%'] = '<a href="' . $url . '" target="_blank">' . $word . '</a>';
			}
		}

		$html = preg_replace('/(^#|\s+#)(\w+)/', ' <a href="' . self::API_HASHTAGLINK . '\2" target="_blank">#\2</a>', $html);

		return trim(strtr($html, $replace));
	}
}
