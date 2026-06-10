<?php
require_once('library/library.php');

PHDE::debug(true);
PHDE::memory('512M');

PHTM::setZone('Asia/Dhaka');

// DIR::initialize(['rootDir' => '/homepages/42/d1017936617/htdocs/']);
// print DIR::getRootDir();
// PHRO::initialize('/projects/topup');
PHRO::guard();
PHRO::key("'4`145g^zy,zQ~:HP*i8-", false);
PHRO::track(false);

PHJT::key('}~0}5z:qrr1nV,Ow$rwW');
PHJT::algorithm('HS512');

// PHAU::identityLib('/identity-lib');

// PHRQ::livemap('/livemap', ['POST' => '/livemap'], 5, 60 * 24);
PHRQ::cross(true);

// PHCD::initialize('/cdn', DIR::path('css'), DIR::path('js'));

// PHDE::apibar('/apibar');

// PHEV::streamUI('/streamui', 3000);
// PHEV::initialize('/websocket', '192.168.1.10', 8000);

// PHJC::app('/app.js');
// PHRO::track(true);
// echo json_encode(PHRO::footprint());


// PHDB::$host = 'localhost';
// PHDB::$username = 'root';
// PHDB::$password = 'root';
// PHDB::$dbname = 'test';
// PHDB::$error = true;
// PHDE::debug(false);


import('app:*.php');




PHRO::get("/", function($data) {
    echo "Welcome to the mystack!";    
})->name('home')->sitemap(['priority' => '1.0', 'changeFreq' => 'daily'])->allow();




PHRO::listen(function($code, $message, $at) {
    http_response_code($code);
    if ($code === 404) {
        echo "<h1>Oops! 404 - Page Not Found</h1>";
        echo "<p>The page you are looking for does not exist.</p>";
    } elseif ($code === 405) {
        echo "<h1>Method Not Allowed! (405 Method Not Allowed)</h1>";
        echo "<p>Your request method is not allowed.</p>";
    } elseif ($code === 403) {
        echo "<h1>Access Denied! (403 Forbidden)</h1>";
        echo "<p>Reason: " . htmlspecialchars($message) . "</p>";
        if (PHDE::isDebug()) echo "<p>Source: " . htmlspecialchars($at) . "</p>";
    } elseif ($code === 429) {
        echo "<h1>429 Too Many Requests</h1>";
        echo "<p>" . htmlspecialchars($message) . "</p>";
    } elseif ($code >= 500) {
        echo "<h1>Server Error</h1>";
        echo "<p>Something went wrong on our end. Reason: " . htmlspecialchars($message) . " at " . htmlspecialchars($at) . "</p>";
    } else {
        throw new Exception("[$code] " . $message . " at " . $at, $code);
    }
});

PHDE::errors(true);
?>