<?php

function action_text(string $field, string $default = '', bool $trim = true): string
{
    $value = $_POST[$field] ?? $default;
    if (!is_string($value)) {
        throw new RuntimeException('Invalid value for ' . $field . '.');
    }
    return $trim ? trim($value) : $value;
}

function action_id(string $field = 'id', bool $allowNew = false): int
{
    $value = action_text($field, $allowNew ? '0' : '');
    $minimum = $allowNew ? 0 : 1;
    $id = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $minimum, 'max_range' => 4294967295]
    ]);
    if ($id === false) {
        throw new RuntimeException('Choose a valid record for ' . $field . '.');
    }
    return $id;
}

function action_choices(string $field, array $allowed): array
{
    $values = $_POST[$field] ?? [];
    if (!is_array($values)) {
        throw new RuntimeException('Choose valid options for ' . $field . '.');
    }
    foreach ($values as $value) {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new RuntimeException('Choose valid options for ' . $field . '.');
        }
    }
    return array_values(array_unique($values));
}

function action_duplicate(PDOException $error): bool
{
    return (int) ($error->errorInfo[1] ?? 0) === 1062;
}

function handle_action(): void
{
    global $config;

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    
    action_text('csrf');
    csrf();
    $action = action_text('action');

    switch ($action) {
        case 'register':
            $name = action_text('name');
            $email = strtolower(action_text('email'));
            $password = action_text('password', '', false);

            if (strlen($name) < 2 || strlen($name) > 100) {
                throw new RuntimeException('Enter a name between 2 and 100 bytes long.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                throw new RuntimeException('Enter a valid email address.');
            }
             
            if (strlen($password) < 10 || strlen($password) > 72 || strpos($password, "\0") !== false) {
                throw new RuntimeException('Use a password of 10 to 72 bytes, without null characters.');
            }
            if (query('SELECT id FROM users WHERE email = ?', [$email])->fetch()) {
                throw new RuntimeException('This email is already registered. Please sign in.');
            }

            try {
                 
                query(
                    "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'visitor')",
                    [$name, $email, password_hash($password, PASSWORD_DEFAULT)]
                );
            } catch (PDOException $error) {
                if (action_duplicate($error)) {
                    throw new RuntimeException('This email is already registered. Please sign in.');
                }
                throw $error;
            }

            flash('Your account is ready. Sign in to save your plans.');
            redirect('?page=login');
            return;

        case 'login':
            $email = strtolower(action_text('email'));
            $password = action_text('password', '', false);
            if (strlen($email) > 190 || strlen($password) > 72 || strpos($password, "\0") !== false) {
                throw new RuntimeException('Email or password is incorrect.');
            }
            $now = time();
            $key = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $email);
            $attempt = query('SELECT * FROM login_attempts WHERE key_hash = ?', [$key])->fetch();

            if ($attempt && (int) $attempt['attempts'] >= 5
                && $now - (int) $attempt['last_attempt'] < 900) {
                throw new RuntimeException('Too many attempts. Try again in 15 minutes.');
            }

            $account = query('SELECT * FROM users WHERE email = ?', [$email])->fetch();
            if (!$account || !password_verify($password, $account['password'])) {
                query(
                    'INSERT INTO login_attempts (key_hash, attempts, last_attempt) VALUES (?, 1, ?)
                     ON DUPLICATE KEY UPDATE
                     attempts = IF(last_attempt < ?, 1, attempts + 1), last_attempt = ?',
                    [$key, $now, $now - 900, $now]
                );
                throw new RuntimeException('Email or password is incorrect.');
            }

            query('DELETE FROM login_attempts WHERE key_hash = ?', [$key]);
            session_regenerate_id(true);
            unset($account['password']);
            $_SESSION['user'] = $account;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            redirect($account['role'] === 'admin' ? '?page=admin' : '?page=saved');
            return;

        case 'logout':
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            redirect('?');
            return;

        case 'generate':
            $input = [
                'start' => action_text('start', '08:00'),
                'hours' => action_text('hours', '8'),
                'pace' => action_text('pace', 'Balanced'),
                'mood' => action_text('mood', 'Any'),
                'companion' => action_text('companion', 'Solo')
            ];
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $input['start'])) {
                throw new RuntimeException('Enter a valid start time.');
            }
            $hours = filter_var($input['hours'], FILTER_VALIDATE_FLOAT);
            if ($hours === false || !is_finite($hours) || $hours < 1 || $hours > 16) {
                throw new RuntimeException('Available time must be between 1 and 16 hours.');
            }
            $input['hours'] = $hours;
            if (!in_array($input['pace'], ['Relaxed', 'Balanced', 'Fast'], true)
                || !in_array($input['mood'], ['Any', 'Nature', 'Adventure', 'Food & Culture', 'History'], true)
                || !in_array($input['companion'], ['Solo', 'Family', 'Friends'], true)) {
                throw new RuntimeException('Choose valid traveller preferences.');
            }
            $mode = action_text('mode', 'selected');
            if (!in_array($mode, ['selected', 'recommend'], true)) {
                throw new RuntimeException('Choose a valid planning method.');
            }

            $places = all_places();  
            if ($mode === 'recommend') {
                $selected = recommend_places($places, $input, $config);
                if (!$selected) {
                    throw new RuntimeException('No suitable places fit these preferences and time. Try another mood or more time.');
                }
            } else {
                $submitted = $_POST['places'] ?? [];
                if (!is_array($submitted)) {
                    throw new RuntimeException('Select places using the planner checkboxes.');
                }
                $ids = [];
                foreach ($submitted as $value) {
                    $id = is_string($value)
                        ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                        : false;
                    if ($id === false) {
                        throw new RuntimeException('A selected place is invalid. Reload the planner.');
                    }
                    $ids[] = $id;
                }
                $ids = array_values(array_unique($ids));
                if (!$ids) {
                    throw new RuntimeException('Select at least one place to create a plan.');
                }
                $selected = array_values(array_filter(
                    $places,
                    fn($place) => in_array((int) $place['id'], $ids, true)
                ));
                if (count($selected) !== count($ids)) {
                    throw new RuntimeException('A selected place is unavailable. Reload the planner.');
                }
            }

             
            $_SESSION['plan'] = generate_plan($selected, $input, $config);
            $_SESSION['plan_token'] = bin2hex(random_bytes(16));
            redirect('?page=summary');
            return;

        case 'save_plan':
            $account = require_user();
            $plan = $_SESSION['plan'] ?? null;
            if (!is_array($plan) || empty($plan['stops'])) {
                throw new RuntimeException('Generate a plan before saving.');
            }
            $token = action_text('plan_token');
            if ($token === '' || !hash_equals($_SESSION['plan_token'] ?? '', $token)) {
                throw new RuntimeException('This plan cannot be saved again. Generate a new plan if needed.');
            }
            $title = action_text('title', 'My day in Valaichenai');
            if ($title === '' || strlen($title) > 100) {
                throw new RuntimeException('Use a plan title between 1 and 100 bytes long.');
            }

             
            $connection = db();
            $connection->beginTransaction();
            try {
                query(
                    'INSERT INTO itineraries (user_id, title, plan_json) VALUES (?, ?, ?)',
                    [$account['id'], $title, json_encode($plan, JSON_THROW_ON_ERROR)]
                );
                $itineraryId = $connection->lastInsertId();
                foreach ($plan['stops'] as $position => $stop) {
                    query(
                        'INSERT INTO visit_plan (itinerary_id, place_id, visit_order) VALUES (?, ?, ?)',
                        [$itineraryId, $stop['place']['id'], $position + 1]
                    );
                }
                $connection->commit();
            } catch (Throwable $error) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1452) {
                    throw new RuntimeException('A record used by this plan is no longer available. Sign in and generate the plan again.');
                }
                throw $error;
            }

            unset($_SESSION['plan_token']);
            flash('Your itinerary has been saved.');
            redirect('?page=saved');
            return;

        case 'delete_plan':
            $account = require_user();
            $deleted = query(
                'DELETE FROM itineraries WHERE itinerary_id = ? AND user_id = ?',
                [action_id(), $account['id']]
            );
            if ($deleted->rowCount() === 0) {
                throw new RuntimeException('This saved plan was not found in your account.');
            }
            flash('Plan deleted.');
            redirect('?page=saved');
            return;

        case 'category':
            require_user(true);
            $id = action_id('id', true);
            $name = action_text('category_name');
            if ($name === '' || strlen($name) > 80) {
                throw new RuntimeException('Enter a category name between 1 and 80 bytes long.');
            }
            if ($id > 0 && !query('SELECT id FROM category WHERE id = ?', [$id])->fetch()) {
                throw new RuntimeException('Category not found.');
            }
            if (query('SELECT id FROM category WHERE category_name = ? AND id <> ?', [$name, $id])->fetch()) {
                throw new RuntimeException('That category name already exists.');
            }
            try {
                if ($id > 0) {
                    query('UPDATE category SET category_name = ? WHERE id = ?', [$name, $id]);
                } else {
                    query('INSERT INTO category (category_name) VALUES (?)', [$name]);
                }
            } catch (PDOException $error) {
                if (action_duplicate($error)) {
                    throw new RuntimeException('That category name already exists.');
                }
                throw $error;
            }
            flash('Category saved.');
            redirect('?page=admin');
            return;

        case 'delete_category':
            require_user(true);
            $id = action_id();
            if (query('SELECT id FROM tourist_place WHERE category_id = ? LIMIT 1', [$id])->fetch()) {
                throw new RuntimeException('Move the places in this category before deleting it.');
            }
            try {
                $deleted = query('DELETE FROM category WHERE id = ?', [$id]);
            } catch (PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) === 1451) {
                    throw new RuntimeException('This category is in use. Move its places before deleting it.');
                }
                throw $error;
            }
            if ($deleted->rowCount() === 0) {
                throw new RuntimeException('Category not found.');
            }
            flash('Category deleted.');
            redirect('?page=admin');
            return;

        case 'delete_place':
            require_user(true);
            $deleted = query('DELETE FROM tourist_place WHERE id = ?', [action_id()]);
            if ($deleted->rowCount() === 0) {
                throw new RuntimeException('Place not found.');
            }
            flash('Place deleted. Saved plan snapshots remain available.');
            redirect('?page=admin');
            return;

        case 'place':
            require_user(true);
            $id = action_id('id', true);
            if ($id > 0 && !query('SELECT id FROM tourist_place WHERE id = ?', [$id])->fetch()) {
                throw new RuntimeException('Place not found.');
            }

            $data = [];
             
            $limits = [
                'name' => 150, 'description' => 10000, 'address' => 255,
                'travel_tips' => 5000, 'image' => 1000, 'image_credit' => 1000,
                'entry_info' => 255, 'contact' => 100, 'dress_code' => 2000,
                'footwear' => 2000, 'photography' => 2000, 'facilities' => 2000,
                'best_time' => 150, 'source_url' => 1000
            ];
            foreach ($limits as $field => $limit) {
                $data[$field] = action_text($field);
                if (strlen($data[$field]) > $limit) {
                    throw new RuntimeException(str_replace('_', ' ', $field) . ' is too long.');
                }
            }
            foreach (['name', 'description', 'address'] as $field) {
                if ($data[$field] === '') {
                    throw new RuntimeException('Name, description and address are required.');
                }
            }

            foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $field => $range) {
                $value = filter_var(action_text($field), FILTER_VALIDATE_FLOAT);
                if ($value === false || !is_finite($value) || $value < $range[0] || $value > $range[1]) {
                    throw new RuntimeException('Enter valid latitude and longitude values.');
                }
                $data[$field] = $value;
            }
            $distanceKm = distance($data);
            $radiusKm = (float) $config['radius_km'];
            if ($distanceKm > $radiusKm) {
                throw new RuntimeException(
                    'This pin is ' . round($distanceKm, 2) . ' km in a straight line from the configured home. '
                    . 'The limit is ' . $radiusKm . ' km. Check the place and home coordinates.'
                );
            }

            $data['category_id'] = action_id('category_id');
            if (!query('SELECT id FROM category WHERE id = ?', [$data['category_id']])->fetch()) {
                throw new RuntimeException('Select a valid category.');
            }
            $duration = filter_var(action_text('visit_minutes'), FILTER_VALIDATE_INT);
            if ($duration === false || $duration < 5 || $duration > 480) {
                throw new RuntimeException('Visit duration must be 5 to 480 minutes.');
            }
            $data['visit_minutes'] = $duration;

            foreach (['open_time', 'close_time'] as $field) {
                $value = action_text($field);
                if ($value !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value)) {
                    throw new RuntimeException('Use valid opening and closing times.');
                }
                $data[$field] = $value === '' ? null : $value;
            }
            if (($data['open_time'] === null) !== ($data['close_time'] === null)) {
                throw new RuntimeException('Enter both opening and closing times, or leave both blank.');
            }
             
            $data['mood_tags'] = implode(',', action_choices(
                'moods', ['Nature', 'Adventure', 'Food & Culture', 'History']
            ));
            foreach (['hidden_gem', 'kid_friendly', 'wheelchair', 'active', 'verified'] as $field) {
                if (isset($_POST[$field])) {
                    action_text($field); // Reject nested or array values.
                }
                $data[$field] = isset($_POST[$field]) ? 1 : 0;
            }
            if ($data['verified'] && $data['source_url'] === '') {
                throw new RuntimeException('Provide a verification source or local consultation note before marking verified.');
            }

            if ($data['image'] !== '') {
                $image = $data['image'];
                $remote = filter_var($image, FILTER_VALIDATE_URL)
                    && strtolower((string) parse_url($image, PHP_URL_SCHEME)) === 'https'
                    && parse_url($image, PHP_URL_HOST)
                    && parse_url($image, PHP_URL_USER) === null
                    && parse_url($image, PHP_URL_PASS) === null;
                $local = preg_match('~^assets/[a-zA-Z0-9/_ .-]+\.(jpg|jpeg|png|webp)$~iD', $image)
                    && !in_array('..', explode('/', $image), true);
                if (!$remote && !$local) {
                    throw new RuntimeException('Use a valid HTTPS photo URL or an assets/ image path without ../ segments.');
                }
            }

            try {
                if ($id > 0) {
                    $assignments = array_map(fn($field) => $field . ' = ?', array_keys($data));
                    query(
                        'UPDATE tourist_place SET ' . implode(', ', $assignments) . ' WHERE id = ?',
                        [...array_values($data), $id]
                    );
                } else {
                    $columns = implode(', ', array_keys($data));
                    $placeholders = implode(', ', array_fill(0, count($data), '?'));
                    query('INSERT INTO tourist_place (' . $columns . ') VALUES (' . $placeholders . ')', array_values($data));
                }
            } catch (PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) === 1452) {
                    throw new RuntimeException('The selected category is no longer available. Reload the form.');
                }
                throw $error;
            }

            flash('Place information saved.');
            redirect('?page=admin');
            return;

        default:
            throw new RuntimeException('Unknown action. Reload the page and try again.');
    }
}
