<?php
/**
 * Template class file.
 *
 * @author Chris Smith <dmagick@gmail.com>
 * @version 1.0
 * @package aggregator
 */

/**
 * The template class.
 * Handles loading, processing keywords, display templates.
 *
 * @package aggregator
 */
class Template
{

    /**
     * A list of templates for this system to serve up
     * when the occasion arises.
     *
     * @static
     */
    private static $_templateStack = array();

    /**
     * An array of keywords per template to process.
     *
     * @static
     */
    private static $_keywords = array();

    /**
     * Where to get template files from.
     *
     * @var string
     * @see setTemplateDir
     *
     * @static
     */
    private static $_templateDir = NULL;

    /**
     * Set the directory where to get templates from.
     * This is generally done at the top of the main index script.
     * Does a basic check to make sure the dir exists, and if
     * it doesn't it will throw an exception.
     *
     * @param string $dir The template dir to use.
     *
     * @see _templateDir
     *
     * @return void
     * @throws exception Throws an exception if the template dir
     *                   doesn't exist.
     *
     * @static
     */
    public static function setDir($dir)
    {
        if (is_dir($dir) === FALSE) {
            throw new Exception("Template dir doesn't exist");
        }

        self::$_templateDir = $dir;
    }

    /**
     * Get the current template directory.
     *
     * @see _templateDir
     *
     * @return string    Returns the template dir
     * @throws exception Throws an exception if the template dir hasn't been
     *                   set before.
     *
     * @static
     */
    public static function getDir()
    {
        if (self::$_templateDir === NULL) {
            throw new Exception("The template dir has not been set.");
        }
        return self::$_templateDir;
    }

    /**
     * Gets a template to be processed. This will be ready made html
     * but with some basic placeholders.
     * All this function does is return the template to the caller.
     * If it doesn't exist, an exception is thrown.
     *
     * @param string $templateName The template name you're looking for.
     *
     * @return string    The template contents if it exists.
     * @throws exception Throws an exception if the template file doesn't
     *                   exist.
     *
     * @static
     */
    public static function getTemplate($templateName=NULL)
    {
        $file = self::$_templateDir.'/'.$templateName.'.tpl';
        if (is_file($file) === FALSE) {
            throw new Exception("Template ".$templateName." doesn't exist");
        }

        $contents = file_get_contents($file);
        return $contents;
    }

    /**
     * Put a template to display on the stack.
     * We don't actually serve it yet in case a page does a redirect
     * or something like that, we just store it.
     * display() goes through the stack and does all the work.
     *
     * @param string $templateName The name of the template to put on
     *                             the stack.
     *
     * @return void
     *
     * @static
     */
    public static function serveTemplate($templateName=NULL)
    {
        self::$_templateStack[] = $templateName;
    }

    /**
     * Process template actions. These are found in templates in the
     * form of a keyword.
     * ~template::action::item~
     * eg to include another template, you do
     * ~template::include::otherTemplateName~
     * and otherTemplateName is processed as a normal template including
     * keywords and possibly recursion.
     *
     * @param string $action Template action to perform.
     *
     * @return string    Returns a string with the action performed,
     *                   for example the template included and keywords
     *                   already processed.
     * @throws exception Throws an exception if you try to process an
     *                   action that isn't handled yet.
     *
     * @uses template::getTemplate
     * @uses template::processKeywords
     *
     * @static
     */
    private static function processTemplateAction($action)
    {
        list($action, $item) = explode('::', $action);
        switch ($action) {
            case 'include':
                $content = self::getTemplate($item);
                $content = self::processKeywords($content, $item);
            break;

            default:
                throw new Exception("Unknown template action ".$action);
        }

        return $content;
    }

    /**
     * Process keywords for a template if there are any to be processed.
     * Returns the content with keywords replaced.
     *
     * @param string $content      The content to put the keywords into.
     * @param string $templateName The name of the template so we know which
     *                             keywords to get.
     *
     * @return string Returns the content with the keywords processed.
     *
     * @uses template::processTemplateAction
     *
     * @static
     */
    private static function processKeywords($content, $templateName)
    {
        preg_match_all('/~template::(.*?)~/', $content, $matches);
        if (empty($matches[1]) === FALSE) {
            foreach ($matches[1] as $mpos => $match) {
                $result = self::processTemplateAction($match);
                $content = str_replace($matches[0][$mpos], $result, $content);
            }
        }

        if (isset(self::$_keywords[$templateName]) === FALSE) {
            return $content;
        }

        $keywords = array_keys(self::$_keywords[$templateName]);
        $values   = array_values(self::$_keywords[$templateName]);
        $content  = str_replace($keywords, $values, $content);
        unset(self::$_keywords[$templateName]);
        return $content;
    }

