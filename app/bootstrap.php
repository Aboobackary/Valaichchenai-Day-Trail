<?php
declare(strict_types=1);

 
$configFolder = __DIR__ . '/../config/';
$configFile = is_file($configFolder . 'config.php')
    ? $configFolder . 'config.php'
    : $configFolder . 'config.example.php';
$config = require $configFile;

if (!is_array($config)) {
    throw new RuntimeException('The configuration file must return an array.');
}

 
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $httpsValue = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $isHttps = $httpsValue !== '' && $httpsValue !== 'off' && $httpsValue !== '0';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isHttps
    ]);
    if (!session_start()) {
        throw new RuntimeException('Could not start a session. Check the PHP session settings.');
    }
}

if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])
    || !preg_match('/^[a-f0-9]{64}$/D', $_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

 
function db(): PDO
{
    global $config;
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }
    if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('Enable the PDO MySQL extension in PHP to use the database.');
    }

    foreach (['db_host', 'db_name', 'db_user', 'db_password'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException('Check the ' . $key . ' value in config/config.php.');
        }
    }
    $port = filter_var($config['db_port'] ?? 3306, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535]
    ]);
    if ($port === false || $config['db_host'] === '' || $config['db_name'] === '') {
        throw new RuntimeException('Enter a valid database host, port and database name.');
    }
     
    if (strpbrk($config['db_host'] . $config['db_name'], ";\r\n\0") !== false) {
        throw new RuntimeException('The database host and name contain invalid characters.');
    }

    $dsn = 'mysql:host=' . $config['db_host']
        . ';port=' . $port
        . ';dbname=' . $config['db_name']
        . ';charset=utf8mb4';

    $connection = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    return $connection;
}

 
function query(string $sql, array $args = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($args);
    return $statement;
}

 
function e($value): string
{
    if ($value === null) {
        return '';
    }
    if (!is_scalar($value)) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

 
function csrf(): void
{
    $storedToken = $_SESSION['csrf'] ?? null;
    $submittedToken = $_POST['csrf'] ?? null;
    if (!is_string($storedToken) || $storedToken === ''
        || !is_string($submittedToken) || $submittedToken === ''
        || !hash_equals($storedToken, $submittedToken)) {
        throw new RuntimeException('Your session token expired. Reload the page and try again.');
    }
}

 
function user(): ?array
{
    $account = $_SESSION['user'] ?? null;
    if (!is_array($account)
        || !isset($account['id'], $account['name'], $account['role'])
        || !is_string($account['name'])
        || !in_array($account['role'], ['visitor', 'admin'], true)) {
        return null;
    }
    $id = $account['id'];
    if ((!is_int($id) && !is_string($id))
        || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        return null;
    }
    return $account;
}

function require_user(bool $admin = false): array
{
    $account = user();
    if ($account === null || ($admin && $account['role'] !== 'admin')) {
        if (!headers_sent()) {
            http_response_code(403);
        }
        throw new RuntimeException('Please sign in with an authorised account.');
    }
    return $account;
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

 
function redirect(string $url): void
{
    if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
        || str_starts_with($url, '//')
        || parse_url($url, PHP_URL_SCHEME) !== null
        || parse_url($url, PHP_URL_HOST) !== null) {
        throw new RuntimeException('Invalid redirect address.');
    }
    header('Location: ' . $url, true, 303);
    exit;
}

 
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    foreach ([[$lat1, $lng1], [$lat2, $lng2]] as [$latitude, $longitude]) {
        if (!is_finite($latitude) || !is_finite($longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Latitude or longitude is outside its valid range.');
        }
    }

    $latitudeChange = deg2rad($lat2 - $lat1);
    $longitudeChange = deg2rad($lng2 - $lng1);
    $value = sin($latitudeChange / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($longitudeChange / 2) ** 2;
     
    $value = max(0.0, min(1.0, $value));
    return 6371.0 * 2 * asin(sqrt($value));
}

 
function place_coordinates(array $point): array
{
    $coordinates = [];
    foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $field => $range) {
        $raw = $point[$field] ?? null;
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            throw new InvalidArgumentException('Missing or invalid ' . $field . '.');
        }
        $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
        if ($value === false || !is_finite($value) || $value < $range[0] || $value > $range[1]) {
            throw new InvalidArgumentException('Missing or invalid ' . $field . '.');
        }
        $coordinates[] = (float) $value;
    }
    return $coordinates;
}

function distance(array $place): float
{
    global $config;
    [$homeLatitude, $homeLongitude] = place_coordinates([
        'latitude' => $config['home_lat'] ?? null,
        'longitude' => $config['home_lng'] ?? null
    ]);
    [$latitude, $longitude] = place_coordinates($place);
    return haversine($homeLatitude, $homeLongitude, $latitude, $longitude);
}

 
function all_places(bool $admin = false): array
{
    global $config;
    if ($admin) {
        require_user(true);
    }
    $radius = filter_var($config['radius_km'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($radius === false || !is_finite($radius) || $radius <= 0) {
        throw new RuntimeException('Configure a positive radius_km value. The project requires 25 km.');
    }
    $sql = 'SELECT p.*, c.category_name FROM tourist_place p '
        . 'JOIN category c ON c.id = p.category_id';
    if (!$admin) {
        $sql .= ' WHERE p.active = 1';
    }
    $sql .= ' ORDER BY p.name';

    $places = [];
    foreach (query($sql)->fetchAll() as $place) {
        $place['distance_km'] = distance($place);
        if ($admin || $place['distance_km'] <= $radius) {
            $places[] = $place;
        }
    }
    return $places;
}

 
function directions(array $places): string
{
    global $config;
    if (!$places) {
        return '#';
    }
    [$homeLatitude, $homeLongitude] = place_coordinates([
        'latitude' => $config['home_lat'] ?? null,
        'longitude' => $config['home_lng'] ?? null
    ]);
    $stops = [];
    foreach ($places as $place) {
        [$latitude, $longitude] = place_coordinates($place);
        $stops[] = $latitude . ',' . $longitude;
    }
    $parameters = [
        'api' => 1,
        'origin' => $homeLatitude . ',' . $homeLongitude,
        'destination' => array_pop($stops),
        'travelmode' => 'driving'
    ];
    if ($stops) {
        $parameters['waypoints'] = implode('|', $stops);
    }
    return 'https://www.google.com/maps/dir/?'
        . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
}

 
function image_url(array $place): string
{
    $url = $place['image'] ?? '';
    if (!is_string($url) || $url === '') {
        return '';
    }
    $remote = filter_var($url, FILTER_VALIDATE_URL)
        && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
        && parse_url($url, PHP_URL_HOST)
        && parse_url($url, PHP_URL_USER) === null
        && parse_url($url, PHP_URL_PASS) === null;
    $local = preg_match('~^assets/[a-zA-Z0-9/_ .-]+\.(jpg|jpeg|png|webp)$~iD', $url)
        && !in_array('..', explode('/', $url), true);
    return ($remote || $local) ? $url : '';
}

 
function time_label(int $minutes): string
{
    if ($minutes < 0) {
        throw new InvalidArgumentException('Time cannot be negative.');
    }
    $days = intdiv($minutes, 1440);
    $minutesToday = $minutes % 1440;
    $label = sprintf('%02d:%02d', intdiv($minutesToday, 60), $minutesToday % 60);
    if ($days > 0) {
        $label .= ' (+' . $days . ($days === 1 ? ' day)' : ' days)');
    }
    return $label;
}

require_once __DIR__ . '/planner.php';