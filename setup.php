<?php
declare(strict_types=1);
 
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';

if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Run setup from localhost on the server computer.');
}

require_once __DIR__ . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$error = '';
$done = false;
$locked = false;
$databaseReady = false;

$name = '';
$email = '';

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

 
function setup_text(array $source, string $key): string
{
    $value = $source[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

 
function setup_admin_exists(): bool
{
    return (bool) query(
        "SELECT id FROM users WHERE role = 'admin' LIMIT 1"
    )->fetch();
}

 
try {
    $locked = setup_admin_exists();
    $databaseReady = true;

    if ($locked) {
        http_response_code(403);
    }
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('DayTrail setup check: ' . $exception->getMessage());

    $error = 'The database could not be checked. Start MySQL and check '
        . 'config/config.php. For a new installation only, import '
        . 'database/daytrail.sql first.';
}

 

if ($isPost && $databaseReady && !$locked) {
    $name = setup_text($_POST, 'name');
    $email = setup_text($_POST, 'email');

    $password = isset($_POST['password']) && is_string($_POST['password'])
        ? $_POST['password']
        : '';

    $lockAcquired = false;
    $lockName = '';

    try {
         
        if (
            !isset($_POST['csrf']) ||
            !is_string($_POST['csrf'])
        ) {
            throw new InvalidArgumentException(
                'Your session token is missing. Reload the page and try again.'
            );
        }

        csrf();

         
        $nameLength = preg_match_all('/./us', $name);

        if (
            $nameLength === false ||
            $nameLength < 2 ||
            $nameLength > 100 ||
            preg_match('/[\x00-\x1F\x7F]/', $name)
        ) {
            throw new InvalidArgumentException(
                'Enter a name between 2 and 100 characters.'
            );
        }

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($email) > 190
        ) {
            throw new InvalidArgumentException(
                'Enter a valid email address of no more than 190 bytes.'
            );
        }

        $passwordCharacters = preg_match_all('/./us', $password);
        $passwordBytes = strlen($password);

        if (
            $passwordCharacters === false ||
            $passwordCharacters < 10 ||
            $passwordBytes > 72 ||
            str_contains($password, "\0")
        ) {
            throw new InvalidArgumentException(
                'Use at least 10 password characters and no more than '
                . '72 bytes. Some non-English characters use multiple bytes.'
            );
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            throw new RuntimeException(
                'The password could not be secured. Please try again.'
            );
        }

       
        $databaseName = (string) query(
            'SELECT DATABASE()'
        )->fetchColumn();

        $lockName = 'daytrail_setup_' . substr(
            hash('sha256', $databaseName),
            0,
            40
        );

        $lockResult = query(
            'SELECT GET_LOCK(?, 5)',
            [$lockName]
        )->fetchColumn();

        if ((int) $lockResult !== 1) {
            throw new RuntimeException(
                'Setup is busy or could not obtain its setup lock. '
                . 'Please try again shortly.'
            );
        }

        $lockAcquired = true;

         
        if (setup_admin_exists()) {
            $locked = true;
            http_response_code(403);
        } else {
            $existingUser = query(
                'SELECT id FROM users WHERE email = ? LIMIT 1',
                [$email]
            )->fetch();

            if ($existingUser) {
                throw new InvalidArgumentException(
                    'This email address already belongs to an account. '
                    . 'Use a different email address for the administrator.'
                );
            }

            query(
                "INSERT INTO users (name, email, password, role)
                 VALUES (?, ?, ?, 'admin')",
                [$name, $email, $passwordHash]
            );

            $done = true;

             
            $password = '';
        }
    } catch (PDOException $exception) {
        error_log('DayTrail setup database: ' . $exception->getMessage());

        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($driverCode === 1062) {
            http_response_code(409);

            $error = 'An account with these unique details already exists. '
                . 'Check the email address and try again.';
        } else {
            http_response_code(503);

            $error = 'The administrator could not be created. '
                . 'Check the database connection and server error log.';
        }
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
        $error = $exception->getMessage();
    } catch (RuntimeException $exception) {
        if (http_response_code() < 400) {
            http_response_code(400);
        }

        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        http_response_code(500);
        error_log('DayTrail setup: ' . $exception->getMessage());

        $error = 'Setup could not be completed. '
            . 'Please check the server error log and try again.';
    } finally {
        if ($lockAcquired) {
            try {
                query(
                    'SELECT RELEASE_LOCK(?)',
                    [$lockName]
                );
            } catch (Throwable $exception) {
                error_log(
                    'DayTrail setup lock release: '
                    . $exception->getMessage()
                );
            }
        }

        unset($password, $passwordHash);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Set up DayTrail</title>

    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>
<main class="auth panel">
    <a class="brand" href="index.php">
        ◈ <span>DayTrail<small>VALAICHCHENAI</small></span>
    </a>

    <?php if ($done): ?>
        <h1>Administrator created</h1>

        <p class="notice success" role="status">
            Your administrator account has been created successfully.
            Initial setup is now locked while this administrator exists.
        </p>

        <p>Sign in using the email address and password you just entered.</p>

        <a class="button wide" href="index.php?page=login">
            Sign in
        </a>

    <?php elseif ($locked): ?>
        <h1>Setup is locked</h1>

        <p class="notice" role="status">
            An administrator account already exists.
            This page cannot create another administrator.
        </p>

        <a class="button wide" href="index.php?page=login">
            Sign in
        </a>

        <a href="index.php">Return to DayTrail</a>

    <?php elseif (!$databaseReady): ?>
        <h1>Check your database</h1>

        <p class="notice error" role="alert">
            <?= e($error) ?>
        </p>

        <p>
            For a new local installation, start Apache and MySQL in XAMPP,
            import <strong>database/daytrail.sql</strong>, and configure
            <strong>config/config.php</strong>.
        </p>

        <p>
            If the database already contains your project data,
            check the connection settings before attempting another import.
        </p>

        <a class="button secondary wide" href="setup.php">
            Check again
        </a>

    <?php else: ?>
        <h1>Create your administrator</h1>

        <p>
            This local setup creates the first administrator account.
            It does not import the database or change your place records.
        </p>

        <p class="muted">
            Before setup, copy
            <strong>config/config.example.php</strong> to
            <strong>config/config.php</strong> if that file does not
            already exist. Enter your database and home settings.
        </p>

        <?php if ($error !== ''): ?>
            <p class="notice error" role="alert">
                <?= e($error) ?>
            </p>
        <?php endif; ?>

        <form method="post" action="setup.php">
            <input
                type="hidden"
                name="csrf"
                value="<?= e($_SESSION['csrf'] ?? '') ?>"
            >

            <label for="setup-name">
                Your name
                <input
                    id="setup-name"
                    name="name"
                    type="text"
                    value="<?= e($name) ?>"
                    minlength="2"
                    maxlength="100"
                    autocomplete="name"
                    required
                >
            </label>

            <label for="setup-email">
                Admin email
                <input
                    id="setup-email"
                    name="email"
                    type="email"
                    value="<?= e($email) ?>"
                    maxlength="190"
                    autocomplete="email"
                    required
                >
            </label>

            <label for="setup-password">
                New password
                <input
                    id="setup-password"
                    name="password"
                    type="password"
                    minlength="10"
                    maxlength="72"
                    autocomplete="new-password"
                    aria-describedby="setup-password-help"
                    required
                >
            </label>

            <p id="setup-password-help" class="muted">
                Use at least 10 characters. The maximum is 72 bytes;
                some non-English characters use multiple bytes.
            </p>

            <button class="wide" type="submit">
                Create administrator
            </button>
        </form>

        <a href="index.php">Return to DayTrail</a>
    <?php endif; ?>
</main>
</body>
</html>