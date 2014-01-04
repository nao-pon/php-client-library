<?php

include('../CloudApi/CloudApi.php');
include('ExampleTools.php');

$consumerKey = $_SERVER['argv'][1];
$consumerSecret = $_SERVER['argv'][2];
$accessToken = $_SERVER['argv'][3];
$tokenSecret = $_SERVER['argv'][4];
$localPath = $_SERVER['argv'][5];
$cloudPath = $_SERVER['argv'][6];
	
// Create a cloud api connection to copy
$ca = new CloudApi("http://api.qa.copy.com", $consumerKey,
	 $consumerSecret, $accessToken, $tokenSecret, false);

// Ensure the local file exists
$fh = fopen($localPath, "rb");
if(!$fh)
	die("Failed to open $localPath\n");

// Send it up, 1MB at a time
print("Sending $localPath to $cloudPath\n");

$parts = array();
while($data = fread($fh, 1024 * 1024))
	array_push($parts, $ca->SendData($data));
fclose($fh);

// Now update the file in the cloud
$ca->CreateFile($cloudPath, $parts);

print("Successfully created/modified file $cloudPath . \n");

?>
