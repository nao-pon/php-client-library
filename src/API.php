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

    const HEADER_DELIMTER = 0xba5eba11;
    const PART_DELIMITER = 0xcab005e5;
    const FINGERPRINT_SIZE = 73;
    const HEADER_STRUCT_SIZE = 24; // 6 * 4
    const PART_HEADER_STRUCT_SIZE = 105; // 8 * 4 + FINGERPRINT_SIZE

    /**
     * API URl
     * @var string $api_url
     */
    protected $api_url = 'https://api.copy.com';

    /**
     * Instance of curl
     * @var resource $curl
     */
    private $curl;

    /**
     * @var array
     * User data
     */
    private $signature;

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
        $this->signature = array(
            'consumer_key' => $consumerKey,
            'shared_secret' => $consumerSecret,
            'oauth_token' => $accessToken,
            'oauth_secret' => $tokenSecret
        );

        // curl setup
        $this->curl = curl_init();
        if (!$this->curl) {
            throw new \Exception("Failed to initialize curl");
        }

        // ca bundle
        if (!is_file(__DIR__ . '/ca.crt')) {
            throw new \Exception("Failed to load ca certificate");
        }
        curl_setopt($this->curl, CURLOPT_CAINFO, __DIR__ . '/ca.crt');
    }

    

    /**
     * Send a request to remove a given file.
     *
     * @param string $path full path containing leading slash and file name
     *
     * @return bool True if the file was removed successfully
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

        $result = $this->post("update_objects", $this->encodeRequest("update_objects", array("meta" => array($request))));

        // Decode the json reply
        $result = json_decode($result);

        // Check for errors
        if (isset($result->error)) {
            throw new \Exception("Error removing file '" . $result->{"error"}->{"message"} . "'");
        }

        return true;
    }

    /**
     * Rename a file
     *
     * Object structure:
     * {
     *  object_id: "4008"
     *  path: "/example"
     *  type: "dir" || "file"
     *  share_id: "0"
     *  share_owner: "21956799"
     *  company_id: NULL
     *  size: filesize in bytes, 0 for folders
     *  created_time: unix timestamp, e.g. "1389731126"
     *  modified_time: unix timestamp, e.g. "1389731126"
     *  date_last_synced: unix timestamp, e.g. "1389731126"
     *  removed_time: unix timestamp, e.g. "1389731126" or empty string for non-deleted files/folders
     *  mime_type: string
     *  revisions: array of revision objects
     * }
     *
     * @param string $source_path full path containing leading slash and file name
     * @param string $destination_path full path containing leading slash and file name
     *
     * @return stdClass using structure as noted above
     */
    public function rename($source_path, $destination_path)
    {
        if ($this->debug) {
            print("Renaming object at path " . $source_path . " to " . $destination_path . "\n");
        }

        $request = array();
        $request["action"] = "rename";
        $request["path"] = $source_path;
        $request["new_path"] = $destination_path;

        $result = $this->post("update_objects", $this->encodeRequest("update_objects", array("meta" => array($request))));

        // Decode the json reply
        $result = json_decode($result);

        // Check for errors
        if (isset($result->error)) {
            throw new \Exception("Error renaming file '" . $result->{"error"}->{"message"} . "'");
        }

        // Return the object
        return $result->{"result"}[0]->{"object"};
    }

    /**
     * List objects within a path
     *
     * Object structure:
     * {
     *  object_id: "4008"
     *  path: "/example"
     *  type: "dir" || "file"
     *  share_id: "0"
     *  share_owner: "21956799"
     *  company_id: NULL
     *  size: filesize in bytes, 0 for folders
     *  created_time: unix timestamp, e.g. "1389731126"
     *  modified_time: unix timestamp, e.g. "1389731126"
     *  date_last_synced: unix timestamp, e.g. "1389731126"
     *  removed_time: unix timestamp, e.g. "1389731126" or empty string for non-deleted files/folders
     *  mime_type: string
     *  revisions: array of revision objects
     * }
     *
     * @param  string $path              full path with leading slash and optionally a filename
     * @param  array  $additionalOptions used for passing options such as include_parts
     *
     * @return array List of file/folder objects described above.
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

            $result = $this->post("list_objects", $this->encodeRequest("list_objects", $request));

            // Decode the json reply
            $result = json_decode($result);

            // Check for errors
            if (isset($result->error)) {
                throw new \Exception("Error listing path " . $path . ": '" . $result->{"error"}->{"message"} . "'");
            }

            // add the children if we got some, otherwise add the root object itself to the return
            if (isset($result->result->children) && empty($result->result->children) === false) {
                $return = array_merge($return, $result->result->children);
                $list_watermark = $result->result->list_watermark;
            } else {
                $return[] = $result->result->object;
            }
        } while (isset($result->result->more_items) && $result->result->more_items == 1);

        return $return;
    }

    /**
     * Create a file with a set of data parts
     *
     * Object structure:
     * {
     *  object_id: "4008"
     *  path: "/example"
     *  type: "dir" || "file"
     *  share_id: "0"
     *  share_owner: "21956799"
     *  company_id: NULL
     *  size: filesize in bytes, 0 for folders
     *  created_time: unix timestamp, e.g. "1389731126"
     *  modified_time: unix timestamp, e.g. "1389731126"
     *  date_last_synced: unix timestamp, e.g. "1389731126"
     *  removed_time: unix timestamp, e.g. "1389731126" or empty string for non-deleted files/folders
     *  mime_type: string
     *  revisions: array of revision objects
     * }
     *
     * @param string $path  full path containing leading slash and file name
     * @param array  $parts contains arrays of parts returned by \Barracuda\Copy\API\sendData
     *
     * @return object described above.
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

        $result = $this->post("update_objects", $this->encodeRequest("update_objects", array("meta" => array($request))));

        // Decode the json reply
        $result = json_decode($result);

        // Check for errors
        if (isset($result->error)) {
            throw new \Exception("Error creating file '" . $result->{"error"}->{"message"} . "'");
        }

        return $result->result[0]->object;
    }

    /**
     * Generate the fingerprint for a string of data.
     *
     * @param string $data Data part to generate the fingerprint for.
     *
     * @return string Fingerprint for $data.
    **/
    public function fingerprint($data)
    {
        return md5($data) . sha1($data);
    }

    /**
     * Send a piece of data
     *
     * @param  string $data    binary data
     * @param  int    $shareId setting this to zero is best, unless share id is known
     *
     * @return array  contains fingerprint and size, to be used when creating a file
     */
    public function sendData($data, $shareId = 0)
    {
        // first generate a part hash
        $fingerprint = $this->fingerprint($data);
        $part_size = strlen($data);

        // see if the cloud has this part, and send if needed
        if(!$this->hasPart($fingerprint, $part_size, $shareId))
            $this->sendPart($fingerprint, $part_size, $data, $shareId);

        // return information about this part
        return array("fingerprint" => $fingerprint, "size" => $part_size);
    }

    /**
     * Send a data part
     *
     * @param string $fingerprint md5 and sha1 concatenated
     * @param int    $size        number of bytes
     * @param string $data        binary data
     * @param int    $shareId     setting this to zero is best, unless share id is known
     *
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

        $request = array(
            'parts' => array(
                array(
                    'share_id' => $shareId,
                    'fingerprint' => $fingerprint,
                    'size' => $size,
                    'data' => 'BinaryData-0-' . $size
                )
            )
        );

        $result = $this->post("send_object_parts_v2", $this->encodeRequest("send_object_parts_v2", $request) . chr(0) . $data);

        // Decode the json reply
        $result = json_decode($result);

        // Check for errors
        if (isset($result->error->message)) {
            throw new \Exception("Error sending part: " . $result->error->message);
        }

        if ($result->result->has_failed_parts) {
            throw new \Exception("Error sending part: " . $result->result->failed_parts[0]->message);
        }
    }

    /**
     * Check to see if a part already exists
     *
     * @param  string $fingerprint md5 and sha1 concatenated
     * @param  int    $size        number of bytes
     * @param  int    $shareId     setting this to zero is best, unless share id is known
     * @return bool   true if part already exists
     */
    public function hasPart($fingerprint, $size, $shareId = 0)
    {
        if ($this->debug) {
            print("Checking if cloud has part $fingerprint \n");
        }

        $request = array(
            'parts' => array(
                array(
                    'share_id' => $shareId,
                    'fingerprint' => $fingerprint,
                    'size' => $size
                )
            )
        );

        $result = $this->post("has_object_parts_v2", $this->encodeRequest("has_object_parts_v2", $request));

        // Decode the json reply
        $result = json_decode($result);

        // Check for errors
        if (isset($result->error)) {
            throw new \Exception("Error checking for part");
        }

        if (empty($result->result->needed_parts)) {
            return true;
        } else {
            $part = $result->result->needed_parts[0];
            if (!empty($part->message)) {
                throw new \Exception("Error checking for part: " . $part->message);
            } else {
                return false;
            }
        }
    }

    /**
     * Get a part
     *
     * @param  string $fingerprint md5 and sha1 concatinated
     * @param  int    $size        number of bytes
     * @param  int    $shareId     setting this to zero is best, unless share id is known
     *
     * @return string binary data
     */
    public function getPart($fingerprint, $size, $shareId = 0)
    {
        if ($this->debug) {
            print("Getting part $fingerprint \n");
        }

        $request = array(
            'parts' => array(
                array(
                    'share_id' => $shareId,
                    'fingerprint' => $fingerprint,
                    'size' => $size
                )
            )
        );

        $result = $this->post("get_object_parts_v2", $this->encodeRequest("get_object_parts_v2", $request));

        // Split up the json and binary payload

        // Find the null byte
        if (($null_offset = strpos($result, chr(0))) != false) {
            // Grab the binary payload
            $binary = substr($result, $null_offset + 1, strlen($result) - $null_offset);

            if ($binary === false) {
                throw new \Exception("Error getting part data");
            }
        }

        // Grab the json payload
        $json = isset($binary) ? substr($result, 0, $null_offset) : $result;

        if ($json === false) {
            throw new \Exception("Error getting part data");
        }

        // Decode the json reply
        $result = json_decode($json);

        // Check for errors
        if (isset($result->error)) {
            throw new \Exception("Error getting part data");
        }

        if (isset($result->result->parts[0]->message)) {
            throw new \Exception("Error getting part data: " . $result->result->parts[0]->message);
        }

        // Get the part data (since there is only one part the binary payload should just be the data)
        if (strlen($binary) != $size) {
            throw new \Exception("Error getting part data");
        }

        return $binary;
    }

    /**
     * Create and execute cURL request to send data.
     *
     * @param  string $method API method
     * @param  string $data   raw request
     *
     * @return mixed  result from curl_exec
     */
    protected function post($method, $data)
    {
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $this->getHeaders($method));
        curl_setopt($this->curl, CURLOPT_URL, $this->api_url . "/" . $this->getEndpoint($method));
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
     * Return which cloud API end point to use for a given method.
     *
     * @param  string $method API method
     *
     * @return string uri of endpoint without leading slash
     */
    private function getEndpoint($method)
    {
        if ($method == "has_object_parts_v2" || $method == "send_object_parts_v2" || $method == "get_object_parts_v2") {
	        return "jsonrpc_binary";
        } else {
            return "jsonrpc";
        }
    }

    /**
     * Generate the HTTP headers need for a given Cloud API method.
     *
     * @param  string $method API method
     *
     * @return array  contains headers to use for HTTP requests
     */
    private function getHeaders($method)
    {
        $headers = array();

        $consumer = new \Eher\OAuth\Consumer($this->signature['consumer_key'], $this->signature['shared_secret']);
        $signatureMethod = new \Eher\OAuth\HmacSha1();
        $token = new \Eher\OAuth\Token($this->signature['oauth_token'], $this->signature['oauth_secret']);
        $request = \Eher\OAuth\Request::from_consumer_and_token(
            $consumer,
            $token,
            'POST',
            $this->api_url . "/" . $this->GetEndpoint($method),
            array()
        );
        $request->sign_request($signatureMethod, $consumer, $token);

        if ($method == "has_object_parts_v2" || $method == "send_object_parts_v2" || $method == "get_object_parts_v2") {
            array_push($headers, "Content-Type: application/octect-stream");
        }

        array_push($headers, "X-Api-Version: 1.0");
        array_push($headers, "X-Client-Type: api");
        array_push($headers, "X-Client-Time: " . time());
        array_push($headers, $request->to_header());

        return $headers;
    }

    /**
     * JSON encode request data.
     *
     * @param  string $method Cloud API method
     * @param  array  $json   contains data to be encoded
     *
     * @return string JSON formatted request body
     */
    private function encodeRequest($method, $json)
    {
        $request["jsonrpc"] = "2.0";
        $request["id"] = "0";
        $request["method"] = $method;
        $request["params"] = $json;
        $request = str_replace('\\/', '/', json_encode($request));
        if ($this->debug) {
            print("Encoded request " . var_export($request) . "\n");
        }

        return $request;
    }
}
