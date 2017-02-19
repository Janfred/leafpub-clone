<?php
namespace Leafpub;
require_once dirname(dirname(__DIR__)) . '/source/runtime.php';
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Deny if already installed or the request is invalid
if(Leafpub::isInstalled() || $_REQUEST['cmd'] !== 'install') {
    header('HTTP/1.0 403 Forbidden');
    exit();
}

// Send a JSON response
header('Content-Type: application/json');

// Force slug syntax for username
$_REQUEST['username'] = Leafpub::slug($_REQUEST['username']);

// Force prefix to use valid chars
$_REQUEST['db-prefix'] = preg_replace('/[^A-Za-z_-]/', '_', $_REQUEST['db-prefix']);

// Set defaults for missing fields
if(empty($_REQUEST['db-host'])) $_REQUEST['db-host'] = 'localhost';
if(empty($_REQUEST['db-prefix'])) $_REQUEST['db-prefix'] = 'leafpub_';
if(empty($_REQUEST['db-port'])) $_REQUEST['db-port'] = '3306';

// Check for errors
$invalid = [];
// Note: we don't check for a database password since some dev environments leave it blank
foreach(['name', 'email', 'username', 'password', 'db-user', 'db-database'] as $field) {
    if(empty($_REQUEST[$field])) $invalid[] = $field;
}
if(count($invalid)) {
    exit(json_encode([
        'success' => false,
        'invalid' => $invalid,
        'message' => 'Please correct the highlighted errors.'
    ]));
}
if(mb_strlen($_REQUEST['password']) < 8) {
    exit(json_encode([
        'success' => false,
        'invalid' => ['password'],
        'message' => 'Passwords need to be at least eight characters.'
    ]));
}
if(!Leafpub::isValidEmail($_REQUEST['email'])) {
    exit(json_encode([
        'success' => false,
        'invalid' => ['email'],
        'message' => 'Please enter a valid email address.'
    ]));
}

// Test database connection
try {
    Database::connect([
        'driver' => $_REQUEST['driver'],
        'host' => $_REQUEST['db-host'],
        'port' => $_REQUEST['db-port'],
        'database' => $_REQUEST['db-database'],
        'user' => $_REQUEST['db-user'],
        'password' => $_REQUEST['db-password'],
        'prefix' => $_REQUEST['db-prefix']
    ], [
        // Set a shorter timeout in case the host is entered incorrectly
        \PDO::ATTR_TIMEOUT => 5
    ]);
} catch(\Exception $e) {
    switch($e->getCode()) {
        case Database::AUTH_ERROR:
            $message = 'The database rejected this user or password. Make sure the user exists and has access to the specified database.';
            $invalid = ['db-user', 'db-password'];
            break;
        case Database::DOES_NOT_EXIST:
            $message = 'The specified database does not exist.';
            $invalid = ['db-database'];
            break;
        case Database::TIMEOUT:
            $message = 'The database is not responding. Is the host correct?';
            $invalid = ['db-host'];
            break;
        default:
            $message = $e->getMessage();
            $invalid = ['db-host', 'db-user', 'db-password', 'db-database'];
    }

    exit(json_encode([
        'success' => false,
        'invalid' => $invalid,
        'message' => $message
    ]));
}

// Read/write test for special folders
foreach(['backups', 'content', 'content/cache', 'content/themes', 'content/uploads'] as $folder) {
    // Create the folder if it doesn't exist
    if(!Leafpub::makeDir(Leafpub::path($folder))) {
        exit(json_encode([
            'success' => false,
            'message' =>
                "Leafpub could not create the /$folder folder. Please make sure the parent " .
                "directory is writeable or create it manually and try again."
        ]));
    }

    // Create a test file
    $file = Leafpub::path($folder, 'leafpub-read-write-test-' . time() . '.txt');
    $test_string = 'This is a test file generated by Leafpub. You can safely delete it.';

    // Write
    $result = file_put_contents($file, $test_string);
    if(!$result) {
        exit(json_encode([
            'success' => false,
            'message' =>
                "Leafpub needs write access to /$folder. Please make sure this directory is " .
                "writeable and try again."
        ]));
    }

    // Read
    $result = file_get_contents($file);
    if($result !== $test_string) {
        exit(json_encode([
            'success' => false,
            'message' =>
                "Leafpub needs read access to /$folder. Please make sure this directory is " .
                "readable and try again."
        ]));
    }

    // Delete
    unlink($file);
}

