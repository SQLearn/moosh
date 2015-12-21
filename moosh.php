#!/usr/bin/env php
<?php
/**
 * moosh - Moodle Shell
 *
 * @copyright  2012 onwards Tomasz Muras
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use \Moosh\MooshCommand;
use \Moosh\Performance;

$cwd = getcwd();

//try to detect if we are packaged moosh version - e.g. dude where are my libraries
if (file_exists(__DIR__ . '/Moosh')) {
    $moosh_dir = __DIR__;
} elseif (file_exists('/usr/share/moosh')) {
    $moosh_dir = '/usr/share/moosh';
} else {
    die("I can't find my own libraries\n");
}

$loader = require $moosh_dir . '/vendor/autoload.php';
$loader->add('Moosh\\', $moosh_dir);
$loader->add('DiffMatchPatch\\', $moosh_dir . '/vendor/yetanotherape/diff-match-patch/src');

$options = array('debug' => true, 'optimizations' => 0);

require_once $moosh_dir . '/includes/functions.php';
require_once $moosh_dir . '/includes/default_options.php';


use GetOptionKit\ContinuousOptionParser;
use GetOptionKit\OptionCollection;

@error_reporting(E_ALL | E_STRICT);
@ini_set('display_errors', '1');

define('MOOSH_VERSION', '0.20');
define('MOODLE_INTERNAL', true);

$appspecs = new OptionCollection;
$spec_verbose = $appspecs->add('v|verbose', "be verbose");
$spec_moodle_path = $appspecs->add('p|moodle-path:', "Moodle directory.");
$spec_moodle_user = $appspecs->add('u|user:', "Moodle user, by default ADMIN");
$spec_moodle_user = $appspecs->add('n|no-user-check', "Don't check if Moodle data is owned by the user running script");
$spec_performance = $appspecs->add('t|performance', "Show performance infomation including timings");

$parser = new ContinuousOptionParser($appspecs);
$app_options = $parser->parse($argv);

if ($app_options->has('moodle-path')) {
    $top_dir = $app_options['moodle-path']->value;
} else {
    $top_dir = find_top_moodle_dir($cwd);
}

$moodle_version = moosh_moodle_version($top_dir);
$local_dir = home_dir() . DIRECTORY_SEPARATOR . '.moosh';
$viable_versions = moosh_generate_version_list($moodle_version);
$viable_versions[] = 'Generic';
$namespaced_commands = moosh_load_all_commands($moosh_dir, $viable_versions);
$namespaced_commands_extra = moosh_load_all_commands($local_dir, $viable_versions);

if ($namespaced_commands_extra) {
    $namespaced_commands = array_merge($namespaced_commands, $namespaced_commands_extra);
    $loader->set(false, $local_dir);
}

$subcommands = array();
foreach ($namespaced_commands as $command) {
    $object = new $command();
    $subcommands[$object->getName()] = $object;
}

$subcommand_specs = array();
foreach ($subcommands as $k => $v) {
    $subcommand_specs[$k] = $v->spec;
}

// for saved options
$subcommand_options = array();

// command arguments
$arguments = array();

// The first argument must be a subcommand.
$subcommand = NULL;
$possible_matches = array();

if (!$parser->isEnd()) {
    $subcommand = $parser->advance();
}


if (!isset($subcommand_specs[$subcommand])) {
    $possible_matches = array();
    foreach ($subcommands as $k => $v) {
        if (strpos($k, $subcommand) !== false) {
            $possible_matches[] = $k;
        }
    }
    if (count($possible_matches) == 1) {
        $subcommand = $possible_matches[0];
    } else {
        $subcommand = NULL;
    }
}

if (!$subcommand && !$possible_matches) {
    echo "moosh version " . MOOSH_VERSION . "\n";
    echo "No command provided, possible commands:\n\t";
    echo implode("\n\t", array_keys($subcommands));
    echo "\n";
    echo "Global options:\n";
    //$appspecs->printOptions();
    $printer = new GetOptionKit\OptionPrinter\ConsoleOptionPrinter;
    echo $printer->render($appspecs);
    echo "\n";
    exit(1);
}

if (!$subcommand && $possible_matches) {
    foreach ($possible_matches as $match) {
        echo $match . "\n";
    }
    exit(1);
}

$parser->setSpecs($subcommand_specs[$subcommand]);
try {
    $subcommand_options[$subcommand] = $parser->continueParse();
} catch (Exception $e) {
    echo $e->getMessage() . "\n";
    die("Moosh global options should be passed before command not after it.");
}

while (!$parser->isEnd()) {
    $arguments[] = $parser->advance();
}

//read config file if available
$moodlerc = NULL;

if (file_exists(home_dir() . DIRECTORY_SEPARATOR . ".mooshrc.php")) {
    $moodlerc = home_dir() . DIRECTORY_SEPARATOR . ".mooshrc.php";
} elseif (file_exists("/etc/moosh/mooshrc.php")) {
    $moodlerc = "/etc/moosh/mooshrc.php";
} elseif (file_exists(home_dir() . DIRECTORY_SEPARATOR . "mooshrc.php")) {
    $moodlerc = home_dir() . DIRECTORY_SEPARATOR . "mooshrc.php";
}

// Create directory for configuration if one is not there already.
if(!file_exists(home_dir() . "/.moosh")) {
    @mkdir(home_dir() . "/.moosh");
}

$options = NULL;
if ($moodlerc) {
    if (isset($app_options['verbose'])) {
        echo "Using '$moodlerc' as moosh runtime configuration file\n";
    }
    $options = array();
    require($moodlerc);
    $options = array_merge_recursive_distinct($defaultOptions, $options);
} else {
    $options = $defaultOptions;
}

/**
 * @var Moosh\MooshCommand $subcommand
 *
 */
