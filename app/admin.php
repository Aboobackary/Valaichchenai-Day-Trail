<?php
 
if (!function_exists('require_user')) {
    http_response_code(403);
    exit('Open the administrator area through index.php.');
}
$adminUser = require_user(true);
if (!isset($page) || !in_array($page, ['admin', 'edit'], true)) {
    throw new RuntimeException('Invalid administrator page.');
}

$radiusKm = (float) $config['radius_km'];
$adminCategories = $categories ?? query('SELECT * FROM category ORDER BY category_name')->fetchAll();
$minimumPlaces = 10;
?>

<?php if ($page === 'admin'): ?>
    <?php
    $allPlaces = all_places(true);
    $activeCount = 0;
    $verifiedCount = 0;

    foreach ($allPlaces as $place) {
        $insideRadius = (float) $place['distance_km'] <= $radiusKm;
        if (!empty($place['active']) && $insideRadius) {
            $activeCount++;
            if (!empty($place['verified'])) {
                $verifiedCount++;
            }
        }
    }
    ?>

    <div class="section-head">
        <div>
            <p class="eyebrow">CONTENT MANAGEMENT</p>
            <h1>Manage places</h1>
            <p>Welcome, <?= e($adminUser['name']) ?>.</p>
        </div>
        <a class="button" href="?page=edit">Add a place</a>
    </div>

    <div class="stats">
        <div><span>Place records</span><strong><?= count($allPlaces) ?></strong></div>
        <div><span>Active &amp; in radius</span><strong><?= $activeCount ?></strong></div>
        <div><span>Verified &amp; in radius</span><strong><?= $verifiedCount ?></strong><span>Minimum: <?= $minimumPlaces ?></span></div>
        <div><span>Categories</span><strong><?= count($adminCategories) ?></strong></div>
    </div>

    <?php if (empty($config['home_verified'])): ?>
        <p class="notice warning">
            The home location has not been verified. Set the actual home latitude,
            longitude and home_verified value in config/config.php before relying on
            the <?= e($radiusKm) ?> km radius check.
        </p>
    <?php endif; ?>

    <p class="notice">
        Home reference: <?= e($config['home_label']) ?>
        (<?= e($config['home_lat']) ?>, <?= e($config['home_lng']) ?>).
        Distances are measured in a straight line from this point.
        A verification flag records an administrator's check; it is not independent certification.
    </p>

    <?php if ($verifiedCount < $minimumPlaces): ?>
        <p class="notice warning">
            <?= $minimumPlaces - $verifiedCount ?> more active, verified place(s) inside the
            configured radius are needed to reach the minimum of <?= $minimumPlaces ?>.
            Verify the actual home location and place details before final submission.
        </p>
    <?php endif; ?>

    <div class="table-wrap panel">
        <table>
            <caption class="sr-only">Tourist places and administrator actions</caption>
            <thead>
                <tr>
                    <th scope="col">Place</th>
                    <th scope="col">Category</th>
                    <th scope="col">Distance</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$allPlaces): ?>
                    <tr><td colspan="5">No places have been added. Select Add a place to begin.</td></tr>
                <?php endif; ?>

                <?php foreach ($allPlaces as $place): ?>
                    <?php $placeId = (int) $place['id']; ?>
                    <tr>
                        <td>
                            <strong><?= e($place['name']) ?></strong>
                            <small>
                                <?= !empty($place['verified']) ? 'Verified by admin' : 'Needs verification' ?>
                                <?= empty($place['image']) ? ' · Photo missing' : '' ?>
                            </small>
                        </td>
                        <td><?= e($place['category_name']) ?></td>
                        <td><?= number_format((float) $place['distance_km'], 2) ?> km</td>
                        <td>
                            <?= !empty($place['active']) ? 'Active' : 'Inactive' ?>
                            <?= (float) $place['distance_km'] > $radiusKm ? ' · Out of radius' : '' ?>
                        </td>
                        <td>
                            <div class="actions">
                                <a href="?page=edit&amp;id=<?= $placeId ?>"
                                   aria-label="<?= e('Edit ' . $place['name']) ?>">Edit</a>
                                <form method="post" action="?page=admin"
                                      data-confirm="Delete this place? Saved plan snapshots will remain.">
                                    <?php token(); ?>
                                    <input type="hidden" name="action" value="delete_place">
                                    <input type="hidden" name="id" value="<?= $placeId ?>">
                                    <button type="submit" class="text-button danger"
                                            aria-label="<?= e('Delete ' . $place['name']) ?>">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <section class="panel" aria-labelledby="category-heading">
        <h2 id="category-heading">Categories</h2>
        <p class="muted">A category can be deleted only when it contains no places.</p>
        <?php if (!$adminCategories): ?>
            <p>No categories yet. Add a category before adding a place.</p>
        <?php endif; ?>
        <div class="category-list">
            <?php foreach ($adminCategories as $category): ?>
                <?php $categoryId = (int) $category['id']; ?>
                <div class="category-row">
                    <form method="post" action="?page=admin" class="inline-form">
                        <?php token(); ?>
                        <input type="hidden" name="action" value="category">
                        <input type="hidden" name="id" value="<?= $categoryId ?>">
                        <label class="sr-only" for="cat-<?= $categoryId ?>">Category name</label>
                        <input id="cat-<?= $categoryId ?>" name="category_name"
                               value="<?= e($category['category_name']) ?>" required maxlength="80">
                        <button type="submit" class="secondary">Rename</button>
                    </form>
                    <form method="post" action="?page=admin" data-confirm="Delete this category if empty?">
                        <?php token(); ?>
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="id" value="<?= $categoryId ?>">
                        <button type="submit" class="text-button danger"
                                aria-label="<?= e('Delete category ' . $category['category_name']) ?>">Delete</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>

        <h3>Add category</h3>
        <form method="post" action="?page=admin" class="inline-form">
            <?php token(); ?>
            <input type="hidden" name="action" value="category">
            <label class="sr-only" for="new-category">New category name</label>
            <input id="new-category" name="category_name" placeholder="Category name" required maxlength="80">
            <button type="submit">Add category</button>
        </form>
    </section>

