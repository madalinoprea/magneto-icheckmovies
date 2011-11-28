<?php
/*
Plugin Name: iCheckMovies Widget
Plugin URI: http://moprea.ro/2011/11/28/icheckmovies-widget-for-wordpress/
Description: Lists movies checked by you on iCheckMovies website.
Version: 1.0
Author: Madalin Oprea aka Mario
Author URI: http://moprea.ro/
License: GPLv2
*/

require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-includes/cache.php');


class Magneto_Utils
{

    /**
     * Finds an attachment by name and returns its id.
     *
     * @static
     * @param $attachmentName
     * @return mixed
     */
    public static function getAttachmentId($attachmentName)
    {
        global $wpdb;
        $query = "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_title='{$attachmentName}'";
        $id = $wpdb->get_var($query);
        return $id;
    }

    /**
     * Helper method to search in DOMElement's children
     *
     * @static
     * @param DOMElement $element
     * @param $tagName
     * @param $class
     * @return null
     */
    public static function findDOMElement(DOMElement $element, $tagName, $class)
    {
        $subElements = $element->getElementsByTagName($tagName);
        foreach ($subElements as $subElement) {
            if (strpos($subElement->getAttribute('class'), $class)!==FALSE) {
                return $subElement;
            }
        }

        return null;
    }

    /**
     * @static
     * @param $url
     * @return string
     */
    public static function getFileNameFromUrl($url)
    {
        preg_match('/[^\?]+\.(jpg|JPG|jpe|JPE|jpeg|JPEG|gif|GIF|png|PNG)/', $url, $matches);
        return basename($matches[0]);
    }

}

class ICheckMovies_Widget extends WP_Widget {
    const BASE_URL = 'http://www.icheckmovies.com';
    const DEFAULT_NUMBER_OF_SHOWN_MOVIES = 9;
    private $_moviesProfile;
    private $_numberOfMovies;
    private $_widgetTitle;

    private $_imageWidth = 107;

    function __construct() {
        $widgetOptions = array(
            'description' => 'Display latest movies checked on iCheckMovies website.'
        );

        parent::__construct(false, 'iCheckMovies', $widgetOptions);
    }

    protected function getProfileUrl()
    {
        return self::BASE_URL . '/profile/checked/' . $this->_moviesProfile;
    }

    /**
     * Returns image url for the cover.
     *
     * @param $imdbUrl
     * @return string   Cover image's url.
     */
    protected function _retrieveImdbCover($imdbUrl)
    {
        $domDocument = new DOMDocument();
        $domDocument->loadHTMLFile($imdbUrl);
        $imgPrimary = $domDocument->getElementById('img_primary');
        $coverUrl = $imgPrimary->getElementsByTagName('img')->item(0)->getAttribute('src');

        return $coverUrl;
    }

    /**
     * Makes sure cover image is registered as a Wordpress attachment.
     *
     * @param $url
     * @param $desc
     * @return int|mixed|object
     */
    protected function _downloadAttachment($url, $desc)
    {
        $id = Magneto_Utils::getAttachmentId($desc);

        // Cover already downloaded
        if ($id) {
            return $id;
        }

        if (!empty($url)) {
            $tmp  = download_url($url);

            $file_array['name'] = Magneto_Utils::getFileNameFromUrl($url);
            $file_array['tmp_name'] = $tmp;

            // If error storing temporarily, unlink
            if ( is_wp_error( $tmp ) ) {
                @unlink($file_array['tmp_name']);
                $file_array['tmp_name'] = '';
            }


            // do the validation and storage stuff
            $id = media_handle_sideload( $file_array, 0, $desc );
            // If error storing permanently, unlink
            if ( is_wp_error($id) ) {
                @unlink($file_array['tmp_name']);
                return $id;
            }

            return $id;
        }
    }

    /**
     * Parse iCheckMovies profile page and finds latest checked movies.
     *
     * @return array    Populated with title, imdb_url, img_id
     */
    protected function _parseProfile()
    {
        $profileUrl = self::BASE_URL . '/profile/checked/' . $this->_moviesProfile;
        $d = new DOMDocument();
        $movies = array();

        if ($d->loadHTMLFile($profileUrl)) {
            $aa = $d->getElementsByTagName('ol');

            /* @var $a DOMElement */
            foreach ($aa as $a){
                if ($a->getAttribute('class')=='topListMovies') {
                    $checkedMovies = $a->getElementsByTagName('li');
                    foreach ($checkedMovies as $checkedMovie) {
                        /* @var $checkedMovie DOMElement */
                        if ( strpos($checkedMovie->getAttribute('class'), 'movie')!==FALSE) {
                            $movieData = $this->_parseMovieNode($checkedMovie);
                            if ($movieData) {
                                $movies[] = $movieData;
                            }
                        }

                        // Parse only required movies
                        if (count($movies)==$this->_numberOfMovies){
                            break;
                        }
                    }
                    break; // top list movies found
                }
            }
        } else {
            echo '<br>Unable to load movies from profile ' . $this->_moviesProfile;
        }

        return $movies;
    }

