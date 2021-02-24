<?php
// run as: php /deploy.php $file_name $version $sandbox $release_mode
// with env vars: USER_ID, PUBLIC_KEY, SECRET_KEY, PLUGIN_SLUG, PLUGIN_ID
$file_name = $_ENV['INPUT_FILE_NAME'];
$version = $_ENV['INPUT_VERSION'];
$sandbox = ($_ENV['INPUT_SANDBOX'] === 'true');

$release_mode = ! isset($_ENV['INPUT_RELEASE_MODE']) || empty($_ENV['INPUT_RELEASE_MODE']) ? 'pending' :  $_ENV['INPUT_RELEASE_MODE'];

echo "\n- Deploying " . $_ENV['PLUGIN_SLUG'] . " to Freemius, with arguments: ";
echo "\n- file_name: " . $file_name . " version: " . $version . " sandbox: " . var_export($sandbox) . " release_mode: " . $release_mode;

require_once '/freemius-php-api/freemius/FreemiusBase.php';
require_once '/freemius-php-api/freemius/Freemius.php';

define('FS__API_SCOPE', 'developer');
define('FS__API_DEV_ID', $_ENV['DEV_ID']);
define('FS__API_PUBLIC_KEY', $_ENV['PUBLIC_KEY']);
define('FS__API_SECRET_KEY', $_ENV['SECRET_KEY']);

echo "\n- Deploy in progress on Freemius\n";

// Init SDK.
$api = new Freemius_Api(FS__API_SCOPE, FS__API_DEV_ID, FS__API_PUBLIC_KEY, FS__API_SECRET_KEY);

if (!is_object($api)) {

  throw new \Exception('Error initializing the Freemius API.');

} // end if;

$deploy = $api->Api('plugins/'.$_ENV['PLUGIN_ID'].'/tags.json', 'GET', array(
  'plugin_id' => $_ENV['PLUGIN_ID'],
));

if ($deploy->tags[0]->version === $version) {
        
  $deploy = $deploy->tags[0];

  echo '- Package already deployed on Freemius'."\n";

} else {
  
  $token = $api->Api('token.json', 'GET');

  $access_token = $token->access;

  /**
   * Upload to Freemius.
   */
  $results = upload_file_to_freemius($access_token, $file_name, $_ENV['PLUGIN_SLUG'], $version);

  if (!property_exists($results, 'id')) {

    throw new \Exception('Error deploying to Freemius.');

  } // end if;

  echo "- Deploy done on Freemius\n";

  $is_released = $api->Api('plugins/'.$_ENV['PLUGIN_ID'].'/tags/' . $results->id . '.json', 'PUT', array(
    'release_mode' => $release_mode,
    'plugin_id' => $_ENV['PLUGIN_ID'],
  ), array());

  echo "- Set as released on Freemius\n";

} // end if;

/**
 * Upload to deploy
 */
$results = upload_file_to_versions($file_name, $_ENV['PLUGIN_SLUG'], $version);

if (!property_exists($results, 'id')) {

  throw new \Exception('Error deploying to versions.');

} // end if;

echo "- Deployed on versions successfully!\n";

echo "- Finished with success!\n";

/**
 * Upload the new version to Freemius
 *
 * @param string $access_token Freemius access token.
 * @param string $file_name File name.
 * @param string $slug Plugin slug.
 * @param string $version Plugin version.
 * @return object JSON Response
 */
function upload_file_to_freemius($access_token, $file_name, $slug, $version) {

  $ch = curl_init();

  $url = sprintf('https://fast-api.freemius.com/v1/developers/%d/plugins/%d/tags.json', FS__API_DEV_ID, $_ENV['PLUGIN_ID']);

  $body = array(
    'add_contributor' => false,
    'file'            => new \CurlFile($file_name, 'application/zip', "{$slug}.zip"),
  );

  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    "Authorization: FSA 3730:{$access_token}",
    'Content-Type: multipart/form-data;',
  ));

  $response = curl_exec($ch);

  if (!$response) {

    throw new \Exception(curl_error($ch));

  } // end if;

  curl_close($ch);

  return json_decode($response);

} // end upload_file_to_freemius;

/**
 * Upload the file to the deploy server.
 *
 * @param string $file_name The file name.
 * @param string $slug Plugin slug.
 * @param string $version Plugin version.
 * @return object
 */
function upload_file_to_versions($file_name, $slug, $version) {

  $ch = curl_init();
  
  $file = file_get_contents($file_name);
  
  $new_filename = "{$slug}.{$version}.zip";
  
  $url = 'https://deploy.nextpress.co/wp-json/wp/v2/media/';
  
  $username = $_ENV['VERSIONS_USERNAME'];
  $password = $_ENV['VERSIONS_PASSWORD'];

  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $file);
  curl_setopt($ch, CURLOPT_USERPWD, $username.':'.$password);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Disposition: form-data; filename="' . $new_filename . '"',
    'Authorization: Basic ' . base64_encode($username . ':' . $password),
  ));

  $result = curl_exec($ch);

  if (!$result) {

    throw new \Exception(curl_error($ch));

  } // end if;

  curl_close($ch);

  $upload = json_decode($result);

  if (!is_object($upload) || !property_exists($upload, 'id')) {

    throw new \Exception(curl_error($ch));

  } // end if;

  $media_id = $upload->id;

  $url = 'https://deploy.nextpress.co/wp-json/realmedialibrary/v1/attachments/bulk/move';
  
  $_mode = $release_mode == 'pending' ? 2 : 1;

  foreach (array($_mode) as $folder) {

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERPWD, $username.':'.$password);

    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
      'ids'    => array($media_id),
      'to'     => $folder,
      'isCopy' => true,
    )));

    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Authorization: Basic ' . base64_encode($username . ':' . $password),
    ));

    $result = curl_exec($ch);

    if (!$result) {

      throw new \Exception(curl_error($ch));

    } // end if;

    curl_close($ch);

  } // end if;

  return $upload;

} // end upload_file_to_versions;
