<?php
declare(strict_types=1);

 
ob_start();

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/actions.php';
require_once __DIR__ . '/app/views.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');

 
function dt_text(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? null;

    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        return $default;
    }

    return trim((string) $value);
}

 
function dt_id(array $source, string $key): int
{
    $value = $source[$key] ?? null;

    if (!is_string($value) && !is_int($value)) {
        return 0;
    }

    $id = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    return $id === false ? 0 : (int) $id;
}

 
function dt_valid_plan($plan): bool
{
    if (
        !is_array($plan) ||
        !isset($plan['stops']) ||
        !is_array($plan['stops']) ||
        !$plan['stops']
    ) {
        return false;
    }

    foreach (['start', 'pace', 'companion'] as $key) {
        if (!isset($plan[$key]) || !is_string($plan[$key])) {
            return false;
        }
    }

    foreach (['total_km', 'travel_minutes', 'finish'] as $key) {
        if (
            !isset($plan[$key]) ||
            !is_numeric($plan[$key]) ||
            !is_finite((float) $plan[$key]) ||
            (float) $plan[$key] < 0
        ) {
            return false;
        }
    }

    if (isset($plan['warnings']) && !is_array($plan['warnings'])) {
        return false;
    }

    foreach ($plan['warnings'] ?? [] as $warning) {
        if (!is_string($warning)) {
            return false;
        }
    }

    foreach ($plan['stops'] as $stop) {
        if (
            !is_array($stop) ||
            !isset($stop['place']) ||
            !is_array($stop['place']) ||
            !isset($stop['place']['name']) ||
            !is_string($stop['place']['name'])
        ) {
            return false;
        }

        foreach (
            ['arrival', 'end', 'visit_minutes', 'travel_minutes', 'leg_km']
            as $key
        ) {
            if (
                !isset($stop[$key]) ||
                !is_numeric($stop[$key]) ||
                !is_finite((float) $stop[$key]) ||
                (float) $stop[$key] < 0
            ) {
                return false;
            }
        }

        if (isset($stop['warnings']) && !is_array($stop['warnings'])) {
            return false;
        }

        foreach ($stop['warnings'] ?? [] as $warning) {
            if (!is_string($warning)) {
                return false;
            }
        }
    }

    return true;
}

 
function dt_decode_plan($json): ?array
{
    if (!is_string($json) || $json === '') {
        return null;
    }

    $plan = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE || !dt_valid_plan($plan)) {
        return null;
    }

    return $plan;
}

 

$allowedPages = [
    'home',
    'explore',
    'place',
    'map',
    'planner',
    'summary',
    'login',
    'register',
    'saved',
    'admin',
    'edit',
    'about'
];

$page = dt_text($_GET, 'page', 'home');

if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'missing';
}

$pageTitles = [
    'home' => 'Home',
    'explore' => 'Explore places',
    'place' => 'Place details',
    'map' => 'Explore the map',
    'planner' => 'Plan your day',
    'summary' => 'Your day plan',
    'login' => 'Sign in',
    'register' => 'Create an account',
    'saved' => 'My saved plans',
    'admin' => 'Manage places',
    'edit' => 'Place editor',
    'about' => 'About DayTrail',
    'missing' => 'Page not found'
];

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$postAction = dt_text($_POST, 'action');

$error = '';
$ready = true;
$accessAllowed = true;
$places = [];
$categories = [];



if ($page !== 'missing' && $isPost) {
    try {
        handle_action();
    } catch (PDOException $exception) {
        http_response_code(500);
        error_log($exception->getMessage());

        $error = 'Real Error: ' 
        . $exception->getMessage();
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if (http_response_code() < 400) {
            http_response_code(400);
        }

        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        http_response_code(500);
        error_log($exception->getMessage());

        $error = 'The request could not be completed. Please try again.';
    }
}

 

$currentUser = user();

if (in_array($page, ['admin', 'edit'], true)) {
    $accessAllowed = is_array($currentUser)
        && ($currentUser['role'] ?? '') === 'admin';
} elseif (
    $page === 'saved' ||
    ($page === 'summary' && array_key_exists('id', $_GET))
) {
    $accessAllowed = is_array($currentUser);
}

if (!$accessAllowed) {
    http_response_code(403);

    $error = in_array($page, ['admin', 'edit'], true)
        ? 'Please sign in with an administrator account to access this page.'
        : 'Please sign in to access your saved plans.';
}

 

