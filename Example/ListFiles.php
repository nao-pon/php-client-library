<?php

include('../CloudApi/CloudApi.php');


function main()
{
	$consumerKey = $_SERVER['argv'][1];
	$consumerSecret = $_SERVER['argv'][2];
	$accessToken = $_SERVER['argv'][3];
	$tokenSecret = $_SERVER['argv'][4];
	$cloudPath = $_SERVER['argv'][5];
		
	// Create a cloud api connection to copy
	$ca = new CloudApi("http://api.qa.copy.com", $consumerKey,
		 $consumerSecret, $accessToken, $tokenSecret, false);

	print("Listing $cloudPath\n");

	$children = $ca->ListPath($cloudPath);

	foreach($children as $child)
		print($child->{"path"} . "\n");
}

main();

?>
