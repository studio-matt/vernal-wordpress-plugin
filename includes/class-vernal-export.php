<?php
/**
 * Authenticated site extraction for Vernal Website re-creation.
 * Produces ExtractionSnapshot chunks only — never packages destination runtime.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Export {

    const SNAPSHOT_VERSION = '1.0.0';
    const OPTION_GRANT = 'vernal_contentum_export_grant';
    const OPTION_SNAPSHOT = 'vernal_contentum_export_snapshot';

    public static function register_routes() {
        $ns = 'vernal-contentum/v1';
        $api = Vernal_API::get_instance();

        register_rest_route($ns, '/export/authorize', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'authorize'),
            'permission_callback' => array($api, 'check_api_key'),
        ));
        register_rest_route($ns, '/export/inventory', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'inventory'),
            'permission_callback' => array($api, 'check_api_key'),
        ));
        register_rest_route($ns, '/export/snapshot/(?P<id>[a-zA-Z0-9\-]+)/status', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'status'),
            'permission_callback' => array($api, 'check_api_key'),
        ));
        register_rest_route($ns, '/export/snapshot/(?P<id>[a-zA-Z0-9\-]+)/chunk', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'chunk'),
            'permission_callback' => array($api, 'check_api_key'),
        ));
    }

    private static function grant_valid() {
        $grant = get_option(self::OPTION_GRANT, null);
        if (!is_array($grant) || empty($grant['token']) || empty($grant['expires'])) {
            return false;
        }
        return (int) $grant['expires'] >= time();
    }

    public static function authorize($request) {
        $confirm = $request->get_param('confirm');
        if (!$confirm) {
            return new WP_Error('export_not_confirmed', 'Explicit confirm=true required', array('status' => 400));
        }
        $token = 'ex_' . bin2hex(random_bytes(16));
        $snapshot_id = wp_generate_uuid4();
        update_option(self::OPTION_GRANT, array(
            'token' => $token,
            'expires' => time() + HOUR_IN_SECONDS,
            'snapshot_id' => $snapshot_id,
        ), false);
        update_option(self::OPTION_SNAPSHOT, array(
            'snapshot_id' => $snapshot_id,
            'status' => 'ready',
            'cursor' => 0,
            'built_at' => gmdate('c'),
        ), false);
        return array(
            'ok' => true,
            'snapshot_id' => $snapshot_id,
            'expires_in' => HOUR_IN_SECONDS,
        );
    }

    public static function inventory($request) {
        if (!self::grant_valid()) {
            return new WP_Error('export_unauthorized', 'Export grant required', array('status' => 403));
        }
        $pages = wp_count_posts('page');
        $posts = wp_count_posts('post');
        $media = wp_count_posts('attachment');
        $menus = wp_get_nav_menus();
        return array(
            'pages' => (int) ($pages->publish ?? 0),
            'posts' => (int) ($posts->publish ?? 0),
            'media' => (int) ($media->inherit ?? 0),
            'menus' => is_array($menus) ? count($menus) : 0,
        );
    }

    public static function status($request) {
        if (!self::grant_valid()) {
            return new WP_Error('export_unauthorized', 'Export grant required', array('status' => 403));
        }
        $id = $request['id'];
        $snap = get_option(self::OPTION_SNAPSHOT, array());
        if (($snap['snapshot_id'] ?? '') !== $id) {
            return new WP_Error('not_found', 'Snapshot not found', array('status' => 404));
        }
        return array(
            'snapshot_id' => $id,
            'status' => $snap['status'] ?? 'unknown',
            'cursor' => (int) ($snap['cursor'] ?? 0),
        );
    }

    public static function chunk($request) {
        if (!self::grant_valid()) {
            return new WP_Error('export_unauthorized', 'Export grant required', array('status' => 403));
        }
        $id = $request['id'];
        $snap = get_option(self::OPTION_SNAPSHOT, array());
        if (($snap['snapshot_id'] ?? '') !== $id) {
            return new WP_Error('not_found', 'Snapshot not found', array('status' => 404));
        }

        $cursor = max(0, (int) $request->get_param('cursor'));
        $limit = min(50, max(1, (int) ($request->get_param('limit') ?: 20)));

        $records = array();
        $media_manifest = array();

        // Stable order by ID
        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ));
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ));
        $all = array();
        foreach ($pages as $p) {
            $all[] = array('type' => 'page', 'post' => $p);
        }
        foreach ($posts as $p) {
            $all[] = array('type' => 'post', 'post' => $p);
        }
        foreach (wp_get_nav_menus() as $menu) {
            $all[] = array('type' => 'menu', 'menu' => $menu);
        }

        $slice = array_slice($all, $cursor, $limit);
        foreach ($slice as $item) {
            if ($item['type'] === 'menu') {
                $menu = $item['menu'];
                $items = wp_get_nav_menu_items($menu->term_id) ?: array();
                $records[] = array(
                    'type' => 'menu',
                    'id' => (string) $menu->term_id,
                    'name' => $menu->name,
                    'location' => 'header',
                    'items' => array_map(function ($it) {
                        return array(
                            'title' => $it->title,
                            'url' => $it->url,
                            'object' => $it->object,
                            'object_id' => (string) $it->object_id,
                        );
                    }, $items),
                );
                continue;
            }
            $post = $item['post'];
            $seo = array(
                'title' => get_post_meta($post->ID, '_yoast_wpseo_title', true) ?: $post->post_title,
                'description' => get_post_meta($post->ID, '_yoast_wpseo_metadesc', true) ?: get_the_excerpt($post),
            );
            $records[] = array(
                'type' => $item['type'],
                'id' => (string) $post->ID,
                'guid' => $post->guid,
                'title' => $post->post_title,
                'slug' => $post->post_name,
                'parent_id' => $post->post_parent ? (string) $post->post_parent : null,
                'menu_order' => (int) $post->menu_order,
                'status' => $post->post_status,
                'content_html' => $post->post_content,
                'builder' => has_blocks($post->post_content) ? 'gutenberg' : 'classic',
                'seo' => $seo,
            );
            $thumb = get_post_thumbnail_id($post->ID);
            if ($thumb) {
                $url = wp_get_attachment_url($thumb);
                $media_manifest[] = array(
                    'id' => 'att-' . $thumb,
                    'source_id' => (string) $thumb,
                    'url' => $url,
                    'mime' => get_post_mime_type($thumb),
                    'alt' => get_post_meta($thumb, '_wp_attachment_image_alt', true),
                    'checksum' => $url ? sha1($url) : '',
                    'relative_path' => 'media/att-' . $thumb,
                );
            }
        }

        $next = $cursor + count($slice);
        $done = $next >= count($all);
        $envelope = array(
            'snapshot_version' => self::SNAPSHOT_VERSION,
            'snapshot_id' => $id,
            'site_url' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'plugin_version' => defined('VERNAL_CONTENTUM_VERSION') ? VERNAL_CONTENTUM_VERSION : '0',
            'exported_at' => gmdate('c'),
            'inventory' => self::inventory($request),
            'records' => $records,
            'media_manifest' => $media_manifest,
            'cursor' => $cursor,
            'next_cursor' => $done ? null : $next,
            'done' => $done,
        );
        $envelope['checksum'] = hash('sha256', wp_json_encode($envelope['records']) . wp_json_encode($envelope['media_manifest']));

        $snap['cursor'] = $next;
        $snap['status'] = $done ? 'complete' : 'in_progress';
        update_option(self::OPTION_SNAPSHOT, $snap, false);

        return $envelope;
    }
}
