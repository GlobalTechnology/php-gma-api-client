<?php
class GMA_API {
	private $gmaUrl;
	private $casUrl = 'https://thekey.me/cas/';
	private $casUser;
	private $casPassword;

	private $gmaCookie;

	public function __construct($gmaUrl) {
		$this->gmaUrl = $gmaUrl;
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

	public function getGmaCookie() {
		return $this->gmaCookie;
	}

	public function setGmaCookie($gmaCookie) {
		$this->gmaCookie = $gmaCookie;
		return $this;
	}

	private function _establishSession() {
		// clear previous gma cookie
		$this->gmaCookie = null;

		// login to CAS
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->casUrl . "v1/tickets");
		curl_setopt($ch, CURLOPT_POSTFIELDS, "username=" . $this->casUser . "&password=" . $this->casPassword);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		$data = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		list($headers, $data) = explode("\n\n", $data, 2);
		curl_close($ch);

		// login successful
		if($code == 201) {
			// parse TGT
			preg_match('/Location:(.*?)\n/', $headers, $matches);
			$tgtUrl = trim(array_pop($matches));

			// get ST
			$service = $this->gmaUrl . '?q=en/GMA&destination=GMA';
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $tgtUrl);
			curl_setopt($ch, CURLOPT_POSTFIELDS, "service=" . urlencode($service));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

			$data = curl_exec($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if($code == 200) {
				$ticket = $data;

				// use ST w/ GMA (follow redirects & capture cookie)
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $service . '&ticket=' . $ticket);
				curl_setopt($ch, CURLOPT_HEADER, true);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

				$data = curl_exec($ch);
				$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				list($headers, $data) = explode("\n\n", $data, 2);
				curl_close($ch);

				// parse Set-Cookie header (TODO: this is brittle)
				preg_match('/Set-Cookie2?:(.*?)\n/', $headers, $matches);
				$tmpCookie = array_shift(explode(';', array_pop($matches)));

				// GMA performs a redirect which sets a new cookie, this is the actual cookie we need
				if(!empty($tmpCookie)) {
					preg_match('/Location:(.*?)\n/', $headers, $matches);
					$newUri = trim(array_pop($matches));

					$ch = curl_init();
					curl_setopt($ch, CURLOPT_URL, $newUri);
					curl_setopt($ch, CURLOPT_COOKIE, $tmpCookie);
					curl_setopt($ch, CURLOPT_HEADER, true);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

					$data = curl_exec($ch);
					$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
					list($headers, $data) = explode("\n\n", $data, 2);
					curl_close($ch);

					// extract the correct Set-Cookie header
					preg_match_all('/Set-Cookie2?:(.*?)\n/', $headers, $matches);
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
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->gmaUrl . $endpoint);
		curl_setopt($ch, CURLOPT_COOKIE, $this->gmaCookie);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		if(!is_null($json)) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
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
