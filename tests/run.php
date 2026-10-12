<?php

declare(strict_types=1);

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

$root = dirname(__DIR__);
$databasePath = sys_get_temp_dir() . '/rezka-watch-sync-test-' . bin2hex(random_bytes(6)) . '.sqlite';
$port = random_int(20000, 50000);
$database = new PDO('sqlite:' . $databasePath);
$database->exec((string) file_get_contents(__DIR__ . '/sqlite-schema.sql'));
$database = null;

$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/server.php'],
    [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/rezka-watch-sync-test-server.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/rezka-watch-sync-test-server.log', 'a']],
    $pipes,
    $root,
    array_merge($_ENV, ['REZKA_TEST_DB' => $databasePath]),
);

if (!is_resource($server)) {
    throw new RuntimeException('Could not start the local PHP API test server.');
}

try {
    $request = static function (string $path, string $method = 'GET', ?array $body = null, ?string $token = null) use ($port): array {
        $headers = ['Content-Type: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 3,
        ]]);
        $response = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
        if (!is_string($response)) {
            throw new RuntimeException('Could not reach local API route ' . $path);
        }
        $statusLine = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $match);
        return [(int) ($match[1] ?? 0), json_decode($response, true, 512, JSON_THROW_ON_ERROR)];
    };

    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        usleep(100000);
        try {
            [$status] = $request('/health');
            $ready = $status === 200;
            if ($ready) {
                break;
            }
        } catch (Throwable) {
        }
    }
    assertSameValue(true, $ready, 'The local API health route did not start.');
    [$folderStatus] = $request('/sync/health');
    assertSameValue(200, $folderStatus, 'An API installed in a subfolder should answer at the folder address.');
    [$publicFolderStatus] = $request('/sync/public/health');
    assertSameValue(200, $publicFolderStatus, 'An API installed in a subfolder should also answer at its public folder.');

    [$status, $registration] = $request('/v1/auth/register', 'POST', [
        'email' => 'tester@example.com',
        'password' => 'test-password-123',
    ]);
    assertSameValue(201, $status, 'Registration should create an account.');
    assertSameValue('tester@example.com', $registration['user']['email'], 'Registration should return the normalized email.');
    assertSameValue(43, strlen($registration['token']), 'Session tokens should use 32 random bytes encoded as base64url.');
    $token = $registration['token'];

    [$duplicateStatus] = $request('/v1/auth/register', 'POST', [
        'email' => 'tester@example.com',
        'password' => 'test-password-123',
    ]);
    assertSameValue(409, $duplicateStatus, 'A duplicate email should be rejected.');

    [$loginStatus, $login] = $request('/v1/auth/login', 'POST', [
        'email' => 'TESTER@example.com',
        'password' => 'test-password-123',
    ]);
    assertSameValue(200, $loginStatus, 'A valid account should sign in.');

    $movieRecord = [
        'item' => ['id' => 'film-1', 'title' => 'Film One', 'kind' => 'movie', 'page' => 'https://omnirezka.tv/film-one'],
        'position' => 120,
        'duration' => 3600,
        'updatedAt' => 2000,
        'completed' => false,
        'translator' => 'dub-1',
    ];
    [$putStatus, $saved] = $request('/v1/history', 'PUT', ['records' => [$movieRecord]], $token);
    assertSameValue(200, $putStatus, 'Authenticated history should save.');
    assertSameValue(1, count($saved['records']), 'History should return the saved movie.');
    assertSameValue('dub-1', $saved['records'][0]['translatorID'], 'The chosen audio track should survive sync.');
    [$unauthenticatedStatus] = $request('/v1/history');
    assertSameValue(401, $unauthenticatedStatus, 'History should require a signed-in account.');

    $newerRecord = $movieRecord;
    $newerRecord['position'] = 900;
    $newerRecord['updatedAt'] = 3000;
    [, $saved] = $request('/v1/history', 'PUT', ['records' => [$newerRecord]], $token);
    assertSameValue(900, $saved['records'][0]['position'], 'Newer watch progress should replace stale progress.');
    [, $saved] = $request('/v1/history', 'PUT', ['records' => [$movieRecord]], $token);
    assertSameValue(900, $saved['records'][0]['position'], 'Older watch progress must not replace newer progress.');

    $seriesRecord = [
        'item' => ['id' => 'series-1', 'title' => 'Series One', 'kind' => 'series'],
        'season' => '1',
        'episode' => '6',
        'position' => 2040,
        'duration' => 3300,
        'updatedAt' => 4000,
        'completed' => false,
    ];
    [, $saved] = $request('/v1/history', 'PUT', ['records' => [$seriesRecord]], $token);
    assertSameValue(2, count($saved['records']), 'Movie and episode history should stay separate.');
    $syncedEpisode = array_values(array_filter($saved['records'], static fn (array $record): bool => $record['item']['id'] === 'series-1'))[0];
    assertSameValue('6', $syncedEpisode['episodeID'], 'Episode identity should be returned for cross-device resume.');

    $iosRecord = [
        'item' => [
            'id' => 'ios-film', 'title' => 'iOS Film', 'kind' => 'movie', 'genre' => 'Drama', 'synopsis' => '',
            'symbol' => 'I', 'paletteIndex' => 0, 'posterURL' => 'https://example.com/poster.jpg',
            'rezka' => ['movieID' => '777', 'pageURL' => 'https://hdrezka.me/films/drama/777-ios-film.html', 'isSeries' => false,
                'translators' => [['id' => '56', 'name' => 'Dub', 'isPremium' => false, 'isSelected' => true, 'flags' => new stdClass()]],
                'seasons' => [], 'playerFlags' => new stdClass()],
        ],
        'translatorID' => '56',
        'position' => 600,
        'duration' => 6000,
        'updatedAt' => 5000,
    ];
    $androidRecord = [
        'item' => [
            'id' => 'android-film', 'title' => 'Android Film', 'kind' => 'movie', 'genre' => 'Drama',
            'synopsis' => str_repeat('я', 1500), 'page' => 'https://omnirezka.tv/films/drama/888-android-film.html',
            'isSeries' => false, 'rezkaID' => '888',
        ],
        'position' => 700,
        'duration' => 6000,
        'updatedAt' => 6000,
        'completed' => false,
    ];
    [, $saved] = $request('/v1/history', 'PUT', ['records' => [$iosRecord, $androidRecord]], $token);
    $byID = array_column(array_map(static fn (array $record): array => [$record['item']['id'], $record], $saved['records']), 1, 0);
    $iosItem = $byID['ios-film']['item'];
    assertSameValue('https://hdrezka.me/films/drama/777-ios-film.html', $iosItem['page'] ?? null, 'iOS records should expose the page URL Android needs.');
    assertSameValue('777', $iosItem['rezkaID'] ?? null, 'iOS records should expose the Rezka ID Android needs.');
    assertSameValue(false, $iosItem['isSeries'] ?? null, 'iOS records should expose the series flag Android needs.');
    assertSameValue('Dub', $iosItem['translators'][0]['name'] ?? null, 'iOS translators should be readable by Android.');
    $androidItem = $byID['android-film']['item'];
    assertSameValue(1200, mb_strlen($androidItem['synopsis'] ?? ''), 'Long synopses should be shortened, not dropped, because iOS requires the field.');
    assertSameValue('888', $androidItem['rezka']['movieID'] ?? null, 'Android records should expose the Rezka media iOS needs.');

    [$clearStatus, $cleared] = $request('/v1/history', 'DELETE', null, $token);
    assertSameValue(200, $clearStatus, 'History should clear.');
    assertSameValue([], $cleared['records'], 'Cleared history should return no records.');
    [, $afterClear] = $request('/v1/history', 'PUT', ['records' => [$movieRecord, $seriesRecord]], $token);
    assertSameValue([], $afterClear['records'], 'Old records must not restore after clear.');

    [$logoutStatus] = $request('/v1/auth/logout', 'POST', [], $token);
    assertSameValue(200, $logoutStatus, 'Sign out should revoke the token.');
    [$revokedStatus] = $request('/v1/history', 'GET', null, $token);
    assertSameValue(401, $revokedStatus, 'A revoked token must no longer authorize requests.');

    touch($databasePath . '.closed');
    [$closedStatus] = $request('/v1/auth/register', 'POST', ['email' => 'new@example.com', 'password' => 'test-password-123']);
    assertSameValue(403, $closedStatus, 'With sign-ups off, no account can be created.');
    [, $health] = $request('/health');
    assertSameValue(false, $health['registration'] ?? null, 'Health should say whether sign-ups are open.');
    [$loginStatus] = $request('/v1/auth/login', 'POST', ['email' => 'tester@example.com', 'password' => 'test-password-123']);
    assertSameValue(200, $loginStatus, 'Existing accounts can still sign in with sign-ups off.');

    echo "PHP sync API tests passed.\n";
} finally {
    proc_terminate($server);
    proc_close($server);
    @unlink($databasePath);
    @unlink($databasePath . '.closed');
}
