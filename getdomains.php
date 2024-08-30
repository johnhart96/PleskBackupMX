<?php
/*
* Project: PleskBackupMX
* Author: John Hart of JH96 Hosting LTD
* Version: 1.0
* Last touched on: 2024-08-30
*/
if( ! file_exists( "configuration.xml" ) ) {
    die( "Unable to load configuration.xml" );
}
echo "Loading configuration.xml\n";
$config = simplexml_load_file( "configuration.xml" );
$plesk_host = $config->host;

// Initialize a cURL session
$ch = curl_init();

// Set cURL options
curl_setopt($ch, CURLOPT_URL, $config->plesk->host . '/enterprise/control/agent.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  // Ignore SSL certificate errors
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'HTTP_AUTH_LOGIN: ' . $config->plesk->username,
    'HTTP_AUTH_PASSWD: ' . $config->plesk->password,
    'Content-Type: text/xml'
]);

// Define the XML request to retrieve the list of domains
$request_xml = '<?xml version="1.0" encoding="UTF-8"?>
<packet version="1.6.3.0">
    <webspace>
        <get>
            <filter/>
            <dataset>
                <gen_info/>
            </dataset>
        </get>
    </webspace>
</packet>';

// Attach the XML request to the cURL session
echo "Talking to plesk\n";
curl_setopt($ch, CURLOPT_POSTFIELDS, $request_xml);

// Execute the cURL session and get the response
echo "Collecting results\n";
$response = curl_exec($ch);

// Check for errors
if ($response === false) {
    die('Error occurred: ' . curl_error($ch));
}

// Close the cURL session
curl_close($ch);

// Parse the XML response
$xml = simplexml_load_string($response);

// Handle errors in response
if ($xml === false) {
    die('Error parsing the XML response.');
}

// Get the list of domains
echo "Assembeling list of domains on plesk\n";
$domains = [];
foreach ($xml->webspace->get->result as $webspace) {
    if ($webspace->status == 'ok') {
        $domains[] = (string) $webspace->data->gen_info->name;
    }
}

// Init MySQL connection
$iredmail = new PDO( "mysql:host=" . $config->iredmail->host . ";dbname=" . $config->iredmail->database , $config->iredmail->username , $config->iredmail->password );

// Prepair queries & remove all existing backupmx domains
echo "Removing all existing backupmx domains\n";
$remove = $iredmail->query( "DELETE FROM domain WHERE backupmx=1" );
$checkifDomainExists = $iredmail->prepare( "SELECT * FROM  domain WHERE domain =:domainName LIMIT 1" );
$addDomain = $iredmail->prepare("
    INSERT INTO domain
    (domain,transport,backupmx,active,created)
    VALUES(:domainName,:transport,1,1,:created)

");

// Output the list of domains
foreach ($domains as $domain) {
    // Check if domains exist in iRedmail already
    echo "Processing domain " . $domain . "\n";
    
    try {
        $addDomain->execute([
            ":transport" => "[" . $config->primaryMX . "]:25",
            ":created" => date( "Y-m-d H:i:s" ),
            ":domainName" => $domain
        ]);
        echo "...adding\n";
    } catch (Exception $e) { 
        echo "...failed\n";
    }
}

?>
