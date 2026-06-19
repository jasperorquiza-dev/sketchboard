<?php
// test_smtp.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/plain');

$to = 'jaspe.test.email@gmail.com'; // Change this to the email you want to test
if (isset($_GET['email'])) {
    $to = $_GET['email'];
}

echo "Testing SMTP to: $to\n";
echo "====================================\n";

$host = 'smtp.gmail.com';
$port = 587;
$username = 'noreply.sketch45@gmail.com';
$password = 'caaz kydh lvvp hhrg';

try {
    $socket = fsockopen($host, $port, $errno, $errstr, 15);
    if (!$socket) {
        throw new Exception("Could not connect to SMTP host: $errstr ($errno)");
    }

    $read = function($socket) {
        $data = '';
        while (($line = fgets($socket, 512)) !== false) {
            echo "S: " . $line;
            $data .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $data;
    };

    $write = function($socket, $cmd) {
        echo "C: " . $cmd . "\n";
        fwrite($socket, $cmd . "\r\n");
    };

    $read($socket); // read banner
    
    $write($socket, "EHLO localhost");
    $read($socket);

    $write($socket, "STARTTLS");
    $res = $read($socket);
    if (strpos($res, '220') === false) {
        throw new Exception("STARTTLS failed");
    }

    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
        throw new Exception("Failed to start encryption");
    }

    $write($socket, "EHLO localhost");
    $read($socket);

    $write($socket, "AUTH LOGIN");
    $read($socket);

    $write($socket, base64_encode($username));
    $read($socket);

    $write($socket, base64_encode($password));
    $res = $read($socket);
    if (strpos($res, '235') === false) {
        throw new Exception("SMTP Authentication failed");
    }

    $write($socket, "MAIL FROM:<$username>");
    $read($socket);

    $write($socket, "RCPT TO:<$to>");
    $read($socket);

    $write($socket, "DATA");
    $read($socket);

    $headers = [
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "From: Sketchboard <$username>",
        "To: <$to>",
        "Subject: SMTP Test",
        "Date: " . date('r'),
    ];

    $emailData = implode("\r\n", $headers) . "\r\n\r\n" . "<h1>SMTP Test Success</h1>" . "\r\n.";
    $write($socket, $emailData);
    $res = $read($socket);

    $write($socket, "QUIT");
    fclose($socket);

    echo "\n====================================\n";
    echo "SUCCESS: Email sent successfully!\n";
} catch (Exception $e) {
    echo "\n====================================\n";
    echo "ERROR: " . $e->getMessage() . "\n";
}