if ($accessAllowed && $page !== 'missing') {
    try {
        $places = all_places();

        $categories = query(
            'SELECT * FROM category ORDER BY category_name'
        )->fetchAll();
    } catch (PDOException $exception) {
        http_response_code(503);
        error_log($exception->getMessage());

        $ready = false;
        $error = 'Real Error: ' . $exception->getMessage();
        
    } catch (Throwable $exception) {
        http_response_code(500);
        error_log($exception->getMessage());

        $ready = false;
        $error = 'DayTrail could not load its catalogue. '
            . 'Check the application configuration and server error log.';
    }
}

$homeVerified = !empty($config['home_verified']);

$radiusLabel = isset($config['radius_km']) && is_numeric($config['radius_km'])
    ? rtrim(rtrim(number_format((float) $config['radius_km'], 2, '.', ''), '0'), '.')
    : 'Not configured';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$title = $pageTitles[$page] ?? 'DayTrail';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta
        name="description"
        content="Discover Valaichenai, Pasikudah and Kalkudah. Create a personal one-day itinerary with DayTrail."
    >

    <title><?= e($title) ?> · Valaichchenai DayTrail</title>

    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">

     
    <link rel="stylesheet" href="assets/vendor/leaflet.css">
    <link rel="stylesheet" href="assets/style.css">

    
    <script defer src="assets/vendor/leaflet.js"></script>
    <script defer src="assets/app.js"></script>
</head>

<body>
<a class="skip" href="#main">Skip to content</a>

<header class="site-header">
    <a class="brand" href="?page=home">
        ◈ <span>DayTrail<small>VALAICHCHENAI</small></span>
    </a>

    <button
        class="menu-toggle secondary"
        type="button"
        aria-expanded="false"
        aria-controls="navigation"
    >
        Menu
    </button>

    <nav id="navigation" aria-label="Main navigation">
        <?php
        $navigation = [
            'explore' => 'Explore places',
            'map' => 'Map',
            'planner' => 'Plan your day'
        ];

        foreach ($navigation as $key => $label):
            ?>
            <a
                href="?page=<?= e($key) ?>"
                <?= $page === $key ? 'aria-current="page"' : '' ?>
            >
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>

        <?php if ($currentUser): ?>
            <a
                href="?page=saved"
                <?= $page === 'saved' ? 'aria-current="page"' : '' ?>
            >
                Saved plans
            </a>

            <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                <a
                    href="?page=admin"
                    <?= in_array($page, ['admin', 'edit'], true)
                        ? 'aria-current="page"' : '' ?>
                >
                    Admin
                </a>
            <?php endif; ?>

            <form method="post" action="?page=home">
                <?php token(); ?>
                <input type="hidden" name="action" value="logout">
                <button class="text-button" type="submit">Sign out</button>
            </form>
        <?php else: ?>
            <a class="button small" href="?page=login">Sign in</a>
        <?php endif; ?>
    </nav>
</header>