    /**
     * @param $movieNode DOMElement
     * @return array
     */
    protected function _parseMovieNode($movieNode)
    {
        try {
            $movieData = array();
            $movieData['title'] = $movieNode->getElementsByTagName('h2')->item(0)->getElementsByTagName('a')->item(0)->textContent;
            $movieData['imdb_url'] = Magneto_Utils::findDOMElement($movieNode, 'a', 'optionIcon optionIMDB external')->getAttribute('href');
            $movieData['when'] = Magneto_Utils::findDOMElement($movieNode, 'span', 'year')->textContent;

            $coverUrl = $this->_retrieveImdbCover($movieData['imdb_url']);

            $attachmentId = $this->_downloadAttachment($coverUrl, 'Cover for ' . $movieData['title']);

            if (!is_wp_error($attachmentId)) {
                $movieData['img_id'] = $attachmentId;
            } else {
                $movieData = null;
            }

        } catch(Exception $e) {
            $movieData = null;
        }

        return $movieData;
    }

    protected function _getMovies()
    {
        $cacheKey = 'icheckmovies_' . $this->_moviesProfile . '_count_' . $this->_numberOfMovies;

        if (function_exists('apc_fetch')) {
            $movies = apc_fetch($cacheKey);
        }
        if (!$movies) {
            $movies = $this->_parseProfile();
        }

        if ($movies && function_exists('apc_add')) {
            apc_add($cacheKey, $movies, 24*3600);
        }

        return $movies;
    }

    /**
     * Renders widget
     */
    protected function _displayMovies() {
        $movies = $this->_getMovies();

        echo '<aside class="side-widget"><h3><a href="' . $this->getProfileUrl()  . '" target="_blank">' . $this->_widgetTitle . '</h3>';
        foreach ($movies as $movie) {
            $imgData = wp_get_attachment_image_src($movie['img_id'], 'full');
            $imgUrl = $imgData[0];

            echo <<<TEXT
            <a href="{$movie['imdb_url']}" title="{$movie['title']}" target="_blank">
                <img src="{$imgUrl}" alt="{$movie['title']}" width="107">
            </a>
TEXT;
        }
        echo "</aside>";
    }


    function widget($args, $instance)
    {
        $this->_init($instance);
        // outputs the content of the widget
        $this->_displayMovies();
    }

    /**
     * Initialize widget attributes from $instance
     *
     * @param $instance
     */
    protected function _init($instance)
    {
        $this->_moviesProfile = $instance['movies_profile'];
        $this->_numberOfMovies = $instance['number_movies'];
        $this->_widgetTitle = $instance['title'];
    }

    /**
     * Creates form displayed in Admin
     *
     * @param $instance
     */
    function form($instance)
    {
        $defaults = array('movies_profile' => '',
                          'number_movies' => self::DEFAULT_NUMBER_OF_SHOWN_MOVIES,
                          'title' => 'My Movies',
        );
        $instance = wp_parse_args((array)$instance, $defaults); ?>
        <p>
            <label for="<?php echo $this->get_field_id('title') ?>">Title:</label>
            <input type="text" name="<?php echo $this->get_field_name('title') ?>" id="<?php echo $this->get_field_id('title') ?>"
                   value="<?php echo $instance['title'] ?>" size="30">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('movies_profile') ?>">iCheckMovies Profile Name:</label>
            <input type="text" name="<?php echo $this->get_field_name('movies_profile') ?>"
                   id="<?php echo $this->get_field_id('movies_profile') ?>"
                   value="<?php echo $instance['movies_profile'] ?>" size="30">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id('number_movies') ?>">Number of shown movies:</label>
            <input type="number" name="<?php echo $this->get_field_name('number_movies') ?>"
                   id="<?php echo $this->get_field_id('number_movies') ?>"
                   value="<?php echo $instance['number_movies'] ?>">
        </p>
            

        <?php

    }

}

add_action( 'widgets_init', create_function( '', 'register_widget("ICheckMovies_Widget");' ) );
