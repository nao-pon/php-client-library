<?php

class CloudApi
{
	function __construct($address, $consumerKey, $consumerSecret, $accessToken, $tokenSecret, $debug = false)
	{
		$this->consumerKey = $consumerKey;
		$this->consumerSecret = $consumerSecret;
		$this->accessToken = $accessToken;
		$this->tokenSecret = $tokenSecret;
		$this->address = $address;
		$this->debug = $debug;

		$this->oauth = new OAuth($this->consumerKey, $this->consumerSecret);
		$this->oauth->setToken($this->accessToken, $this->tokenSecret);

		$this->curl = curl_init();
		if(!$this->curl)
			throw new Exception("Failed to initialize curl");
	}

	public function SendData($data)
	{
		// First generate a part hash
		$hash = md5($data) . sha1($data) . (string)strlen($data);
	}

	public function CreateFile($path, $parts)
	{
	}

	public function ListPath($path)
	{
		$request = array();
		$request["path"] = $path;
		$request["max_items"] = 10;
		$request["list_watermark"] = 0;

		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, $this->EncodeRequest("list_objects", $request));
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, $this->GetHeaders("list_objects"));
		curl_setopt($this->curl, CURLOPT_URL, $this->address . "/" . "jsonrpc");
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true); 
		curl_setopt($this->curl, CURLOPT_POST, 1); 
		if($this->debug)
			curl_setopt($this->curl, CURLOPT_VERBOSE, true);

		$result = curl_exec($this->curl);

		if($this->debug && $result != FALSE)
			printf("Got reply: " . var_export($result) . "\n");

		// If curl grossly failed, throw
		if($result == FALSE)
			throw new Exception("Curl failed to exec " . curl_error($this->curl));

		// Decode the json reply
		$result = json_decode($result);

		return $result->{"result"}->{"children"};
	}

	public function AuthCheck()
	{
	}

	private function GetHeaders($method)
	{
		$headers = array();
		$endpoint = "jsonrpc";

		if($method == "has_object_parts" || $method == "send_object_parts" || $method == "get_object_parts")
		{
			array_push($headers, "Content-Type: application/octect-stream");
			$endpoint = $method;
		}
		else
			$method = "jsonrpc";

		array_push($headers, "X-Api-Version: 1.0");
		array_push($headers, "X-Client-Type: api");
		array_push($headers, "X-Client-Time: " . time());
		array_push($headers, "Authorization: " .  $this->oauth->getRequestHeader('POST', $this->address . "/" . $method));

		return $headers;
	}

	private function EncodeRequest($method, $json)
	{
		$request["jsonrpc"] = "2.0";
		$request["id"] = "0";
		$request["method"] = $method;
		$request["params"] = $json;
		$request = json_encode($request);
		if($this->debug)
			print("Encoded request " . var_export($request) . "\n");
		return $request;
	}

	private $address;
	private $consumerKey;
	private $consumerSecret;
	private $tokenSecret;
	private $curl;
	private $oauth;
}

?>
