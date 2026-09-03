<?php
/**
 * Internal cross-article linking — cron, inventory, Machine match, audit, undo.
 *
 * @package VernalContentum
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Internal_Links {

    const OPTION_SETTINGS = 'vernal_contentum_internal_links';
    const OPTION_LOCK     = 'vernal_contentum_il_run_lock';
    const OPTION_LAST_RUN = 'vernal_contentum_il_last_run';
    const OPTION_RECENT   = 'vernal_contentum_il_recent_mutations';
    const CRON_HOOK       = 'vernal_il_cron_tick';
    const CRON_RECONCILE  = 'vernal_il_reconcile_tick';
    const META_PASS_AT    = '_vernal_il_pass_at';
    const META_SRC_MOD    = '_vernal_il_source_modified_gmt';
    const META_FP         = '_vernal_il_content_fp';
    const META_LINKS      = '_vernal_il_links';
    const META_GRAPH      = '_vernal_il_graph_stats';
    const META_TARGETS    = '_vernal_il_link_targets';
    const LEASE_SECONDS   = 900;

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('cron_schedules', array($this, 'register_schedules'));
        add_action(self::CRON_HOOK, array($this, 'cron_tick'));
        add_action(self::CRON_RECONCILE, array($this, 'reconcile_inbound_cache'));
        add_action('save_post_post', array($this, 'maybe_enqueue_on_save'), 20, 3);
        add_action('admin_post_vernal_il_run_now', array($this, 'handle_run_now'));
        add_action('admin_post_vernal_il_undo', array($this, 'handle_undo'));
        add_action('admin_post_vernal_il_save_settings', array($this, 'handle_save_settings'));
        add_action('init', array($this, 'ensure_cron_scheduled'));
    }

    public static function default_settings() {
        return array(
            'enabled'                                    => 1,
            'schedule'                                   => 'hourly',
            'max_new_outbound_links_per_source'          => 1,
            'max_inbound_source_mutations_per_new_target'=> 1,
            'batch_sources_per_tick'                     => 25,
            'min_relevance_score'                        => 0.35,
            'prefer_same_category'                       => 1,
            'orphan_repair_after_days'                   => 14,
            'min_word_count'                             => 120,
            'max_vernal_links_per_post'                  => 12,
            'max_total_internal_links_per_post'          => 12,
            'soft_target_long_form'                      => 8,
            'healthy_cooldown_days'                      => 7,
            'pillar_post_ids'                            => array(),
            'excluded_category_ids'                      => array(),
            'excluded_post_ids'                          => array(),
            'social_destination_id'                      => 0,
            'process_new_and_modified'                   => 1,
            'orphan_repair_enabled'                      => 1,
        );
    }

    public static function get_settings() {
        $defaults = self::default_settings();
        $stored = get_option(self::OPTION_SETTINGS, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $out = array_merge($defaults, $stored);
        $out['excluded_category_ids'] = self::normalize_id_list(isset($out['excluded_category_ids']) ? $out['excluded_category_ids'] : array());
        $out['excluded_post_ids'] = self::normalize_id_list(isset($out['excluded_post_ids']) ? $out['excluded_post_ids'] : array());
        $out['pillar_post_ids'] = self::normalize_id_list(isset($out['pillar_post_ids']) ? $out['pillar_post_ids'] : array());
        // v1 hard rule: at most one edge per source per pass
        $out['max_new_outbound_links_per_source'] = 1;
        return $out;
    }

    private static function normalize_id_list($raw) {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw);
        }
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $v) {
            $i = (int) $v;
            if ($i > 0 && !in_array($i, $out, true)) {
                $out[] = $i;
            }
        }
        return $out;
    }

    public function register_schedules($schedules) {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Once Weekly', 'vernal-contentum'),
            );
        }
        return $schedules;
    }

    public function ensure_cron_scheduled() {
        $settings = self::get_settings();
        $wanted = !empty($settings['enabled']) ? $settings['schedule'] : '';
        $allowed = array('hourly', 'twicedaily', 'daily', 'weekly');
        if ($wanted && !in_array($wanted, $allowed, true)) {
            $wanted = 'hourly';
        }
        $next = wp_next_scheduled(self::CRON_HOOK);
        if (empty($settings['enabled'])) {
            if ($next) {
                wp_unschedule_event($next, self::CRON_HOOK);
            }
            $rec = wp_next_scheduled(self::CRON_RECONCILE);
            if ($rec) {
                wp_unschedule_event($rec, self::CRON_RECONCILE);
            }
            return;
        }
        // Reschedule if missing or interval changed (store schedule in option stamp)
        $current_sched = get_option('vernal_il_cron_schedule', '');
        if (!$next || $current_sched !== $wanted) {
            if ($next) {
                wp_unschedule_event($next, self::CRON_HOOK);
            }
            wp_schedule_event(time() + 60, $wanted, self::CRON_HOOK);
            update_option('vernal_il_cron_schedule', $wanted, false);
        }
        if (!wp_next_scheduled(self::CRON_RECONCILE)) {
            wp_schedule_event(time() + 300, 'daily', self::CRON_RECONCILE);
        }
    }

    public function cron_tick() {
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return;
        }
        $this->run_pass('cron');
    }

    /**
     * Nightly: recompute derived inbound counts from a wider post sample.
     */
    public function reconcile_inbound_cache() {
        $settings = self::get_settings();
        $counts = $this->estimate_inbound_counts($settings, 200);
        foreach ($counts as $post_id => $in_count) {
            $post_id = (int) $post_id;
            if ($post_id < 1) {
                continue;
            }
            $stats = $this->get_graph_stats($post_id);
            $stats['contextual_links_in'] = (int) $in_count;
            $stats['computed_at'] = gmdate('c');
            $stats['cache_version'] = 1;
            $post = get_post($post_id);
            if ($post) {
                $analysis = Vernal_Internal_Link_Inserter::analyze_internal_links($post->post_content);
                $stats['contextual_links_out'] = (int) $analysis['total'];
                $stats['vernal_links_out'] = (int) $analysis['vernal'];
                $stats['orphan_status'] = ($analysis['vernal'] === 0 && (int) $in_count === 0);
                $profile = $this->resolve_link_profile($post, $settings);
                $slots = isset($stats['slots_filled_by_role']) && is_array($stats['slots_filled_by_role'])
                    ? $stats['slots_filled_by_role']
                    : $this->slots_from_ledger($post_id);
                $stats['link_health'] = $this->compute_link_health_label(
                    $post,
                    $settings,
                    $analysis,
                    (int) $in_count,
                    $profile,
                    $slots
                );
            }
            $this->save_graph_stats($post_id, $stats);
        }
    }

    /**
     * Acquire run lease. Returns run_id or WP_Error.
     */
    public function acquire_lock() {
        $now = time();
        $lock = get_option(self::OPTION_LOCK, null);
        if (is_array($lock) && !empty($lock['lease_expires_at']) && (int) $lock['lease_expires_at'] > $now) {
            return new WP_Error('locked', __('Internal linking run already in progress.', 'vernal-contentum'));
        }
        $run_id = 'run_' . gmdate('YmdHis') . '_' . wp_generate_password(6, false, false);
        $payload = array(
            'run_id'           => $run_id,
            'started_at'       => gmdate('c', $now),
            'lease_expires_at' => $now + self::LEASE_SECONDS,
        );
        update_option(self::OPTION_LOCK, $payload, false);
        return $run_id;
    }

    public function release_lock($run_id = null) {
        $lock = get_option(self::OPTION_LOCK, null);
        if ($run_id && is_array($lock) && isset($lock['run_id']) && $lock['run_id'] !== $run_id) {
            return;
        }
        delete_option(self::OPTION_LOCK);
    }

    /**
     * Main pass.
     *
     * @param string $trigger cron|manual_run|enqueue
     * @param array  $opts optional focus_post_ids
     * @return array run summary
     */
    public function run_pass($trigger = 'cron', $opts = array()) {
        $settings = self::get_settings();
        $run_id = $this->acquire_lock();
        if (is_wp_error($run_id)) {
            return array(
                'status'  => 'skipped_locked',
                'errors'  => 1,
                'message' => $run_id->get_error_message(),
            );
        }

        $summary = array(
            'run_id'       => $run_id,
            'started_at'   => gmdate('c'),
            'completed_at' => null,
            'status'       => 'running',
            'scanned'      => 0,
            'linked'       => 0,
            'skipped'      => 0,
            'errors'       => 0,
            'trigger'      => $trigger,
        );

        $meaningful = false;

        try {
            if (empty($settings['enabled']) && $trigger === 'cron') {
                $summary['status'] = 'disabled';
                $this->finish_run($summary, false);
                return $summary;
            }

            if (!class_exists('Vernal_Backend_API') || !Vernal_Backend_API::is_configured()) {
                $summary['status'] = 'error';
                $summary['errors'] = 1;
                $summary['message'] = 'Backend not configured';
                $this->finish_run($summary, false);
                return $summary;
            }

            $dest_id = (int) $settings['social_destination_id'];
            if ($dest_id < 1) {
                // Fall back to retrofit destination if set
                $dest_id = (int) get_option('vernal_contentum_retrofit_destination_id', 0);
            }
            if ($dest_id < 1) {
                $summary['status'] = 'error';
                $summary['errors'] = 1;
                $summary['message'] = 'social_destination_id is required (set it on the Internal Linking settings page)';
                $this->finish_run($summary, false);
                return $summary;
            }

            $batch = (int) $settings['batch_sources_per_tick'];
            $sources = $this->select_source_posts($settings, $batch, isset($opts['focus_post_ids']) ? $opts['focus_post_ids'] : array());
            $meaningful = !empty($sources);

            foreach ($sources as $post) {
                $summary['scanned']++;
                $result = $this->process_source_outbound($post, $settings, $run_id, $dest_id, $trigger);
                if (!empty($result['linked'])) {
                    $summary['linked'] += (int) $result['linked'];
                }
                if (!empty($result['skipped'])) {
                    $summary['skipped'] += (int) $result['skipped'];
                }
                if (!empty($result['errors'])) {
                    $summary['errors'] += (int) $result['errors'];
                } else {
                    // Stamp success for this source even if zero links (idempotent pass)
                    $this->stamp_source_pass($post->ID, !empty($result['linked']));
                }

                // After processing a relatively new source, try inbound backfill onto older posts
                if ($this->is_recently_published($post, 7)) {
                    $ib = $this->process_inbound_backfill($post, $settings, $run_id, $dest_id, $trigger);
                    if (!empty($ib['linked'])) {
                        $summary['linked'] += (int) $ib['linked'];
                    }
                    if (!empty($ib['errors'])) {
                        $summary['errors'] += (int) $ib['errors'];
                    }
                }
            }

            $summary['status'] = ($summary['errors'] > 0 && $summary['linked'] === 0 && $summary['scanned'] === 0)
                ? 'error'
                : 'completed';
            $this->finish_run($summary, $meaningful && $summary['status'] === 'completed');
        } catch (Exception $e) {
            $summary['status'] = 'error';
            $summary['errors']++;
            $summary['message'] = $e->getMessage();
            $this->finish_run($summary, false);
        }

        return $summary;
    }

    private function finish_run(&$summary, $advance_watermark) {
        $summary['completed_at'] = gmdate('c');
        $this->release_lock(isset($summary['run_id']) ? $summary['run_id'] : null);
        update_option(self::OPTION_LAST_RUN, $summary, false);
        if ($advance_watermark) {
            update_option('vernal_il_last_success_gmt', gmdate('Y-m-d H:i:s'), false);
        }
    }

    /**
     * @param array $settings
     * @param int   $limit
     * @param array $focus_ids
     * @return WP_Post[]
     */
    public function select_source_posts($settings, $limit, $focus_ids = array()) {
        $limit = max(1, min(50, (int) $limit));
        $exclude = self::normalize_id_list($settings['excluded_post_ids']);
        $exclude_cats = self::normalize_id_list($settings['excluded_category_ids']);

        if (!empty($focus_ids)) {
            $q = new WP_Query(array(
                'post_type'              => 'post',
                'post_status'            => 'publish',
                'post__in'               => array_map('intval', $focus_ids),
                'posts_per_page'         => $limit,
                'orderby'                => 'post__in',
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => true,
            ));
            return $this->filter_eligible_posts($q->posts, $settings);
        }

        $args = array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => $limit * 3,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'has_password'        => false,
        );
        if ($exclude) {
            $args['post__not_in'] = $exclude;
        }
        if ($exclude_cats) {
            $args['category__not_in'] = $exclude_cats;
        }

        $q = new WP_Query($args);
        $eligible = $this->filter_eligible_posts($q->posts, $settings);

        // Prefer those needing a pass
        $needs = array();
        foreach ($eligible as $p) {
            if ($this->source_needs_pass($p, $settings)) {
                $needs[] = $p;
            }
            if (count($needs) >= $limit) {
                break;
            }
        }
        return $needs;
    }

    /**
     * @param WP_Post[] $posts
     * @param array     $settings
     * @return WP_Post[]
     */
    private function filter_eligible_posts($posts, $settings) {
        $out = array();
        foreach ((array) $posts as $post) {
            if ($this->is_post_eligible($post, $settings)) {
                $out[] = $post;
            }
        }
        return $out;
    }

    /**
     * @param WP_Post|int $post
     * @param array       $settings
     */
    public function is_post_eligible($post, $settings = null) {
        $settings = $settings ? $settings : self::get_settings();
        $post = get_post($post);
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish') {
            return false;
        }
        if (!empty($post->post_password)) {
            return false;
        }
        if (in_array((int) $post->ID, self::normalize_id_list($settings['excluded_post_ids']), true)) {
            return false;
        }
        $cats = wp_get_post_categories($post->ID);
        foreach (self::normalize_id_list($settings['excluded_category_ids']) as $ex) {
            if (in_array($ex, $cats, true)) {
                return false;
            }
        }
        if (class_exists('Vernal_Semantic_Content')) {
            $kind = Vernal_Semantic_Content::get_instance()->detect_kind($post);
            if ($kind === 'show_landing') {
                return false;
            }
        } else {
            if (get_post_meta($post->ID, 'vernal_episode_id', true)) {
                return false;
            }
        }
        $permalink = get_permalink($post);
        if (!$permalink || is_wp_error($permalink)) {
            return false;
        }
        $words = str_word_count(wp_strip_all_tags($post->post_content));
        if ($words < (int) $settings['min_word_count']) {
            return false;
        }
        return true;
    }

    /**
     * @param WP_Post $post
     * @param array   $settings
     */
    public function source_needs_pass($post, $settings) {
        $fp = Vernal_Internal_Link_Inserter::content_fingerprint($post->post_content);
        $stored_fp = (string) get_post_meta($post->ID, self::META_FP, true);
        $pass_at = (string) get_post_meta($post->ID, self::META_PASS_AT, true);
        $stats = $this->get_graph_stats($post->ID);

        if ($pass_at === '') {
            return true;
        }
        if ($stored_fp && $stored_fp !== $fp) {
            $this->wake_post($post->ID, 'editorial_edit');
            return true; // genuine editorial change
        }

        // Healthy cooldown: skip until next_eligible_at unless woken
        $next = isset($stats['next_eligible_at']) ? (string) $stats['next_eligible_at'] : '';
        if ($next !== '') {
            $next_ts = strtotime($next);
            if ($next_ts && $next_ts > time()) {
                $health = isset($stats['link_health']) ? (string) $stats['link_health'] : '';
                if (in_array($health, array('healthy', 'overlinked'), true)) {
                    return false;
                }
            }
        }

        // Orphan repair: zero Vernal outbound links and old enough
        if (!empty($settings['orphan_repair_enabled'])) {
            $analysis = Vernal_Internal_Link_Inserter::analyze_internal_links($post->post_content);
            $days = (int) $settings['orphan_repair_after_days'];
            $age = time() - strtotime($post->post_date_gmt . ' UTC');
            if ($analysis['vernal'] === 0 && $days > 0 && $age > ($days * DAY_IN_SECONDS)) {
                $pass_ts = strtotime($pass_at);
                if (!$pass_ts || (time() - $pass_ts) > ($days * DAY_IN_SECONDS)) {
                    return true;
                }
            }
        }

        // New/modified relative to last success watermark — but not our own writes
        if (!empty($settings['process_new_and_modified'])) {
            $last_success = get_option('vernal_il_last_success_gmt', '');
            $src_mod_stamp = (string) get_post_meta($post->ID, self::META_SRC_MOD, true);
            if ($src_mod_stamp && $src_mod_stamp === $post->post_modified_gmt && $stored_fp === $fp) {
                // Still eligible if underlinked / missing cornerstone / new
                $health = isset($stats['link_health']) ? (string) $stats['link_health'] : '';
                if (in_array($health, array('underlinked', 'missing_cornerstone', 'orphan', 'new_unoptimized'), true)) {
                    return empty($next) || (strtotime($next) && strtotime($next) <= time());
                }
                return false;
            }
            if ($last_success && $post->post_date_gmt > $last_success && $pass_at < $post->post_date_gmt) {
                return true;
            }
        }

        $health = isset($stats['link_health']) ? (string) $stats['link_health'] : '';
        if (in_array($health, array('underlinked', 'missing_cornerstone', 'orphan', 'new_unoptimized', ''), true)) {
            return true;
        }

        return false;
    }

    private function is_recently_published($post, $days) {
        $age = time() - strtotime($post->post_date_gmt . ' UTC');
        return $age <= ($days * DAY_IN_SECONDS);
    }

    public function stamp_source_pass($post_id, $mutated = false) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        update_post_meta($post_id, self::META_PASS_AT, gmdate('c'));
        update_post_meta($post_id, self::META_SRC_MOD, $post->post_modified_gmt);
        update_post_meta(
            $post_id,
            self::META_FP,
            Vernal_Internal_Link_Inserter::content_fingerprint($post->post_content)
        );
        $this->refresh_graph_stats_for_post($post_id, $mutated);
    }

    /**
     * Process outbound links for one source — at most one high-value edge.
     */
    private function process_source_outbound($post, $settings, $run_id, $dest_id, $trigger) {
        $out = array('linked' => 0, 'skipped' => 0, 'errors' => 0);
        $analysis = Vernal_Internal_Link_Inserter::analyze_internal_links($post->post_content);
        $profile = $this->resolve_link_profile($post, $settings);
        $max_vernal = (int) $profile['soft_max'];
        if ($max_vernal >= 999) {
            $max_vernal = max(20, (int) $settings['max_vernal_links_per_post']);
        }
        $max_total = (int) $settings['max_total_internal_links_per_post'];
        if ($max_vernal < 999 && $max_total < $max_vernal) {
            // Keep total soft cap aligned for non-pillars
            $max_total = max($max_total, $max_vernal);
        }

        if ($analysis['vernal'] >= $max_vernal || $analysis['total'] >= $max_total) {
            $out['skipped']++;
            $this->refresh_graph_stats_for_post($post->ID, false);
            return $out;
        }

        // v1: exactly one edge per pass when room exists
        $room = 1;

        $ledger_strategy = 'best_missing_edge';
        if ($trigger === 'manual_run') {
            $ledger_strategy = 'manual_run';
        } elseif (!empty($settings['orphan_repair_enabled'])) {
            $age = time() - strtotime($post->post_date_gmt . ' UTC');
            if ($analysis['vernal'] === 0 && $age > ((int) $settings['orphan_repair_after_days'] * DAY_IN_SECONDS)) {
                $ledger_strategy = 'orphan_repair';
            } else {
                $ledger_strategy = 'new_post_outbound';
            }
        } else {
            $ledger_strategy = 'new_post_outbound';
        }

        $payload = $this->build_best_edge_payload($post, $settings, $dest_id, $analysis, $profile);
        $resp = Vernal_Backend_API::request('plugin/internal-links/match', array(
            'method' => 'POST',
            'body'   => $payload,
            'timeout'=> 60,
        ));
        if (is_wp_error($resp)) {
            $out['errors']++;
            return $out;
        }
        $results = isset($resp['results']) && is_array($resp['results']) ? $resp['results'] : array();
        $content = $post->post_content;
        $ledger = $this->get_ledger($post->ID);
        $inserted_this_pass = 0;
        $used_anchors = $this->used_anchor_texts_from_ledger($ledger);

        foreach ($results as $row) {
            if ($inserted_this_pass >= $room) {
                break;
            }
            $score = isset($row['score']) ? (float) $row['score'] : 0;
            if ($score < (float) $settings['min_relevance_score']) {
                $out['skipped']++;
                continue;
            }
            $target_id = isset($row['target_wp_post_id']) ? (int) $row['target_wp_post_id'] : 0;
            if (!$this->is_valid_target($target_id, $settings, $post->ID)) {
                $out['skipped']++;
                continue;
            }
            $permalink = get_permalink($target_id);
            $anchors = isset($row['anchors']) && is_array($row['anchors']) ? $row['anchors'] : array();
            if (!$anchors) {
                $out['skipped']++;
                continue;
            }
            $phrase = isset($anchors[0]['text']) ? (string) $anchors[0]['text'] : '';
            if ($phrase === '' || $this->is_generic_anchor($phrase) || in_array(strtolower($phrase), $used_anchors, true)) {
                $out['skipped']++;
                continue;
            }
            $mutation_id = Vernal_Internal_Link_Inserter::new_mutation_id();
            $ins = Vernal_Internal_Link_Inserter::insert_link($content, array(
                'phrase'            => $phrase,
                'target_wp_post_id' => $target_id,
                'permalink'         => $permalink,
                'mutation_id'       => $mutation_id,
            ));
            if (empty($ins['inserted'])) {
                $out['skipped']++;
                continue;
            }
            $content = $ins['content'];
            $edge_role = isset($row['edge_role']) ? sanitize_key((string) $row['edge_role']) : 'other_relevant';
            $entry = array(
                'id'                    => $mutation_id,
                'source_wp_post_id'     => (int) $post->ID,
                'target_wp_post_id'     => $target_id,
                'target_url'            => $permalink,
                'anchor'                => $phrase,
                'inserted_at'           => gmdate('c'),
                'run_id'                => $run_id,
                'strategy'              => $ledger_strategy,
                'edge_role'             => $edge_role,
                'link_score'            => $score,
                'primary_search_intent' => '',
            );
            $ledger[] = $entry;
            $this->push_recent_mutation($entry);
            $inserted_this_pass++;
            $out['linked']++;
        }

        if ($inserted_this_pass > 0) {
            $this->save_post_content($post->ID, $content, $ledger);
            $this->refresh_graph_stats_for_post($post->ID, true);
            if (!empty($entry['target_wp_post_id'])) {
                $this->bump_inbound_cache((int) $entry['target_wp_post_id']);
            }
        } else {
            $this->refresh_graph_stats_for_post($post->ID, false);
        }
        return $out;
    }

    private function process_inbound_backfill($new_post, $settings, $run_id, $dest_id, $trigger) {
        $out = array('linked' => 0, 'errors' => 0);
        $max = (int) $settings['max_inbound_source_mutations_per_new_target'];
        if ($max < 1) {
            return $out;
        }

        $stubs = $this->build_candidate_stubs($settings, 40, array( (int) $new_post->ID ));
        $payload = array(
            'mode'                      => 'inbound_sources',
            'social_destination_id'     => (int) $dest_id,
            'strategy'                  => 'new_post_inbound_backfill',
            'limit'                     => $max * 3,
            'min_score'                 => (float) $settings['min_relevance_score'],
            'target'                    => $this->post_to_match_source($new_post),
            'candidate_stubs'           => $stubs,
            'rag_excluded_category_ids' => $this->rag_excluded_category_ids_for_match(),
        );
        $resp = Vernal_Backend_API::request('plugin/internal-links/match', array(
            'method' => 'POST',
            'body'   => $payload,
            'timeout'=> 60,
        ));
        if (is_wp_error($resp)) {
            $out['errors']++;
            return $out;
        }
        $results = isset($resp['results']) && is_array($resp['results']) ? $resp['results'] : array();
        $done = 0;
        $target_permalink = get_permalink($new_post);

        foreach ($results as $row) {
            if ($done >= $max) {
                break;
            }
            $source_id = isset($row['source_wp_post_id']) ? (int) $row['source_wp_post_id'] : 0;
            if ($source_id < 1 || $source_id === (int) $new_post->ID) {
                continue;
            }
            $source = get_post($source_id);
            if (!$source || !$this->is_post_eligible($source, $settings)) {
                continue;
            }
            if (!$this->is_valid_target((int) $new_post->ID, $settings, $source_id)) {
                continue;
            }
            $analysis = Vernal_Internal_Link_Inserter::analyze_internal_links($source->post_content);
            if ($analysis['vernal'] >= (int) $settings['max_vernal_links_per_post']
                || $analysis['total'] >= (int) $settings['max_total_internal_links_per_post']) {
                continue;
            }
            if (in_array((int) $new_post->ID, $analysis['target_ids'], true)) {
                continue;
            }

            $anchors = isset($row['anchors']) && is_array($row['anchors']) ? $row['anchors'] : array();
            // Prefer Machine anchors grounded in source; else try local grounding from target title/keyphrase
            $phrase = '';
            if ($anchors && !empty($anchors[0]['text'])) {
                $phrase = (string) $anchors[0]['text'];
            } else {
                $phrase = $this->local_ground_phrase($source->post_content, $new_post);
            }
            if ($phrase === '') {
                continue;
            }

            $mutation_id = Vernal_Internal_Link_Inserter::new_mutation_id();
            $ins = Vernal_Internal_Link_Inserter::insert_link($source->post_content, array(
                'phrase'            => $phrase,
                'target_wp_post_id' => (int) $new_post->ID,
                'permalink'         => $target_permalink,
                'mutation_id'       => $mutation_id,
            ));
            if (empty($ins['inserted'])) {
                continue;
            }
            $ledger = $this->get_ledger($source_id);
            $entry = array(
                'id'                 => $mutation_id,
                'source_wp_post_id'  => $source_id,
                'target_wp_post_id'  => (int) $new_post->ID,
                'target_url'         => $target_permalink,
                'anchor'             => $phrase,
                'inserted_at'        => gmdate('c'),
                'run_id'             => $run_id,
                'strategy'           => 'new_post_inbound_backfill',
            );
            $ledger[] = $entry;
            $this->save_post_content($source_id, $ins['content'], $ledger);
            $this->push_recent_mutation($entry);
            $done++;
            $out['linked']++;
        }
        return $out;
    }

    private function local_ground_phrase($content, $target_post) {
        $plain = wp_strip_all_tags($content);
        $title = get_the_title($target_post);
        $candidates = array();
        if ($title) {
            $words = preg_split('/\s+/', $title);
            if (count($words) >= 3) {
                $candidates[] = $title;
                $candidates[] = implode(' ', array_slice($words, 0, 6));
            }
        }
        foreach ($candidates as $c) {
            if ($c && stripos($plain, $c) !== false) {
                return $c;
            }
        }
        return '';
    }

    private function rag_excluded_category_ids_for_match() {
        if (class_exists('Vernal_Rag_Eligibility')) {
            return Vernal_Rag_Eligibility::get_excluded_category_ids();
        }
        return array();
    }

    private function build_outbound_match_payload($post, $settings, $dest_id, $analysis, $strategy, $limit) {
        $inbound_counts = $this->estimate_inbound_counts($settings);
        return array(
            'mode'                       => 'outbound_candidates',
            'social_destination_id'      => (int) $dest_id,
            'strategy'                   => $strategy,
            'limit'                      => (int) $limit,
            'min_score'                  => (float) $settings['min_relevance_score'],
            'source'                     => $this->post_to_match_source($post),
            'already_linked_target_ids'  => $analysis['target_ids'],
            'inbound_counts'             => $inbound_counts,
            'candidate_stubs'            => $this->build_candidate_stubs($settings, 40, array( (int) $post->ID )),
            'rag_excluded_category_ids'  => $this->rag_excluded_category_ids_for_match(),
        );
    }

    private function build_best_edge_payload($post, $settings, $dest_id, $analysis, $profile) {
        $inbound_counts = $this->estimate_inbound_counts($settings);
        $slots = $this->slots_from_ledger($post->ID);
        $ledger = $this->get_ledger($post->ID);
        $stats = $this->get_graph_stats($post->ID);
        $source = $this->post_to_match_source($post);
        $source['cluster_role'] = isset($stats['cluster_role']) ? (string) $stats['cluster_role'] : 'standalone';
        $source['topic_cluster_key'] = isset($stats['topic_cluster_key']) ? (string) $stats['topic_cluster_key'] : '';
        $source['cornerstone_wp_post_id'] = isset($stats['cornerstone_wp_post_id']) ? (int) $stats['cornerstone_wp_post_id'] : 0;
        $source['word_count'] = str_word_count(wp_strip_all_tags($post->post_content));
        $source['primary_search_intent'] = isset($stats['primary_search_intent']) ? (string) $stats['primary_search_intent'] : '';
        if (in_array((int) $post->ID, self::normalize_id_list($settings['pillar_post_ids']), true)) {
            $source['cluster_role'] = 'pillar';
        }
        return array(
            'mode'                       => 'best_missing_edge',
            'social_destination_id'      => (int) $dest_id,
            'min_score'                  => (float) $settings['min_relevance_score'],
            'source'                     => $source,
            'already_linked_target_ids'  => $analysis['target_ids'],
            'inbound_counts'             => $inbound_counts,
            'slots_filled'               => $slots,
            'used_anchor_texts'          => $this->used_anchor_texts_from_ledger($ledger),
            'soft_max'                   => (int) $profile['soft_max'],
            'vernal_links_out'           => (int) $analysis['vernal'],
            'candidate_stubs'            => $this->build_candidate_stubs($settings, 40, array( (int) $post->ID )),
            'graph_stats'                => $stats,
            'rag_excluded_category_ids'  => $this->rag_excluded_category_ids_for_match(),
        );
    }

    private function post_to_match_source($post) {
        $cats = wp_get_post_categories($post->ID);
        return array(
            'wp_post_id'            => (int) $post->ID,
            'title'                 => get_the_title($post),
            'excerpt'               => get_the_excerpt($post),
            'content_text'          => wp_strip_all_tags($post->post_content),
            'category_ids'          => array_map('intval', $cats),
            'permalink'             => get_permalink($post),
            'primary_keyphrase'     => '',
            'secondary_keyphrases'  => array(),
            'published_at'          => $post->post_date_gmt,
            'word_count'            => str_word_count(wp_strip_all_tags($post->post_content)),
        );
    }

    private function build_candidate_stubs($settings, $limit, $exclude_ids = array()) {
        $args = array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => $limit,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'has_password'        => false,
            'post__not_in'        => array_merge(
                self::normalize_id_list($settings['excluded_post_ids']),
                array_map('intval', $exclude_ids)
            ),
        );
        $q = new WP_Query($args);
        $stubs = array();
        foreach ($q->posts as $p) {
            if (!$this->is_post_eligible($p, $settings)) {
                continue;
            }
            $stubs[] = array(
                'wp_post_id'           => (int) $p->ID,
                'title'                => get_the_title($p),
                'excerpt'              => get_the_excerpt($p),
                'content_text'         => wp_strip_all_tags(wp_trim_words($p->post_content, 80)),
                'category_ids'         => array_map('intval', wp_get_post_categories($p->ID)),
                'permalink'            => get_permalink($p),
                'primary_keyphrase'    => '',
                'secondary_keyphrases' => array(),
                'published_at'         => $p->post_date_gmt,
            );
        }
        return $stubs;
    }

    private function estimate_inbound_counts($settings, $sample = 80) {
        // Derived graph observation: count Vernal target ids across a post sample.
        // Not authoritative; HTML + ledger remain source of truth for outbound.
        $counts = array();
        $q = new WP_Query(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => max(20, min(250, (int) $sample)),
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ));
        foreach ($q->posts as $pid) {
            $content = get_post_field('post_content', $pid);
            $a = Vernal_Internal_Link_Inserter::analyze_internal_links($content);
            foreach ($a['target_ids'] as $tid) {
                $tid = (int) $tid;
                if ($tid < 1) {
                    continue;
                }
                if (!isset($counts[$tid])) {
                    $counts[$tid] = 0;
                }
                $counts[$tid]++;
            }
        }
        return $counts;
    }

    public function get_graph_stats($post_id) {
        $raw = get_post_meta((int) $post_id, self::META_GRAPH, true);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_array($raw)) {
            return $raw;
        }
        return array(
            'cache_version'           => 1,
            'contextual_links_out'    => 0,
            'contextual_links_in'     => 0,
            'cornerstone_links_in'    => 0,
            'vernal_links_out'        => 0,
            'orphan_status'           => false,
            'link_health'             => 'new_unoptimized',
            'topic_cluster_key'       => '',
            'cluster_role'            => 'standalone',
            'cornerstone_wp_post_id'  => 0,
            'primary_search_intent'   => '',
            'anchor_texts_used'       => array(),
            'slots_filled_by_role'    => array(),
            'last_graph_evaluated_at' => '',
            'last_graph_mutated_at'   => '',
            'next_eligible_at'        => '',
        );
    }

    private function save_graph_stats($post_id, $stats) {
        update_post_meta((int) $post_id, self::META_GRAPH, wp_json_encode($stats));
    }

    public function wake_post($post_id, $reason = '') {
        $stats = $this->get_graph_stats($post_id);
        $stats['next_eligible_at'] = '';
        if (!empty($reason) && empty($stats['link_health'])) {
            $stats['link_health'] = 'new_unoptimized';
        }
        $this->save_graph_stats($post_id, $stats);
    }

    /**
     * Wake posts that share a category with the published article (cluster wake heuristic).
     */
    private function wake_related_cluster($post_id, $settings) {
        $cats = wp_get_post_categories((int) $post_id);
        if (!$cats) {
            $this->wake_post($post_id, 'publish');
            return;
        }
        $q = new WP_Query(array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => 40,
            'category__in'        => array_map('intval', $cats),
            'post__not_in'        => array((int) $post_id),
            'fields'              => 'ids',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
        ));
        $this->wake_post($post_id, 'publish');
        foreach ($q->posts as $pid) {
            $this->wake_post((int) $pid, 'cluster_wake');
        }
    }

    public function resolve_link_profile($post, $settings) {
        $wc = str_word_count(wp_strip_all_tags($post->post_content));
        $pillars = self::normalize_id_list($settings['pillar_post_ids']);
        $stats = $this->get_graph_stats($post->ID);
        $role = isset($stats['cluster_role']) ? (string) $stats['cluster_role'] : 'standalone';
        if (in_array((int) $post->ID, $pillars, true) || in_array($role, array('pillar', 'cornerstone'), true)) {
            $profile = array('profile' => 'pillar', 'soft_target' => 12, 'soft_max' => 999);
        } elseif ($wc < 1200) {
            $profile = array('profile' => 'short', 'soft_target' => 4, 'soft_max' => 8);
        } elseif ($wc < 2500) {
            $profile = array('profile' => 'standard', 'soft_target' => 6, 'soft_max' => 12);
        } else {
            $soft_target = max(5, (int) ($settings['soft_target_long_form'] ?? 8));
            $profile = array('profile' => 'long_form', 'soft_target' => $soft_target, 'soft_max' => 12);
        }
        update_post_meta((int) $post->ID, self::META_TARGETS, wp_json_encode($profile));
        return $profile;
    }

    public function slots_from_ledger($post_id) {
        $slots = array(
            'cornerstone_up'   => 0,
            'sibling'          => 0,
            'supporting_down'  => 0,
            'underlinked'      => 0,
            'other_relevant'   => 0,
        );
        foreach ($this->get_ledger($post_id) as $e) {
            $role = isset($e['edge_role']) ? (string) $e['edge_role'] : 'other_relevant';
            if (!isset($slots[$role])) {
                $role = 'other_relevant';
            }
            $slots[$role]++;
        }
        return $slots;
    }

    private function used_anchor_texts_from_ledger($ledger) {
        $out = array();
        foreach ((array) $ledger as $e) {
            if (!empty($e['anchor'])) {
                $out[] = strtolower((string) $e['anchor']);
            }
        }
        return $out;
    }

    private function is_generic_anchor($phrase) {
        $p = strtolower(trim((string) $phrase));
        return in_array($p, array('learn more', 'read more', 'click here', 'here', 'this article', 'this post'), true);
    }

    public function compute_link_health_label($post, $settings, $analysis, $inbound, $profile, $slots) {
        $vernal = (int) $analysis['vernal'];
        $soft_target = (int) $profile['soft_target'];
        $soft_max = (int) $profile['soft_max'];
        $has_cs = !empty($slots['cornerstone_up']);
        $stats = $this->get_graph_stats($post->ID);
        $needs_cs = !empty($stats['cornerstone_wp_post_id']) && (int) $stats['cornerstone_wp_post_id'] !== (int) $post->ID;
        $age = time() - strtotime($post->post_date_gmt . ' UTC');
        $is_new = $age < (3 * DAY_IN_SECONDS);

        if ($soft_max < 999 && $vernal >= $soft_max) {
            return 'overlinked';
        }
        if ($is_new && $vernal === 0) {
            return 'new_unoptimized';
        }
        if ((int) $inbound === 0 && $vernal === 0) {
            return 'orphan';
        }
        if ($needs_cs && !$has_cs) {
            return 'missing_cornerstone';
        }
        if ((int) $inbound <= 1 && $soft_target >= 5) {
            return 'underlinked';
        }
        if ($vernal < max(1, (int) floor($soft_target / 2))) {
            return 'underlinked';
        }
        return 'healthy';
    }

    public function refresh_graph_stats_for_post($post_id, $mutated = false) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        $settings = self::get_settings();
        $analysis = Vernal_Internal_Link_Inserter::analyze_internal_links($post->post_content);
        $stats = $this->get_graph_stats($post_id);
        $profile = $this->resolve_link_profile($post, $settings);
        $slots = $this->slots_from_ledger($post_id);
        $inbound = isset($stats['contextual_links_in']) ? (int) $stats['contextual_links_in'] : 0;
        $stats['cache_version'] = 1;
        $stats['computed_at'] = gmdate('c');
        $stats['contextual_links_out'] = (int) $analysis['total'];
        $stats['vernal_links_out'] = (int) $analysis['vernal'];
        $stats['orphan_status'] = ($analysis['vernal'] === 0 && $inbound === 0);
        $stats['anchor_texts_used'] = $this->used_anchor_texts_from_ledger($this->get_ledger($post_id));
        $stats['slots_filled_by_role'] = $slots;
        $stats['link_health'] = $this->compute_link_health_label($post, $settings, $analysis, $inbound, $profile, $slots);
        $stats['last_graph_evaluated_at'] = gmdate('c');
        if ($mutated) {
            $stats['last_graph_mutated_at'] = gmdate('c');
        }
        $cooldown_days = max(1, (int) ($settings['healthy_cooldown_days'] ?? 7));
        if ($stats['link_health'] === 'healthy' || $stats['link_health'] === 'overlinked') {
            $stats['next_eligible_at'] = gmdate('c', time() + ($cooldown_days * DAY_IN_SECONDS));
        } else {
            $stats['next_eligible_at'] = '';
        }
        $this->save_graph_stats($post_id, $stats);
    }

    private function bump_inbound_cache($target_id) {
        $target_id = (int) $target_id;
        if ($target_id < 1) {
            return;
        }
        $stats = $this->get_graph_stats($target_id);
        $stats['contextual_links_in'] = (int) ($stats['contextual_links_in'] ?? 0) + 1;
        $stats['computed_at'] = gmdate('c');
        $this->save_graph_stats($target_id, $stats);
        $this->refresh_graph_stats_for_post($target_id, false);
    }

    /**
     * Collect site link-health rows for admin table (derived cache).
     *
     * @return array
     */
    public function collect_link_health_rows($limit = 20) {
        $settings = self::get_settings();
        $q = new WP_Query(array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => 60,
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ));
        $rows = array();
        foreach ($q->posts as $post) {
            if (!$this->is_post_eligible($post, $settings)) {
                continue;
            }
            $stats = $this->get_graph_stats($post->ID);
            $analysis = Vernal_Internal_Link_Inserter::analyze_internal_links($post->post_content);
            $inbound = (int) ($stats['contextual_links_in'] ?? 0);
            $importance = 0.45;
            $role = (string) ($stats['cluster_role'] ?? 'standalone');
            if ($role === 'pillar') {
                $importance = 1.0;
            } elseif ($role === 'cornerstone') {
                $importance = 0.92;
            } elseif ($role === 'secondary') {
                $importance = 0.62;
            }
            $under = max(0, 3 - $inbound);
            $rows[] = array(
                'post_id'    => (int) $post->ID,
                'title'      => get_the_title($post),
                'cluster'    => (string) ($stats['topic_cluster_key'] ?? ''),
                'role'       => $role,
                'health'     => (string) ($stats['link_health'] ?? 'new_unoptimized'),
                'links_in'   => $inbound,
                'links_out'  => (int) $analysis['total'],
                'vernal_out' => (int) $analysis['vernal'],
                'gap'        => $this->humanize_gap($stats, $analysis, $inbound),
                'sort'       => $under * $importance,
            );
        }
        usort($rows, function ($a, $b) {
            return $b['sort'] <=> $a['sort'];
        });
        return array_slice($rows, 0, max(5, (int) $limit));
    }

    private function humanize_gap($stats, $analysis, $inbound) {
        $health = (string) ($stats['link_health'] ?? '');
        if ($health === 'missing_cornerstone') {
            return __('Missing link up to main topic page', 'vernal-contentum');
        }
        if ($health === 'underlinked' || $inbound <= 1) {
            return sprintf(__('Needs more inbound links (has %d)', 'vernal-contentum'), (int) $inbound);
        }
        if ($health === 'orphan') {
            return __('No internal links in or out yet', 'vernal-contentum');
        }
        if ($health === 'new_unoptimized') {
            return __('New article — not optimized yet', 'vernal-contentum');
        }
        if ($health === 'overlinked') {
            return __('At or over soft link maximum', 'vernal-contentum');
        }
        if ($health === 'healthy') {
            return __('Graph looks healthy', 'vernal-contentum');
        }
        return __('Review linking opportunities', 'vernal-contentum');
    }

    public function is_valid_target($target_id, $settings, $source_id = 0) {
        $target_id = (int) $target_id;
        if ($target_id < 1 || $target_id === (int) $source_id) {
            return false;
        }
        $post = get_post($target_id);
        if (!$post || !$this->is_post_eligible($post, $settings)) {
            return false;
        }
        $permalink = get_permalink($target_id);
        return (bool) $permalink;
    }

    public function get_ledger($post_id) {
        $raw = get_post_meta($post_id, self::META_LINKS, true);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : array();
        }
        if (is_array($raw)) {
            return $raw;
        }
        return array();
    }

    private function save_post_content($post_id, $content, $ledger) {
        $GLOBALS['vernal_il_mutating'] = true;
        try {
            update_post_meta($post_id, self::META_LINKS, wp_json_encode(array_values($ledger)));
            wp_update_post(array(
                'ID'           => $post_id,
                'post_content' => $content,
            ));
            // Refresh stamps after our write so we don't re-queue
            $post = get_post($post_id);
            if ($post) {
                update_post_meta($post_id, self::META_PASS_AT, gmdate('c'));
                update_post_meta($post_id, self::META_SRC_MOD, $post->post_modified_gmt);
                update_post_meta(
                    $post_id,
                    self::META_FP,
                    Vernal_Internal_Link_Inserter::content_fingerprint($post->post_content)
                );
            }
        } finally {
            $GLOBALS['vernal_il_mutating'] = false;
        }
    }

    private function push_recent_mutation($entry) {
        $recent = get_option(self::OPTION_RECENT, array());
        if (!is_array($recent)) {
            $recent = array();
        }
        array_unshift($recent, $entry);
        $recent = array_slice($recent, 0, 50);
        update_option(self::OPTION_RECENT, $recent, false);
    }

    /**
     * Undo one mutation on a post.
     */
    public function undo_mutation($post_id, $mutation_id) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('missing', 'Post not found');
        }
        $result = Vernal_Internal_Link_Inserter::unwrap_by_mutation_id($post->post_content, $mutation_id);
        if (empty($result['unwrapped'])) {
            return new WP_Error('not_found', 'Mutation not found in content');
        }
        $ledger = $this->get_ledger($post_id);
        $removed_target = 0;
        foreach ($ledger as $e) {
            if (isset($e['id']) && $e['id'] === $mutation_id) {
                $removed_target = (int) ($e['target_wp_post_id'] ?? 0);
                break;
            }
        }
        $ledger = array_values(array_filter($ledger, function ($e) use ($mutation_id) {
            return !(isset($e['id']) && $e['id'] === $mutation_id);
        }));
        $this->save_post_content($post_id, $result['content'], $ledger);
        $this->refresh_graph_stats_for_post($post_id, true);
        $this->wake_post($post_id, 'undo');
        if ($removed_target > 0) {
            $tstats = $this->get_graph_stats($removed_target);
            $tstats['contextual_links_in'] = max(0, (int) ($tstats['contextual_links_in'] ?? 0) - 1);
            $this->save_graph_stats($removed_target, $tstats);
            $this->refresh_graph_stats_for_post($removed_target, false);
        }
        return true;
    }

    public function enqueue_post($post_id) {
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return array('enqueued' => false, 'reason' => 'disabled');
        }
        if (!$this->is_post_eligible($post_id, $settings)) {
            return array('enqueued' => false, 'reason' => 'ineligible');
        }
        // Clear pass stamp so next tick / immediate run picks it up
        delete_post_meta($post_id, self::META_PASS_AT);
        $this->wake_post((int) $post_id, 'enqueue');
        $this->wake_related_cluster((int) $post_id, $settings);
        return array('enqueued' => true, 'post_id' => (int) $post_id);
    }

    public function maybe_enqueue_on_save($post_id, $post, $update) {
        if (!empty($GLOBALS['vernal_il_mutating'])) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!$post || $post->post_status !== 'publish') {
            return;
        }
        $this->enqueue_post($post_id);
    }

    public function handle_run_now() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Forbidden', 'vernal-contentum'));
        }
        check_admin_referer('vernal_il_run_now');
        $summary = $this->run_pass('manual_run');
        $redirect = add_query_arg(
            array(
                'page'           => 'vernal-contentum-internal-links',
                'vernal_il_done' => 1,
                'status'         => isset($summary['status']) ? $summary['status'] : '',
            ),
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_undo() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Forbidden', 'vernal-contentum'));
        }
        check_admin_referer('vernal_il_undo');
        $post_id = isset($_GET['post_id']) ? (int) $_GET['post_id'] : 0;
        $mutation_id = isset($_GET['mutation_id']) ? sanitize_text_field(wp_unslash($_GET['mutation_id'])) : '';
        if ($post_id && $mutation_id) {
            $this->undo_mutation($post_id, $mutation_id);
        }
        wp_safe_redirect(add_query_arg(
            array('page' => 'vernal-contentum-internal-links', 'vernal_il_undone' => 1),
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Forbidden', 'vernal-contentum'));
        }
        check_admin_referer('vernal_il_save_settings');
        $defaults = self::default_settings();
        $input = isset($_POST['vernal_il']) && is_array($_POST['vernal_il']) ? wp_unslash($_POST['vernal_il']) : array();
        $out = $defaults;
        $out['enabled'] = !empty($input['enabled']) ? 1 : 0;
        $sched = isset($input['schedule']) ? sanitize_text_field($input['schedule']) : 'hourly';
        $out['schedule'] = in_array($sched, array('hourly', 'twicedaily', 'daily', 'weekly'), true) ? $sched : 'hourly';
        $out['max_new_outbound_links_per_source'] = 1; // v1 hard rule
        $out['max_inbound_source_mutations_per_new_target'] = max(0, (int) ($input['max_inbound_source_mutations_per_new_target'] ?? 1));
        $out['batch_sources_per_tick'] = max(1, min(50, (int) ($input['batch_sources_per_tick'] ?? 25)));
        $out['min_relevance_score'] = max(0, min(1, (float) ($input['min_relevance_score'] ?? 0.35)));
        $out['prefer_same_category'] = !empty($input['prefer_same_category']) ? 1 : 0;
        $out['orphan_repair_after_days'] = max(0, (int) ($input['orphan_repair_after_days'] ?? 14));
        $out['min_word_count'] = max(0, (int) ($input['min_word_count'] ?? 120));
        $out['max_vernal_links_per_post'] = max(0, (int) ($input['max_vernal_links_per_post'] ?? 12));
        $out['max_total_internal_links_per_post'] = max(0, (int) ($input['max_total_internal_links_per_post'] ?? 12));
        $out['soft_target_long_form'] = max(3, min(20, (int) ($input['soft_target_long_form'] ?? 8)));
        $out['healthy_cooldown_days'] = max(1, min(60, (int) ($input['healthy_cooldown_days'] ?? 7)));
        $out['pillar_post_ids'] = self::normalize_id_list($input['pillar_post_ids'] ?? '');
        $out['excluded_category_ids'] = self::normalize_id_list($input['excluded_category_ids'] ?? '');
        $out['excluded_post_ids'] = self::normalize_id_list($input['excluded_post_ids'] ?? '');
        $out['social_destination_id'] = max(0, (int) ($input['social_destination_id'] ?? 0));
        $out['process_new_and_modified'] = !empty($input['process_new_and_modified']) ? 1 : 0;
        $out['orphan_repair_enabled'] = !empty($input['orphan_repair_enabled']) ? 1 : 0;
        update_option(self::OPTION_SETTINGS, $out, false);
        delete_option('vernal_il_cron_schedule');
        $this->ensure_cron_scheduled();
        wp_safe_redirect(add_query_arg(
            array('page' => 'vernal-contentum-internal-links', 'settings-updated' => 1),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Render one settings row with optional help + impact text.
     *
     * @param string $label
     * @param string $field_html
     * @param string $help Plain-language description.
     * @param string $impact Higher/lower guidance (optional).
     */
    private function render_settings_row($label, $field_html, $help = '', $impact = '') {
        echo '<tr>';
        echo '<th scope="row"><label>' . esc_html($label) . '</label></th>';
        echo '<td>';
        echo $field_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by caller
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
        if ($impact !== '') {
            echo '<p class="description" style="margin-top:6px;"><em>' . esc_html($impact) . '</em></p>';
        }
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Human label for a stored strategy code.
     *
     * @param string $strategy
     * @return string
     */
    private function humanize_strategy_label($strategy) {
        $map = array(
            'new_post_outbound'         => __('New article linked out to others', 'vernal-contentum'),
            'new_post_inbound_backfill' => __('Older article linked to new one', 'vernal-contentum'),
            'orphan_repair'             => __('Catch-up on article with no links yet', 'vernal-contentum'),
            'manual_run'                => __('Added during a manual run', 'vernal-contentum'),
            'best_missing_edge'         => __('Highest-value missing link', 'vernal-contentum'),
        );
        return isset($map[$strategy]) ? $map[$strategy] : (string) $strategy;
    }

    private function humanize_health_label($health) {
        $map = array(
            'healthy'             => __('Healthy', 'vernal-contentum'),
            'underlinked'         => __('Underlinked', 'vernal-contentum'),
            'missing_cornerstone' => __('Missing cornerstone', 'vernal-contentum'),
            'overlinked'          => __('Overlinked', 'vernal-contentum'),
            'intent_collision'    => __('Intent collision', 'vernal-contentum'),
            'orphan'              => __('Orphan', 'vernal-contentum'),
            'new_unoptimized'     => __('New / unoptimized', 'vernal-contentum'),
        );
        return isset($map[$health]) ? $map[$health] : (string) $health;
    }

    /**
     * Human label for last-run status.
     *
     * @param string $status
     * @return string
     */
    private function humanize_run_status($status) {
        $map = array(
            'completed'     => __('Finished successfully', 'vernal-contentum'),
            'running'       => __('Still running', 'vernal-contentum'),
            'error'         => __('Stopped with errors', 'vernal-contentum'),
            'disabled'      => __('Automatic linking is turned off', 'vernal-contentum'),
            'skipped_locked'=> __('Skipped — another run was already in progress', 'vernal-contentum'),
        );
        return isset($map[$status]) ? $map[$status] : (string) $status;
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = self::get_settings();
        $last = get_option(self::OPTION_LAST_RUN, array());
        $recent = get_option(self::OPTION_RECENT, array());
        if (!is_array($recent)) {
            $recent = array();
        }
        $lock = get_option(self::OPTION_LOCK, null);
        $schedule_labels = array(
            'hourly'     => __('Every hour', 'vernal-contentum'),
            'twicedaily' => __('Twice a day', 'vernal-contentum'),
            'daily'      => __('Once a day', 'vernal-contentum'),
            'weekly'     => __('Once a week', 'vernal-contentum'),
        );
        $dest_missing = empty($settings['social_destination_id']);
        ?>
        <div class="wrap vernal-il-settings">
            <h1><?php esc_html_e('Article Linking', 'vernal-contentum'); ?></h1>
            <p class="description" style="font-size:14px;max-width:820px;">
                <?php esc_html_e('This tool adds helpful links inside your blog posts — pointing readers to other related articles on your site. It runs on a schedule (or when you click Run now). It does not change related-news blocks, show pages, or SEO plugin settings.', 'vernal-contentum'); ?>
            </p>

            <?php if ($dest_missing) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php esc_html_e('Setup needed:', 'vernal-contentum'); ?></strong>
                        <?php esc_html_e('Enter your Vernal WordPress site ID below before automatic linking can work. Ask your Vernal admin or check WordPress destinations in Vernal if you are not sure of the number.', 'vernal-contentum'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['settings-updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'vernal-contentum'); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['vernal_il_done'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Manual run finished.', 'vernal-contentum'); ?> <?php echo esc_html(isset($_GET['status']) ? (string) $_GET['status'] : ''); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['vernal_il_undone'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('That link was removed. The text in the article was kept.', 'vernal-contentum'); ?></p></div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;margin:20px 0;max-width:920px;">
                <h2 style="margin-top:0;"><?php esc_html_e('Latest activity', 'vernal-contentum'); ?></h2>
                <?php if (is_array($last) && !empty($last['run_id'])) : ?>
                    <p style="font-size:14px;">
                        <strong><?php esc_html_e('Result:', 'vernal-contentum'); ?></strong>
                        <?php echo esc_html($this->humanize_run_status((string) ($last['status'] ?? ''))); ?>
                    </p>
                    <ul style="list-style:disc;margin-left:18px;font-size:14px;">
                        <li><?php echo esc_html(sprintf(__('Articles checked: %d', 'vernal-contentum'), (int) ($last['scanned'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(__('Links added: %d', 'vernal-contentum'), (int) ($last['linked'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(__('Skipped (no good match or already linked): %d', 'vernal-contentum'), (int) ($last['skipped'] ?? 0))); ?></li>
                        <li><?php echo esc_html(sprintf(__('Errors: %d', 'vernal-contentum'), (int) ($last['errors'] ?? 0))); ?></li>
                    </ul>
                    <p class="description">
                        <?php echo esc_html($last['completed_at'] ?? $last['started_at'] ?? ''); ?>
                        <?php if (!empty($last['run_id'])) : ?>
                            <span> · <?php echo esc_html($last['run_id']); ?></span>
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p><?php esc_html_e('No automatic or manual runs yet.', 'vernal-contentum'); ?></p>
                <?php endif; ?>
                <?php if (is_array($lock) && !empty($lock['lease_expires_at']) && (int) $lock['lease_expires_at'] > time()) : ?>
                    <p><em><?php esc_html_e('A run is in progress right now. Wait for it to finish before starting another.', 'vernal-contentum'); ?></em></p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px;">
                    <input type="hidden" name="action" value="vernal_il_run_now" />
                    <?php wp_nonce_field('vernal_il_run_now'); ?>
                    <?php submit_button(__('Run linking now', 'vernal-contentum'), 'primary', 'submit', false); ?>
                    <span class="description" style="margin-left:8px;"><?php esc_html_e('Use this to test settings or catch up after a busy publishing day.', 'vernal-contentum'); ?></span>
                </form>
            </div>

            <?php $health_rows = $this->collect_link_health_rows(20); ?>
            <div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;margin:20px 0;max-width:1100px;">
                <h2 style="margin-top:0;"><?php esc_html_e('Site link health', 'vernal-contentum'); ?></h2>
                <p class="description" style="max-width:820px;">
                    <?php esc_html_e('What Vernal thinks needs attention. Inbound counts are cached graph observations (recomputed nightly), not a second source of truth.', 'vernal-contentum'); ?>
                </p>
                <table class="widefat striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Article', 'vernal-contentum'); ?></th>
                            <th><?php esc_html_e('Cluster', 'vernal-contentum'); ?></th>
                            <th><?php esc_html_e('Role', 'vernal-contentum'); ?></th>
                            <th><?php esc_html_e('Health', 'vernal-contentum'); ?></th>
                            <th><?php esc_html_e('Links in / out', 'vernal-contentum'); ?></th>
                            <th><?php esc_html_e('Gap', 'vernal-contentum'); ?></th>
                            <th><?php esc_html_e('Action', 'vernal-contentum'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$health_rows) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No articles scored yet. Run linking once to populate health.', 'vernal-contentum'); ?></td></tr>
                    <?php else : foreach ($health_rows as $hr) : ?>
                        <tr>
                            <td><?php echo esc_html($hr['title']); ?></td>
                            <td><code><?php echo esc_html($hr['cluster'] !== '' ? $hr['cluster'] : '—'); ?></code></td>
                            <td><?php echo esc_html($hr['role']); ?></td>
                            <td><?php echo esc_html($this->humanize_health_label($hr['health'])); ?></td>
                            <td><?php echo esc_html((int) $hr['links_in'] . ' / ' . (int) $hr['links_out']); ?></td>
                            <td><?php echo esc_html($hr['gap']); ?></td>
                            <td><a href="<?php echo esc_url(get_edit_post_link((int) $hr['post_id'], 'raw')); ?>"><?php esc_html_e('Edit', 'vernal-contentum'); ?></a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vernal_il_save_settings" />
                <?php wp_nonce_field('vernal_il_save_settings'); ?>

                <h2><?php esc_html_e('Site connection', 'vernal-contentum'); ?></h2>
                <p class="description" style="max-width:820px;margin-bottom:12px;">
                    <?php esc_html_e('Tells Vernal which WordPress site this is, so it only links articles from this site — not another customer\'s site.', 'vernal-contentum'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <?php
                    ob_start();
                    ?>
                    <input type="number" class="small-text" name="vernal_il[social_destination_id]" value="<?php echo (int) $settings['social_destination_id']; ?>" min="0" />
                    <?php
                    $field = ob_get_clean();
                    $this->render_settings_row(
                        __('Vernal WordPress site ID', 'vernal-contentum'),
                        $field,
                        __('The numeric ID for this site in Vernal (WordPress destinations). Required for linking to work.', 'vernal-contentum'),
                        __('If this is 0, linking will not run. Your Vernal admin can look up the correct ID.', 'vernal-contentum')
                    );
                    ?>
                </table>

                <h2><?php esc_html_e('Automatic schedule', 'vernal-contentum'); ?></h2>
                <p class="description" style="max-width:820px;margin-bottom:12px;">
                    <?php esc_html_e('How often the site checks articles and adds links without you clicking anything.', 'vernal-contentum'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <?php
                    ob_start();
                    ?>
                    <label>
                        <input type="checkbox" name="vernal_il[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?> />
                        <?php esc_html_e('Turn on automatic linking', 'vernal-contentum'); ?>
                    </label>
                    <?php
                    $this->render_settings_row(
                        __('Automatic linking', 'vernal-contentum'),
                        ob_get_clean(),
                        __('When checked, WordPress runs linking on the schedule below.', 'vernal-contentum'),
                        __('Off = only runs when you click “Run linking now”.', 'vernal-contentum')
                    );

                    ob_start();
                    ?>
                    <select name="vernal_il[schedule]">
                        <?php foreach ($schedule_labels as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['schedule'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php
                    $this->render_settings_row(
                        __('How often to run', 'vernal-contentum'),
                        ob_get_clean(),
                        __('How frequently WordPress looks for new linking opportunities.', 'vernal-contentum'),
                        __('Publishing many articles per day? Choose “Every hour”. A quiet site can use “Once a day”.', 'vernal-contentum')
                    );

                    ob_start();
                    ?>
                    <input type="number" class="small-text" name="vernal_il[batch_sources_per_tick]" value="<?php echo (int) $settings['batch_sources_per_tick']; ?>" min="1" max="50" />
                    <?php
                    $this->render_settings_row(
                        __('Articles checked each run', 'vernal-contentum'),
                        ob_get_clean(),
                        __('Maximum number of articles WordPress will review in one pass.', 'vernal-contentum'),
                        __('Higher = more articles processed per run (good for 50–100+ posts/day). Lower = lighter on the server but slower catch-up.', 'vernal-contentum')
                    );

                    ob_start();
                    ?>
                    <label>
                        <input type="checkbox" name="vernal_il[process_new_and_modified]" value="1" <?php checked(!empty($settings['process_new_and_modified'])); ?> />
                        <?php esc_html_e('Include newly published and recently edited articles', 'vernal-contentum'); ?>
                    </label>
                    <?php
                    $this->render_settings_row(
                        __('New and edited articles', 'vernal-contentum'),
                        ob_get_clean(),
                        __('Prioritizes fresh content and real edits. Ignores changes made only by this linking tool.', 'vernal-contentum'),
                        __('Leave on for active publishing. Turn off only if you want linking to focus on older catch-up.', 'vernal-contentum')
                    );
                    ?>
                </table>

                <h2><?php esc_html_e('Linking goal', 'vernal-contentum'); ?></h2>
                <p class="description" style="max-width:820px;margin-bottom:12px;">
                    <?php esc_html_e('Soft targets guide health status. The system still only adds a link when it clears relevance gates — it will not fill mediocre links to hit a number. Each run adds at most one link per article.', 'vernal-contentum'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <?php
                    ob_start();
                    ?>
                    <input type="number" class="small-text" name="vernal_il[soft_target_long_form]" value="<?php echo (int) ($settings['soft_target_long_form'] ?? 8); ?>" min="3" max="20" />
                    <?php
                    $this->render_settings_row(
                        __('Long article soft target', 'vernal-contentum'),
                        ob_get_clean(),
                        __('Recommended number of contextual links for longer posts (about 2,500+ words). Default 8.', 'vernal-contentum'),
                        __('This is a goal for health reporting, not a forced quota.', 'vernal-contentum')
                    );

                    ob_start();
                    ?>
                    <input type="number" class="small-text" name="vernal_il[healthy_cooldown_days]" value="<?php echo (int) ($settings['healthy_cooldown_days'] ?? 7); ?>" min="1" max="60" />
                    <?php
                    $this->render_settings_row(
                        __('Healthy article cooldown (days)', 'vernal-contentum'),
                        ob_get_clean(),
                        __('After an article looks healthy, wait this many days before reconsidering it (unless it is woken by a related new post or an edit).', 'vernal-contentum'),
                        __('Higher = less churn on mature pages. Lower = more frequent rechecks.', 'vernal-contentum')
                    );

                    ob_start();
                    ?>
                    <input type="text" class="regular-text" name="vernal_il[pillar_post_ids]" value="<?php echo esc_attr(implode(',', $settings['pillar_post_ids'] ?? array())); ?>" placeholder="101, 202" />
                    <?php
                    $this->render_settings_row(
                        __('Pillar / authority post IDs', 'vernal-contentum'),
                        ob_get_clean(),
                        __('Comma-separated WordPress post IDs for main topic pages. These get higher destination importance and a higher soft link allowance.', 'vernal-contentum'),
                        __('Prefer linking supporting articles up to these pages.', 'vernal-contentum')
                    );
                    ?>
                </table>

                <h2><?php esc_html_e('How many links to add', 'vernal-contentum'); ?></h2>
                <p class="description" style="max-width:820px;margin-bottom:12px;">
                    <?php esc_html_e('Each article receives at most one new outbound link per run. Soft maximums stop linking when a page is already well connected.', 'vernal-contentum'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <?php
                    ob_start();
                    ?>
                    <input type="number" class="small-text" value="1" min="1" max="1" disabled="disabled" />
                    <input type="hidden" name="vernal_il[max_new_outbound_links_per_source]" value="1" />
                    <?php
                    $this->render_settings_row(
                        __('New links added to each article (per run)', 'vernal-contentum'),
                        ob_get_clean(),
                        __('Fixed at 1 in this version so the graph can reassess after every insert.', 'vernal-contentum'),
                        __('Hourly schedule + one edge/pass is the intended pacing.', 'vernal-contentum')
                    );

                    ob_start();
                    ?>
                    <input type="number" class="small-text" name="vernal_il[max_inbound_source_mutations_per_new_target]" value="<?php echo (int) $settings['max_inbound_source_mutations_per_new_target']; ?>" min="0" max="20" />
                    <?php
                    $this->render_settings_row(
                        __('Older articles updated for each new post', 'vernal-contentum'),
                        ob_get_clean(),
                        __('When something new is published, up to this many older articles may get a link pointing to the new post.', 'vernal-contentum'),
                        __('Higher = new posts get more backlinks from old content quickly. Lower = fewer edits to existing posts. 1 is usually enough.', 'vernal-contentum')
                    );
                    ?>
                </table>

                <h2><?php esc_html_e('Match quality', 'vernal-contentum'); ?></h2>
                <p class="description" style="max-width:820px;margin-bottom:12px;">
                    <?php esc_html_e('Controls how picky the system is about which articles are “related enough” to link.', 'vernal-contentum'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <?php
                    ob_start();
                    ?>
                    <input type="number" step="0.01" min="0" max="1" class="small-text" name="vernal_il[min_relevance_score]" value="<?php echo esc_attr($settings['min_relevance_score']); ?>" />
                    <?php
                    $this->render_settings_row(
                        __('How closely related articles must be', 'vernal-contentum'),
                        ob_get_clean(),
                        __('Score from 0 to 1. Only pairs at or above this score are considered.', 'vernal-contentum'),
                        __('Higher (e.g. 0.45) = fewer links, but very on-topic. Lower (e.g. 0.30) = more links, but some may feel loosely related. Default 0.35 is balanced.', 'vernal-contentum')
                    );

                    ob_start();
                    ?>
                    <label>
                        <input type="checkbox" name="vernal_il[prefer_same_category]" value="1" <?php checked(!empty($settings['prefer_same_category'])); ?> />
                        <?php esc_html_e('Prefer linking articles in the same category', 'vernal-contentum'); ?>
                    </label>
                    <?php
                    $this->render_settings_row(
                        __('Same category preference', 'vernal-contentum'),
                        ob_get_clean(),
                        __('When two articles score similarly, favor the one in the same WordPress category.', 'vernal-contentum'),
                        __('On = tighter topical clusters. Off = purely topic-based matching across categories.', 'vernal-contentum')
                    );
                    ?>
                </table>

                <h2><?php esc_html_e('Catch-up for older articles', 'vernal-contentum'); ?></h2>
                <p class="description" style="max-width:820px;margin-bottom:12px;">
                    <?php esc_html_e('Finds published articles that still have no links added by this tool and tries again.', 'vernal-contentum'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <?php
                    ob_start();
                    ?>
                    <label style="display:inline-block;margin-right:12px;">
                        <input type="checkbox" name="vernal_il[orphan_repair_enabled]" value="1" <?php checked(!empty($settings['orphan_repair_enabled'])); ?> />
                        <?php esc_html_e('Turn on catch-up for articles with no links yet', 'vernal-contentum'); ?>
                    </label>
                    <label>
                        <?php esc_html_e('Wait at least', 'vernal-contentum'); ?>
                        <input type="number" class="small-text" name="vernal_il[orphan_repair_after_days]" value="<?php echo (int) $settings['orphan_repair_after_days']; ?>" min="0" />
                        <?php esc_html_e('days after publish', 'vernal-contentum'); ?>
                    </label>
                    <?php
                    $this->render_settings_row(
                        __('Orphan catch-up', 'vernal-contentum'),
                        ob_get_clean(),
                        __('Re-checks older articles that never received a Vernal link.', 'vernal-contentum'),
                        __('More days = less rework on brand-new posts. Fewer days = faster catch-up on articles that were missed.', 'vernal-contentum')
                    );
                    ?>
                </table>

                <h2><?php esc_html_e('Articles to skip', 'vernal-contentum'); ?></h2>
                <p class="description" style="max-width:820px;margin-bottom:12px;">
                    <?php esc_html_e('Optional block list. Show landing pages and non-articles are already skipped automatically.', 'vernal-contentum'); ?>
                </p>
                <table class="form-table" role="presentation">
                    <?php
                    ob_start();
                    ?>
                    <input type="text" class="regular-text" name="vernal_il[excluded_category_ids]" value="<?php echo esc_attr(implode(',', $settings['excluded_category_ids'])); ?>" placeholder="12, 45" />
                    <?php
                    $this->render_settings_row(
                        __('Excluded category IDs', 'vernal-contentum'),
                        ob_get_clean(),
                        __('Comma-separated WordPress category IDs. Articles in these categories are never used as source or target.', 'vernal-contentum'),
                        __('Example: exclude press releases or legal pages you never want auto-linked.', 'vernal-contentum')
                    );

                    ob_start();
                    ?>
                    <input type="text" class="regular-text" name="vernal_il[excluded_post_ids]" value="<?php echo esc_attr(implode(',', $settings['excluded_post_ids'])); ?>" placeholder="101, 202" />
                    <?php
                    $this->render_settings_row(
                        __('Excluded post IDs', 'vernal-contentum'),
                        ob_get_clean(),
                        __('Comma-separated WordPress post IDs to never touch.', 'vernal-contentum'),
                        __('Use for cornerstone pages or posts you edit by hand only.', 'vernal-contentum')
                    );
                    ?>
                </table>

                <details style="max-width:920px;margin:24px 0 12px;">
                    <summary style="cursor:pointer;font-size:14px;font-weight:600;">
                        <?php esc_html_e('Advanced limits (optional)', 'vernal-contentum'); ?>
                    </summary>
                    <p class="description" style="margin:12px 0;">
                        <?php esc_html_e('Most sites can leave these at the defaults. They prevent any single article from becoming over-linked.', 'vernal-contentum'); ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <?php
                        ob_start();
                        ?>
                        <input type="number" class="small-text" name="vernal_il[min_word_count]" value="<?php echo (int) $settings['min_word_count']; ?>" min="0" />
                        <?php
                        $this->render_settings_row(
                            __('Minimum article length (words)', 'vernal-contentum'),
                            ob_get_clean(),
                            __('Skip very short posts that are not useful for in-body linking.', 'vernal-contentum'),
                            __('Higher = only longer articles qualify. Lower = includes brief posts.', 'vernal-contentum')
                        );

                        ob_start();
                        ?>
                        <input type="number" class="small-text" name="vernal_il[max_vernal_links_per_post]" value="<?php echo (int) $settings['max_vernal_links_per_post']; ?>" min="0" />
                        <?php
                        $this->render_settings_row(
                            __('Max links added by this tool (per article, total)', 'vernal-contentum'),
                            ob_get_clean(),
                            __('Stop adding Vernal-managed links after an article already has this many.', 'vernal-contentum'),
                            __('Higher = more auto-links allowed over time. Lower = stricter cap.', 'vernal-contentum')
                        );

                        ob_start();
                        ?>
                        <input type="number" class="small-text" name="vernal_il[max_total_internal_links_per_post]" value="<?php echo (int) $settings['max_total_internal_links_per_post']; ?>" min="0" />
                        <?php
                        $this->render_settings_row(
                            __('Max internal links in one article (all sources)', 'vernal-contentum'),
                            ob_get_clean(),
                            __('Counts manual links plus Vernal links. Stops new inserts if the article is already link-heavy.', 'vernal-contentum'),
                            __('Higher = allows busier articles. Lower = keeps pages cleaner.', 'vernal-contentum')
                        );
                        ?>
                    </table>
                </details>

                <?php submit_button(__('Save settings', 'vernal-contentum')); ?>
            </form>

            <h2><?php esc_html_e('Recent links added', 'vernal-contentum'); ?></h2>
            <p class="description" style="max-width:820px;">
                <?php esc_html_e('Links inserted by this tool. Undo removes the link but keeps the visible text in the article.', 'vernal-contentum'); ?>
            </p>
            <table class="widefat striped" style="max-width:920px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('When', 'vernal-contentum'); ?></th>
                        <th><?php esc_html_e('Article edited', 'vernal-contentum'); ?></th>
                        <th><?php esc_html_e('Linked to article', 'vernal-contentum'); ?></th>
                        <th><?php esc_html_e('Linked phrase', 'vernal-contentum'); ?></th>
                        <th><?php esc_html_e('Why', 'vernal-contentum'); ?></th>
                        <th><?php esc_html_e('Undo', 'vernal-contentum'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$recent) : ?>
                    <tr><td colspan="6"><?php esc_html_e('No links added yet.', 'vernal-contentum'); ?></td></tr>
                <?php else : foreach ($recent as $row) :
                    $undo_url = wp_nonce_url(
                        add_query_arg(array(
                            'action'      => 'vernal_il_undo',
                            'post_id'     => (int) ($row['source_wp_post_id'] ?? 0),
                            'mutation_id' => (string) ($row['id'] ?? ''),
                        ), admin_url('admin-post.php')),
                        'vernal_il_undo'
                    );
                    $source_id = (int) ($row['source_wp_post_id'] ?? 0);
                    $target_id = (int) ($row['target_wp_post_id'] ?? 0);
                    ?>
                    <tr>
                        <td><?php echo esc_html($row['inserted_at'] ?? ''); ?></td>
                        <td>
                            <?php if ($source_id) : ?>
                                <a href="<?php echo esc_url(get_edit_post_link($source_id, 'raw')); ?>">#<?php echo (int) $source_id; ?></a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($target_id) : ?>
                                <a href="<?php echo esc_url(get_edit_post_link($target_id, 'raw')); ?>">#<?php echo (int) $target_id; ?></a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($row['anchor'] ?? ''); ?></td>
                        <td><?php echo esc_html($this->humanize_strategy_label((string) ($row['strategy'] ?? ''))); ?></td>
                        <td><a href="<?php echo esc_url($undo_url); ?>"><?php esc_html_e('Remove link', 'vernal-contentum'); ?></a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
