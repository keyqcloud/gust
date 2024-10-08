#!/usr/bin/env php
<?php

$developer_mode = true;

// prevent access from web
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    echo 'Warning: Gust should be invoked via the CLI version of PHP, not the '.PHP_SAPI.' SAPI'.PHP_EOL;
}

// set local
setlocale(LC_ALL, 'C');

// constants
define('KYTE_STDIN', fopen("php://stdin","r"));
define('KYTE_gust_env', $_SERVER['HOME']."/.kytegust");

// check if .kytegust exists
if (!file_exists( KYTE_gust_env )) {
    if (!isset($argv[1]) ) {
        echo "Thank you for installing Gust to get your Kyte application up in to the sky.\n";
        echo "First, we need some information to configure your Gust environment.\n\n";

        echo "Where is your Kyte application located? (/var/www/html/): ";
        $line = fgets(KYTE_STDIN);
        $gust_env['kyte_dir'] = trim($line);

        echo "\n\nExcellent, next what is the DB engine? (InnoDB): ";
        $line = fgets(KYTE_STDIN);
        $gust_env['db_engine'] = trim($line);

        echo "\n\nPerfect, and one last, what is the charset? (utf8): ";
        $line = fgets(KYTE_STDIN);
        $gust_env['db_charset'] = trim($line);
        fclose(KYTE_STDIN);

        echo "\n\nAweseome! Your answers have been saved in ".KYTE_gust_env." so you won't have to keep typing them\n";

        $config_content = <<<EOT
<?php
    \$gust_env['kyte_dir'] = '{$gust_env['kyte_dir']}';
    \$gust_env['db_engine'] = '{$gust_env['db_engine']}';
    \$gust_env['db_charset'] = '{$gust_env['db_charset']}';
EOT;

        // write config file
        file_put_contents(KYTE_gust_env, $config_content);
        require_once(KYTE_gust_env);
        $developer_mode = false;
    } else {
        echo "Server configuration not found - running in developer mode.\n";
    }
} else {
    require_once(KYTE_gust_env);
    $developer_mode = false;
}

if (!$developer_mode) {
// check if required files exist
    if (!file_exists($gust_env['kyte_dir'].'config.php')) {
        echo "Missing configuration file.  Please create a configuration file with path ".$gust_env['kyte_dir'].'config.php'.PHP_EOL;
        exit(-1);
    }
    if (!file_exists($gust_env['kyte_dir'].'vendor/autoload.php')) {
        echo "Missing composer autoload file.".PHP_EOL;
        exit(-1);
    }

    // read in required files
    require_once($gust_env['kyte_dir'].'/vendor/autoload.php');
    require_once($gust_env['kyte_dir'].'/config.php');

    require_once(__DIR__.'/lib/Database.php');

    // load API and bootstrap to read in models
    $api = new \Kyte\Core\Api();
}

