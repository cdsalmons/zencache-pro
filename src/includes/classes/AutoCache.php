<?php
namespace WebSharks\ZenCache\Pro;

/**
 * Auto-Cache Engine.
 *
 * @since 150422 Rewrite
 */
class AutoCache extends AbsBase
{
    /**
     * Class constructor.
     *
     * @since 150422 Rewrite
     */
    public function __construct()
    {
        parent::__construct();

        $this->run();
    }

    /**
     * Public runner; attach to WP-Cron.
     *
     * @since 150422 Rewrite
     */
    protected function run()
    {
        if (!$this->plugin->options['enable']) {
            return; // Nothing to do.
        }
        if (!$this->plugin->options['auto_cache_enable']) {
            return; // Nothing to do.
        }
        if (!$this->plugin->options['auto_cache_sitemap_url']) {
            if (!$this->plugin->options['auto_cache_other_urls']) {
                return; // Nothing to do.
            }
        }
        $cache_dir = $this->plugin->cacheDir();
        if (!is_dir($cache_dir) || !is_writable($cache_dir)) {
            return; // Not possible in this case.
        }
        $max_time = (integer) $this->plugin->options['auto_cache_max_time'];
        $max_time = $max_time > 60 ? $max_time : 900;

        @set_time_limit($max_time); // Max time.
        // @TODO When disabled, display a warning.

        ignore_user_abort(true); // Keep running.

        $micro_start_time = microtime(true);
        $start_time       = time(); // Initialize.
        $total_urls       = $total_time = 0; // Initialize.

        $network_home_url  = rtrim(network_home_url(), '/');
        $network_home_host = parse_url($network_home_url, PHP_URL_HOST);
        $network_home_path = parse_url($network_home_url, PHP_URL_PATH);

        $delay = (integer) $this->plugin->options['auto_cache_delay']; // In milliseconds.
        $delay = $delay > 0 ? $delay * 1000 : 0; // Convert delay to microseconds for `usleep()`.

        if (($other_urls = preg_split('/\s+/', $this->plugin->options['auto_cache_other_urls'], null, PREG_SPLIT_NO_EMPTY))) {
            $blogs = array((object) array('domain' => $network_home_host, 'path' => $network_home_path, 'other' => $other_urls));
        } else {
            $blogs = array((object) array('domain' => $network_home_host, 'path' => $network_home_path));
        }
        if (is_multisite()) {
            $wpdb = $this->plugin->wpdb(); // WordPress DB object instance.
            if (($_child_blogs = $wpdb->get_results('SELECT `domain`, `path` FROM `'.esc_sql($wpdb->blogs)."` WHERE `deleted` <= '0'"))) {
                $blogs = array_merge($blogs, $_child_blogs);
            }
            unset($_child_blogs); // Housekeeping.
        }
        shuffle($blogs); // Randomize the order; i.e. don't always start from the top.

        foreach ($blogs as $_blog) {
            $_blog_sitemap_urls = $_blog_other_urls = $_blog_urls = array();
            $_blog_url          = 'http://'.$_blog->domain.'/'.trim($_blog->path, '/');

            if ($this->plugin->options['auto_cache_sitemap_url']) {
                $_blog_sitemap_urls = $this->getSitemapUrlsDeep($_blog_url.'/'.$this->plugin->options['auto_cache_sitemap_url']);
            }
            if (!empty($_blog->other)) {
                $_blog_other_urls = array_merge($_blog_urls, $_blog->other);
            }
            $_blog_urls = array_merge($_blog_sitemap_urls, $_blog_other_urls);
            $_blog_urls = array_unique($_blog_urls); // Unique URLs only.
            shuffle($_blog_urls); // Randomize the order.

            foreach ($_blog_urls as $_url) {
                $total_urls++; // Counter.

                $this->autoCacheUrl($_url);

                if ((time() - $start_time) > ($max_time - 30)) {
                    break 2; // Stop now.
                }
                if ($delay) {
                    usleep($delay);
                }
            }
            unset($_url); // A little housekeeping.
        }
        unset($_blog, $_blog_sitemap_urls, $_blog_other_urls, $_blog_urls, $_blog_url);

        $total_time = number_format(microtime(true) - $micro_start_time, 5, '.', '').' seconds';

        $this->logAutoCacheRun($total_urls, $total_time);
    }

