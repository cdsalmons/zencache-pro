<?php
namespace WebSharks\ZenCache\Pro;

/**
 * Conflicts.
 *
 * @since 150422 Rewrite
 */
class Conflicts
{
    /**
     * Check.
     *
     * @since 150422 Rewrite
     */
    public static function check()
    {
        if (static::doCheck()) {
            static::mayEnqueueNotice();
        }
        return $GLOBALS[GLOBAL_NS.'_conflicting_plugin'];
    }

    /**
     * Perform check.
     *
     * @since 150422 Rewrite
     */
    protected static function doCheck()
    {
        if (!empty($GLOBALS[GLOBAL_NS.'_conflicting_plugin'])) {
            return $GLOBALS[GLOBAL_NS.'_conflicting_plugin'];
        }
        $ns_path = str_replace('\\', '/', __NAMESPACE__);
        $is_pro  = strtolower(basename($ns_path)) === 'pro';

        $conflicting_plugin_slugs = array(
            'quick-cache', 'quick-cache-pro',
            str_replace('_', '-', GLOBAL_NS).($is_pro ? '' : '-pro'),
            'wp-super-cache', 'w3-total-cache', 'hyper-cache', 'wp-rocket',
        );
        $active_plugins           = (array) get_option('active_plugins', array());
        $active_sitewide_plugins  = is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', array())) : array();
        $active_plugins           = array_unique(array_merge($active_plugins, $active_sitewide_plugins));

        foreach ($active_plugins as $_active_plugin_basename) {
            if (!($_active_plugin_slug = strstr($_active_plugin_basename, '/', true))) {
                continue; // Nothing to check in this case.
            }
            if (in_array($_active_plugin_slug, $conflicting_plugin_slugs, true)) {
                if (in_array($_active_plugin_slug, array('quick-cache', 'quick-cache-pro'), true)) {
                    add_action('admin_init', function () use ($_active_plugin_basename) {
                        if (function_exists('deactivate_plugins')) {
                            deactivate_plugins($_active_plugin_basename, true);
                        }
                    }, -1000);
                } else {
                    return ($GLOBALS[GLOBAL_NS.'_conflicting_plugin'] = $_active_plugin_slug);
                }
            }
        }
        return ($GLOBALS[GLOBAL_NS.'_conflicting_plugin'] = ''); // i.e. No conflicting plugins.
    }

    /**
     * Maybe enqueue dashboard notice.
     *
     * @since 150422 Rewrite
     */
    protected function mayEnqueueNotice()
    {
        if (!empty($GLOBALS[GLOBAL_NS.'_uninstalling'])) {
            return; // Not when uninstalling.
        }
        if (empty($GLOBALS[GLOBAL_NS.'_conflicting_plugin'])) {
            return; // Not conflicts.
        }
        if (!empty($GLOBALS[GLOBAL_NS.'_conflicting_plugin_lite_pro'])) {
            return; // Already did this in one plugin or the other.
        }
        add_action('all_admin_notices', function () {
            $construct_name          = function ($slug_or_ns) {
                $slug_or_ns = trim(strtolower((string) $slug_or_ns));

                if (preg_match('/^'.preg_quote(GLOBAL_NS, '/').'[_\-]pro$/', $slug_or_ns)) {
                    $slug_or_ns = strtolower(GLOBAL_NS); // Strip `-pro` suffix.
                }
                $name = preg_replace('/[^a-z0-9]/', ' ', $slug_or_ns);
                $name = str_replace('cache', 'Cache', ucwords($name));

                return $name; // e.g. `x-cache` becomes `X Cache`.
            };
            $text_domain             = str_replace('_', '-', GLOBAL_NS);
            $conflicting_plugin_name = $construct_name($GLOBALS[GLOBAL_NS.'_conflicting_plugin']);
            $plugin_name             = $construct_name(GLOBAL_NS);

            if (strcasecmp($conflicting_plugin_name, $plugin_name) === 0) {
                $conflicting_plugin_name = $plugin_name.' '.__('Lite', $text_domain);
                $plugin_name             = $plugin_name.' '.__('Pro', $text_domain);
                $GLOBALS[GLOBAL_NS.'_conflicting_plugin_lite_pro'] = true;
            }
            echo '<div class="error">'.
                 '   <p>'.// Running one or more conflicting plugins at the same time.
                 '      '.sprintf(__('<strong>%1$s</strong> is NOT running. A conflicting plugin, <strong>%2$s</strong>, is currently active. Please deactivate the %2$s plugin to clear this message.', $text_domain), esc_html($plugin_name), esc_html($conflicting_plugin_name)).
                 '   </p>'.
                 '</div>';
        });
    }
}