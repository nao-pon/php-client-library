<?php

function humanFileSize($size)
{
	if(!$size)
		return "";

	if((!$unit && $size >= 1 << 30))
		return number_format($size / (1 << 30), 2) . "GB";

	if((!$unit && $size >= 1 << 20))
		return number_format($size / (1 << 20), 2) . "MB";

	if((!$unit && $size >= 1 << 10))
		return number_format($size / (1 << 10),2) . "kB";

	return number_format($size) . "B";
}

?>
