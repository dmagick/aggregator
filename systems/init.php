<?php
/**
 * Init file handles the start up stuff.
 * Its all then handed off to the appropriate
 * system for it to deal with the rest.
 *
 * @author Chris Smith <dmagick@gmail.com>
 * @version 1.0
 * @package aggregator
 */

/**
 * Set up the base dir.
 */
$basedir = dirname(dirname(__FILE__));

/**
 * Of course we need our config.
 */
require $basedir.'/config/config.php';

if (function_exists('date_default_timezone_set') === TRUE) {
    date_default_timezone_set('Australia/NSW');
}

/**
 * A list of systems.
 * All of these are included at the start.
 * Everything is made available.
 * This list is also used to by isValidSystem
 * to make sure a user isn't trying to cause errors by
 * making up their own url.
 * 
 * @see isValidSystem
 */
$systems = array(
    'db',
    'feed',
    'frontend',
    'template',
    'url',
    'session',
    'messagelog',
    'user',
);

/**
 * Helper function to make sure the requested system is valid.
 * Just in case someone decides to change the url (hoping for
 * information disclosure etc).
 *
 * @param string $systemName The system being checked
 *
 * @uses systems
 *
 * @return boolean
 */
function isValidSystem($systemName=NULL)
{
    global $systems;
    if (in_array($systemName, $systems) === TRUE) {
        return TRUE;
    }
    return FALSE;
}

/**
 * Change a postgres timestamp into a nice date.
 *
 * @param string $datetime The timestamp to transform.
 */
function niceDate($datetime)
{
    $time = strtotime($datetime);
    $date = date('jS M, Y', $time);
    return $date;
}

/**
 * Change a postgres timestamp into a nice date and time.
 *
 * @param string $datetime The timestamp to transform.
 */
function niceDateAndTime($datetime)
{
    $time = strtotime($datetime);
    $date = date('g:i a, j/M/y', $time);
    return $date;
}

/**
 * Include all of our required systems.
 * Since we're using a consistent structure,
 * we can just loop over 'em to do it all in one go.
 */
foreach ($systems as $system) {
    require $basedir.'/systems/'.$system.'/'.$system.'.php';
}

session::setDir($config['cachedir']);
session::start();

messagelog::setLog($config['cachedir'].'/debug.log');

url::setUrl($config['url']);

template::setDir($basedir.'/templates');

if (isset($config['defaultpage']) === TRUE) {
    if (empty($config['defaultpage']) === FALSE) {
        if (in_array($config['defaultpage'], $systems) === FALSE) {
            $systems[] = $config['defaultpage'];
        }
    }
    frontend::setDefaultPage($config['defaultpage']);
}

try {
    db::connect($config['db']);
} catch (Exception $e) {
    messagelog::enable();
    messagelog::LogMessage($e->getMessage());
    template::serveTemplate('error.technical');
    template::display();
    exit;
}

/* vim: set expandtab ts=4 sw=4: */
