<?php
require_once('library/library.php');

PHDE::debug(false);
PHDE::memory('512M');

PHTM::setZone('Asia/Dhaka');

PHMO::configure(['enabled' => true]);

// DIR::initialize(['rootDir' => '/homepages/42/d1017936617/htdocs/']);
// print DIR::getRootDir();
// PHRO::initialize('/projects/topup');
PHRO::guard();
PHRO::key("'4`145g^zy,zQ~:HP*i8-", false);
PHRO::track(false);

PHJT::key('}~0}5z:qrr1nV,Ow$rwW');
PHJT::algorithm('HS512');

// PHRQ::livemap('/livemap', ['POST' => '/livemap'], 5, 60 * 24);
PHRQ::cross(true);

// PHAU::identityLib('/identity-lib');

// PHCD::initialize('/cdn', DIR::path('css'), DIR::path('js'));

// PHDE::apibar('/apibar');

// PHEV::streamUI('/streamui', 3000);
// PHEV::initialize('/websocket', 'x.x.x.x', 8000);

// PHJC::app('/app.js');
// PHRO::track(true);
// echo json_encode(PHRO::footprint());

// PHMO::dashboard('/monitor');
// PHMO::registerRoutes();

PHDB::$host = '';
PHDB::$username = '';
PHDB::$password = '';
PHDB::$dbname = '';
// PHDB::$error = true;
// PHDE::debug(false);


// PHEM::smtp('', 587, 'tls');
// PHEM::smtpLogin('', '');


import('app:*.php');

PHFY::configure(['enabled' => false]);
PHFY::registerRoutes();



PHML::init();
PHRO::get('/', function($data) {
	echo "HI";
});

PHRO::listen(function ($code, $message, $at) {
    http_response_code($code);
    if ($code === 404) {
        echo "<h1>Oops! 404 - Page Not Found</h1>";
    } elseif ($code === 405) {
        echo "<h1>Method Not Allowed! (405)</h1>";
    } elseif ($code === 403) {
        echo "<h1>Access Denied! (403)</h1>";
    } elseif ($code === 429) {
        echo "<h1>429 Too Many Requests</h1>";
    } elseif ($code >= 500) {
        echo "<h1>Server Error</h1>";
        if (PHDE::isDebug())
            echo "<p>Reason: " . htmlspecialchars($message) . " at " . htmlspecialchars($at) . "</p>";
    } else {
        throw new Exception("[$code] " . $message . " at " . $at, $code);
    }
});

PHDE::errors(PHDE::isDebug());
?>