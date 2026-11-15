<?php
declare(strict_types=1);

require 'vendor/autoload.php';
require __DIR__ . '/lib/EinkDashboard.php';
require __DIR__ . '/lib/RemoteDashboards.php';

use Google\Cloud\Storage\StorageClient;
use GDText\Box;
use GDText\Color;

const APOD_URL = "https://pimoroni.github.io/feed2image/nasa-apod-800x480-daily.jpg";

if (getenv('GOOGLE_APPLICATION_CREDENTIALS') && getenv('GOOGLE_APP_CREDENTIALS')) {
    @file_put_contents(getenv('GOOGLE_APPLICATION_CREDENTIALS'), getenv('GOOGLE_APP_CREDENTIALS'));
}

function env(string $name): string {
    return trim(preg_replace('/\s+/', ' ', (string)getenv($name)));
}

function logErrorMessage(string $message): void {
    $datetime = new DateTime('now', new DateTimeZone('UTC'));
    error_log($datetime->format('Y/m/d H:i:s') . ' ' . $message);
}

function getHAState(string $entity): ?string {
    try {
        $info = json_decode(EinkDashboard::haRequest('/api/states/' . $entity), true);
        return $info['state'] ?? null;
    } catch (Throwable $e) {
        logErrorMessage($e->getMessage());
        return null;
    }
}

function getRandomCloudStorageImageName($bucket) {
    $objects = iterator_to_array($bucket->objects(), false);
    return $objects[mt_rand(0, count($objects) - 1)];
}

function downloadAndCropCloudStorageImage($bucket, bool $weather = false, int $attempt = 0): void {
    $randomObject = getRandomCloudStorageImageName($bucket);
    $tempFileName = tempnam(sys_get_temp_dir(), 'random_image');
    $randomObject->downloadToFile($tempFileName);

    $image = @imagecreatefromjpeg($tempFileName);
    unlink($tempFileName);
    if ($image === false) {
        logErrorMessage("Cannot decode jpeg " . $randomObject->name());
        if ($attempt < 3) {
            downloadAndCropCloudStorageImage($bucket, $weather, $attempt + 1);
            return;
        }
        getRemoteImage(APOD_URL);
        return;
    }

    $originalWidth = imagesx($image);
    $originalHeight = imagesy($image);
    $cropWidth = (int)min($originalWidth, $originalHeight * 800 / 480);
    $cropHeight = (int)min($originalHeight, $originalWidth * 480 / 800);
    $cropX = (int)(($originalWidth - $cropWidth) / 2);
    $cropY = (int)(($originalHeight - $cropHeight) / 2);
    $resizedImage = imagecreatetruecolor(800, 480);
    imagecopyresampled($resizedImage, $image, 0, 0, $cropX, $cropY, 800, 480, $cropWidth, $cropHeight);

    if ($weather) {
        $temperature = round((float)getHAState("sensor.openweathermap_temperature"));
        $box = new Box($resizedImage);
        $box->setFontFace(__DIR__ . '/font.TTF');
        $box->setFontSize(40);
        $box->setFontColor(new Color(255, 255, 255));
        $box->setBox(20, 20, 770, 460);
        $box->setTextAlign('right', 'bottom');
        $box->setStrokeColor(new Color(0, 0, 0));
        $box->setStrokeSize(1);
        $box->draw($temperature . "°C");
    }

    header('Content-Type: image/jpeg');
    imagejpeg($resizedImage);
    imagedestroy($image);
    imagedestroy($resizedImage);
}

function getRemoteImage(string $imageUrl, bool $fallback = true): void {
    $imageData = @file_get_contents($imageUrl);
    if ($imageData === false) {
        logErrorMessage("Cannot fetch $imageUrl");
        if ($fallback && $imageUrl !== APOD_URL) {
            getRemoteImage(APOD_URL, false);
        } else {
            http_response_code(502);
        }
        return;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    header('Content-Type: ' . finfo_buffer($finfo, $imageData));
    header('Content-Length: ' . strlen($imageData));
    echo $imageData;
}

/**
 * ?config=1 – JSON for E-ink screen: {"dither": "0|1", "hash": "...", "format": "jpeg|png"}
 */
function configOutput(string $dither, string $hash, string $format = 'jpeg'): void {
    if (($_GET['config'] ?? null) == "1") {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['dither' => $dither, 'hash' => $hash, 'format' => $format]);
        exit(0);
    }
    if (($_GET['gethash'] ?? null) == "1") {
        header('Content-Type: text/plain');
        header('Cache-Control: no-store');
        echo $hash;
        exit(0);
    }
}

// dashboard e-ink

