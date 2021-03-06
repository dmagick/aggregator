<?php
/**
 * User class file.
 *
 * @author Chris Smith <dmagick@gmail.com>
 * @version 1.0
 * @package aggregator
 */

/**
 * The user class.
 * Handles authentication, checking the user hasn't locked their
 * account, setting tokens.
 * Anything user related.
 *
 * @package aggregator
 */
class user
{

    /**
     * Number of minutes to lock someone out from too many
     * attempts to log in.
     *
     * @static
     */
    private static $_lockTimeLimit = 5;

    /**
     * Keeps a cache of userid => username.
     *
     * @see getUsernameById
     */
    private static $_usernames = array();

    /**
     * This does all the work when viewed in a browser.
     * It displays the appropriate template (with keyword replacements).
     *
     * @param string $action The action being performed. Defaults to login.
     *                       Only handles login and logout.
     *
     * @uses template::display
     * @uses template::serveTemplate
     * @uses template::setKeyword
     * @uses user::authCheck
     * @uses user::logout
     * @uses user::setToken
     *
     * @return void
     *
     * @static
     */
    public static function process($action='login')
    {
        if ($action === 'logout') {
            self::logout();
            return;
        }

        template::serveTemplate('header.empty');

        if (empty($_POST) === TRUE) {
            $token = self::setToken();
            template::setKeyword('user.login', 'token', $token);
            template::serveTemplate('user.login');
            template::display();
        } else {
            self::authCheck();
        }
        template::serveTemplate('footer');
        template::display();
    }

    /**
     * Check the user hasn't locked themselves out.
     * If they have, the process is stopped quickly.
     * After that, checks the appropriate options are filled in
     * (username/password and there is a session token set).
     * Then finally checks the values match the db.
     *
     * @uses session::get
     * @uses session::set
     * @uses session::setFlashMessage
     * @uses template::serveTemplate
     * @uses template::setKeyword
     * @uses url::redirect
     * @uses user::setToken
     * @uses user::_isLockedOut
     *
     * @return void
     *
     * @static
     */
    private static function authCheck()
    {
        if (self::_isLockedOut(FALSE) === TRUE) {
            $token = self::setToken();
            session::setFlashMessage('You have been locked out. Try again later.', 'error');
            template::setKeyword('user.login', 'token', $token);
            template::serveTemplate('user.login');
            return;
        }

        $options = array('username', 'userpassword', 'token');

        foreach ($options as $option) {
            $$option = '';
            if (isset($_POST[$option]) === FALSE) {
                continue;
            }

            if (empty($_POST[$option]) === FALSE) {
                $$option = $_POST[$option];
            }
        }

        try {
            $savedToken = session::get('login.token');
        } catch (Exception $e) {
            $token = self::setToken();
            session::setFlashMessage('Invalid login token. Try again.', 'error');
            template::setKeyword('user.login', 'token', $token);
            template::serveTemplate('user.login');
            self::_isLockedOut(TRUE);
            return;
        }

        if ($savedToken !== $token) {
            $token = self::setToken();
            session::setFlashMessage('Invalid login token. Try again.', 'error');
            template::setKeyword('user.login', 'token', $token);
            template::serveTemplate('user.login');
            self::_isLockedOut(TRUE);
            return;
        }

        try {
            $user = self::checkLoginDetails($username, $userpassword);
        } catch (Exception $e) {
            $token = self::setToken();
            session::setFlashMessage('Check your username and password and try again.', 'error');
            template::setKeyword('user.login', 'token', $token);
            template::serveTemplate('user.login');
            self::_isLockedOut(TRUE);
            return;
        }

        session::set('user', $user);

        $originalpage = session::get('viewPage');
        url::redirect($originalpage);
        return;
    }

