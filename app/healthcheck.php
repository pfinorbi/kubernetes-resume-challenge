<?php

// Set the appropriate headers for a JSON response
header('Content-Type: application/json');

// Define the health check status
$healthCheckStatus = array(
    'status' => 'OK'
);

// Set the HTTP status code to 200 OK
http_response_code(200);

// Encode the status array as JSON and output it
echo json_encode($healthCheckStatus);

?>