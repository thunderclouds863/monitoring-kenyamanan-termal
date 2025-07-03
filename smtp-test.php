<?php
$fp = fsockopen("smtp-relay.brevo.com", 587, $errno, $errstr, 10);
if (!$fp) {
    echo "ERROR: $errno - $errstr";
} else {
    echo "Connected!";
    fclose($fp);
}
?>