// Create .htaccess if it doesn't already exist
if(!file_exists(Leafpub::path('.htaccess'))) {
    if(!file_put_contents(
        Leafpub::path('.htaccess'),
        file_get_contents(Leafpub::path('source/defaults/default.htaccess'))
    )) {
        exit(json_encode([
            'success' => false,
            'message' =>
                'Unable to create /.htaccess. Make sure the directory is writeable or create the ' .
                'file yourself by copying it from /source/defaults/default.htaccess and try again.'
        ]));
    }
}

// Create database.php from default.database.php
$db_pathname = Leafpub::path('database.php');
$db_config = file_get_contents(Leafpub::path('source/defaults/default.database.php'));
$db_config = str_replace('{{driver}}', $_REQUEST['driver'], $db_config);
$db_config = str_replace('{{host}}', $_REQUEST['db-host'], $db_config);
$db_config = str_replace('{{port}}', $_REQUEST['db-port'], $db_config);
$db_config = str_replace('{{database}}', $_REQUEST['db-database'], $db_config);
$db_config = str_replace('{{user}}', $_REQUEST['db-user'], $db_config);
$db_config = str_replace('{{password}}', $_REQUEST['db-password'], $db_config);
$db_config = str_replace('{{prefix}}', $_REQUEST['db-prefix'], $db_config);
if(!file_put_contents($db_pathname, $db_config)) {
    exit(json_encode([
        'success' => false,
        'message' =>
            'Unable to create /database.php. Make sure the directory is writeable or create the ' .
            'file yourself by copying it from /source/defaults/default.database.php and try again.'
    ]));
}

// Initialize database tables
try {
    Database::resetTables();
} catch(\Exception $e) {
    // Cleanup database.php so we can try again
    unlink($db_pathname);

    exit(json_encode([
        'success' => false,
        'message' => 'Unable to create the database schema: ' . $e->getMessage()
    ]));
}

// Insert default settings
Models\Setting::create(['name' => 'auth_key', 'value' => Leafpub::randomBytes(32)]); // create a unique and secure auth key
Models\Setting::create(['name' => 'allowed_upload_types', 'value' => 'pdf,doc,docx,ppt,pptx,pps,ppsx,odt,xls,xlsx,psd,txt,md,csv,jpg,jpeg,png,gif,ico,svg,mp3,m4a,ogg,wav,mp4,m4v,mov,wmv,avi,mpg,ogv,3gp,3g2']);
Models\Setting::create(['name' => 'cover', 'value' => 'source/assets/img/leaves.jpg']);
Models\Setting::create(['name' => 'default_content', 'value' => 'Start writing here...']);
Models\Setting::create(['name' => 'default_title', 'value' => 'Untitled Post']);
Models\Setting::create(['name' => 'favicon', 'value' => 'source/assets/img/logo-color.png']);
Models\Setting::create(['name' => 'foot_code', 'value' => '']);
Models\Setting::create(['name' => 'frag_admin', 'value' => 'admin']);
Models\Setting::create(['name' => 'frag_author', 'value' => 'author']);
Models\Setting::create(['name' => 'frag_blog', 'value' => 'blog']);
Models\Setting::create(['name' => 'frag_feed', 'value' => 'feed']);
Models\Setting::create(['name' => 'frag_page', 'value' =>  'page']);
Models\Setting::create(['name' => 'frag_search', 'value' => 'search']);
Models\Setting::create(['name' => 'frag_tag', 'value' => 'tag']);
Models\Setting::create(['name' => 'generator', 'value' => 'on']);
Models\Setting::create(['name' => 'hbs_cache', 'value' => 'on']);
Models\Setting::create(['name' => 'head_code', 'value' => '']);
Models\Setting::create(['name' => 'homepage', 'value' =>  '']);
Models\Setting::create(['name' => 'language', 'value' => 'en-us']);
Models\Setting::create(['name' => 'logo', 'value' => 'source/assets/img/logo-color.png']);
Models\Setting::create(['name' => 'maintenance', 'value' => 'off']);
Models\Setting::create(['name' => 'maintenance_message', 'value' => '<p>Sorry for the inconvenience but we&rsquo;re performing some maintenance at the moment. We&rsquo;ll be back online shortly!</p><p>&mdash; The Team</p>']);
Models\Setting::create(['name' => 'navigation', 'value' => '[{"label":"Home","link":"/"}]']);
Models\Setting::create(['name' => 'posts_per_page', 'value' => '10']);
Models\Setting::create(['name' => 'tagline', 'value' => 'Go forth and create!']);
Models\Setting::create(['name' => 'theme', 'value' => 'range']);
Models\Setting::create(['name' => 'timezone', 'value' => 'America/New_York']);
Models\Setting::create(['name' => 'title', 'value' => 'A Leafpub Blog']);
Models\Setting::create(['name' => 'twitter', 'value' => '']);
Models\Setting::create(['name' => 'password_min_length', 'value' => '8']);
Models\Setting::create(['name' => 'mailer', 'value' => 'default']);

