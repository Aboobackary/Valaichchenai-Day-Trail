<?php
 

function token(): void
{
    echo '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf'] ?? '') . '">';
}

function badge(string $text): void
{
    echo '<span class="tag">' . e($text) . '</span>';
}

function photo(array $place, string $class = ''): void
{
    $url = image_url($place);
    if ($url !== '') {
         
        ?>
        <img class="place-photo <?= e($class) ?>" src="<?= e($url) ?>"
             alt="<?= e($place['name'] ?? 'Local place') ?>" loading="lazy">
        <span class="photo-fallback" hidden>Photo unavailable</span>
        <?php
    } else {
        ?>
        <div class="no-photo <?= e($class) ?>">
            <span>Photo awaiting local contribution</span>
        </div>
        <?php
    }
}

function cards(array $places): void
{
    if (!$places) {
        ?>
        <div class="empty panel">
            <h2>No places found</h2>
            <p>Try another keyword or category.</p>
            <a href="?page=explore">Clear filters</a>
        </div>
        <?php
        return;
    }
    ?>
    <div class="cards">
        <?php foreach ($places as $place): $id = (int) $place['id']; ?>
            <article class="place-card">
                <a class="photo-link" href="?page=place&amp;id=<?= $id ?>">
                    <?php photo($place); ?>
                    <?php if (!empty($place['hidden_gem'])): ?>
                        <span class="gem">Hidden gem</span>
                    <?php endif; ?>
                </a>
                <div class="card-body">
                    <p class="eyebrow"><?= e($place['category_name'] ?? '') ?></p>
                    <h3><a href="?page=place&amp;id=<?= $id ?>"><?= e($place['name']) ?></a></h3>
                    <p class="muted">
                        <?= e(number_format((float) $place['distance_km'], 1)) ?> km from home
                        &middot; <?= (int) $place['visit_minutes'] ?> min visit
                    </p>
                    <div class="card-bottom">
                        <a href="?page=place&amp;id=<?= $id ?>">View place</a>
                        <a class="button small secondary" href="?page=planner&amp;add=<?= $id ?>">Plan a visit</a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
}

 
function view_coordinates(array $point, string $latitudeKey, string $longitudeKey): array
{
    $coordinates = [];
    foreach ([$latitudeKey => [-90, 90], $longitudeKey => [-180, 180]] as $key => $range) {
        $raw = $point[$key] ?? null;
        $value = (is_string($raw) || is_int($raw) || is_float($raw))
            ? filter_var($raw, FILTER_VALIDATE_FLOAT) : false;
        if ($value === false || !is_finite($value) || $value < $range[0] || $value > $range[1]) {
            throw new InvalidArgumentException('Invalid map coordinate.');
        }
        $coordinates[] = (float) $value;
    }
    return $coordinates;
}

 
function map_view(array $places, bool $route = false): void
{
    global $config;
    try {
        $home = view_coordinates($config, 'home_lat', 'home_lng');
        $markers = [];
        foreach ($places as $place) {
            [$latitude, $longitude] = view_coordinates($place, 'latitude', 'longitude');
            $markers[] = [
                'id' => (int) $place['id'],
                'name' => (string) ($place['name'] ?? 'Local place'),
                'lat' => $latitude,
                'lng' => $longitude
            ];
        }
         
        $json = json_encode(
            ['home' => $home, 'places' => $markers, 'route' => $route],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    } catch (InvalidArgumentException | JsonException $exception) {
        echo '<p class="notice warning">The map cannot be displayed. Check the home and place coordinates.</p>';
        return;
    }
    ?>
    <div class="map-wrap">
        <div id="map" role="region" aria-label="Interactive place map"></div>
        <p id="map-status" class="map-status" role="status">
            Loading map. Place information remains available without the map.
        </p>
    </div>
    <script type="application/json" id="map-data"><?= $json ?></script>
    <noscript><p class="notice">Enable JavaScript and connect to the internet to view the map.</p></noscript>
    <?php
}

 
function view_directions(array $places): string
{
    global $config;
    try {
        view_coordinates($config, 'home_lat', 'home_lng');
        foreach ($places as $place) {
            view_coordinates($place, 'latitude', 'longitude');
        }
        return $places ? directions($places) : '';
    } catch (InvalidArgumentException $exception) {
        return '';
    }
}

function summary_view(array $plan, bool $saved = false): void
{
    if (empty($plan['stops']) || !is_array($plan['stops'])) {
        echo '<div class="empty panel"><h2>No plan available</h2>'
            . '<p>Create a plan with at least one place.</p>'
            . '<a href="?page=planner">Open planner</a></div>';
        return;
    }

    $stops = array_values($plan['stops']);
    $places = array_column($stops, 'place');
    $routeUrl = view_directions($places);
    $statistics = [
        'Stops' => count($stops),
        'Estimated distance' => $plan['total_km'] . ' km',
        'Travel time' => $plan['travel_minutes'] . ' min',
        'Expected finish' => time_label((int) $plan['finish'])
    ];
    ?>
    <div class="section-head">
        <div>
            <p class="eyebrow">READY TO EXPLORE</p>
            <h1>Your day plan</h1>
            <p class="muted">
                Starts <?= e($plan['start']) ?> &middot; <?= e($plan['pace']) ?> pace
                &middot; <?= e($plan['companion']) ?>
            </p>
        </div>
        <button class="secondary no-print" type="button" id="print-plan">Print / Save PDF</button>
    </div>

    <div class="stats">
        <?php foreach ($statistics as $label => $value): ?>
            <div><span><?= e($label) ?></span><strong><?= e($value) ?></strong></div>
        <?php endforeach; ?>
    </div>

    <p class="notice">
        Travel estimates use straight-line distances adjusted by the planner's distance factor
        and average speed. They do not use live road or traffic data.
        The plan starts at the configured home and ends at the final stop; no return journey is included.
        Places are ordered using the nearest-neighbour method, which does not guarantee the shortest route.
    </p>
    <?php if ($saved): ?>
        <p class="muted">Saved times and distances reflect the saved plan. The map and navigation links use the current home setting.</p>
    <?php endif; ?>
    <?php foreach ($plan['warnings'] ?? [] as $warning): ?>
        <p class="notice warning"><?= e($warning) ?></p>
    <?php endforeach; ?>

    <div class="split">
        <div class="timeline">
            <?php foreach ($stops as $index => $stop):
                $place = $stop['place'];
                $stopUrl = view_directions([$place]);
                ?>
                <article class="stop panel">
                    <span class="stop-number"><?= $index + 1 ?></span>
                    <p class="eyebrow">
                        <?= e(time_label((int) $stop['arrival'])) ?> &mdash;
                        <?= e(time_label((int) $stop['end'])) ?>
                    </p>
                    <h2><?= e($place['name']) ?></h2>
                    <p>
                        <?= e($stop['leg_km']) ?> km / <?= e($stop['travel_minutes']) ?> min travel
                        &middot; <?= e($stop['visit_minutes']) ?> min visit
                    </p>
                    <?php if (!empty($place['travel_tips'])): ?>
                        <p><?= e($place['travel_tips']) ?></p>
                    <?php endif; ?>
                    <?php foreach ($stop['warnings'] ?? [] as $warning): ?>
                        <p class="warning-text"><?= e($warning) ?></p>
                    <?php endforeach; ?>
                    <?php if ($stopUrl !== ''): ?>
                        <a target="_blank" rel="noopener noreferrer" href="<?= e($stopUrl) ?>">Directions from home to this stop</a>
                    <?php else: ?>
                        <p class="muted">Directions are unavailable until the coordinates are corrected.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>

        <aside>
            <div class="sticky">
                <?php map_view($places, true); ?>
                <?php if ($routeUrl !== ''): ?>
                    <a class="button wide" target="_blank" rel="noopener noreferrer"
                       href="<?= e($routeUrl) ?>">Open route in Google Maps</a>
                <?php endif; ?>
                <p class="muted">Dashed lines show visit order, not roads. If Google Maps limits the stops on your device, use the individual directions links.</p>

                <?php if (!$saved): ?>
                    <?php if (!user()): ?>
                        <div class="panel no-print">
                            <h3>Save your plan</h3>
                            <a href="?page=login">Sign in</a> or
                            <a href="?page=register">create an account</a> to save your plan.
                        </div>
                    <?php elseif (is_string($_SESSION['plan_token'] ?? null) && $_SESSION['plan_token'] !== ''):
                        $title = 'My Valaichchenai day';
                        if (($_POST['action'] ?? '') === 'save_plan' && is_string($_POST['title'] ?? null)) {
                            $title = $_POST['title'];
                        }
                        ?>
                        <form class="panel no-print" method="post">
                            <?php token(); ?>
                            <input type="hidden" name="action" value="save_plan">
                            <input type="hidden" name="plan_token" value="<?= e($_SESSION['plan_token']) ?>">
                            <label>Plan name
                                <input name="title" value="<?= e($title) ?>" required maxlength="100">
                            </label>
                            <button class="wide" type="submit">Save this plan</button>
                        </form>
                    <?php else: ?>
                        <div class="panel no-print">
                            <p>This plan has already been saved or is no longer available to save.</p>
                            <a href="?page=saved">View saved plans</a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <a class="button secondary wide no-print" href="?page=planner">
                    <?= $saved ? 'Create another plan' : 'Adjust your plan' ?>
                </a>
            </div>
        </aside>
    </div>
    <?php
}
