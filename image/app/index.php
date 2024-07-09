<?php

require 'vendor/autoload.php';
use Google\Cloud\Storage\StorageClient;
use GDText\Box;
use GDText\Color;

$APOD_URL = "https://pimoroni.github.io/feed2image/nasa-apod-800x480-daily.jpg";

file_put_contents(getenv('GOOGLE_APPLICATION_CREDENTIALS'), getenv('GOOGLE_APP_CREDENTIALS'));

function getHAState($entity) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_URL, trim(preg_replace('/\s+/', ' ', getenv('HA_URL'))) . '/api/states/' . $entity);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . trim(preg_replace('/\s+/', ' ', getenv('HA_BEARER')))
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); //timeout in seconds
    $result = curl_exec($ch);
    curl_close($ch);

    $info = json_decode($result,true);
    return $info['state'];
}

function getRandomCloudStorageImageName($bucket) {
    $objectList = $bucket->objects();

    $totalObjects = 0;
    foreach ($objectList as $object) {
        $totalObjects++;
    }
    $randomIndex = mt_rand(0, $totalObjects - 1);
    $objectList->rewind();
    for ($i = 0; $i < $randomIndex; $i++) {
        $objectList->next();
    }
    $randomObject = $objectList->current();

    return $randomObject;
}

function downloadAndCropCloudStorageImage($randomObject, $weather = false) {
    $objectData = $randomObject->downloadAsStream();

    // Create a temporary file to store the downloaded image
    $tempFileName = tempnam(sys_get_temp_dir(), 'random_image');
    $tempFile = fopen($tempFileName, 'w');

    while (!$objectData->eof()) {
        fwrite($tempFile, $objectData->read(1024));
    }
    fclose($tempFile);
    
    $image = imagecreatefromjpeg($tempFileName);

    // Get the original image dimensions
    $originalWidth = imagesx($image);
    $originalHeight = imagesy($image);

    // Calculate the coordinates and dimensions for cropping to fit 800x480px
    $cropWidth = min($originalWidth, $originalHeight * 800 / 480);
    $cropHeight = min($originalHeight, $originalWidth * 480 / 800);
    $cropX = ($originalWidth - $cropWidth) / 2;
    $cropY = ($originalHeight - $cropHeight) / 2;

    // Create a new image with the desired dimensions
    $resizedImage = imagecreatetruecolor(800, 480);

    // Crop and resize the image
    imagecopyresampled($resizedImage, $image, 0, 0, $cropX, $cropY, 800, 480, $cropWidth, $cropHeight);

    if($weather) {
        $temperature = round(getHAState("sensor.openweathermap_temperature"));

        $box = new Box($resizedImage);
        $box->setFontFace(__DIR__.'/font.TTF');
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

    // Clean up
    imagedestroy($image);
    imagedestroy($resizedImage);
    unlink($tempFileName); // Delete the temporary file
}

function getRemoteImage($imageUrl) {
	$imageData = file_get_contents($imageUrl);

    if ($imageData !== false) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $imageData);

        header("Content-Type: $mimeType");
        header('Content-Length: ' . strlen($imageData));

        echo $imageData;
    } else {
	    setData("1");
	    getRemoteImage($APOD_URL);
    }
}

function setData($dither) {
	$json = "{\"dither\":\"" . $dither . "\"}";
	return $json;
}

function configOutput($dither) {
    if ($_GET['config'] == "1") {
        echo setData($dither);
        exit(0);
    }
}

# Healthcheck
$health = $_GET['health'] ?? null;

if ($health == "true") {
    $state = "Healthcheck";
} else {
    $state = getHAState(getenv('HA_STATE_ENTITY_ID'));
}


switch($state) {
    case "SmartHome Dashboard":
    	configOutput("0");
    	getRemoteImage(trim(preg_replace('/\s+/', ' ', getenv('HA_IMAGE_URL'))));
    	break;
    case "SpacePicture":
    	configOutput("1");
    	getRemoteImage($APOD_URL);
    	break;
    case "CloudStorage Random":
    	configOutput("1");
        $storage = new StorageClient();
        $bucket = $storage->bucket(getenv('GCS_BUCKET_NAME'));
        $object = getRandomCloudStorageImageName($bucket);
        downloadAndCropCloudStorageImage($object);
    	break;
    case "CloudStorage Random Weather":
        configOutput("1");
        $storage = new StorageClient();
        $bucket = $storage->bucket(getenv('GCS_BUCKET_NAME'));
        $object = getRandomCloudStorageImageName($bucket);
        downloadAndCropCloudStorageImage($object, true);
        break;
    default:
        configOutput("1");
        getRemoteImage('image.jpg');
        break;
}

?>
