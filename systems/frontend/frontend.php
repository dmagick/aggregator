<?php
/**
 * Frontend class file.
 *
 * @author Chris Smith <dmagick@gmail.com>
 * @version 1.0
 * @package aggregator
 */

/**
 * The frontend class.
 * Works out which page you are trying to view and processes it.
 * Could hand off requests to other systems if it needs to.
 *
 * @package aggregator
 */
class frontend
{

    /**
     * The default page to display.
     *
     * Set by setDefaultPage() and returned by getDefaultPage().
     */
    private static $_defaultPage = '';


    /**
     * Display a page.
     *
     * If the user hasn't logged in, it remembers the page you are trying
     * to view, takes you to the login page, then if that works, redirects
     * the user back to the original page.
     *
     * @return void
     *
     * @uses isValidSystem
     * @uses session::get
     * @uses session::has
     * @uses session::remove
     * @uses session::set
     * @uses template::display
     * @uses template::serveTemplate
     * @uses user::process
     */
    public static function display()
    {
        $page = self::getCurrentPage();

        if (empty($page) === FALSE) {
            $info = trim($page, '/');
            $bits = explode('/', $info);
            if (empty($bits[0]) === FALSE) {
                $system = array_shift($bits);

                if ($system !== 'frontend') {
                    template::serveTemplate('header');
                    template::display();
                }

                $bits   = implode('/', $bits);
                if (isValidSystem($system) === TRUE) {
                    call_user_func_array(array($system, 'process'), array($bits));
                }
            }
        } else {
            template::serveTemplate('header');
            template::serveTemplate('home');
        }

        template::serveTemplate('footer');
        template::display();
    }

    /**
     * Get the current page trying to be viewed.
     *
     * @return string Returns the current page, or default page.
     */
    public static function getCurrentPage()
    {
        $page = '';

        if (isset($_SERVER['REQUEST_URI']) === TRUE && isset($_SERVER['HTTP_HOST']) === TRUE) {
            $protocol = 'http';
            $page     = $protocol.'//'.$_SERVER['HTTP_HOST'].'/'.$_SERVER['REQUEST_URI'];
            $page     = substr($page, strlen(url::getUrl()));
            $page     = trim($page, '/');
        }

        if (empty($page) === TRUE) {
            $page = self::getDefaultPage();
        }

        return $page;
    }



    /**
     * Get the default page, previously set by setDefaultPage
     *
     * @return string
     */
    static public function getDefaultPage()
    {
        return self::$_defaultPage;
    }


    /**
     * Set the default page for the frontend to show.
     *
     * It should come from the config file.
     *
     * @param string $page The new default page.
     */
    static public function setDefaultPage($page='')
    {
        self::$_defaultPage = $page;
    }

}

/* vim: set expandtab ts=4 sw=4: */