<?php else: ?>
    <?php
     
    $rawId = $_GET['id'] ?? '0';
    $placeId = is_string($rawId)
        ? filter_var($rawId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 4294967295]
        ])
        : false;
    if ($placeId === false) {
        throw new RuntimeException('Invalid place ID. Return to Manage places and choose Edit.');
    }

    $placeForm = [];
    if ($placeId > 0) {
        $placeForm = query('SELECT * FROM tourist_place WHERE id = ?', [$placeId])->fetch();
        if (!$placeForm) {
            throw new RuntimeException('Place not found.');
        }
    }

    $textFields = [
        'name', 'description', 'address', 'category_id', 'latitude', 'longitude',
        'open_time', 'close_time', 'visit_minutes', 'image', 'image_credit',
        'entry_info', 'contact', 'best_time', 'source_url', 'travel_tips',
        'facilities', 'dress_code', 'footwear', 'photography'
    ];
    $flagLabels = [
        'active' => 'Active listing',
        'hidden_gem' => 'Hidden local gem',
        'kid_friendly' => 'Suitable for family suggestions',
        'wheelchair' => 'Wheelchair access reported',
        'verified' => 'Details verified locally'
    ];
    $moodOptions = ['Nature', 'Adventure', 'Food & Culture', 'History'];
    $selectedMoods = explode(',', (string) ($placeForm['mood_tags'] ?? 'Nature'));

     
    $postedId = $_POST['id'] ?? null;
    $sameRecord = is_string($postedId)
        && filter_var($postedId, FILTER_VALIDATE_INT) === $placeId;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
        && ($_POST['action'] ?? null) === 'place' && $sameRecord) {
        foreach ($textFields as $field) {
            if (isset($_POST[$field]) && is_string($_POST[$field])) {
                $placeForm[$field] = trim($_POST[$field]);
            }
        }
        foreach ($flagLabels as $field => $label) {
            $placeForm[$field] = isset($_POST[$field]) && is_string($_POST[$field]) ? 1 : 0;
        }
        $postedMoods = $_POST['moods'] ?? [];
        $selectedMoods = [];
        if (is_array($postedMoods)) {
            foreach ($postedMoods as $mood) {
                if (is_string($mood) && in_array($mood, $moodOptions, true)) {
                    $selectedMoods[] = $mood;
                }
            }
        }
    }

    $formAction = '?page=edit' . ($placeId > 0 ? '&id=' . $placeId : '');
    $shortFields = [
        'image' => ['Photo URL (HTTPS) or assets/ path', 1000],
        'image_credit' => ['Photo credit / license', 1000],
        'entry_info' => ['Entry / fee information', 255],
        'contact' => ['Contact number', 100],
        'best_time' => ['Best visiting time', 150],
        'source_url' => ['Verification URL or local consultation note', 1000]
    ];
    $longFields = [
        'travel_tips' => ['Travel and safety tips', 5000],
        'facilities' => ['Facilities', 2000],
        'dress_code' => ['Dress code', 2000],
        'footwear' => ['Footwear guidance', 2000],
        'photography' => ['Photography guidance', 2000]
    ];
    ?>

    <a class="breadcrumb" href="?page=admin">Admin / Manage places</a>
    <h1><?= $placeId > 0 ? 'Edit place' : 'Add a local place' ?></h1>
    <?php if (!$adminCategories): ?>
        <p class="notice warning">Add a category in Manage places before saving a place.</p>
    <?php endif; ?>

    <form method="post" action="<?= e($formAction) ?>" class="panel editor">
        <?php token(); ?>
        <input type="hidden" name="action" value="place">
        <input type="hidden" name="id" value="<?= $placeId ?>">

        <div class="form-row">
            <label>Place name
                <input name="name" value="<?= e($placeForm['name'] ?? '') ?>" required maxlength="150">
            </label>
            <label>Category
                <select name="category_id" required>
                    <option value="">Choose a category</option>
                    <?php foreach ($adminCategories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>"
                            <?= (string) ($placeForm['category_id'] ?? '') === (string) $category['id'] ? 'selected' : '' ?>>
                            <?= e($category['category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label>Description
            <textarea name="description" rows="4" required maxlength="10000"><?= e($placeForm['description'] ?? '') ?></textarea>
        </label>
        <label>Address / location
            <input name="address" value="<?= e($placeForm['address'] ?? '') ?>" required maxlength="255">
        </label>

        <fieldset>
            <legend>Map location</legend>
            <p class="muted" id="coordinate-help">
                Enter coordinates, not kilometres. Distance is calculated from the configured home.
                The allowed straight-line radius is <?= e($radiusKm) ?> km.
            </p>
            <div class="form-row">
                <label>Latitude
                    <input type="number" step="any" min="-90" max="90" name="latitude"
                           value="<?= e($placeForm['latitude'] ?? '') ?>" required aria-describedby="coordinate-help">
                </label>
                <label>Longitude
                    <input type="number" step="any" min="-180" max="180" name="longitude"
                           value="<?= e($placeForm['longitude'] ?? '') ?>" required aria-describedby="coordinate-help">
                </label>
            </div>
        </fieldset>

        <div class="form-row three">
            <label>Opening time
                <input type="time" name="open_time" aria-describedby="hours-help"
                       value="<?= e(substr((string) ($placeForm['open_time'] ?? ''), 0, 5)) ?>">
            </label>
            <label>Closing time
                <input type="time" name="close_time" aria-describedby="hours-help"
                       value="<?= e(substr((string) ($placeForm['close_time'] ?? ''), 0, 5)) ?>">
            </label>
            <label>Visit duration (minutes)
                <input type="number" name="visit_minutes" min="5" max="480" step="1"
                       value="<?= e($placeForm['visit_minutes'] ?? 45) ?>" required>
            </label>
        </div>
        <p class="muted" id="hours-help">
            Enter both times, or leave both blank if unknown.
            Closing earlier than opening represents overnight hours.
        </p>

        <fieldset>
            <legend>Mood tags</legend>
            <div class="checks">
                <?php foreach ($moodOptions as $mood): ?>
                    <label class="check">
                        <input type="checkbox" name="moods[]" value="<?= e($mood) ?>"
                               <?= in_array($mood, $selectedMoods, true) ? 'checked' : '' ?>>
                        <?= e($mood) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="form-row">
            <?php foreach ($shortFields as $field => [$label, $limit]): ?>
                <label><?= e($label) ?>
                    <input name="<?= e($field) ?>" maxlength="<?= $limit ?>"
                           value="<?= e($placeForm[$field] ?? '') ?>">
                </label>
            <?php endforeach; ?>
        </div>
        <p class="muted">
            Use an HTTPS photo URL or a local path such as assets/photos/place.jpg.
            Include the photo credit and confirm permission to use it.
        </p>

        <div class="form-row">
            <?php foreach ($longFields as $field => [$label, $limit]): ?>
                <label><?= e($label) ?>
                    <textarea name="<?= e($field) ?>" rows="3" maxlength="<?= $limit ?>"><?= e($placeForm[$field] ?? '') ?></textarea>
                </label>
            <?php endforeach; ?>
        </div>

        <fieldset>
            <legend>Visibility &amp; accessibility</legend>
            <div class="checks">
                <?php foreach ($flagLabels as $field => $label): ?>
                    <?php $isChecked = (bool) ($placeForm[$field] ?? ($field === 'active')); ?>
                    <label class="check">
                        <input type="checkbox" name="<?= e($field) ?>" value="1" <?= $isChecked ? 'checked' : '' ?>>
                        <?= e($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="muted">
                Mark details as verified only after checking them locally and recording a source note.
                Untick Active listing to hide a place without deleting it.
            </p>
        </fieldset>

        <div class="actions">
            <button type="submit" <?= !$adminCategories ? 'disabled' : '' ?>>Save place</button>
            <a href="?page=admin">Cancel</a>
        </div>
    </form>
<?php endif; ?>