    /**
     * Auto-cache a specific URL.
     *
     * @since 150422 Rewrite
     *
     * @param string $url The URL to auto-cache.
     */
    protected function autoCacheUrl($url)
    {
        if (!($url = trim((string) $url))) {
            return; // Nothing to do.
        }
        if (!$this->plugin->options['get_requests'] && strpos($url, '?') !== false) {
            return; // We're NOT caching URLs with a query string.
        }
        $cache_path      = $this->plugin->buildCachePath($url);
        $cache_file_path = $this->plugin->cacheDir($cache_path);

        if (is_file($cache_file_path)) {
            if (filemtime($cache_file_path) >= strtotime('-'.$this->plugin->options['cache_max_age'])) {
                return; // Cached already.
            }
            $this->logAutoCacheUrl(
                $url,
                wp_remote_get(
                    $url,
                    array(
                        'blocking'   => false,
                        'user-agent' => $this->plugin->options['auto_cache_user_agent'].
                            '; '.GLOBAL_NS.' '.$this->plugin->version,
                    )
                )
            );
        }
    }

    /**
     * Logs an attempt to auto-cache a specific URL.
     *
     * @since 150422 Rewrite
     *
     * @param string    $url                    The URL we attempted to auto-cache.
     * @param \WP_Error $wp_remote_get_response For IDEs.
     *
     * @throws \exception If log file exists already; but is NOT writable.
     */
    protected function logAutoCacheUrl($url, $wp_remote_get_response)
    {
        $cache_dir           = $this->plugin->cacheDir();
        $cache_lock          = $this->plugin->cacheLock();
        $auto_cache_log_file = $cache_dir.'/zc-auto-cache.log';

        if (is_file($auto_cache_log_file) && !is_writable($auto_cache_log_file)) {
            throw new \Exception(sprintf(__('Auto-cache log file is NOT writable: `%1$s`. Please set permissions to `644` (or higher). `666` might be needed in some cases.', $this->plugin->text_domain), $auto_cache_log_file));
        }
        if (is_wp_error($wp_remote_get_response)) {
            $log_entry =
                'Time: '.date(DATE_RFC822)."\n".
                'URL: '.$url."\n".
                'Error: '.$wp_remote_get_response->get_error_message()."\n\n";
        } else {
            $log_entry =
                'Time: '.date(DATE_RFC822)."\n".
                'URL: '.$url."\n\n";
        }
        file_put_contents($auto_cache_log_file, $log_entry, FILE_APPEND);

        if (filesize($auto_cache_log_file) > 2097152) {
            rename($auto_cache_log_file, substr($auto_cache_log_file, 0, -4).'-archived-'.time().'.log');
        }
        $this->plugin->cacheUnlock($cache_lock);
    }

    /**
     * Logs auto-cache run totals.
     *
     * @since 150422 Rewrite
     *
     * @param int    $total_urls Total URLs processed by the run.
     * @param string $total_time Total time it took to complete processing.
     *
     * @throws \exception If log file exists already; but is NOT writable.
     */
    protected function logAutoCacheRun($total_urls, $total_time)
    {
        $cache_dir           = $this->plugin->cacheDir();
        $cache_lock          = $this->plugin->cacheLock();
        $auto_cache_log_file = $cache_dir.'/zc-auto-cache.log';

        if (is_file($auto_cache_log_file) && !is_writable($auto_cache_log_file)) {
            throw new \Exception(sprintf(__('Auto-cache log file is NOT writable: `%1$s`. Please set permissions to `644` (or higher). `666` might be needed in some cases.', $this->plugin->text_domain), $auto_cache_log_file));
        }
        $log_entry =
            'Run Completed: '.date(DATE_RFC822)."\n".
            'Total URLs: '.$total_urls."\n".
            'Total Time: '.$total_time."\n\n";

        file_put_contents($auto_cache_log_file, $log_entry, FILE_APPEND);

        if (filesize($auto_cache_log_file) > 2097152) {
            rename($auto_cache_log_file, substr($auto_cache_log_file, 0, -4).'-archived-'.time().'.log');
        }
        $this->plugin->cacheUnlock($cache_lock);
    }

