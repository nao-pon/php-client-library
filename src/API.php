<?php

namespace Barracuda\Copy;

/**
 * Copy API class
 *
 * @package Copy
 * @license https://raw.github.com/copy-app/php-client-library/master/LICENSE MIT
 */
class API
{
    /**
     * API URl
     * @var string $api_url
     */
    protected $api_url = 'https://api.copy.com';

    /**
     * Instance of OAuth
     * @var OAuth $oauth
     */
    private $oauth;

    /**
     * Instance of curl
     * @var resource $curl
     */
    private $curl;

    /**
     * Constructor
     *
     * @param string $consumerKey    OAuth consumer key
     * @param string $consumerSecret OAuth consumer secret
     * @param string $accessToken    OAuth access token
     * @param string $tokenSecret    OAuth token secret
     * @param bool   $debug          true to output debugging information to stdout
     */
    public function __construct($consumerKey, $consumerSecret, $accessToken, $tokenSecret, $debug = false)
    {
        // debug flag
        $this->debug = $debug;

        // oauth setup
        $this->oauth = new \OAuth($consumerKey, $consumerSecret);
        $this->oauth->setToken($accessToken, $tokenSecret);

        // curl setup
        $this->curl = curl_init();
        if (!$this->curl) {
            throw new \Exception("Failed to initialize curl");
        }
    }

    /**
     * Send a peice of data
     *
     * @param  string $data    binary data
     * @param  int    $shareId setting this to zero is best, unless share id is known
     * @return array  contains fingerprint and size, to be used when creating a file
     */
    public function sendData($data, $shareId = 0)
    {
        // first generate a part hash
        $hash = md5($data) . sha1($data);

        // see if the cloud has this part, and send if needed
        if(!$this->HasPart($hash, strlen($data), $shareId))
            $this->SendPart($hash, strlen($data), $data, $shareId);

        // return information about this part
        return array("fingerprint" => $hash, "size" => strlen($data));
    }

