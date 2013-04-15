<?php
/**
 * Feed class file.
 *
 * @author Chris Smith <dmagick@gmail.com>
 * @version 1.0
 * @package aggregator
 */

/**
 * The feed class.
 *
 * @package aggregator
 */
class feed
{

    private static function newFeed()
    {
        if (empty($_POST) === TRUE) {
            template::serveTemplate('feed.new');
            template::display();
            return;
        }

        $title = NULL;
        if (empty($_POST['feed_title']) === FALSE) {
            $title = $_POST['feed_title'];
        }

        if (
            empty($_POST['feed_url']) === TRUE || 
            $_POST['feed_url'] === 'http://' ||
            $_POST['feed_url'] === 'https://'
        ) {
            session::setFlashMessage('New feed not added, enter a proper url.', 'error');
            session::save();
            url::redirect('feed/new');
            return;
        }

        $feedInfo = array(
                     'feed_url'   => $_POST['feed_url'],
                     'feed_title' => $title,
                     'user_id'    => session::get('user'),
                    );
        $result   = self::saveFeed($feedInfo);

        if ($result === TRUE) {
            session::setFlashMessage('New feed added.', 'success');
            session::save();
            url::redirect('feed/list');
            return;
        }
        session::setFlashMessage('New feed not added.', 'error');
        session::save();
        url::redirect('feed/new');
    }

    private static function updateFeeds()
    {
        if (isset($_POST) === FALSE || empty($_POST) === TRUE) {
            return;
        }

        messagelog::enable();

        $cleanValues = array();
        $values      = array();
        foreach (array_keys($_POST['delete']) as $id => $encodedUrl) {
            $url                                = base64_decode($encodedUrl);
            $values[':feed_url'.$id]            = $url;
            $cleanValues[':clean_feed_url'.$id] = $url;
        }
        db::beginTransaction();

        $result1 = FALSE;
        $result2 = FALSE;
        $result3 = FALSE;

        /**
         * Delete the link from users_feeds to the feed_url.
         */
        $sql  = "DELETE FROM ".db::getPrefix()."users_feeds WHERE username=:username AND feed_url IN (";
        $sql .= implode(',', array_keys($values));
        $sql .= ")";

        $deleteValues              = $values;
        $deleteValues[':username'] = user::getUsernameById(session::get('user'));

        $result1 = db::execute($sql, $deleteValues);

        if ($result1 === TRUE) {
            /**
             * Delete records about which urls have been viewed/not viewed in the users_urls list.
             */
            $sql  = "DELETE FROM ".db::getPrefix()."users_urls WHERE username=:username AND url IN (";
            $sql .= " SELECT url FROM ".db::getPrefix()."urls WHERE feed_url IN (";
            $sql .= implode(',', array_keys($values));
            $sql .= ")";
            $sql .= ")";
            $deleteValues              = $values;
            $deleteValues[':username'] = user::getUsernameById(session::get('user'));

            $result2 = db::execute($sql, $deleteValues);

            if ($result2 === TRUE) {
                /**
                 * Delete the feed completely, but only if no other users are using it.
                 * If this deletes anything for a feed_url, it will clean up the urls table
                 * for us (via foreign key).
                 */
                $sql  = "DELETE FROM ".db::getPrefix()."feeds f WHERE feed_url IN (";
                $sql .= implode(',', array_keys($values));
                $sql .= ")";
                $sql .= " AND NOT EXISTS (";
                $sql .= " SELECT feed_url FROM ".db::getPrefix()."users_feeds uf";
                $sql .= " WHERE feed_url IN (";
                $sql .= implode(',', array_keys($cleanValues));
                $sql .= ") AND f.feed_url=uf.feed_url";
                $sql .= ")";

                $allValues = $values + $cleanValues;
                $result3   = db::execute($sql, $allValues);
            }
        }

        if ($result1 && $result2 && $result3) {
            db::commitTransaction();
            return TRUE;
        }

        db::rollbackTransaction();
        return FALSE;
    }

    /**
     * Saves a feed (for global fetching) and links the user to this feed as well.
     */
    private static function saveFeed($feedInfo)
    {
        messagelog::enable();
        db::beginTransaction();

        $sql  = "INSERT INTO ".db::getPrefix()."feeds (feed_url, feed_title, last_checked, last_status)";
        $sql .= " SELECT ";
        $sql .= ":feed_url, :feed_title, :last_checked, :last_status";
        $sql .= " WHERE NOT EXISTS (";
        $sql .= " SELECT feed_url FROM ".db::getPrefix()."feeds WHERE feed_url = :feed_url_exists";
        $sql .= ")";

        $values  = array(
                    ':feed_url'        => $feedInfo['feed_url'],
                    ':feed_title'      => $feedInfo['feed_title'],
                    ':last_checked'    => NULL,
                    ':last_status'     => 0,
                    ':feed_url_exists' => $feedInfo['feed_url'],
                   );
        $result1 = db::execute($sql, $values);

        $result2 = FALSE;
        if ($result1 === TRUE) {
            $username = user::getUsernameById($feedInfo['user_id']);

            $sql  = "INSERT INTO ".db::getPrefix()."users_feeds (username, feed_url, user_checked)";
            $sql .= " SELECT ";
            $sql .= ":username, :feed_url, :user_checked";
            $sql .= " WHERE NOT EXISTS (";
            $sql .= " SELECT feed_url FROM ".db::getPrefix()."users_feeds ";
            $sql .= " WHERE ";
            $sql .= " feed_url = :feed_url_exists";
            $sql .= " AND username = :username_exists";
            $sql .= ")";

            $values  = array(
                        ':feed_url'        => $feedInfo['feed_url'],
                        ':feed_url_exists' => $feedInfo['feed_url'],
                        ':username'        => $username,
                        ':username_exists' => $username,
                        ':user_checked'    => NULL,
                       );
            $result2 = db::execute($sql, $values);
        }

        if ($result1 && $result2) {
            db::commitTransaction();
            return TRUE;
        }
        db::rollbackTransaction();
        return FALSE;
    }