// Insert owner
try {
    Models\User::create([
        'slug' => $_REQUEST['username'],
        'name' => $_REQUEST['name'],
        'email' => $_REQUEST['email'],
        'password' => $_REQUEST['password'],
        'role' => 'owner',
        'created' => new \Zend\Db\Sql\Expression('NOW()')
    ]);
} catch(\Exception $e) {
    // Cleanup database.php so we can try again
    unlink($db_pathname);

    switch($e->getCode()) {
        case Models\User::INVALID_SLUG:
            $invalid = ['username'];
            $message = 'This username is reserved and cannot be used.';
            break;
        default:
            $invalid = null;
            $message = 'Unable to create the owner user: ' . $e->getMessage();
    }

    exit(json_encode([
        'success' => false,
        'invalid' => $invalid,
        'message' => $message
    ]));
}

// Insert default tag
try {
    Models\Tag::create([
        'slug' => 'getting-started', 
        'name' => 'Getting Started',
        'description' => 'This is a sample tag. You can delete it, rename it, or do whatever you want with it!',
        'type' => 'post',
        'created' => new \Zend\Db\Sql\Expression('NOW()')
    ]);
} catch(\Exception $e) {
    // Cleanup database.php so we can try again
    unlink($db_pathname);

    exit(json_encode([
        'success' => false,
        'message' => 'Unable to insert default tags: ' . $e->getMessage()
    ]));
}

// Insert initial posts
try {
    Models\Post::create([
        'slug' => 'welcome-to-leafpub',
        'pub_date' => '2016-07-27 22:50:00',
        'author' => $_REQUEST['username'],
        'title' => 'Welcome to Leafpub',
        'content' => file_get_contents(Leafpub::path('source/defaults/post.welcome.html')),
        'image' => 'content/uploads/2016/10/leaves.jpg',
        'status' => 'published',
        'tags' => ['getting-started'],
        'sticky' => true,
        'created' => new \Zend\Db\Sql\Expression('NOW()')
    ]);
    Models\Post::create([
        'slug' => 'the-editor', 
        'pub_date' => '2016-07-27 22:50:00',
        'author' => $_REQUEST['username'],
        'title' => 'The Editor',
        'content' => file_get_contents(Leafpub::path('source/defaults/post.editor.html')),
        'image' => 'content/uploads/2016/10/sunflower.jpg',
        'status' => 'published',
        'tags' => ['getting-started'],
        'created' => new \Zend\Db\Sql\Expression('NOW()')
    ]);
    Models\Post::create([
        'slug' => 'themes-and-plugins', 
        'pub_date' => '2016-07-27 22:50:00',
        'author' => $_REQUEST['username'],
        'title' => 'Themes & Plugins',
        'content' => file_get_contents(Leafpub::path('source/defaults/post.themes.html')),
        'image' => 'content/uploads/2016/10/autumn.jpg',
        'status' => 'published',
        'tags' => ['getting-started'],
        'created' => new \Zend\Db\Sql\Expression('NOW()')
    ]);
    Models\Post::create([
        'slug' => 'help-and-support', 
        'pub_date' => '2016-07-27 22:50:00',
        'author' => $_REQUEST['username'],
        'title' => 'Help & Support',
        'content' => file_get_contents(Leafpub::path('source/defaults/post.support.html')),
        'image' => 'content/uploads/2016/10/ladybug.jpg',
        'status' => 'published',
        'tags' => ['getting-started'],
        'created' => new \Zend\Db\Sql\Expression('NOW()')
    ]);
} catch(\Exception $e) {
    // Cleanup database.php so we can try again
    unlink($db_pathname);

    exit(json_encode([
        'success' => false,
        'message' => 'Unable to insert default posts: ' . $e->getMessage()
    ]));
}

// Log the owner in
Session::login($_REQUEST['username'], $_REQUEST['password']);

// Send response and redirect to the editor
exit(json_encode([
    'success' => true,
    'redirect' => Admin::url()
]));