    /**
     * Create a file with a set of data parts
     *
     * @param string $path  full path containing leading slash and file name
     * @param array  $parts contains arrays of parts returned by \Barracuda\Copy\API\sendData
     */
    public function createFile($path, $parts)
    {
        if ($this->debug) {
            print("Creating file at path " . $path . "\n");
        }

        $request = array();
        $request["action"] = "create";
        $request["object_type"] = "file";
        $request["parts"] = array();
        $request["path"] = $path;

        $offset = 0;
        foreach ($parts as $part) {
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
        if ($result->{"error"} != null) {
            throw new \Exception("Error creating file '" . $result->{"error"}->{"message"} . "'");
        }
    }

    /**
     * Remove a file
     *
     * @param string $path full path containing leading slash and file name
     */
    public function removeFile($path)
    {
        if ($this->debug) {
            print("Removing file at path " . $path . "\n");
        }

        $request = array();
        $request["action"] = "remove";
        $request["object_type"] = "file";
        $request["path"] = $path;

        $result = $this->Post("update_objects", $this->EncodeRequest("update_objects", array("meta" => array($request))));

        // Decode the json reply
        $result = json_decode($result);

        // Check for errors
        if ($result->{"error"} != null) {
            throw new \Exception("Error removing file '" . $result->{"error"}->{"message"} . "'");
        }
    }

    /**
     * List objects within a path
     *
     * @param  string $path              full path with leading slash and optionally a filename
     * @param  array  $additionalOptions used for passing options such as include_parts
     * @return array  contains items
     */
    public function listPath($path, $additionalOptions = null)
    {
        $list_watermark = false;
        $return = array();

        do {
            $request = array();
            $request["path"] = $path;
            $request["max_items"] = 100;
            $request["list_watermark"] = $list_watermark;

            if ($additionalOptions) {
                $request = array_merge($request, $additionalOptions);
            }

            $result = $this->Post("list_objects", $this->EncodeRequest("list_objects", $request));

            // Decode the json reply
            $result = json_decode($result);

            // Check for errors
            if ($result->{"error"} != null) {
                throw new \Exception("Error listing path '" . $result->{"error"}->{"message"} . "'");
            }

            // add the children if we got some, otherwise add the root object itself to the return
            if ($result->{"result"}->{"children"}) {
                $return = array_merge($return, $result->result->children);
                $list_watermark = $result->result->list_watermark;
            } else {
                $return[] = $result->result->object;
            }
        } while (isset($result->result->more_items) && $result->result->more_items == 1);

        return $return;
    }

    /**
     * Send a data part
     *
     * @param string $fingerprint md5 and sha1 concatinated
     * @param int    $size        number of bytes
     * @param string $data        binary data
     * @param int    $shareId     setting this to zero is best, unless share id is known
     */
    public function sendPart($fingerprint, $size, $data, $shareId = 0)
    {
        // They must match
        if (md5($data) . sha1($data) != $fingerprint) {
            throw new \Exception("Failed to validate part hash");
        }

        if ($this->debug) {
            print("Sending part $fingerprint \n");
        }

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

        if ($this->debug) {
            printf("Size of part request is " . strlen($part) . "\n");
        }

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

        if (!$header) {
            throw new \Exception("Failed to parse binary part reply");
        }

        // See if we got an erro
        if ($header["errorCode"]) {
            // Just the error string remains
            throw new \Exception("Cloud returned part error " . "'" . substr($result, 6 * 4) . "'");
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
        if ($part["partErrorCode"]) {
            var_dump($part);
            throw new \Exception("Got part error " . $part["partErrorCode"] . "'" . substr($result, (6 * 4) + (8 * 4) + 73) . "'");
        }
    }

    /**
     * Check to see if a part already exists
     *
     * @param  string $fingerprint md5 and sha1 concatinated
     * @param  int    $size        number of bytes
     * @param  int    $shareId     setting this to zero is best, unless share id is known
     * @return bool   true if part already exists
     */
    public function hasPart($fingerprint, $size, $shareId = 0)
    {
        if ($this->debug) {
            print("Checking if cloud has part $fingerprint \n");
        }

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

        if (!$header) {
            throw new \Exception("Failed to parse binary part reply");
        }

        // See if we got an erro
        if ($header["errorCode"]) {
            // Just the error string remains
            throw new \Exception("Cloud returned part error " . "'" . substr($result, 6 * 4) . "'");
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
        if ($part["partErrorCode"]) {
            throw new \Exception("Got part error " . $part["partErrorCode"] . "'" . substr($result, (6 * 4) + (8 * 4) + 73) . "'");
        }

        // Now the cloud will set the partSize field to zero if it doesn't have the part
        if ($part["partSize"] == 0) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Get a part
     *
     * @param  string $fingerprint md5 and sha1 concatinated
     * @param  int    $size        number of bytes
     * @param  int    $shareId     setting this to zero is best, unless share id is known
     * @return string binary data
     */
    public function getPart($fingerprint, $size, $shareId = 0)
    {
        if ($this->debug) {
            print("Getting part $fingerprint \n");
        }

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

        if (!$header) {
            throw new \Exception("Failed to parse binary part reply");
        }

        // See if we got an erro
        if ($header["errorCode"]) {
            // Just the error string remains
            throw new \Exception("Cloud returned part error " . "'" . substr($result, 6 * 4) . "'");
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
        if ($part["partErrorCode"]) {
            throw new \Exception("Got part error " . $part["partErrorCode"] . "'" . substr($result, (6 * 4) + (8 * 4) + 73) . "'");
        }

        // No error, see if data is in there
        if ($part["payloadSize"] == 0) {
            throw new \Exception("No data sent for part ");
        }

        // Get the data out of there
        $data = substr($result, (6 * 4) + (8 * 4) + 73, $part["payloadSize"]);

        // Triple check the data matches the fingerprint
        if (md5($data) . sha1($data) != $fingerprint) {
            throw new \Exception("Failed to validate part hash");
        }

        // Part hash matches, return it
        return $data;
    }

    /**
     * Post data
     *
     * @param  string $method API method
     * @param  string $data   raw request
     * @return mixed  result from curl_exec
     */
    protected function post($method, $data)
    {
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $this->GetHeaders($method));
        curl_setopt($this->curl, CURLOPT_URL, $this->api_url . "/" . $this->GetEndpoint($method));
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curl, CURLOPT_POST, 1);

        $result = curl_exec($this->curl);

        // If curl grossly failed, throw
        if ($result == FALSE) {
            throw new \Exception("Curl failed to exec " . curl_error($this->curl));
        }

        return $result;
    }

    /**
     * Get endpoint
     *
     * @param  string $method API method
     * @return string uri of endpoint without leading slash
     */
    private function getEndpoint($method)
    {
        if ($method == "has_object_parts" || $method == "send_object_parts" || $method == "get_object_parts") {
            return $method;
        } else {
            return "jsonrpc";
        }
    }

    /**
     * Get headers
     *
     * @param  string $method API method
     * @return array  contains headers to use for HTTP requests
     */
    private function getHeaders($method)
    {
        $headers = array();
        $endpoint = "jsonrpc";

        if ($method == "has_object_parts" || $method == "send_object_parts" || $method == "get_object_parts") {
            array_push($headers, "Content-Type: application/octect-stream");
        }

        array_push($headers, "X-Api-Version: 1.0");
        array_push($headers, "X-Client-Type: api");
        array_push($headers, "X-Client-Time: " . time());
        array_push($headers, "Authorization: " .  $this->oauth->getRequestHeader('POST', $this->api_url . "/" . $this->GetEndpoint($method)));

        return $headers;
    }

    /**
     * Encode request
     *
     * @param  string $method API method
     * @param  array  $json   contains data to be encoded
     * @return string json formatted request
     */
    private function encodeRequest($method, $json)
    {
        $request["jsonrpc"] = "2.0";
        $request["id"] = "0";
        $request["method"] = $method;
        $request["params"] = $json;
        $request = json_encode($request, JSON_UNESCAPED_SLASHES);
        if ($this->debug) {
            print("Encoded request " . var_export($request) . "\n");
        }

        return $request;
    }
}
