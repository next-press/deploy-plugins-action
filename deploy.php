<?php
    // run as: php /deploy.php $file_name $version $sandbox $release_mode
    // with env vars: USER_ID, PUBLIC_KEY, SECRET_KEY, PLUGIN_SLUG, PLUGIN_ID
    $file_name = $_ENV['INPUT_FILE_NAME'];
    $version = $_ENV['INPUT_VERSION'];
    $sandbox = ($_ENV['INPUT_SANDBOX'] === 'true' );
    $release_mode = ! isset( $_ENV['INPUT_RELEASE_MODE'] ) || empty( $_ENV['INPUT_RELEASE_MODE'] ) ? 'pending' :  $_ENV['INPUT_RELEASE_MODE'];

    echo "\n- Deploying " . $_ENV['PLUGIN_SLUG'] . " to Freemius, with arguments: ";
    echo "\n- file_name: " . $file_name . " version: " . $version . " sandbox: " . $sandbox . " release_mode: " . $release_mode;

    require_once '/freemius-php-api/freemius/FreemiusBase.php';
    require_once '/freemius-php-api/freemius/Freemius.php';
	define( 'FS__API_SCOPE', 'developer' );
	define( 'FS__API_DEV_ID', $_ENV['DEV_ID'] );
	define( 'FS__API_PUBLIC_KEY', $_ENV['PUBLIC_KEY'] );
	define( 'FS__API_SECRET_KEY', $_ENV['SECRET_KEY'] );

    // var_dump(FS__API_PUBLIC_KEY, FS__API_SECRET_KEY, FS__API_DEV_ID, $_ENV['PLUGIN_SLUG']);

    echo "\n- Deploy in progress on Freemius\n";

    try {
        // Init SDK.
        $api = new Freemius_Api(FS__API_SCOPE, FS__API_DEV_ID, FS__API_PUBLIC_KEY, FS__API_SECRET_KEY);

	// var_dump($api);
	    
        if (!is_object($api)) {
            var_dump('error_1', $deploy);
            die();
        }

        $deploy = $api->Api('plugins/'.$_ENV['PLUGIN_ID'].'/tags.json', 'GET', array(
            'plugin_id' => $_ENV['PLUGIN_ID']
        ));
        if ( $deploy->tags[0]->version === $version ) {
                $deploy = $deploy->tags[0];
                echo '-Package already deployed on Freemius'."\n";
        } else {
            // Upload the zip
	    $api = new Freemius_Api(FS__API_SCOPE, FS__API_DEV_ID, FS__API_PUBLIC_KEY, FS__API_SECRET_KEY);
            $deploy = $api->Api('plugins/'.$_ENV['PLUGIN_ID'].'/tags.json', 'POST', array(
                'add_contributor' => false,
                'plugin_id' => $_ENV['PLUGIN_ID']
            ), array(
                'file' =>  './' . $file_name
            ));

            if (!property_exists($deploy, 'id')) {
                var_dump('error_2', $deploy);
                die();
            }

            echo "- Deploy done on Freemius\n";

            $is_released = $api->Api('plugins/'.$_ENV['PLUGIN_ID'].'/tags/'.$deploy->id.'.json', 'PUT', array(
                'release_mode' => $release_mode,
                'plugin_id' => $_ENV['PLUGIN_ID']
            ), array());

            echo "- Set as released on Freemius\n";
        }

        echo "- Download Freemius free version\n";

        // Generate url to download the zip
        $zip_free = $api->GetSignedUrl('plugins/'.$_ENV['PLUGIN_ID'].'/tags/'.$deploy->id.'.zip?plugin_id='.$_ENV['PLUGIN_ID'], array());
        $path = pathinfo($file_name);
        $zipname_free = $path['dirname'] . '/' . basename($file_name, '.zip');
        $zipname_free .= '__free.zip';

        file_put_contents($zipname_free,file_get_contents($zip_free));

        echo "- Downloaded Freemius free version to ".$zipname_free."\n";
        echo "::set-output name=free_version::" . $zipname_free . "\n";

        // Generate url to download the pro zip
        $zip_pro = $api->GetSignedUrl('plugins/'.$_ENV['PLUGIN_ID'].'/tags/'.$deploy->id.'.zip?is_premium=true&plugin_id='.$_ENV['PLUGIN_ID'], array());
        $path = pathinfo($file_name);
        $zipname_pro = $path['dirname'] . '/' . basename($file_name, '.zip');
        $zipname_pro .= '.zip';

        file_put_contents($zipname_pro,file_get_contents($zip_pro));

        echo "- Downloaded Freemius pro version to ".$zipname_pro."\n";
        echo "::set-output name=pro_version::" . $zipname_pro . "\n";
    }
    catch (Exception $e) {
        echo "- Freemius server has problems\n";
    }
