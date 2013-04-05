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
                         'feedurl'     => $feed['feed_url'],
                         'feedtitle'   => $feed['feed_url'],
                         'lastchecked' => 'Pending',
                         'lastviewed'  => 'Never',
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

        if (empty($action) === TRUE) {
            $action = 'list';
        }

        if ($action === 'new') {
            return self::newFeed();
        }

        if ($action === 'list') {
            return self::listFeeds();
        }

        if (strpos($action, 'edit') === 0) {
            list($action, $id) = explode('/', $action);
            return self::editFeed($id);
        }

        if (strpos($action, 'delete') === 0) {
            return self::deleteFeed();
        }

        throw new Exception("Unknown action $action");
    }
}