<main id="main" class="container" tabindex="-1">
    <?php if (is_string($flash) && $flash !== ''): ?>
        <div class="notice success" role="status"><?= e($flash) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$accessAllowed): ?>
        <section class="panel">
            <h1>Sign-in required</h1>
            <p>Use an account with permission to access this page.</p>
            <div class="actions">
                <a class="button" href="?page=login">Sign in</a>
                <a href="?page=explore">Explore places</a>
            </div>
        </section>

    <?php elseif (!$ready): ?>
        <section class="panel">
            <h1>DayTrail is temporarily unavailable</h1>
            <p>
                For a local installation, check Apache, MySQL and the
                database configuration. Import the SQL file only during
                initial setup; do not overwrite an existing database
                just to refresh a page.
            </p>
            <a class="button secondary" href="?page=home">Try again</a>
        </section>

    <?php else: ?>
        <?php
       
        ob_start();

        try {
            ?>
            <?php if (
                !$homeVerified &&
                !in_array(
                    $page,
                    ['admin', 'edit', 'login', 'register', 'missing'],
                    true
                )
            ): ?>
                <p class="notice">
                    Demo catalogue: distances use the configured reference
                    point. Set your registered home and verify place pins
                    before relying on this plan.
                </p>
            <?php endif; ?>

            <?php if ($page === 'home'): ?>
                <?php
                $withPhotos = array_values(array_filter(
                    $places,
                    static fn(array $place): bool => image_url($place) !== ''
                ));

                $heroPlace = $withPhotos[0] ?? null;

           
                foreach ($withPhotos as $candidate) {
                    if (
                        stripos((string) $candidate['name'], 'pasikudah') !== false ||
                        stripos((string) $candidate['name'], 'passikudah') !== false
                    ) {
                        $heroPlace = $candidate;
                        break;
                    }
                }

                $featured = array_slice(
                    $withPhotos ?: $places,
                    0,
                    3
                );

                $gems = array_values(array_filter(
                    $places,
                    static fn(array $place): bool => !empty($place['hidden_gem'])
                ));
                ?>

                <section class="hero">
                    <div class="hero-copy">
                        <p class="eyebrow">
                            VALAICHENAI · PASIKUDAH · KALKUDAH
                        </p>

                        <h1>
                            Your next day out,<br>
                            <span class="gradient-text">made simple.</span>
                        </h1>

                        <p>
                            Find a place you love. Choose your stops.
                            Create a one-day plan and check how it fits your time.
                        </p>

                        <div class="hero-actions">
                            <a class="button" href="?page=planner">
                                Create my day plan
                            </a>
                            <a class="button secondary" href="?page=explore">
                                Explore places
                            </a>
                        </div>

                        <form action="index.php" method="get" class="hero-search">
                            <input type="hidden" name="page" value="explore">

                            <label class="sr-only" for="hero-q">
                                Search local places
                            </label>

                            <input
                                id="hero-q"
                                type="search"
                                name="q"
                                placeholder="Search a beach, temple or local spot"
                            >

                            <button type="submit">Search</button>
                        </form>

                        <div class="hero-facts">
                            <span>
                                <b><?= count($places) ?></b> local places
                            </span>
                            <span>
                                <b><?= e($radiusLabel) ?> km</b> around home
                            </span>
                            <span>
                                <b>No login</b> needed to plan
                            </span>
                        </div>
                    </div>

                    <div class="hero-visual">
                        <?php if ($heroPlace): ?>
                            <?php photo($heroPlace); ?>

                            <div class="image-caption">
                                <span>DISCOVER THE EAST COAST</span>
                                <h2><?= e($heroPlace['name']) ?></h2>

                                <a href="?page=place&amp;id=<?= (int) $heroPlace['id'] ?>">
                                    View place
                                </a>

                                <?php if (!empty($heroPlace['image_credit'])): ?>
                                    <p><?= e($heroPlace['image_credit']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-photo">
                                <span>Local place photos will appear here.</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <ol class="quick-steps" aria-label="How to plan your day">
                    <li>
                        <span>1</span>
                        <div>
                            <strong>Find your places</strong>
                            <small>Browse beaches, culture and local gems.</small>
                        </div>
                    </li>
                    <li>
                        <span>2</span>
                        <div>
                            <strong>Set your time</strong>
                            <small>Choose your start, pace and companions.</small>
                        </div>
                    </li>
                    <li>
                        <span>3</span>
                        <div>
                            <strong>Enjoy your day</strong>
                            <small>Check your plan and open directions.</small>
                        </div>
                    </li>
                </ol>

                <nav class="category-chips" aria-label="Browse categories">
                    <a class="chip all" href="?page=explore">All places</a>

                    <?php foreach ($categories as $category): ?>
                        <a
                            class="chip"
                            href="?page=explore&amp;category=<?= (int) $category['id'] ?>"
                        >
                            <?= e($category['category_name']) ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <section>
                    <div class="section-head">
                        <div>
                            <p class="eyebrow">START EXPLORING</p>
                            <h2>Places to start with</h2>
                        </div>
                        <a href="?page=explore">See all places</a>
                    </div>

                    <?php cards($featured); ?>
                </section>

                <section class="gem-section">
                    <div class="section-head">
                        <div>
                            <p class="eyebrow">MORE TO DISCOVER</p>
                            <h2>Hidden local gems</h2>
                        </div>
                        <a href="?page=explore&amp;gems=1">
                            Explore hidden gems
                        </a>
                    </div>

                    <?php if ($gems): ?>
                        <?php cards(array_slice($gems, 0, 3)); ?>
                    <?php else: ?>
                        <div class="panel">
                            <p>No hidden local gems are currently listed.</p>
                            <a href="?page=explore">Browse all places</a>
                        </div>
                    <?php endif; ?>
                </section>

            <?php elseif ($page === 'explore'): ?>
                <?php
                $q = dt_text($_GET, 'q');
                $categoryId = dt_id($_GET, 'category');
                $gemsOnly = dt_text($_GET, 'gems') === '1';

                $filtered = array_values(array_filter(
                    $places,
                    static function (array $place) use (
                        $q,
                        $categoryId,
                        $gemsOnly
                    ): bool {
                        $searchText = ($place['name'] ?? '') . ' '
                            . ($place['description'] ?? '');

                        return ($q === '' || stripos($searchText, $q) !== false)
                            && (
                                $categoryId === 0 ||
                                (int) $place['category_id'] === $categoryId
                            )
                            && (!$gemsOnly || !empty($place['hidden_gem']));
                    }
                ));
                ?>

                <div class="section-head">
                    <div>
                        <p class="eyebrow">FIND YOUR NEXT STOP</p>
                        <h1><?= $gemsOnly ? 'Hidden local gems' : 'Explore places' ?></h1>
                        <p class="muted">From coastal mornings to local stories.</p>
                    </div>
                    <a class="button secondary" href="?page=map">
                        Explore the map
                    </a>
                </div>

                <form class="filters panel" method="get" action="index.php">
                    <input type="hidden" name="page" value="explore">

                    <label>
                        Search places
                        <input
                            type="search"
                            name="q"
                            value="<?= e($q) ?>"
                            placeholder="Search by name or description"
                        >
                    </label>

                    <label>
                        Category
                        <select name="category">
                            <option value="0">All categories</option>

                            <?php foreach ($categories as $category): ?>
                                <option
                                    value="<?= (int) $category['id'] ?>"
                                    <?= $categoryId === (int) $category['id']
                                        ? 'selected' : '' ?>
                                >
                                    <?= e($category['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="check">
                        <input
                            type="checkbox"
                            name="gems"
                            value="1"
                            <?= $gemsOnly ? 'checked' : '' ?>
                        >
                        Hidden gems only
                    </label>

                    <button type="submit">Find places</button>
                    <a href="?page=explore">Reset</a>
                </form>

                <p class="muted">
                    <?= count($filtered) ?> places · distances measured
                    in a straight line from the configured home
                </p>

                <?php cards($filtered); ?>

            <?php elseif ($page === 'place'): ?>
                <?php
                $id = dt_id($_GET, 'id');
                $p = null;

                foreach ($places as $item) {
                    if ((int) $item['id'] === $id) {
                        $p = $item;
                        break;
                    }
                }

                if (!$p) {
                    http_response_code(404);
                    throw new RuntimeException(
                        'This place is unavailable. Browse Explore Places for current listings.'
                    );
                }

                $opening = dt_text($p, 'open_time');
                $closing = dt_text($p, 'close_time');

                $details = [
                    'Opening hours' => $opening !== '' && $closing !== ''
                        ? substr($opening, 0, 5) . ' – ' . substr($closing, 0, 5)
                        : 'Not verified',
                    'Suggested visit' => (int) $p['visit_minutes'] . ' minutes',
                    'Best time' => dt_text($p, 'best_time'),
                    'Entry information' => dt_text($p, 'entry_info'),
                    'Facilities' => dt_text($p, 'facilities'),
                    'Contact' => dt_text($p, 'contact') ?: 'Not recorded',
                    'Dress code' => dt_text($p, 'dress_code'),
                    'Footwear' => dt_text($p, 'footwear'),
                    'Photography' => dt_text($p, 'photography'),
                    'Travel tips' => dt_text($p, 'travel_tips')
                ];

                $offlineJson = json_encode(
                    $p,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT |
                    JSON_HEX_APOS | JSON_INVALID_UTF8_SUBSTITUTE |
                    JSON_THROW_ON_ERROR
                );
                ?>

                <a class="breadcrumb" href="?page=explore">
                    Explore places / <?= e($p['name']) ?>
                </a>

                <div class="section-head">
                    <div>
                        <p class="eyebrow"><?= e($p['category_name']) ?></p>
                        <h1><?= e($p['name']) ?></h1>
                        <p>
                            <?= e($p['address']) ?> ·
                            <?= number_format((float) $p['distance_km'], 1) ?>
                            km from home
                        </p>
                    </div>

                    <a
                        class="button"
                        href="?page=planner&amp;add=<?= (int) $p['id'] ?>"
                    >
                        Add to my day
                    </a>
                </div>

                <div class="split">
                    <article>
                        <div class="detail-photo"><?php photo($p); ?></div>

                        <?php if (!empty($p['image_credit'])): ?>
                            <p class="muted photo-credit">
                                <?= e($p['image_credit']) ?> ·
                                <a href="docs/PHOTO_CREDITS.md">Source &amp; license</a>
                            </p>
                        <?php endif; ?>

                        <div class="panel">
                            <h2>A little about this place</h2>
                            <p><?= e($p['description']) ?></p>

                            <div class="tags">
                                <?php
                                if (!empty($p['hidden_gem'])) {
                                    badge('Hidden gem');
                                }
                                if (!empty($p['kid_friendly'])) {
                                    badge('Family suggestion');
                                }
                                if (!empty($p['wheelchair'])) {
                                    badge('Wheelchair access reported');
                                }
                                ?>
                            </div>

                            <?php if (empty($p['verified'])): ?>
                                <p class="notice warning">
                                    These details await local verification.
                                    Confirm the exact pin, access and visitor
                                    information before travelling.
                                </p>
                            <?php else: ?>
                                <p class="muted">
                                    Details marked as verified by the administrator.
                                    Confirm current conditions before travelling.
                                </p>
                            <?php endif; ?>

                            <dl class="details">
                                <?php foreach ($details as $label => $value): ?>
                                    <?php if ($value !== ''): ?>
                                        <div>
                                            <dt><?= e($label) ?></dt>
                                            <dd><?= e($value) ?></dd>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                    </article>

                    <aside>
                        <div class="sticky">
                            <?php map_view([$p]); ?>

                            <a
                                class="button wide"
                                target="_blank"
                                rel="noopener noreferrer"
                                href="<?= e(directions([$p])) ?>"
                            >
                                Get directions
                            </a>

                            <p class="muted">
                                Map pin: <?= e($p['latitude']) ?>,
                                <?= e($p['longitude']) ?>
                            </p>

                            <button
                                id="save-offline"
                                class="secondary wide"
                                type="button"
                            >
                                Save details for offline reading
                            </button>

                            <p class="muted">
                                Downloads a text copy.
                                Maps and online directions require internet.
                            </p>

                            <script type="application/json" id="offline-place"><?= $offlineJson ?></script>
                        </div>
                    </aside>
                </div>

            <?php elseif ($page === 'map'): ?>
                <div class="section-head">
                    <div>
                        <p class="eyebrow">GET YOUR BEARINGS</p>
                        <h1>Explore the map</h1>
                        <p>Choose a marker to explore a place.</p>
                    </div>
                    <a class="button" href="?page=planner">Plan your day</a>
                </div>

                <?php map_view($places); ?>

                <?php if (!$places): ?>
                    <div class="panel">
                        <p>No active places are currently available within the configured radius.</p>
                    </div>
                <?php endif; ?>

                <div class="map-list">
                    <?php foreach ($places as $p): ?>
                        <div class="panel">
                            <h3>
                                <a href="?page=place&amp;id=<?= (int) $p['id'] ?>">
                                    <?= e($p['name']) ?>
                                </a>
                            </h3>
                            <p>
                                <?= number_format((float) $p['distance_km'], 1) ?>
                                km · <?= e($p['category_name']) ?>
                            </p>
                            <a
                                target="_blank"
                                rel="noopener noreferrer"
                                href="<?= e(directions([$p])) ?>"
                            >
                                Directions
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($page === 'planner'): ?>
                <?php
                $old = is_array($_SESSION['plan'] ?? null)
                    ? $_SESSION['plan'] : [];

                $selected = [];

                foreach ($old['stops'] ?? [] as $stop) {
                    if (is_array($stop) && is_array($stop['place'] ?? null)) {
                        $selected[] = dt_id($stop['place'], 'id');
                    }
                }

                if ($isPost && $postAction === 'generate') {
                    foreach (
                        ['mood', 'companion', 'start', 'hours', 'pace']
                        as $field
                    ) {
                        $old[$field] = dt_text($_POST, $field);
                    }

                    $selected = [];
                    $postedPlaces = $_POST['places'] ?? [];

                    if (is_array($postedPlaces)) {
                        foreach ($postedPlaces as $postedId) {
                            $selected[] = dt_id(['id' => $postedId], 'id');
                        }
                    }
                } else {
                    $addedId = dt_id($_GET, 'add');

                    if ($addedId > 0) {
                        $selected[] = $addedId;
                    }
                }

                $selected = array_values(array_unique(array_filter($selected)));

                $mood = dt_text($old, 'mood', 'Any');
                $companion = dt_text($old, 'companion', 'Solo');
                $start = dt_text($old, 'start', '08:00');
                $hours = dt_text($old, 'hours', '8');
                $pace = dt_text($old, 'pace', 'Balanced');

                $moods = ['Any', 'Nature', 'Adventure', 'Food & Culture', 'History'];
                $companions = ['Solo', 'Family', 'Friends'];
                $paces = ['Relaxed', 'Balanced', 'Fast'];

                if (!in_array($mood, $moods, true)) {
                    $mood = 'Any';
                }
                if (!in_array($companion, $companions, true)) {
                    $companion = 'Solo';
                }
                if (!in_array($pace, $paces, true)) {
                    $pace = 'Balanced';
                }
                ?>

                <div class="section-head">
                    <div>
                        <p class="eyebrow">YOUR ONE-DAY PLANNER</p>
                        <h1>Plan your day</h1>
                        <p class="muted">
                            Choose places, set your preferences,
                            then create your plan.
                        </p>
                    </div>
                    <span class="tag">No account needed</span>
                </div>

                <form method="post" action="?page=planner" class="planner-layout">
                    <?php token(); ?>
                    <input type="hidden" name="action" value="generate">

                    <aside class="panel planner-controls">
                        <h2>1. Set your preferences</h2>

                        <label>
                            Mood
                            <select name="mood">
                                <?php foreach ($moods as $value): ?>
                                    <option
                                        value="<?= e($value) ?>"
                                        <?= $mood === $value ? 'selected' : '' ?>
                                    >
                                        <?= e($value) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            Travelling with
                            <select name="companion">
                                <?php foreach ($companions as $value): ?>
                                    <option
                                        value="<?= e($value) ?>"
                                        <?= $companion === $value ? 'selected' : '' ?>
                                    >
                                        <?= e($value) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <div class="form-row">
                            <label>
                                Start time
                                <input
                                    type="time"
                                    name="start"
                                    value="<?= e($start) ?>"
                                    required
                                >
                            </label>

                            <label>
                                Available hours
                                <input
                                    type="number"
                                    name="hours"
                                    min="1"
                                    max="16"
                                    step="0.5"
                                    value="<?= e($hours) ?>"
                                    required
                                >
                            </label>
                        </div>

                        <label>
                            Travel pace
                            <select name="pace">
                                <?php foreach ($paces as $value): ?>
                                    <option
                                        value="<?= e($value) ?>"
                                        <?= $pace === $value ? 'selected' : '' ?>
                                    >
                                        <?= e($value) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <p class="muted">
                            Relaxed adds 25% to visits. Fast reduces visits by
                            25%. Travel speed stays the same.
                        </p>

                        <button
                            class="wide"
                            type="submit"
                            name="mode"
                            value="selected"
                            <?= !$places ? 'disabled' : '' ?>
                        >
                            Create my plan
                        </button>

                        <button
                            class="secondary wide"
                            type="submit"
                            name="mode"
                            value="recommend"
                            <?= !$places ? 'disabled' : '' ?>
                        >
                            Suggest places for me
                        </button>

                        <p class="muted">
                            Suggestions use mood, available time and family
                            tags. Selected-place mode keeps your own choices.
                        </p>
                    </aside>

                    <section>
                        <div class="section-head">
                            <h2>2. Choose your places</h2>
                            <span id="selected-count" aria-live="polite"></span>
                        </div>

                        <div class="selection-list">
                            <?php if (!$places): ?>
                                <div class="panel">
                                    <p>No places are currently available to plan.</p>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($places as $p): ?>
                                <label class="select-place panel">
                                    <input
                                        type="checkbox"
                                        name="places[]"
                                        value="<?= (int) $p['id'] ?>"
                                        <?= in_array((int) $p['id'], $selected, true)
                                            ? 'checked' : '' ?>
                                    >

                                    <span>
                                        <strong><?= e($p['name']) ?></strong>
                                        <small>
                                            <?= e($p['category_name']) ?> ·
                                            <?= (int) $p['visit_minutes'] ?> min ·
                                            <?= number_format((float) $p['distance_km'], 1) ?> km
                                        </small>
                                        <span class="muted">
                                            <?= e($p['mood_tags'] ?? '') ?>
                                        </span>
                                    </span>

                                    <?php if (!empty($p['hidden_gem'])): ?>
                                        <span class="tag">Gem</span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </form>

            <?php elseif ($page === 'summary'): ?>
                <?php
                $plan = $_SESSION['plan'] ?? null;
                $saved = false;

                if (array_key_exists('id', $_GET)) {
                    $u = require_user();
                    $savedId = dt_id($_GET, 'id');

                    $row = $savedId > 0
                        ? query(
                            'SELECT * FROM itineraries WHERE itinerary_id = ? AND user_id = ?',
                            [$savedId, $u['id']]
                        )->fetch()
                        : false;

                    if (!$row) {
                        http_response_code(404);
                        throw new RuntimeException(
                            'This saved plan was not found.'
                        );
                    }

                    $plan = dt_decode_plan($row['plan_json'] ?? null);
                    $saved = true;

                    if (!$plan) {
                        throw new RuntimeException(
                            'This saved plan cannot be displayed. Please create a new plan.'
                        );
                    }
                    ?>
                    <p class="notice">
                        Saved plan: <?= e($row['title']) ?>.
                        This is a snapshot; place details may have changed
                        since it was saved.
                    </p>
                    <?php
                }

                if (!dt_valid_plan($plan)) {
                    throw new RuntimeException(
                        'Create a valid plan first using Plan your day.'
                    );
                }

                summary_view($plan, $saved);
                ?>

            <?php elseif ($page === 'login' || $page === 'register'): ?>
                <?php
                $register = $page === 'register';
                $restoreForm = $isPost && $postAction === $page;

                $nameValue = $restoreForm ? dt_text($_POST, 'name') : '';
                $emailValue = $restoreForm ? dt_text($_POST, 'email') : '';
                ?>

                <section class="auth panel">
                    <p class="eyebrow">YOUR NEXT DAY OUT STARTS HERE</p>

                    <h1>
                        <?= $register ? 'Create your account' : 'Welcome back.' ?>
                    </h1>

                    <p>
                        <?= $register
                            ? 'Create an account to keep your favourite day plans.'
                            : 'Sign in to access saved plans or your admin dashboard.' ?>
                    </p>

                    <form method="post" action="?page=<?= e($page) ?>">
                        <?php token(); ?>
                        <input type="hidden" name="action" value="<?= e($page) ?>">

                        <?php if ($register): ?>
                            <label>
                                Your name
                                <input
                                    name="name"
                                    value="<?= e($nameValue) ?>"
                                    required
                                    maxlength="100"
                                    autocomplete="name"
                                >
                            </label>
                        <?php endif; ?>

                        <label>
                            Email
                            <input
                                type="email"
                                name="email"
                                value="<?= e($emailValue) ?>"
                                required
                                maxlength="190"
                                autocomplete="email"
                            >
                        </label>

                        <label>
                            Password
                            <input
                                type="password"
                                name="password"
                                required
                                <?= $register ? 'minlength="10" maxlength="72"' : '' ?>
                                autocomplete="<?= $register
                                    ? 'new-password' : 'current-password' ?>"
                            >
                        </label>

                        <?php if ($register): ?>
                            <p class="muted">
                                Use at least 10 characters.
                                The server also checks the password length.
                            </p>
                        <?php endif; ?>

                        <button class="wide" type="submit">
                            <?= $register ? 'Create account' : 'Sign in' ?>
                        </button>
                    </form>

                    <?php if ($register): ?>
                        <p>
                            Already registered?
                            <a href="?page=login">Sign in</a>
                        </p>
                    <?php else: ?>
                        <p>
                            New here?
                            <a href="?page=register">Create an account</a>
                        </p>
                    <?php endif; ?>

                    <a href="?page=explore">Continue exploring</a>
                </section>

            <?php elseif ($page === 'saved'): ?>
                <?php
                $u = require_user();

                $rows = query(
                    'SELECT * FROM itineraries WHERE user_id = ? ORDER BY created_at DESC',
                    [$u['id']]
                )->fetchAll();
                ?>

                <div class="section-head">
                    <div>
                        <p class="eyebrow">YOUR COLLECTION</p>
                        <h1>My saved plans</h1>
                    </div>
                    <a class="button" href="?page=planner">Create a plan</a>
                </div>

                <?php if (!$rows): ?>
                    <div class="empty panel">
                        <h2>Your first trail is waiting.</h2>
                        <p>Create a one-day itinerary, then save it here.</p>
                    </div>
                <?php endif; ?>

                <div class="cards">
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $snapshot = dt_decode_plan($row['plan_json'] ?? null);
                        $createdAt = strtotime((string) ($row['created_at'] ?? ''));
                        ?>

                        <article class="panel">
                            <p class="eyebrow">
                                <?= $createdAt !== false
                                    ? e(date('d M Y', $createdAt))
                                    : 'Saved plan' ?>
                            </p>

                            <h2><?= e($row['title']) ?></h2>

                            <?php if ($snapshot): ?>
                                <p>
                                    <?= count($snapshot['stops']) ?> stops ·
                                    <?= e($snapshot['total_km']) ?> km ·
                                    <?= e($snapshot['pace']) ?>
                                </p>

                                <a
                                    class="button secondary"
                                    href="?page=summary&amp;id=<?= (int) $row['itinerary_id'] ?>"
                                >
                                    Open plan
                                </a>
                            <?php else: ?>
                                <p class="notice warning">
                                    This saved plan cannot be displayed.
                                    You can delete it and create a new plan.
                                </p>
                            <?php endif; ?>

                            <form
                                method="post"
                                action="?page=saved"
                                data-confirm="Delete this saved plan?"
                            >
                                <?php token(); ?>
                                <input type="hidden" name="action" value="delete_plan">
                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int) $row['itinerary_id'] ?>"
                                >
                                <button class="text-button danger" type="submit">
                                    Delete
                                </button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($page === 'admin' || $page === 'edit'): ?>
                <?php
                require_user(true);

                if (
                    $page === 'edit' &&
                    array_key_exists('id', $_GET) &&
                    dt_id($_GET, 'id') === 0
                ) {
                    http_response_code(404);
                    throw new RuntimeException('Invalid place ID.');
                }

                require __DIR__ . '/app/admin.php';
                ?>

            <?php elseif ($page === 'about'): ?>
                <div class="reading panel">
                    <p class="eyebrow">LOCAL PLACES, CONSIDERED DAYS</p>
                    <h1>About DayTrail</h1>

                    <p>
                        DayTrail is a student-built tourism information and
                        one-day visit planner for Valaichenai, Pasikudah and
                        Kalkudah in Sri Lanka's Batticaloa District.
                    </p>

                    <h2>Explore with a little context</h2>
                    <p>
                        Discover beaches, places of worship, local culture,
                        lagoon views and industrial heritage within
                        <?= e($radiusLabel) ?> km of the configured home point.
                        Create a day from your own choices or get suggestions
                        based on mood, available time and companions.
                    </p>

                    <h2>Know before you go</h2>
                    <p>
                        Distances and schedules are estimates. Respect local
                        customs, confirm entry permissions and opening hours,
                        and follow current local safety advice.
                        Place pages show whether their details have been
                        marked as verified.
                    </p>

                    <h2>How your plan works</h2>
                    <p>
                        Starting from the configured home, the planner visits
                        the nearest remaining place in turn. Travel distances
                        use straight-line distances adjusted by the configured
                        distance factor. Travel times use the configured
                        average speed.
                    </p>
                    <p>
                        These estimates do not use live traffic or road-routing
                        data and do not guarantee the shortest route.
                        The final stop is the end of the plan; a return journey
                        is not included. Google Maps provides navigation
                        separately.
                    </p>

                    <p>
                        No booking, payment or transport reservation services
                        are provided.
                    </p>

                    <p>
                        Map data ©
                        <a
                            href="https://www.openstreetmap.org/copyright"
                            target="_blank"
                            rel="noopener noreferrer"
                        >OpenStreetMap contributors</a>.
                        <a href="docs/PHOTO_CREDITS.md">
                            Photo credits and licenses
                        </a>.
                    </p>
                </div>

            <?php else: ?>
                <section class="empty panel">
                    <h1>Page not found</h1>
                    <p>The requested page does not exist.</p>
                    <a class="button" href="?page=home">Return home</a>
                </section>
            <?php endif; ?>

            <?php
            echo ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();

            if ($exception instanceof PDOException) {
                http_response_code(503);
                error_log($exception->getMessage());

                $message = 'The requested data is temporarily unavailable. '
                    . 'Please try again.';
            } elseif (
                $exception instanceof InvalidArgumentException ||
                $exception instanceof RuntimeException
            ) {
                if (http_response_code() < 400) {
                    http_response_code(400);
                }

                $message = $exception->getMessage();
            } else {
                http_response_code(500);
                error_log($exception->getMessage());

                $message = 'This page could not be displayed. Please try again.';
            }
            ?>
            <section class="panel">
                <h1>Unable to display this page</h1>
                <p class="notice error" role="alert"><?= e($message) ?></p>

                <div class="actions">
                    <a href="?page=explore">Explore available places</a>
                    <a href="?page=planner">Plan your day</a>
                </div>
            </section>
            <?php
        }
        ?>
    <?php endif; ?>
</main>

<footer>
    <a class="brand" href="?page=home">◈ DayTrail</a>
    <p>Valaichenai · Pasikudah · Kalkudah</p>
    <a href="?page=about">About DayTrail</a>
    <a href="?page=login">Administrator sign in</a>
</footer>
</body>
</html>
<?php
ob_end_flush();