    /**
     * Replace built in keywords and return the new content.
     * For example, replace url keyword.
     *
     * @param string $content The content to process keywords for.
     *
     * @return string
     *
     * @uses session::getFlashMessages
     * @uses template::getTemplate
     *
     * @static
     */
    private static function processBuiltInKeywords($t, $content)
    {
        $source  = array(
                    '~url::baseurl~',
                   );
        $replace = array(
                    url::getUrl(),
                   );
        
        if (strpos($content, '~flashmessage~') !== FALSE) {
            $allMessages = '';
            $flashMessages = session::getFlashMessages();
            foreach ($flashMessages as $messageInfo) {
                $message     = $messageInfo[0];
                $messageType = $messageInfo[1];

                switch ($messageType) {
                    case 'error':
                        $templateName = 'flash.message.error';
                    break;
                    case 'success':
                        $templateName = 'flash.message.success';
                    break;
                }

                $template     = self::getTemplate($templateName);
                $template     = str_replace('~message~', $message, $template);
                $allMessages .= $template;
            }
            /**
             * Make sure we replace keywords in our messages as well,
             * before we add the flashmessage to the replacement list.
             */
            $allMessages = str_replace($source, $replace, $allMessages);

            $source[]  = '~flashmessage~';
            $replace[] = $allMessages;
        }
        $content = str_replace($source, $replace, $content);
        return $content;
    }

    /**
     * Go through the list of templates we've been told to process,
     * fix up keywords and print the template out.
     * This should be the last step of a page, so we go through the
     * list of templates previously set, process keywords
     * and print them out.
     *
     * @return void Prints out the content, doesn't return it.
     *
     * @uses template::getTemplate
     * @uses template::processKeywords
     * @uses template::_templateStack
     *
     * @static
     */
    public static function display()
    {
        if (headers_sent() === FALSE) {
            header('Content-type: text/html; charset=utf-8');
        }

        foreach (self::$_templateStack as $template) {
            $content = self::getTemplate($template);
            $content = self::processKeywords($content, $template);
            $content = self::processBuiltInKeywords($template, $content);
            echo $content;
        }
        self::$_templateStack = array();
    }

    /**
     * Set a keyword and value for a particular template.
     * This is used by processKeywords to go through and replace.
     *
     * @param string $template Template we are setting the keyword for.
     * @param string $keyword  Keyword name.
     * @param string $value    Keyword value.
     *
     * @static
     */
    public function setKeyword($template, $keyword, $value)
    {
        if (isset(self::$_keywords[$template]) === FALSE) {
            self::$_keywords[$template] = array();
        }
        self::$_keywords[$template]['~'.$keyword.'~'] = $value;
    }

    /**
     * Get a keyword for a particular template and return it.
     *
     * @param string $template The template to get the keyword for.
     * @param string $keyword  The keyword you want to return.
     *
     * @return Returns the keyword if it exists, otherwise returns NULL.
     *
     * @static
     */
    public static function getKeyword($template, $keyword)
    {
        if (isset(self::$_keywords[$template]) === FALSE) {
            return NULL;
        }
        if (isset(self::$_keywords[$template]['~'.$keyword.'~']) === FALSE) {
            return NULL;
        }

        return self::$_keywords[$template]['~'.$keyword.'~'];
    }

    /**
     * Unload a template from the template stack.
     *
     * This is useful if the header is loaded but then something happens
     * and the empty header needs to be displayed instead.
     * The header template can be unloaded from the stack so you only
     * see the header once instead of twice.
     * It removes it from the stack and also removes all keywords that may
     * have been set.
     *
     * @param string $template The template to unload from the stack.
     *
     * @return void
     */
    public static function unload($template='')
    {
        if (in_array($template, self::$_templateStack) === TRUE) {
            $key = array_search($template, self::$_templateStack);
            if ($key !== FALSE) {
                unset(self::$_templateStack[$key]);
            }
            if (isset(self::$_keywords[$template]) === TRUE) {
                unset(self::$_keywords[$template]);
            }
        }
    }

    /**
     * Clear out the entire template stack and all keywords.
     *
     * @return void
     */
    public static function clearStack()
    {
        self::$_keywords      = array();
        self::$_templateStack = array();
    }
}

/* vim: set expandtab ts=4 sw=4: */
