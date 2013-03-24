<?php
class GMA_API {
	private $gmaUrl;
	private $casUrl = 'https://thekey.me/cas/';
	private $casUser;
	private $casPassword;

	private $_curlOpts = null;

	private $gmaCookie;

	private $language = null;

	public function __construct($gmaUrl) {
		$this->gmaUrl = $gmaUrl;
	}

	private function _getCurlHandle() {
		$ch = curl_init();

		// set default options
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLINFO_HEADER_OUT    => true,
		));
		if(is_array($this->_curlOpts)) {
			curl_setopt_array($ch, $this->_curlOpts);
		}

		return $ch;
	}

	public function setCasUrl($casUrl) {
		$this->gmaCookie = null;
		$this->casUrl = $casUrl;
		return $this;
	}

	public function setCasUsername($username) {
		$this->gmaCookie = null;
		$this->casUser = $username;
		return $this;
	}

	public function setCasPassword($password) {
		$this->gmaCookie = null;
		$this->casPassword = $password;
		return $this;
	}

	public function setCurlOpts(array $opts = null) {
		$this->_curlOpts = $opts;
	}

	public function getGmaCookie() {
		return $this->gmaCookie;
	}

	public function setGmaCookie($gmaCookie) {
		$this->gmaCookie = $gmaCookie;
		return $this;
	}

	public function setLanguage($language = null) {
		$this->language = $language;
	}

	private function _establishSession() {
		// clear previous gma cookie
		$this->gmaCookie = null;

		// login to CAS
		$ch = $this->_getCurlHandle();
		curl_setopt_array($ch, array(
			CURLOPT_URL        => $this->casUrl . "v1/tickets",
			CURLOPT_POSTFIELDS => "username=" . $this->casUser . "&password=" . $this->casPassword,
			CURLOPT_HEADER     => true,
		));
		$data = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// login successful
		if($code == 201) {
			// parse TGT
			preg_match('/Location:(.*?)\n/', $data, $matches);
			$tgtUrl = trim(array_pop($matches));

			// get ST
			$service = $this->gmaUrl . '?q=en/GMA&destination=GMA';
			$ch = $this->_getCurlHandle();
			curl_setopt_array($ch, array(
				CURLOPT_URL        => $tgtUrl,
				CURLOPT_POSTFIELDS =>  "service=" . urlencode($service),
			));
			$data = curl_exec($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if($code == 200) {
				$ticket = $data;

				// use ST w/ GMA (follow redirects & capture cookie)
				$ch = $this->_getCurlHandle();
				curl_setopt_array($ch, array(
					CURLOPT_URL    => $service . '&ticket=' . $ticket,
					CURLOPT_HEADER => true,
				));
				$data = curl_exec($ch);
				curl_close($ch);

				// parse Set-Cookie header (TODO: this is brittle)
				preg_match('/Set-Cookie2?:(.*?)\n/', $data, $matches);
				$tmpCookie = trim(array_shift(explode(';', array_pop($matches))));

				// GMA performs a redirect which sets a new cookie, this is the actual cookie we need
				if(!empty($tmpCookie)) {
					preg_match('/Location:(.*?)\n/', $data, $matches);
					$newUri = trim(array_pop($matches));

					$ch = $this->_getCurlHandle();
					curl_setopt_array($ch, array(
						CURLOPT_URL    => $newUri,
						CURLOPT_COOKIE => $tmpCookie,
						CURLOPT_HEADER => true,
					));
					$data = curl_exec($ch);
					curl_close($ch);

					// extract the correct Set-Cookie header
					preg_match_all('/Set-Cookie2?:(.*?)\n/', $data, $matches);
					$this->gmaCookie = array_shift(explode(';', array_pop(array_pop($matches))));

					return true;
				}
			}
		}

		return false;
	}

	public function apiRequest($endpoint, $method = 'GET', $postdata = null, $retryCount = 0) {
		if(empty($this->gmaCookie)) {
			$this->_establishSession();
		}

		if(!is_null($this->language)) {
			$endpoint .= '&languageId=' . $this->language;
		}

		// parse postdata into json
		if(is_null($postdata)) {
			$json = null;
		} else if(is_string($postdata)) {
			// raw json data
			$json = $postdata;
		} else {
			$json = json_encode($postdata);
		}

		//make request
		$ch = $this->_getCurlHandle();
		curl_setopt_array($ch, array(
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_URL           => $this->gmaUrl . $endpoint,
			CURLOPT_COOKIE        => $this->gmaCookie,
		));
		if($json !== null) {
			curl_setopt_array($ch, array(
				CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
				CURLOPT_POST       => true,
				CURLOPT_POSTFIELDS => $json,
			));
		}
		$data = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		// valid response
		if($code == 200) {
			return json_decode($data);
		} elseif($code == 404) {
			return null;
		}

		// retry the request because of an invalid response
		// reset the gmaCookie
		$this->gmaCookie = null;

		// infinite loop sanity check
		if($retryCount > 2) {
			die("API error!!!!");
		}

		// reissue API request
		return $this->apiRequest($endpoint, $method, $postdata, $retryCount + 1);
	}

	// API wrapper functions
	public function getLanguages() {
		return $this->apiRequest('?q=gmaservices/gma_language');
	}

	public function getNodes() {
		return $this->apiRequest('?q=gmaservices/gma_node');
	}

	public function getNode($id) {
		return $this->apiRequest('?q=gmaservices/gma_node/' . $id);
	}

	public function getNodeMeasurements($id) {
		return $this->apiRequest('?q=gmaservices/gma_node/' . $id . '/measurements');
	}

	public function getNodeParent($id) {
		return $this->apiRequest('?q=gmaservices/gma_node/' . $id . '/parent');
	}

	public function getUsers($type = 'active') {
		return $this->apiRequest('?q=gmaservices/gma_user&type=' . $type);
	}

	public function getStaffReportMeasurements($id) {
		return $this->apiRequest('?q=gmaservices/gma_staffReport/' . $id);
	}

	public function getStaffReports($all = false, $nodeId = null, $renId = null, DateTime $date = null, $submitted = null) {
		$data = array(
			'maxResult' => 0,
		);
		if(!is_null($nodeId)) {
			$data['nodeId'] = $nodeId;
		}
		if(!is_null($date)) {
			$data['dateWithin'] = $date->format('Ymd');
		}
		if(!is_null($submitted)) {
			$data['submitted'] = $submitted;
		}
		if($all && !is_null($renId)) {
			$data['renId'] = $renId;
		}

		return $this->apiRequest('?q=gmaservices/gma_staffReport/search' . ($all ? 'All' : 'Own'), 'POST', $data);
	}

	public function setStaffReportMeasurements($id, array $measurements = array()) {
		$data = array();
		foreach($measurements as $key => $value) {
			$data[] = array(
				'measurementId' => $key,
				'type'          => is_numeric($value) ? 'numeric' : 'text',
				'value'         => $value,
			);
		}

		return $this->apiRequest('?q=gmaservices/gma_staffReport/' . $id, 'PUT', $data);
	}
}
