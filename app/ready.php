<?php

// Set the appropriate headers for a JSON response
header('Content-Type: application/json');

// Check if the application dependencies are ready
$dbHost = getenv('DB_HOST');
$dbUser = getenv('DB_USER');
$dbPassword = getenv('DB_PASSWORD');
$dbName = getenv('DB_NAME');

// Attempt to connect to the database
$link = @mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);

if (!$link) {
    $isReady = false;
} else {
    $isReady = true;
}

// Define the readiness status based on the check result
$readinessStatus = array(
    'status' => $isReady ? 'OK' : 'NOT_READY',
);

// Set the HTTP status code based on readiness status
http_response_code($isReady ? 200 : 503); // 200 OK if ready, 503 Service Unavailable if not ready

// Encode the status array as JSON and output it
echo json_encode($readinessStatus);

?>