    /**
     * Collects all URLs from an XML sitemap deeply.
     *
     * @since 150422 Rewrite
     *
     * @param string $sitemap      A URL to an XML sitemap file.
     *                             This supports nested XML sitemap index files too; i.e. `<sitemapindex>`.
     *                             Note that GZIP files are NOT supported at this time.
     * @param bool   $___recursive For internal use only.
     *
     * @throws \exception If `$sitemap` is NOT actually a sitemap.
     *
     * @return array URLs from an XML sitemap deeply.
     */
    protected function getSitemapUrlsDeep($sitemap, $___recursive = false)
    {
        $urls       = array();
        $xml_reader = new \XMLReader();

        if (!($sitemap = trim((string) $sitemap))) {
            goto finale; // Nothing we can do.
        }
        if (is_wp_error($head = wp_remote_head($sitemap, array('redirection' => 5)))) {
            if ($___recursive) {
                goto finale; // Fail silently on recursive calls.
            }
            throw new \Exception(sprintf(__('Invalid XML sitemap. Unreachable URL: `%1$s`. %2$s', $this->plugin->text_domain), $sitemap, $head->get_error_message()));
        }
        if (empty($head['response']['code']) || (integer) $head['response']['code'] >= 400) {
            if ($___recursive) {
                goto finale; // Fail silently on recursive calls.
            }
            throw new \Exception(sprintf(__('Invalid XML sitemap status code at: `%1$s`. Expecting a `200` status. Instead got: `%2$s`.', $this->plugin->text_domain), $sitemap, !empty($head['response']['code']) ? $head['response']['code'] : ''));
        }
        if (empty($head['headers']['content-type']) || stripos($head['headers']['content-type'], 'xml') === false) {
            if ($___recursive) {
                goto finale; // Fail silently on recursive calls.
            }
            throw new \Exception(sprintf(__('Invalid XML sitemap content type at: `%1$s`. Expecting XML. Instead got: `%2$s`.', $this->plugin->text_domain), $sitemap, !empty($head['headers']['content-type']) ? $head['headers']['content-type'] : ''));
        }
        if ($xml_reader->open($sitemap)) {
            while ($xml_reader->read()) {
                if ($xml_reader->nodeType === $xml_reader::ELEMENT) {
                    switch ($xml_reader->name) {
                        case 'sitemapindex': // e.g. <http://www.smashingmagazine.com/sitemap_index.xml>
                            if (($_sitemapindex_urls = $this->xmlGetSitemapIndexUrlsDeep($xml_reader))) {
                                $urls = array_merge($urls, $_sitemapindex_urls);
                            }
                            break; // Break switch handler.

                        case 'urlset': // e.g. <http://www.smashingmagazine.com/category-sitemap.xml>
                            if (($_urlset_urls = $this->xmlGetUrlsetUrls($xml_reader))) {
                                $urls = array_merge($urls, $_urlset_urls);
                            }
                            break; // Break switch handler.
                    }
                }
            }
            unset($_sitemapindex_urls, $_urlset_urls);
        }
        finale: // Target point; grand finale.

        return $urls; // A full set of all sitemap URLs; i.e. `<loc>` tags.
    }

    /**
     * For internal use only.
     *
     * @since 150422 Rewrite
     *
     * @param \XMLReader $xml_reader
     *
     * @return array All sitemap URLs from this `<sitemapindex>` node; deeply.
     */
    protected function xmlGetSitemapIndexUrlsDeep(\XMLReader $xml_reader)
    {
        $urls = array(); // Initialize.

        if ($xml_reader->name === 'sitemapindex') {
            while ($xml_reader->read()) {
                if ($xml_reader->nodeType === $xml_reader::ELEMENT) {
                    switch ($xml_reader->name) {
                        case 'sitemap':
                            $is_sitemap_node = true;
                            break; // Break switch handler.

                        case 'loc': // A URL location.
                            if (!empty($is_sitemap_node) && $xml_reader->read() && ($_loc = trim($xml_reader->value))) {
                                $urls = array_merge($urls, $this->getSitemapUrlsDeep($_loc, true));
                            }
                            break; // Break switch handler.

                        default: // Anything else.
                            $is_sitemap_node = false;
                            break; // Break switch handler.
                    }
                }
            }
            return $urls; // All sitemap URLs from this `<sitemapindex>` node; deeply.
        }
    }

    /**
     * For internal use only.
     *
     * @since 150422 Rewrite
     *
     * @param \XMLReader $xml_reader
     *
     * @return array All sitemap URLs from this `<urlset>` node.
     */
    protected function xmlGetUrlsetUrls(\XMLReader $xml_reader)
    {
        $urls = array(); // Initialize.

        if ($xml_reader->name === 'urlset') {
            while ($xml_reader->read()) {
                if ($xml_reader->nodeType === $xml_reader::ELEMENT) {
                    switch ($xml_reader->name) {
                        case 'url':
                            $is_url_node = true;
                            break; // Break switch handler.

                        case 'loc': // A URL location.
                            if (!empty($is_url_node) && $xml_reader->read() && ($_loc = trim($xml_reader->value))) {
                                $urls[] = $_loc;
                            } // Add this URL to the list :-)
                            break; // Break switch handler.

                        default: // Anything else.
                            $is_url_node = false;
                            break; // Break switch handler.
                    }
                }
            }
            return $urls; // All sitemap URLs from this `<urlset>` node.
        }
    }
}