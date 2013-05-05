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

        $groupname = NULL;
        if (empty($_POST['groupname']) === FALSE) {
            $groupname = $_POST['groupname'];
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
                     'groupname'  => $groupname,
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

            $sql  = "INSERT INTO ".db::getPrefix()."users_feeds (username, feed_url, groupname, user_checked)";
            $sql .= " SELECT ";
            $sql .= ":username, :feed_url, :groupname, :user_checked";
            $sql .= " WHERE NOT EXISTS (";
            $sql .= " SELECT feed_url FROM ".db::getPrefix()."users_feeds ";
            $sql .= " WHERE ";
            $sql .= " feed_url = :feed_url_exists";
            $sql .= " AND username = :username_exists";
            $sql .= ")";

            $values  = array(
                        ':feed_url'        => $feedInfo['feed_url'],
                        ':feed_url_exists' => $feedInfo['feed_url'],
                        ':groupname'       => $feedInfo['groupname'],
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

    public static function getFeedsForUser($userid=0, $limit=0)
    {
        $username = user::getUsernameById($userid);

        $sql  = "SELECT uf.feed_url, uf.user_checked, uf.groupname, f.feed_title, f.last_checked";
        $sql .= " FROM ".db::getPrefix()."users_feeds uf";
        $sql .= " INNER JOIN ".db::getPrefix()."feeds f ON (uf.feed_url=f.feed_url)";
        $sql .= " WHERE uf.username=:username";
        $sql .= " ORDER BY uf.user_checked ASC";
        if ($limit > 0) {
            $sql .= " LIMIT ".$limit;
        }

        $query = db::select($sql, array($username));
        $feeds = db::fetchAll($query);

        return $feeds;
    }

    public static function listFeeds()
    {
        $feeds = self::getFeedsForUser(session::get('user'), 20);

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
                         'groupname'      => $feed['groupname'],
                         'lastchecked'    => 'Pending',
                         'lastviewed'     => 'Never',
                         'feedurl.encode' => base64_encode($feed['feed_url']),
                        );

            if (empty($feed['feed_title']) === FALSE && $feed['feed_title'] !== NULL) {
                $keywords['feedtitle'] = $feed['feed_title'];
            }

            if (empty($feed['last_checked']) === FALSE && $feed['last_checked'] !== NULL) {
                $keywords['lastchecked'] = niceDateAndTime($feed['last_checked']);
            }

            if (empty($feed['user_checked']) === FALSE && $feed['user_checked'] !== NULL) {
                $keywords['lastviewed'] = niceDateAndTime($feed['user_checked']);
            }

            foreach ($keywords as $keyword => $value) {
                template::setKeyword('feed.list.detail', $keyword, $value);
            }
            template::serveTemplate('feed.list.detail');
            template::display();
        }

        template::serveTemplate('feed.list.footer');
    }

    public static function process($action='view')
    {

        $userGroups     = array();
        $userFeedGroups = self::getFeedGroups();
        foreach ($userFeedGroups as $k => $groupInfo) {
            $groupname    = $groupInfo['groupname'];
            if (empty($groupname) === TRUE) {
                $groupname = 'Uncategorised';
            }
            $userGroups[] = '<a href="~url::baseurl~/feed/view/'.$groupname.'">'.$groupname.' ('.$groupInfo['unread'].')</a>';
        }
        template::setKeyword('feed.header', 'feedcategories', implode('&nbsp;|&nbsp;', $userGroups));
        template::serveTemplate('feed.header');

        if (strpos($action, 'mark') === 0 || strpos($action, 'unmark') === 0) {
            list($action, $url) = explode('/', $action);
        }


        $group = NULL;
        if (strpos($action, 'view') === 0) {
            list($action, $group) = explode('/', $action);
        }

        switch ($action) {
            case 'new':
                return self::newFeed();
                break;

            case 'list':
                // Delete any feeds if required.
                self::updateFeeds();

                // Then list 'em.
                return self::listFeeds();
                break;

            case 'mark':
                template::clearStack();
                $result = self::updateUserUrlsStatus('read', $url);
                if ($result === FALSE) {
                    echo json_encode('There was a problem, try again');
                }
                exit;
                break;

            case 'unmark':
                template::clearStack();
                $result = self::updateUserUrlsStatus('unread', $url);
                if ($result === FALSE) {
                    echo json_encode('There was a problem, try again');
                }
                exit;
                break;

            case 'view':
            default:
                return self::viewUrls($group);
                break;
        }
    }

    private static function getFeedGroups()
    {
        $userid   = session::get('user');
        $username = user::getUsernameById($userid);

        $sql  = "SELECT groupname, SUM(unread) AS unread";
        $sql .= " FROM ".db::getPrefix()."users_feeds";
        $sql .= " WHERE username=:username";
        $sql .= " GROUP BY groupname";

        $query  = db::select($sql, array($username));
        $groups = db::fetchAll($query);

        return $groups;
    }

    private static function updateUserUrlsStatus($status='', $url=NULL)
    {
        $url = base64_decode($url);
        if ($url === FALSE) {
            return FALSE;
        }

        $newStatus = 'NOW()';
        if ($status === 'unread') {
            $newStatus = 'NULL';
        }

        db::beginTransaction();

        $userid   = session::get('user');
        $username = user::getUsernameById($userid);

        $sql  = "UPDATE ".db::getPrefix()."users_urls";
        $sql .= " SET user_checked = ".$newStatus;
        $sql .= " WHERE ";
        $sql .= " username=:username";
        $sql .= " AND url=:url";

        $bindVars = array(
            ':username' => $username,
            ':url'      => $url,
        );
        $markResult = db::execute($sql, $bindVars);

        $updateUnreadCountResult = self::updateUnreadCount($url, $userid, $status);

        $result = FALSE;
        if ($markResult === TRUE && $updateUnreadCountResult === TRUE) {
            $result = TRUE;
        }

        if ($result === TRUE) {
            db::commitTransaction();
        } else {
            db::rollbackTransaction();
        }

        return $result;
    }

    public static function getAllFeeds()
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

    public static function saveFeedUrls($feed_url, $data)
    {

        $urlsSql  = "INSERT INTO ".db::getPrefix()."urls (url, url_description, url_title, feed_url, last_checked, status)";
        $urlsSql .= " SELECT ";
        $urlsSql .= " :url, :url_description, :url_title, :feed_url, NOW(), :status";
        $urlsSql .= " WHERE NOT EXISTS";
        $urlsSql .= " (SELECT url FROM ".db::getPrefix()."urls WHERE url=:urlCheck)";

        $usersSql  = "INSERT INTO ".db::getPrefix()."users_urls(username, url, url_description, url_title, user_checked)";
        $usersSql .= " SELECT username, :url, :url_description, :url_title, NULL ";
        $usersSql .= " FROM ".db::getPrefix()."users_feeds WHERE ";
        $usersSql .= " feed_url=:feed_url";
        $usersSql .= " AND NOT EXISTS ";
        $usersSql .= " (SELECT url FROM ".db::getPrefix()."users_urls WHERE url=:urlCheck)";
        foreach ($data['urls'] as $url => $info) {

            $urlValues = array(
                ':feed_url'        => $feed_url,
                ':status'          => 0,
                ':url'             => $url,
                ':url_description' => $info['description'],
                ':url_title'       => $info['title'],
                ':urlCheck'        => $url,
            );
            db::execute($urlsSql, $urlValues);

            $usersValues = array(
                ':feed_url'        => $feed_url,
                ':url'             => $url,
                ':url_description' => $info['description'],
                ':url_title'       => $info['title'],
                ':urlCheck'        => $url,
            );
            db::execute($usersSql, $usersValues);
        }

        $unreadSql  = "SELECT u.feed_url, uu.username, count(*) AS unreadcount";
        $unreadSql .= " FROM ".db::getPrefix()."users_urls uu ";
        $unreadSql .= " INNER JOIN ".db::getPrefix()."urls u on (uu.url=u.url) ";
        $unreadSql .= " WHERE user_checked IS NULL ";
        $unreadSql .= " GROUP BY u.feed_url, uu.username";
        $query      = db::select($unreadSql);
        $urls       = db::fetchAll($query);
        foreach ($urls as $urlid => $urlinfo) {
            $updateUnreadSql  = "UPDATE ".db::getPrefix()."users_feeds ";
            $updateUnreadSql .= " SET unread=:unread";
            $updateUnreadSql .= " WHERE feed_url=:feed_url";
            $updateUnreadSql .= " AND username=:username";

            $userUnreadValues = array(
                ':feed_url' => $urlinfo['feed_url'],
                ':unread'   => $urlinfo['unreadcount'],
                ':username' => $urlinfo['username'],
            );

            db::execute($updateUnreadSql, $userUnreadValues);
        }
    }

    public static function updateUnreadCount($user_url, $userid=NULL, $status='unread')
    {
        $bindVars = array(
            ':url' => $user_url,
        );

        switch ($status) {
            case 'read':
                $direction = '-';
                break;

            default:
                $direction = '+';
        }

        $sql  = "UPDATE ".db::getPrefix()."users_feeds SET unread=unread ".$direction." 1";
        $sql .= " WHERE feed_url IN (";
        $sql .= "   SELECT feed_url FROM ".db::getPrefix()."urls WHERE ";
        $sql .= "   url=:url";
        $sql .= ")";

        if ($userid !== NULL) {
            $username  = user::getUsernameById($userid);
            $sql      .= " AND username=:username";
            $bindVars[':username'] = $username;
        }

        $result = db::execute($sql, $bindVars);
        return $result;
    }

    public static function getUrlsForUser($userid=0, $groupname=NULL)
    {
        $username = user::getUsernameById($userid);

        $bindVars = array(
            ':username' => $username,
        );

        messagelog::enable();

        $sql  = "SELECT uu.url, uu.url_description, f.feed_title";
        $sql .= " FROM ".db::getPrefix()."users_urls uu";
        $sql .= " INNER JOIN ".db::getPrefix()."urls u ON (uu.url=u.url)";
        $sql .= " INNER JOIN ".db::getPrefix()."feeds f ON (u.feed_url=f.feed_url)";
        $sql .= " INNER JOIN ".db::getPrefix()."users_feeds uf ON (uf.feed_url=f.feed_url)";
        $sql .= " WHERE uu.username=:username";

        if ($groupname !== NULL) {
            if ($groupname === 'Uncategorised') {
                $sql .= " AND uf.groupname IS NULL";
            } else {
                $sql .= " AND uf.groupname=:groupname";
                $bindVars[':groupname'] = $groupname;
            }
        }

        $sql .= " AND uu.user_checked IS NULL";
        $sql .= " ORDER BY u.last_checked ASC";

        $query = db::select($sql, $bindVars);
        $urls  = db::fetchAll($query);

        return $urls;
    }

    public static function viewUrls($groupname=NULL)
    {
        $urls = self::getUrlsForUser(session::get('user'), $groupname);

        if (empty($urls) === TRUE) {
            template::serveTemplate('feed.urls.empty');
            return;
        }

        template::serveTemplate('feed.urls.header');

        foreach ($urls as $urlid => $urlinfo) {
            $keywords = array(
                'feed.title'      => $urlinfo['feed_title'],
                'preview'         => $urlinfo['url_description'],
                'url'             => $urlinfo['url'],
                'url.hash'        => md5($urlinfo['url']),
                'url.hash.footer' => md5($urlinfo['url'].'footer'),
                'url.hash.more'   => md5($urlinfo['url'].'more'),
                'url.hash.title'  => md5($urlinfo['url'].'title'),
                'url.title'       => $urlinfo['url'],
                'url.encoded'     => base64_encode($urlinfo['url']),
            );
            foreach ($keywords as $keyword => $value) {
                template::setKeyword('feed.urls.view', $keyword, $value);
            }
            template::serveTemplate('feed.urls.view');
            template::display();
        }
        template::serveTemplate('feed.urls.footer');
    }

}

