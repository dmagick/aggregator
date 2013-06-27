<?php
/**
 * Cron class file.
 *
 * @author Chris Smith <dmagick@gmail.com>
 * @version 1.0
 * @package aggregator
 */

/**
 * The cron class.
 *
 * @package aggregator
 */
class cron
{

    public static function run()
    {
        // Use NULLS FIRST so new feeds get done first.
        // Otherwise they'll never get done since we're only
        // processing 20 at a time.
        $feeds = feed::getAllFeeds("last_checked ASC NULLS FIRST", 20);

        foreach ($feeds as $feed) {
            list($rc, $data) = self::fetch($feed['feed_url']);

            $hash = sha1($data);

            if ($hash === $feed['feed_hash']) {
                feed::updateFeedStatus($feed['feed_url'], array('last_status' => $rc));
                continue;
            }

            $info = self::parseContents($feed['feed_url'], $data);

            if ($info === FALSE) {
                continue;
            }

            $feedInfo = array(
                'last_status' => $rc,
                'feed_hash'   => $hash,
                'feed_title'  => $info['title'],
            );

            db::beginTransaction();
                feed::updateFeedStatus($feed['feed_url'], $feedInfo);
                feed::saveFeedUrls($feed['feed_url'], $info);
            db::commitTransaction();
        }
    }

    private static function fetch($url, $headerOnly=FALSE)
    {
        $rc   = 0;
        $data = '';

        $handle = curl_init();
        curl_setopt($handle, CURLOPT_MAXREDIRS, 3);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($handle, CURLOPT_TIMEOUT, 3);
        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, TRUE);

        $rc   = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $data = curl_exec($handle);

        curl_close($handle);

        if (is_string($data) === TRUE) {
            $data = trim($data);
        }

        return array($rc, $data);
    }

    private static function parseContents($feed_url, $data)
    {

        $info = array(
            'title' => '',
            'urls'  => array(),
        );

        $ext = substr(strrchr($feed_url, '.'), 1);

        if (empty($data) === TRUE) {
            echo "Feed url ".$feed_url." is returning empty.\n";
            return $info;
        }

        switch ($ext)
        {
            case 'rss':
            case 'rdf':
            case 'xml':
                $info = self::_parseXml($data);
                break;

            default:
                $info = self::_parseHtml($data);
                if ($info === FALSE) {
                    echo "Trying to parse url (".$feed_url.") has failed\n";
                }
        }

        return $info;
    }

    private static function _parseHtml($data)
    {
        // If the first few chars say it's xml, parse it as xml.
        if (substr($data, 0, 5) === '<?xml') {
            return self::_parseXml($data);
        }

        return FALSE;
    }

    private static function _parseXml($data)
    {
        try {
            $xml = new SimpleXMLElement($data);
        } catch (Exception $e) {
            echo "Unable to parse data ".$data." as xml.\n";
            return FALSE;
        }

        $feedtitle = trim(reset($xml->channel->title));
        $urls      = array();
        foreach ($xml->channel->item as $subitem) {
            $url         = trim(reset($subitem->link));
            $description = trim(reset($subitem->description));
            $title       = trim(reset($subitem->title));
            $urls[$url]  = array(
                'title'       => $title,
                'description' => $description,
            );
        }

        return array(
            'title' => $feedtitle,
            'urls'  => $urls,
        );
    }
}