// init db
// init account
if (isset($argv[1], $argv[2]) ) {

    // init db
    if ($argv[1] == 'init' && $argv[2] == 'db' && !$developer_mode) {
        // create db connection sh for convenience
        $content = <<<EOT
#!/usr/bin/bash
mysql -u%s -p"%s" -h%s %s
EOT;

        file_put_contents($_SERVER['HOME'].'/dbconnect.sh', sprintf($content, KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE));
        echo "Database connection bash script created ({$_SERVER['HOME']}/dbconnect.sh)\n";

        $content = <<<EOT
#!/usr/bin/bash

current_time=$(date "+%%Y.%%m.%%d-%%H.%%M.%%S")
mysqldump -u%s -p"%s" -h%s %s > backup_\$current_time.sql
EOT;

        file_put_contents($_SERVER['HOME'].'/dump.sh', sprintf($content, KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE));
        echo "Database backup bash script created ({$_SERVER['HOME']}/dbconnect.sh)\n";

        echo "Initializing database...";
        // check if database exists and if not create it
        shell_exec(sprintf("mysql -u%s -p\"%s\" -h%s -e 'CREATE DATABASE IF NOT EXISTS %s;'", KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE));
        // TODO: Check return response
        echo sprintf("database %s created\n", KYTE_DB_DATABASE);

        echo "Creating tables...\n";
        $model_sql = \Gust\Database::create_tables($gust_env['db_charset'], $gust_env['db_engine']);
        $sql_stmt = '';

        foreach($model_sql as $stmt) {
            $sql_stmt .= $stmt."\n\n";
        }
        file_put_contents($_SERVER['HOME'].'/schema.sql', $sql_stmt);

        // create tables
        shell_exec(sprintf("mysql -u%s -p\"%s\" -h%s %s < schema.sql", KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE));
        // TODO: check return response

        echo "DB initialization complete!\n\n";
        echo "Next, consider running `{$argv[0]} init account` to create API keys, install default roles and permission and setup a Kyte account.\n";
    }

    // init account [Account Name] [User's Name] [email] [password]
    if ($argv[1] == 'init' && $argv[2] == 'account' && isset($argv[3], $argv[4], $argv[5], $argv[6]) && !$developer_mode) {
        echo "Begining account initialization...\n\n";

        // create account
        echo "Creating account...";
        $account = new \Kyte\Core\ModelObject(KyteAccount);
        $length=20; //maximum: 32
        do {
            $account_number = substr(md5(uniqid(microtime())),0,$length);
        } while ($account->retrieve('number', $account_number));
        
        if (!$account->create([
            'name'      => $argv[3],
            'number'    => $account_number
        ])) {
            echo "FAILED\n\n";
            exit(-1);
        }
        echo "OK\n\n";
        echo "Account # $account_number created.\n\n";

        echo "Creating API Keys...";
        // create API Keys
        $epoch = time();
        $identifier = uniqid();
        $secret_key = hash_hmac('sha1', $identifier, $epoch);
        $public_key = hash_hmac('sha1', $identifier, $secret_key);

        $apiKey = new \Kyte\Core\ModelObject(KyteAPIKey);
        if (!$apiKey->create([
            'identifier' => $identifier,
            'public_key' => $public_key,
            'secret_key' => $secret_key,
            'epoch' => $epoch,
            'kyte_account' => $account->id,
        ])) {
            echo "FAILED\n\n";
            exit(-1);
        }
        echo "OK\n\n";
        echo "Identifier $identifier\n";
        echo "Public Key $public_key\n";
        echo "Secret Key $secret_key\n\n";

        // populate with default Admin role for all models
        echo "Creating new admin role...";
        $role = new \Kyte\Core\ModelObject(Role);
        if (!$role->create([
            'name' => 'Administrator',
            'kyte_account' => $account->id
        ])) {
            echo "FAILED\n\n";
            exit(-1);
        }
        echo "OK\n\n";

        echo "Creating default admin permissions for role.\n";
        foreach (KYTE_MODELS as $model) {
            foreach (['new', 'update', 'get', 'delete'] as $actionType) {
                $permission = new \Kyte\Core\ModelObject(Permission);
                echo "Creating $actionType permission for ".$model['name']."...";
                if (!$permission->create([
                    'role'  => $role->id,
                    'model' => $model['name'],
                    'action' => $actionType,
                    'kyte_account' => $account->id,
                ])) {
                    echo "FAILED\n\n";
                    exit(-1);
                }
                echo "OK\n\n";
            }
        }

        // create user
        echo "Creating new admin user...";
        $user = new \Kyte\Core\ModelObject(KyteUser);
        if (!$user->create([
            'name' => $argv[4],
            'email' => $argv[5],
            'password' => password_hash($argv[6], PASSWORD_DEFAULT),
            'role'  => $role->id,
            'kyte_account' => $account->id,
        ])) {
            echo "FAILED\n\n";
            exit(-1);
        }
        echo "OK\n\n";

    }

    //
    if ($argv[1] == 'list' && $argv[2] == 'apps' && !$developer_mode) {
        $apps = new \Kyte\Core\Model(Application);
        $apps->retrieve();
        foreach ($apps->objects as $app) {
            echo "\033[1m{$app->name}\033[0m\tIdentifier: {$app->identifier}\n";
        }
    }

    if ($argv[1] == 'list' && $argv[2] == 'accounts' && !$developer_mode) {
        $accounts = new \Kyte\Core\Model(KyteAccount);
        $accounts->retrieve();
        foreach ($accounts->objects as $account) {
            echo "\033[1m{$account->name}\033[0m\tNumber: {$account->number}\n";
        }
    }

    if ($argv[1] == 'list' && $argv[2] == 'apikeys' && !$developer_mode) {
        $apikeys = new \Kyte\Core\Model(KyteAPIKey);
        $apikeys->retrieve();
        foreach ($apikeys->objects as $apikey) {
            $account = new \Kyte\Core\ModelObject(KyteAccount);
            if ($account->retrieve('id', $apikey->kyte_account)) {
                echo "\033[1m{$account->name}\033[0m\tNumber: {$account->number}\tPublic: {$apikey->public_key}\tSecret: {$apikey->secret_key}\tIdentifier: {$apikey->identifier}\n";
            } else {
                echo "\033[1mUnknown Account\033[0m\tNumber: ?\tPublic: {$apikey->public_key}\tSecret: {$apikey->secret_key}\tIdentifier: {$apikey->identifier}\n";
            }
        }
    }

    // generates a kyte-connect.js file that can be used for custom integrations with Kyte API using Kyte JS
    if ($argv[1] == 'create' && $argv[2] == 'kyte-connect' && isset($argv[3]) && !empty($argv[3]) && !$developer_mode) {

        $app = new \Kyte\Core\ModelObject(Application);
        if ($app->retrieve('identifier', $argv[3])) {
            $account = new \Kyte\Core\ModelObject(KyteAccount);
            if ($account->retrieve('id', $app->kyte_account)) {
                $apikey = new \Kyte\Core\ModelObject(KyteAPIKey);
                if ($apikey->retrieve('kyte_account', $app->kyte_account)) {
                    // Retrieve the necessary information (assuming it's available in your environment)
                    $endpoint = empty($_SERVER['SERVER_NAME']) ? 'https://place_your_endpoint_here.example.com' : $_SERVER['SERVER_NAME'];
                    $public_key = $apikey->public_key; // Replace with actual value or fetch from the environment
                    $identifier = $apikey->identifier; // Replace with actual value or fetch from the environment
                    $account = $account->number; // Replace with actual value or fetch from the environment
                    $app_id = $argv[3];

                    // JavaScript template for kyte-connect.js
                    $js_content = <<<EOT
let endpoint = '$endpoint';
let publickey = '$public_key';
let identifier = '$identifier';
let account = '$account';
let appId = '$app_id';

var k = new Kyte(endpoint, publickey, identifier, account, appId);

k.init();

// add global logout handler
$(document).ready(function() {
    k.addLogoutHandler("#logout");
});
EOT;

                    // Display the content in the terminal with formatting
                    echo "Generated kyte-connect.js:\n\n";
                    echo "-----------------------------------------\n";
                    echo $js_content . "\n";
                    echo "-----------------------------------------\n";
                    echo "\nYou can copy the above content or retrieve the file.\n";

                    // Save the content to kyte-connect.js in the user's home directory
                    $file_path = $_SERVER['HOME'] . '/kyte-connect.js';
                    file_put_contents($file_path, $js_content);
                    echo "The file has been saved as $file_path\n";
                } else {
                    echo "No API keys found for application.\n";
                }
            } else {
                echo "No active account found for application.\n";
            }
        } else {
            echo "No application found with specified identifier. Please try `list apps` to find the app you are trying to create a kyte-connect.js file for.\n";
        }
    }


    // create a new controller file
    if ($argv[1] == 'controller' && $argv[2] == 'create' && isset($argv[3])) {
        require_once __DIR__.'/lib/Controller.php';

        $model_name = $argv[3];

        // create new model file
        $model = \Gust\Controller::create($model_name);

        // check if dir exists
        if(!is_dir(($developer_mode || !isset($gust_env) ? '' : $gust_env['kyte_dir']).'app')) {
            // create dir
            shell_exec(sprintf("mkdir %s", ($developer_mode || !isset($gust_env) ? '' : $gust_env['kyte_dir']).'app'));
        }
        if(!is_dir(($developer_mode || !isset($gust_env) ? '' : $gust_env['kyte_dir']).'app/controllers')) {
            // create dir
            shell_exec(sprintf("mkdir %s", ($developer_mode || !isset($gust_env) ? '' : $gust_env['kyte_dir']).'app/controllers'));
        }
        
        // write to file
        file_put_contents(($developer_mode || !isset($gust_env) ? '' : $gust_env['kyte_dir']).'app/controllers/'.$model_name.'Controller.php', $model);
    }

    // create a new model file
    if ($argv[1] == 'model' && $argv[2] == 'create' && isset($argv[3])) {
        require_once __DIR__.'/lib/Model.php';

        $model_name = $argv[3];

        // create new model file
        $model = \Gust\Model::create($model_name);

        // check if dir exists
        if(!is_dir(($developer_mode || !isset($gust_env) ? '' : $gust_env['kyte_dir']).'app')) {
            // create dir
            shell_exec(sprintf("mkdir %s", ($developer_mode || !isset($gust_env)  ? '' : $gust_env['kyte_dir']).'app'));
        }
        if(!is_dir(($developer_mode || !isset($gust_env)  ? '' : $gust_env['kyte_dir']).'app/models')) {
            // create dir
            shell_exec(sprintf("mkdir %s", ($developer_mode && !isset($gust_env)  ? '' : $gust_env['kyte_dir']).'app/models'));
        }
        
        // write to file
        file_put_contents(($developer_mode || !isset($gust_env)  ? '' : $gust_env['kyte_dir']).'app/models/'.$model_name.'.php', $model);
    }

    // add new model to db
    if ($argv[1] == 'model' && $argv[2] == 'add' && isset($argv[3]) && !$developer_mode) {
        // load DB lib
        require_once __DIR__.'/lib/Database.php';

        $model_name = $argv[3];

        echo "Creating database table for new model...";
        $model_sql = \Gust\Database::create_table(constant($model_name), $gust_env['db_charset'], $gust_env['db_engine']);
        $sql_stmt = '';

        file_put_contents($_SERVER['HOME'].'/'.$model_name.'.sql', $model_sql);

        // create tables
        shell_exec(sprintf("mysql -u%s -p\"%s\" -h%s %s < ".$model_name.'.sql', KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE));
        // TODO: check return response

        //  set permissions for primary admin role
        $perm = new \Kyte\Core\ModelObject(Permission);
        foreach(['new','update','get','delete'] as $action) {
            $perm->create([
                'role' => 1,
                'model' => $model_name,
                'action' => $action,
                'kyte_account' => 1,
            ]);
        }

        echo "Model added!\n\n";
    }
}
