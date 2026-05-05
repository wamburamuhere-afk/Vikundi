<?php
$r = file_get_contents('roots.php');
if (preg_match_all("/'templates' => (.*?),/", $r, $matches, PREG_OFFSET_CAPTURE)) {
    foreach ($matches[0] as $match) {
        $line = substr_count(substr($r, 0, $match[1]), "\n") + 1;
        echo "Found at line $line: " . $match[0] . PHP_EOL;
    }
}
