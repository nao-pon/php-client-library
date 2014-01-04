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

	public function SendData($data, $shareId = 0)
	{
		// First generate a part hash
		$hash = md5($data) . sha1($data);

		// See if the cloud has this part, and send if needed
		if(!$this->HasPart($hash, strlen($data), $shareId))
			$this->SendPart($hash, strlen($data), $data, $shareId);

		// Return information about this part
		return array("fingerprint" => $hash, "size" => strlen($data));
	}

	public function CreateFile($path, $parts)
	{
		if($this->debug)
			print("Creating file at path " . $path . "\n");

		$request = array();
		$request["action"] = "create";
		$request["object_type"] = "file";
		$request["parts"] = array();
		$request["path"] = $path;
		
		$offset = 0;
		foreach($parts as $part)
		{
			$partRequest["fingerprint"] = $part["fingerprint"];
			$partRequest["offset"] = $offset;
			$partRequest["size"] = $part["size"];

			array_push($request["parts"], $partRequest);

			$offset += $part["size"];
		}

		$request["size"] = $offset;

		$result = $this->Post("update_objects", $this->EncodeRequest("update_objects", array("meta" => array($request))));

		// Decode the json reply
		$result = json_decode($result);

		// Check for errors
		if($result->{"error"} != null)
			throw new Exception("Error creating file '" . $result->{"error"}->{"message"} . "'");
	}

	public function ListPath($path, $additionalOptions = null)
	{
		$request = array();
		$request["path"] = $path;
		$request["max_items"] = 10;
		$request["list_watermark"] = false;

		if($additionalOptions)
			$request = array_merge($request, $additionalOptions);
		
		$result = $this->Post("list_objects", $this->EncodeRequest("list_objects", $request));

		// Decode the json reply
		$result = json_decode($result);

		// Check for errors
		if($result->{"error"} != null)
			throw new Exception("Error listing path '" . $result->{"error"}->{"message"} . "'");

		// Return children if we got some, otherwise return the root object itself
		if($result->{"result"}->{"children"})
			return $result->{"result"}->{"children"};
		else
			return array($result->{"result"}->{"object"});
	}

	public function SendPart($fingerprint, $size, $data, $shareId = 0)
	{
		// They must match
		if(md5($data) . sha1($data) != $fingerprint)
			throw new Exception("Failed to validate part hash");

		if($this->debug)
			print("Sending part $fingerprint \n");

		// Pack in the part
		$part = 
			pack("N", 0xcab005e5) .			// uint32_t // "0xcab005e5"
			pack("N", 8 * 4 + 73 + $size) .	// uint32_t // Size of this struct plus payload size
			pack("N", 1) .					// uint32_t // Struct version
			pack("N", $shareId) .			// uint32_t // Share id for part (for verification)
			pack("a73", $fingerprint) .		// char[73] // Part fingerprint
			pack("N", $size) .				// uint32_t // Size of the part
			pack("N", $size) . 				// uint32_t // Size of our payload (partSize or 0, error msg size on error)
			pack("N", 0) .					// uint32_t // Error code for individual parts
			pack("N", 0);					// uint32_t // Reserved for future use

		// Add the data at the end
		$part .= $data;

		// Pack in the header
		$header = 
			pack("N", 0xba5eba11) .		// uint32_t Fixed signature "0xba5eba11"
			pack("N", 6 * 4) .          // uint32_t Size of this structure 
			pack("N", 1) .              // uint32_t Struct version (1)
			pack("N", strlen($part)) .  // uint32_t Total size of all data after the header
			pack("N", 1) .              // uint32_t Part count
			pack("N", 0);               // uint32_t Error code for errors regarding the entire request

		printf("Size of part request is " . strlen($part) . "\n");

		$result = $this->Post("send_object_parts", $header . $part);

		$header = unpack(
			// Parse our the header
			"N1signature/" .			// uint32_t Fixed signature "0xba5eba11"
			"N1size/" .                 // uint32_t Size of this structure 
			"N1version/" .              // uint32_t Struct version (1)
			"N1totalSize/" .            // uint32_t Total size of all data after the header
			"N1partCount/" .            // uint32_t Part count
			"N1errorCode/",             // uint32_t Error code for errors regarding the entire 
			$result);
		
		if(!$header)
			throw new Exception("Failed to parse binary part reply");

		// See if we got an erro
		if($header["errorCode"])
		{
			// Just the error string remains
			throw new Exception("Cloud returned part error " . "'" . substr($result, 6 * 4) . "'");
		}

		// No error, parse the data
		$part = unpack(
			// Parse out the part
			"N1partSignature/" .		// uint32_t // "0xcab005e5"
			"N1partWithPayloadSize/" .  // uint32_t // Size of this struct plus payload size
			"N1partVersion/" .          // uint32_t // Struct version
			"N1partShareId/" .          // uint32_t // Share id for part (for verification)
			"a73partFingerprint/" .     // char[73] // Part fingerprint
			"N1partSize/" .             // uint32_t // Size of the part
			"N1payloadSize/" .          // uint32_t // Size of our payload (partSize or 0, error msg size on error)
			"N1partErrorCode/" .        // uint32_t // Error code for individual parts
			"N1reserved/",  			// uint32_t // Reserved for future use
			substr($result, 6 * 4));

		// Check for part error
		if($part["partErrorCode"])
		{
			var_dump($part);
			throw new Exception("Got part error " . $part["partErrorCode"] . "'" . substr($result, (6 * 4) + (8 * 4) + 73) . "'");
		}

		// Success
	}

	public function HasPart($fingerprint, $size, $shareId = 0)
	{
		if($this->debug)
			print("Checking if cloud has part $fingerprint \n");

		// Pack in the part
		$part = 
			pack("N", 0xcab005e5) .		// uint32_t // "0xcab005e5"
			pack("N", 8 * 4 + 73) .		// uint32_t // Size of this struct plus payload size
			pack("N", 1) .				// uint32_t // Struct version
			pack("N", $shareId) .		// uint32_t // Share id for part (for verification)
			pack("a73", $fingerprint) . // char[73] // Part fingerprint
			pack("N", $size) .      	// uint32_t // Size of the part
			pack("N", 0) .  			// uint32_t // Size of our payload (partSize or 0, error msg size on error)
			pack("N", 0) .				// uint32_t // Error code for individual parts
			pack("N", 0);				// uint32_t // Reserved for future use

		// Pack in the header
		$header = 
			pack("N", 0xba5eba11) .		// uint32_t Fixed signature "0xba5eba11"
			pack("N", 6 * 4) .          // uint32_t Size of this structure 
			pack("N", 1) .              // uint32_t Struct version (1)
			pack("N", strlen($part)) .  // uint32_t Total size of all data after the header
			pack("N", 1) .              // uint32_t Part count
			pack("N", 0);               // uint32_t Error code for errors regarding the entire request

		$result = $this->Post("has_object_parts", $header . $part);

		$header = unpack(
			// Parse our the header
			"N1signature/" .			// uint32_t Fixed signature "0xba5eba11"
			"N1size/" .                 // uint32_t Size of this structure 
			"N1version/" .              // uint32_t Struct version (1)
			"N1totalSize/" .            // uint32_t Total size of all data after the header
			"N1partCount/" .            // uint32_t Part count
			"N1errorCode/",             // uint32_t Error code for errors regarding the entire 
			$result);
		
		if(!$header)
			throw new Exception("Failed to parse binary part reply");

		// See if we got an erro
		if($header["errorCode"])
		{
			// Just the error string remains
			throw new Exception("Cloud returned part error " . "'" . substr($result, 6 * 4) . "'");
		}

		// No error, parse the data
		$part = unpack(
			// Parse out the part
			"N1partSignature/" .		// uint32_t // "0xcab005e5"
			"N1partWithPayloadSize/" .  // uint32_t // Size of this struct plus payload size
			"N1partVersion/" .          // uint32_t // Struct version
			"N1partShareId/" .          // uint32_t // Share id for part (for verification)
			"a73partFingerprint/" .     // char[73] // Part fingerprint
			"N1partSize/" .             // uint32_t // Size of the part
			"N1payloadSize/" .          // uint32_t // Size of our payload (partSize or 0, error msg size on error)
			"N1partErrorCode/" .        // uint32_t // Error code for individual parts
			"N1reserved/",  			// uint32_t // Reserved for future use
			substr($result, 6 * 4));

		// Check for part error
		if($part["partErrorCode"])
			throw new Exception("Got part error " . $part["partErrorCode"] . "'" . substr($result, (6 * 4) + (8 * 4) + 73) . "'");

		// Now the cloud will set the partSize field to zero if it doesn't have the part
		if($part["partSize"] == 0)
			return false;
		else 
			return true;
	}

	public function GetPart($fingerprint, $size, $shareId = 0)
	{
		if($this->debug)
			print("Getting part $fingerprint \n");

		// Pack in the part
		$part = 
			pack("N", 0xcab005e5) .		// uint32_t // "0xcab005e5"
			pack("N", 8 * 4 + 73) .		// uint32_t // Size of this struct plus payload size
			pack("N", 1) .				// uint32_t // Struct version
			pack("N", $shareId) .		// uint32_t // Share id for part (for verification)
			pack("a73", $fingerprint) . // char[73] // Part fingerprint
			pack("N", $size) .			// uint32_t // Size of the part
			pack("N", 0) .				// uint32_t // Size of our payload (partSize or 0, error msg size on error)
			pack("N", 0) .				// uint32_t // Error code for individual parts
			pack("N", 0);				// uint32_t // Reserved for future use

		// Pack in the header
		$header = 
			pack("N", 0xba5eba11) .		// uint32_t Fixed signature "0xba5eba11"
			pack("N", 6 * 4) .          // uint32_t Size of this structure 
			pack("N", 1) .              // uint32_t Struct version (1)
			pack("N", strlen($part)) .  // uint32_t Total size of all data after the header
			pack("N", 1) .              // uint32_t Part count
			pack("N", 0);               // uint32_t Error code for errors regarding the entire request

		$result = $this->Post("get_object_parts", $header . $part);

		$header = unpack(
			// Parse our the header
			"N1signature/" .			// uint32_t Fixed signature "0xba5eba11"
			"N1size/" .                 // uint32_t Size of this structure 
			"N1version/" .              // uint32_t Struct version (1)
			"N1totalSize/" .            // uint32_t Total size of all data after the header
			"N1partCount/" .            // uint32_t Part count
			"N1errorCode/",             // uint32_t Error code for errors regarding the entire 
			$result);
		
		if(!$header)
			throw new Exception("Failed to parse binary part reply");

		// See if we got an erro
		if($header["errorCode"])
		{
			// Just the error string remains
			throw new Exception("Cloud returned part error " . "'" . substr($result, 6 * 4) . "'");
		}

		// No error, parse the data
		$part = unpack(
			// Parse out the part
			"N1partSignature/" .		// uint32_t // "0xcab005e5"
			"N1partWithPayloadSize/" .  // uint32_t // Size of this struct plus payload size
			"N1partVersion/" .          // uint32_t // Struct version
			"N1partShareId/" .          // uint32_t // Share id for part (for verification)
			"a73partFingerprint/" .     // char[73] // Part fingerprint
			"N1partSize/" .             // uint32_t // Size of the part
			"N1payloadSize/" .          // uint32_t // Size of our payload (partSize or 0, error msg size on error)
			"N1partErrorCode/" .        // uint32_t // Error code for individual parts
			"N1reserved/",  			// uint32_t // Reserved for future use
			substr($result, 6 * 4));

		// Check for part error
		if($part["partErrorCode"])
			throw new Exception("Got part error " . $part["partErrorCode"] . "'" . substr($result, (6 * 4) + (8 * 4) + 73) . "'");

		// No error, see if data is in there
		if($part["payloadSize"] == 0)
			throw new Exception("No data sent for part ");

		// Get the data out of there
		$data = substr($result, (6 * 4) + (8 * 4) + 73, $part["payloadSize"]);

		// Triple check the data matches the fingerprint
		if(md5($data) . sha1($data) != $fingerprint)
			throw new Exception("Failed to validate part hash");

		// Part hash matches, return it
		return $data;
	}

	protected function Post($method, $data)
	{
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($this->curl, CURLOPT_POSTFIELDS, $data);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, $this->GetHeaders($method));
		curl_setopt($this->curl, CURLOPT_URL, $this->address . "/" . $this->GetEndpoint($method));
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true); 
		curl_setopt($this->curl, CURLOPT_POST, 1); 

		$result = curl_exec($this->curl);

		// If curl grossly failed, throw
		if($result == FALSE)
			throw new Exception("Curl failed to exec " . curl_error($this->curl));
		return $result;
	}

	private function GetEndpoint($method)
	{
		if($method == "has_object_parts" || $method == "send_object_parts" || $method == "get_object_parts")
			return $method;
		else
			return "jsonrpc";
	}

	private function GetHeaders($method)
	{
		$headers = array();
		$endpoint = "jsonrpc";

		if($method == "has_object_parts" || $method == "send_object_parts" || $method == "get_object_parts")
			array_push($headers, "Content-Type: application/octect-stream");

		array_push($headers, "X-Api-Version: 1.0");
		array_push($headers, "X-Client-Type: api");
		array_push($headers, "X-Client-Time: " . time());
		array_push($headers, "Authorization: " .  $this->oauth->getRequestHeader('POST', $this->address . "/" . $this->GetEndpoint($method)));

		return $headers;
	}

	private function EncodeRequest($method, $json)
	{
		$request["jsonrpc"] = "2.0";
		$request["id"] = "0";
		$request["method"] = $method;
		$request["params"] = $json;
		$request = json_encode($request, JSON_UNESCAPED_SLASHES);
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