function serveDashboard(string $name): void {
    $preview = $_GET['preview'] ?? null;

    $baseDir = getenv('DASHBOARDS_DIR') ?: __DIR__ . '/dashboards';
    if ($url = env('DASHBOARDS_URL')) {
        $cacheRoot = getenv('CACHE_DIR') ?: sys_get_temp_dir() . '/eink-cache';
        $remote = new RemoteDashboards($url, $cacheRoot . '/_remote', (int)(getenv('DASHBOARDS_TTL') ?: 60));
        $baseDir = $remote->sync($name, force: $preview !== null);   // podgląd zawsze świeży
    }
    $dash = new EinkDashboard($name, $baseDir, getenv('CACHE_DIR') ?: null);

    // --- podgląd w przeglądarce (edycja szablonu na żywo) ---
    if ($preview !== null) {
        if ($preview === 'svg') {
            header('Content-Type: image/svg+xml');
            header('Cache-Control: no-store');
            echo $dash->renderSvg($dash->fetchData(), ($_GET['sim'] ?? '') === '1');
            return;
        }
        [$hash, $png, $data] = $dash->current();
        if ($preview === 'png') {
            header('Content-Type: image/png');
            header('Cache-Control: no-store');
            readfile($png);
            return;
        }
        $q = 'dashboard=' . rawurlencode($name);
        $dev = $dash->device();
        header('Content-Type: text/html; charset=utf-8');
        $json = htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo <<<HTML
        <!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="10">
        <title>Preview: {$name}</title>
        <style>body{font:14px system-ui;margin:20px;background:#ddd}figure{display:inline-block;margin:0 20px 20px 0}
        img{border:1px solid #888;image-rendering:pixelated;background:#fff}pre{background:#fff;padding:10px;max-width:780px;overflow:auto}</style>
        <h2>{$name} · {$dev['width']}×{$dev['height']} · {$dev['palette']} · hash <code>{$hash}</code></h2>
        <figure><img src="?{$q}&preview=png&h={$hash}" width="{$dev['width']}"><figcaption>PNG sent to device (color palette of the device)</figcaption></figure>
        <figure><img src="?{$q}&preview=svg&sim=1&t={$hash}" width="{$dev['width']}"><figcaption>Panel color simulation</figcaption></figure>
        <h3>Dane z HA</h3><pre>{$json}</pre><p>Refreshes every 10 sec.</p>
        HTML;
        return;
    }

    if (!empty($_GET['hash']) && ($png = $dash->pngForHash((string)$_GET['hash']))) {
        $hash = basename($png, '.png');
    } else {
        [$hash, $png] = $dash->current();
    }

    configOutput($dash->device()['dither'] ? "1" : "0", $hash, 'png');

    header('Content-Type: image/png');
    header('ETag: "' . $hash . '"');
    header('X-Content-Hash: ' . $hash);
    header('Cache-Control: no-cache');
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '', '"') === $hash) {
        http_response_code(304);
        return;
    }
    header('Content-Length: ' . filesize($png));
    readfile($png);
}

// router

if (($_GET['health'] ?? null) == "true") {
    $state = "Healthcheck";
} elseif (!empty($_GET['dashboard'])) {
    $state = 'Dashboard: ' . $_GET['dashboard'];
} else {
    // If Home Assistant is currently unavailable, try last mode
    $stateFile = (getenv('CACHE_DIR') ?: sys_get_temp_dir() . '/eink-cache') . '/last_state';
    $state = getHAState(env('HA_STATE_ENTITY_ID'));
    if ($state !== null && $state !== 'unavailable' && $state !== 'unknown') {
        @mkdir(dirname($stateFile), 0775, true);
        @file_put_contents($stateFile, $state);
    } else {
        $state = @file_get_contents($stateFile) ?: 'unavailable';
    }
}

try {
    if (str_starts_with($state, 'Dashboard:')) {
        serveDashboard(trim(substr($state, strlen('Dashboard:'))));
        exit(0);
    }

    switch ($state) {
        case "SmartHome Dashboard":
            configOutput("0", bin2hex(random_bytes(8)));
            getRemoteImage(env('HA_IMAGE_URL'));
            break;
        case "SpacePicture":
            configOutput("1", substr(md5('apod' . date('Y-m-d')), 0, 16));
            getRemoteImage(APOD_URL);
            break;
        case "CloudStorage Random":
        case "CloudStorage Random Weather":
            configOutput("1", bin2hex(random_bytes(8)));
            $bucket = (new StorageClient())->bucket(env('GCS_BUCKET_NAME'));
            downloadAndCropCloudStorageImage($bucket, $state === "CloudStorage Random Weather");
            break;
        default:
            configOutput("1", substr(md5_file(__DIR__ . '/image.jpg'), 0, 16));
            getRemoteImage(__DIR__ . '/image.jpg', false);
            break;
    }
} catch (Throwable $e) {
    logErrorMessage($e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(503);
    header('Content-Type: text/plain');
    echo 'Error: ' . $e->getMessage();
}