    public static function listFeeds()
    {
        $username = user::getUsernameById(session::get('user'));

        $sql  = "SELECT uf.feed_url, uf.user_checked, f.feed_title, f.last_checked";
        $sql .= " FROM ".db::getPrefix()."users_feeds uf";
        $sql .= " INNER JOIN ".db::getPrefix()."feeds f ON (uf.feed_url=f.feed_url)";
        $sql .= " WHERE uf.username=:username";
        $sql .= " ORDER BY f.feed_title ASC";

        $query = db::select($sql, array($username));
        $feeds = db::fetchAll($query);

        if (empty($feeds) === TRUE) {
            template::serveTemplate('feed.list.empty');
            template::display();
            return;
        }
        template::serveTemplate('feed.list.header');

        foreach ($feeds as $feed) {
            $keywords = array(
                         'feedurl'        => $feed['feed_url'],
                         'feedtitle'      => $feed['feed_url'],
                         'lastchecked'    => 'Pending',
                         'lastviewed'     => 'Never',
                         'feedurl.encode' => base64_encode($feed['feed_url']),
                        );

            if (empty($feed['feed_title']) === FALSE && $feed['feed_title'] !== NULL) {
                $keywords['feedtitle'] = $feed['feed_title'];
            }

            if (empty($feed['last_checked']) === FALSE && $feed['last_checked'] !== NULL) {
                $keywords['lastchecked'] = niceDate($feed['last_checked']);
            }

            if (empty($feed['user_checked']) === FALSE && $feed['user_checked'] !== NULL) {
                $keywords['lastviewed'] = niceDate($feed['user_checked']);
            }

            foreach ($keywords as $keyword => $value) {
                template::setKeyword('feed.list.detail', $keyword, $value);
            }
            template::serveTemplate('feed.list.detail');
            template::display();
        }

        template::serveTemplate('feed.list.footer');
    }

    public static function process($action='list')
    {

        template::serveTemplate('feed.header');

        if (empty($action) === TRUE) {
            $action = 'list';
        }

        if ($action === 'new') {
            return self::newFeed();
        }

        if ($action === 'list') {
            self::updateFeeds();
            return self::listFeeds();
        }

        if (strpos($action, 'delete') === 0) {
            return self::deleteFeed();
        }

        throw new Exception("Unknown action $action");
    }

    public static function getFeeds()
    {
        $sql   = "SELECT feed_url, feed_hash, last_checked FROM ".db::getPrefix()."feeds";
        $query = db::select($sql);
        $feeds = db::fetchAll($query);
        return $feeds;
    }

    public static function updateFeedStatus($feed_url, $info)
    {
        $bindVars = array(
            ':feed_url' => $feed_url,
        );

        $sql  = "UPDATE ".db::getPrefix()."feeds ";
        $sql .= " SET last_checked = NOW()";
        foreach ($info as $key => $value) {
            $sql .= ", ".$key." = :".$key;
            $bindVars[":".$key] = $value;
        }
        $sql .= " WHERE feed_url=:feed_url";

        $result = db::execute($sql, $bindVars);
        return $result;
    }

    public static function saveFeedUrls($feed_url, $urls)
    {

        $urlsSql  = "INSERT INTO ".db::getPrefix()."urls (url, url_description, feed_url, last_checked, status)";
        $urlsSql .= " SELECT ";
        $urlsSql .= " :url, :url_description, :feed_url, NOW(), :status";
        $urlsSql .= " WHERE NOT EXISTS";
        $urlsSql .= " (SELECT url FROM ".db::getPrefix()."urls WHERE url=:urlCheck)";

        $usersSql  = "INSERT INTO ".db::getPrefix()."users_urls(username, url, url_description, user_checked)";
        $usersSql .= " SELECT username, :url, :url_description, NULL ";
        $usersSql .= " FROM ".db::getPrefix()."users_feeds WHERE ";
        $usersSql .= " feed_url=:feed_url";
        $usersSql .= " AND NOT EXISTS ";
        $usersSql .= " (SELECT url FROM ".db::getPrefix()."users_urls WHERE url=:urlCheck)";
        foreach ($urls as $url => $description) {
            $urlValues = array(
                ':feed_url'        => $feed_url,
                ':status'          => 0,
                ':url'             => $url,
                ':url_description' => $description,
                ':urlCheck'        => $url,
            );
            db::execute($urlsSql, $urlValues);

            $usersValues = array(
                ':feed_url'        => $feed_url,
                ':url'             => $url,
                ':url_description' => $description,
                ':urlCheck'        => $url,
            );
            db::execute($usersSql, $usersValues);
        }
    }

}