    /**
     * Checks whether the user has tried to log in too many times
     * in the last time period.
     * If $update is FALSE, returns a boolean based on the number of attempts.
     * If $update is TRUE, it either starts a counter for the ip
     * based on the current time, or updates the counter it already has,
     * and then returns nothing.
     *
     * @param boolean $update Whether to try updating the db (TRUE) -
     *                        this is also the default,
     *                        or just see if they have locked themselves out.
     *
     * @return mixed Returns a boolean if update is FALSE, returns void if
     *               update is TRUE.
     *
     * @static
     */
    private static function _isLockedOut($update=TRUE)
    {
        $ip     = self::_getIp();
        $sql    = "select attempts from ".db::getPrefix()."user_login_locks where ip=:ip and NOW() between start_time AND end_time";
        $query  = db::select($sql, array($ip));
        $result = db::fetch($query);

        if ($update === FALSE) {
            if (empty($result) === TRUE) {
                return FALSE;
            }
            if ($result['attempts'] <= 2) {
                return FALSE;
            }
            return TRUE;
        }

        if (empty($result) === TRUE) {
            $sql    = "insert into ".db::getPrefix()."user_login_locks(ip, start_time, end_time, attempts) values (:ip, :start_time, :end_time, :attempts)";
            $now    = date('r');
            $values = array(
                    ':ip'         => $ip,
                    ':start_time' => $now,
                    ':end_time'   => date('r', strtotime($now.' + '.self::$_lockTimeLimit.' minutes')),
                    ':attempts'   => 1,
                    );
            $result = db::execute($sql, $values);
            return;
        }

        $sql    = "update ".db::getPrefix()."user_login_locks set attempts = attempts + 1 where ip=:ip and now() between start_time and end_time";
        $values = array(
                ':ip' => $ip,
                );
        $result = db::execute($sql, $values);
    }

    /**
     * Gets the ip from the users browser.
     * Checks for X_FORWARDED_FOR in case they are behind a proxy.
     * If that's not available, uses REMOTE_ADDR
     *
     * @return string The users ip.
     *
     * @static
     */
    private static function _getIp()
    {
        $ip = '';
        if (isset($_SERVER['X_FORWARDED_FOR']) === TRUE) {
            $addrs = explode(',',$_SERVER['X_FORWARDED_FOR']);
            $ip    = array_pop($addrs);
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return trim($ip);
    }

    /**
     * Set a unique token in the session.
     * Also returns it for the login page to use.
     *
     * @return string The random token for the login page to use.
     *
     * @static
     */
    private static function setToken()
    {
        $token = sha1(uniqid(rand(), TRUE));
        session::set('login.token', $token);
        return $token;
    }

    /**
     * Check whether a username/password combination match the database.
     * If they do, it will also delete any user_login_locks the user has.
     * If they don't, throws an exception.
     *
     * @param string $username The username to try.
     * @param string $password The password to try.
     *
     * @return integer The user_id of the user if the username/password match.
     * @throws exception Throws an exception if the details don't match.
     *
     * @static
     */
    private static function checkLoginDetails($username=NULL, $password=NULL)
    {
        if ($username === NULL || $password === NULL) {
            throw new Exception("Unable to authenticate user");
        }

        $sql   = "select user_id from ".db::getPrefix()."users where username=:username and passwd=:password and useractive='y'";
        $query = db::select($sql, array($username, sha1($password)));
        $user  = db::fetch($query);

        if (empty($user) === TRUE) {
            throw new Exception("Unable to authenticate user");
        }

        $sql    = "delete from ".db::getPrefix()."user_login_locks WHERE ip=:ip";
        $values = array(
                ':ip' => self::_getIp(),
                );
        $result = db::execute($sql, $values);
        return $user['user_id'];
    }

    /**
     * Log the user out of the system.
     *
     * @uses session::has
     * @uses session::remove
     * @uses session::setFlashMessage
     * @uses url::redirect
     * @uses user::setToken
     */
    private static function logout()
    {
        $token = self::setToken();
        if (session::has('user') === TRUE) {
            session::setFlashMessage('You have been logged out.', 'success');
            session::remove('user');
        }
        url::redirect('/');
    }

    /**
     * Get the username from a userid.
     * The userid is stored in the session (so we don't store the username there),
     * but then we need a way to get it back to the username which is what the db
     * wants.
     */
    public static function getUsernameById($userid=NULL)
    {
        if (isset(self::$_usernames[$userid]) === TRUE) {
            return self::$_usernames[$userid];
        }

        $sql   = "select username from ".db::getPrefix()."users where user_id=:userid";
        $query = db::select($sql, array($userid));
        $user  = db::fetch($query);

        if (empty($user) === TRUE) {
            throw new Exception('Unable to find username for userid '.$userid);
        }

        self::$_usernames[$userid] = $user['username'];

        return self::$_usernames[$userid];
    }
}

/* vim: set expandtab ts=4 sw=4: */
