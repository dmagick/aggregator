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
        $feeds = feed::getFeeds();

        foreach ($feeds as $feed) {
            list($rc, $data) = self::fetch($feed['feed_url']);

            $hash = sha1($data);

            if ($hash === $feed['feed_hash']) {
                feed::updateFeedStatus($feed['feed_url'], array('last_status' => $rc));
                continue;
            }

            $info = self::parseContents($feed['feed_url'], $data);
            $feedInfo = array(
                'last_status' => $rc,
                'feed_hash'   => $hash,
                'feed_title'  => $info['title'],
            );

            db::beginTransaction();
                feed::updateFeedStatus($feed['feed_url'], $feedInfo);
                feed::saveFeedUrls($feed['feed_url'], $info['urls']);
            db::commitTransaction();
        }
    }

    private static function fetch($url, $headerOnly=FALSE)
    {
        $rc   = 0;
        $data = '';

        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);

        $rc   = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $data = curl_exec($handle);

        curl_close($handle);

        return array($rc, $data);
    }

    private static function parseContents($feed_url, $data)
    {

        $info = array(
            'title' => '',
            'urls'  => array(),
        );

        $ext = substr(strrchr($feed_url, '.'), 1);

        switch ($ext)
        {
            case 'rss':
            case 'rdf':
            case 'xml':
                $info = self::_parseXml($data);
                break;

            default:
                $info = self::_parseHtml($data);
        }

        return $info;
    }

    private static function _parseXml($data)
    {
        $xml = new SimpleXMLElement($data);

        $title = trim(reset($xml->channel->title));
        $urls  = array();
        foreach ($xml->channel->item as $subitem) {
            $url         = trim(reset($subitem->link));
            $description = trim(reset($subitem->description));
            $urls[$url]  = $description;
        }
        return array(
            'title' => $title,
            'urls'  => $urls,
        );
    }
}

