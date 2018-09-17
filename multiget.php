#!/usr/bin/php
<?php

# could be handled in php.ini
ini_set("log_errors", 1);
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set("error_log", "/var/log/php-error.log");

const MEBIBYTE = 1048576;

/*
 *@param $argc int
 *@param $argv array of string
 *@return void
 *@description
 * - first check for errors from the command line
 * - make a request to get the file size
 * - prepare curls
 * - run them sequentually or in parallel
 * - write the data to a file
 * - exit the script
 */
function main($argc, $argv) {

    # if there is any errors with argv's 
    $errMessage = validate($argc, $argv);
    if (!empty($errMessge)) {
        echo $message;
        exit(1);
    }

    $url = end($argv);

    # get values from args passed in to the script
    $options = getopt("o::p");
    $parallel = array_key_exists('p', $options)
        ? true
        : false;
    $outputFile = !empty($options['o'])
        ? $options['o']
        : getFileNameForUrl($url);

    # send a file size req via curl
    $fileSize = getFileSizeFromUrl($url);
    echo "the request size of the url is ".$fileSize."\n";

    # if the file is bigger than 4 mebibytes
    # calculate a reasoable chunkSize and how many
    # curl clients to download the data
    if ($fileSize < (MEBIBYTE * 4)) {
        $chunkSize = findGoodChunksize($fileSize);
        $numOfCurls = $fileSize / $chunkSize; 
    }
    # just download a mebibyte at a time
    else {
        $chunkSize = MEBIBYTE;
        $numOfCurls = 4;
    }

    echo "going to download ".$fileSize." bytes of data \n";

    # prep the curl handlers 
    $curlHandlers = [];
    for ($index = 0; $index < $numOfCurls; $index++) {
        $curlHandlers[] = getCurlHandlerForChunk($url, $index, $chunkSize);
    }

    # run curls either sequentually or in parallel
    $data = runCurls($curlHandlers, $parallel);
    file_put_contents($outputFile, $data);
    echo "got back ".strlen($data)." bytes of data\n".
        " and wrote them to file: ".$outputFile."\n";

    exit(0);
}

/*
 *@param $curlHandlers curl resource
 *@param $parallel boolean
 *@return string
 */
function runCurls($curlHandlers, $parallel) {
    if ($parallel) {
        $data = runCurlsInParallel($curlHandlers);
    }
    else {
        $data = runCurlsSequentually($curlHandlers);
    }
    return $data;
}

/*
 *@param $fileSize int
 *@returns int
 */
function findGoodChunkSize($fileSize) {
    # first try to see there is a divisor greater
    # or equal than 20 as the download might go
    # faster
    for($i=4; $i <= 20; $i++)
    {
        if($fileSize % $i === 0)
        {
            return $fileSize / $i;
        }
    }

    # try a last ditch effort
    # to get something better than
    # just 1 curl handler
    if ($fileSize % 2 === 0) {
        return $fileSize / 2;
    }

    # couldn't find a good chunk size
    # at this point just downlad the file already
    return $fileSize;
}


/*
 *@param $url string
 *@return string
 *
 *@description 
 * parse url and try to look for the last path
 * of the url and set that as a default file name
 */
function getFileNameForUrl($url) {
    $urlParts = parse_url($url);
    # no path?
    # just get back the host (test.com)
    if (empty($urlParts['path'])) {
        return $urlParts['host'];
    }

    # did have path so try to get the last item
    $pathParts = explode("/", $urlParts['path']);

    return end($pathParts);
}

/*
 *@param $curlHandlers array of curl_resource
 *@return string
 *
 *@description
 * iterate through the curl hanlders and concat
 * their result into a string
 */
function runCurlsSequentually($curlHandlers) {
    $data = '';

    foreach($curlHandlers as $ch) {
        $data .= curl_exec($ch);
    }
    return $data;
}

/*
 *@param $curlHandlers array of curl_resource
 *@return string
 *
 *@description
 * use curl_multi fns
 * to run curls in parallel
 */
function runCurlsInParallel($curlHandlers) {
    $mh = curl_multi_init();
    $data = '';
    foreach($curlHandlers as $ch) {
        curl_multi_add_handle($mh, $ch);
    }
    $active = null;

    do {
        $mrc = curl_multi_exec($mh, $active);
    }
    while ($mrc === CURLM_CALL_MULTI_PERFORM);

    while ($active && $mrc === CURLM_OK) {
        if (curl_multi_select($mh) === -1) {
            usleep(1);
        }

        do {
            $mrc = curl_multi_exec($mh, $active);
        }
        while ($mrc === CURLM_CALL_MULTI_PERFORM);
    }

    # requests are finished
    # so get their result and concat them together
    foreach($curlHandlers as $ch) {
        $data .= curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    return $data;
}

/*
 *@param $url string
 *@param $chunkIndex int
 *@param $chunkSize  int
 *@return curl_resource
 */
function getCurlHandlerForChunk($url, $chunkIndex, $chunkSize) {
    $lowEnd = ($chunkIndex * $chunkSize);
    $highEnd = ($lowEnd + $chunkSize) - 1;
    $range = $lowEnd . "-". $highEnd;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RANGE, $range);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    return $ch;
}

/*
 *@param $argc int
 *@param $argv array mixed
 *@return string
 */
function validate($argc, $argv) {
    $message = '';
    if (!function_exists('curl_init')) {
        $message = "curl support is required to run this script\n";
    }

    if ($argc < 2) {
        $message = "Useage: ./muiltiget.php [OPTIONS] url\n".
            "  -o\"string\"\n".
            "\tWrite output to <file> isntead of default\n".
            "  -p\n".
            "\tDownload chunks in parallel instead of sequentally\n";
    }

    if (filter_var(end($argv), FILTER_VALIDATE_URL)===false) {
        $message = "Invalid Url\n";
    }

    return $message;
}

/*
 *@param $url string
 *@return int
 *
 *@description
 * make a request to see how many bytes
 * we are gonna download
 */
function getFileSizeFromUrl($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);

    return $size;
}

# call main
main($argc, $argv);