$subcommand = $subcommands[$subcommand];


if ($bootstrap_level = $subcommand->bootstrapLevel()) {
    if ($bootstrap_level == MooshCommand::$BOOTSTRAP_FULL_NOCLI) {
        $_SERVER['REMOTE_ADDR'] = 'localhost';
        $_SERVER['SERVER_PORT'] = 80;
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP 1.1';
        $_SERVER['SERVER_SOFTWARE'] = 'PHP/'.phpversion() ;
        $_SERVER['REQUEST_URI'] = '/';
    } else {
        define('CLI_SCRIPT', true);
    }
    if ($subcommand->bootstrapLevel() == MooshCommand::$BOOTSTRAP_CONFIG) {
        define('ABORT_AFTER_CONFIG', true);
    }
    if (!$top_dir) {
        echo "Could not find Moodle installation!\n";
        exit(1);
    }
    require_once($top_dir . '/config.php');

    $shell_user = false;
    if (!$app_options->has('no-user-check')) {
    	// make sure the PHP POSIX library is installed before using it
    	if(!(function_exists('posix_getpwuid') && function_exists('posix_geteuid'))){
    		cli_error("The PHP POSIX extension is not installed - see http://php.net/manual/en/book.posix.php (on CentOS/RHEL the package php-process provides this extension)");
    	}
        $shell_user = posix_getpwuid(posix_geteuid());
        $moodledata_owner = detect_moodledata_owner($CFG->dataroot);
        if($moodledata_owner && $shell_user['name'] != $moodledata_owner['user']['name']) {
            cli_error("One of your Moodle data directories ({$moodledata_owner['dir']}) is owned by
different user ({$moodledata_owner['user']['name']}) than the one that runs the script ({$shell_user['name']}).
If you're sure you know what you're doing, run moosh with -n flag to skip that test.");
        }
    }

    //gather more info based on the directory where moosh was run
    $relative_dir = substr($cwd, strlen($top_dir));
    $relative_dir = trim($relative_dir, '/');
    if ($app_options->has('verbose')) {
        echo "Top Moodle dir: $top_dir\n";
        echo "Current working dir: " . $cwd . "\n";
        echo "Relative Moodle dir: $relative_dir\n";
    }
    $plugin_info = detect_plugin($relative_dir);
    $subcommand->setPluginInfo($plugin_info);
    $subcommand->topDir = $top_dir;
    $subcommand->relativeDir = $relative_dir;

    //set up debugging
    $CFG->debug = (E_ALL);
    $CFG->debugdisplay = 1;
    @error_reporting(E_ALL);
    @ini_set('display_errors', '1');


    // By default set up $USER to admin user.
    if ($app_options->has('user')) {
        $user = get_user_by_name($app_options['user']->value);
        if (!$user) {
            echo "Error: No user account was found\n";
            exit(1);
        }
    } else {
        $user = get_admin();
        if (!$user) {
            echo "Error: No admin account was found\n";
            exit(1);
        }

        complete_user_login($user);
    }
}

if ($app_options->has('verbose')) {
    $subcommand->verbose = true;
}

$subcommand->cwd = $cwd;
$subcommand->mooshDir = $moosh_dir;
$subcommand->defaults = $options;

// Process the arguments.
$subcommand->setParsedOptions($subcommand_options[$subcommand->getName()]);
$subcommand->setArguments($arguments);
$subcommand->processOptions($options);
$subcommand->expandOptions();

// Some more debug if requested.
if ($app_options->has('verbose')) {
    $subcommand->status();
}

// Execute the actual logic.
if($app_options->has('performance')) {
    $perf = new Performance();
    $perf->start();
}
$subcommand->execute();
if($app_options->has('performance')) {
    $perf->stop();
    echo $perf->summary();
}
