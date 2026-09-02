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
    const META_PASS_AT    = '_vernal_il_pass_at';
    const META_SRC_MOD    = '_vernal_il_source_modified_gmt';
    const META_FP         = '_vernal_il_content_fp';
    const META_LINKS      = '_vernal_il_links';
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
        add_action('save_post_post', array($this, 'maybe_enqueue_on_save'), 20, 3);
        add_action('admin_post_vernal_il_run_now', array($this, 'handle_run_now'));
        add_action('admin_post_vernal_il_undo', array($this, 'handle_undo'));
        add_action('admin_post_vernal_il_save_settings', array($this, 'handle_save_settings'));
        add_action('init', array($this, 'ensure_cron_scheduled'));
    }

    public static function default_settings() {
        return array(
            'enabled'                                    => 1,
            'schedule'                                   => 'daily',
            'max_new_outbound_links_per_source'          => 3,
            'max_inbound_source_mutations_per_new_target'=> 2,
            'batch_sources_per_tick'                     => 10,
            'min_relevance_score'                        => 0.35,
            'prefer_same_category'                       => 1,
            'orphan_repair_after_days'                   => 14,
            'min_word_count'                             => 120,
            'max_vernal_links_per_post'                  => 8,
            'max_total_internal_links_per_post'          => 12,
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
            $wanted = 'daily';
        }
        $next = wp_next_scheduled(self::CRON_HOOK);
        if (empty($settings['enabled'])) {
            if ($next) {
                wp_unschedule_event($next, self::CRON_HOOK);
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
    }

    public function cron_tick() {
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return;
        }
        $this->run_pass('cron');
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
                    $this->stamp_source_pass($post->ID);
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

        if ($pass_at === '') {
            return true;
        }
        if ($stored_fp && $stored_fp !== $fp) {
            return true; // genuine editorial change
        }

        // Orphan repair: zero Vernal outbound links and old enough
        if (!empty($settings['orphan_repair_enabled'])) {
            $analysis = Vernal_Internal_Link_Inserter::analyze_internal_links($post->post_content);
            $days = (int) $settings['orphan_repair_after_days'];
            $age = time() - strtotime($post->post_date_gmt . ' UTC');
            if ($analysis['vernal'] === 0 && $days > 0 && $age > ($days * DAY_IN_SECONDS)) {
                // Only re-run if last pass older than orphan window
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
            // If current post_modified_gmt equals what we stamped, our write — skip
            if ($src_mod_stamp && $src_mod_stamp === $post->post_modified_gmt && $stored_fp === $fp) {
                return false;
            }
            if ($last_success && $post->post_date_gmt > $last_success && $pass_at < $post->post_date_gmt) {
                return true;
            }
        }

        return false;
    }

    private function is_recently_published($post, $days) {
        $age = time() - strtotime($post->post_date_gmt . ' UTC');
        return $age <= ($days * DAY_IN_SECONDS);
    }

    public function stamp_source_pass($post_id) {
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
    }

    /**
     * Process outbound links for one source.
     */
    private function process_source_outbound($post, $settings, $run_id, $dest_id, $trigger) {
        $out = array('linked' => 0, 'skipped' => 0, 'errors' => 0);
        $analysis = Vernal_Internal_Link_Inserter::analyze_internal_links($post->post_content);
        $max_new = (int) $settings['max_new_outbound_links_per_source'];
        $max_vernal = (int) $settings['max_vernal_links_per_post'];
        $max_total = (int) $settings['max_total_internal_links_per_post'];

        if ($analysis['vernal'] >= $max_vernal || $analysis['total'] >= $max_total) {
            $out['skipped']++;
            return $out;
        }
        $room = min(
            $max_new,
            $max_vernal - $analysis['vernal'],
            $max_total - $analysis['total']
        );
        if ($room <= 0) {
            $out['skipped']++;
            return $out;
        }

        $strategy = 'contextual';
        if (!empty($settings['orphan_repair_enabled'])) {
            $age = time() - strtotime($post->post_date_gmt . ' UTC');
            if ($analysis['vernal'] === 0 && $age > ((int) $settings['orphan_repair_after_days'] * DAY_IN_SECONDS)) {
                $strategy = 'orphan_repair';
            }
        }
        if ($trigger === 'manual_run') {
            $strategy = 'contextual';
            $ledger_strategy = 'manual_run';
        } elseif ($strategy === 'orphan_repair') {
            $ledger_strategy = 'orphan_repair';
        } else {
            $ledger_strategy = 'new_post_outbound';
        }

        $payload = $this->build_outbound_match_payload($post, $settings, $dest_id, $analysis, $strategy, $room);
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
            if ($phrase === '') {
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
            $entry = array(
                'id'                 => $mutation_id,
                'source_wp_post_id'  => (int) $post->ID,
                'target_wp_post_id'  => $target_id,
                'target_url'         => $permalink,
                'anchor'             => $phrase,
                'inserted_at'        => gmdate('c'),
                'run_id'             => $run_id,
                'strategy'           => $ledger_strategy,
            );
            $ledger[] = $entry;
            $this->push_recent_mutation($entry);
            $inserted_this_pass++;
            $out['linked']++;
        }

        if ($inserted_this_pass > 0) {
            $this->save_post_content($post->ID, $content, $ledger);
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
            'mode'                   => 'inbound_sources',
            'social_destination_id'  => (int) $dest_id,
            'strategy'               => 'new_post_inbound_backfill',
            'limit'                  => $max * 3,
            'min_score'              => (float) $settings['min_relevance_score'],
            'target'                 => $this->post_to_match_source($new_post),
            'candidate_stubs'        => $stubs,
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

    private function build_outbound_match_payload($post, $settings, $dest_id, $analysis, $strategy, $limit) {
        $inbound_counts = $this->estimate_inbound_counts($settings);
        return array(
            'mode'                      => 'outbound_candidates',
            'social_destination_id'     => (int) $dest_id,
            'strategy'                  => $strategy,
            'limit'                     => (int) $limit,
            'min_score'                 => (float) $settings['min_relevance_score'],
            'source'                    => $this->post_to_match_source($post),
            'already_linked_target_ids' => $analysis['target_ids'],
            'inbound_counts'            => $inbound_counts,
            'candidate_stubs'           => $this->build_candidate_stubs($settings, 40, array( (int) $post->ID )),
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

    private function estimate_inbound_counts($settings) {
        // Lightweight: count Vernal target ids across a recent sample of posts
        $counts = array();
        $q = new WP_Query(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ));
        foreach ($q->posts as $pid) {
            $content = get_post_field('post_content', $pid);
            $a = Vernal_Internal_Link_Inserter::analyze_internal_links($content);
            foreach ($a['target_ids'] as $tid) {
                if (!isset($counts[$tid])) {
                    $counts[$tid] = 0;
                }
                $counts[$tid]++;
            }
        }
        return $counts;
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
        $ledger = array_values(array_filter($ledger, function ($e) use ($mutation_id) {
            return !(isset($e['id']) && $e['id'] === $mutation_id);
        }));
        $this->save_post_content($post_id, $result['content'], $ledger);
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
        $sched = isset($input['schedule']) ? sanitize_text_field($input['schedule']) : 'daily';
        $out['schedule'] = in_array($sched, array('hourly', 'twicedaily', 'daily', 'weekly'), true) ? $sched : 'daily';
        $out['max_new_outbound_links_per_source'] = max(0, (int) ($input['max_new_outbound_links_per_source'] ?? 3));
        $out['max_inbound_source_mutations_per_new_target'] = max(0, (int) ($input['max_inbound_source_mutations_per_new_target'] ?? 2));
        $out['batch_sources_per_tick'] = max(1, min(50, (int) ($input['batch_sources_per_tick'] ?? 10)));
        $out['min_relevance_score'] = max(0, min(1, (float) ($input['min_relevance_score'] ?? 0.35)));
        $out['prefer_same_category'] = !empty($input['prefer_same_category']) ? 1 : 0;
        $out['orphan_repair_after_days'] = max(0, (int) ($input['orphan_repair_after_days'] ?? 14));
        $out['min_word_count'] = max(0, (int) ($input['min_word_count'] ?? 120));
        $out['max_vernal_links_per_post'] = max(0, (int) ($input['max_vernal_links_per_post'] ?? 8));
        $out['max_total_internal_links_per_post'] = max(0, (int) ($input['max_total_internal_links_per_post'] ?? 12));
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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Internal Linking', 'vernal-contentum'); ?></h1>
            <p><?php esc_html_e('Contextual in-body links between articles. Machine ranks candidates; WordPress inserts safely. Related-news Query Loops are unchanged.', 'vernal-contentum'); ?></p>

            <?php if (!empty($_GET['settings-updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'vernal-contentum'); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['vernal_il_done'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Run finished.', 'vernal-contentum'); ?> <?php echo esc_html(isset($_GET['status']) ? (string) $_GET['status'] : ''); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['vernal_il_undone'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Link undone.', 'vernal-contentum'); ?></p></div>
            <?php endif; ?>

            <div style="background:#fff;border:1px solid #ccd0d4;padding:16px;margin:16px 0;">
                <h2><?php esc_html_e('Last run', 'vernal-contentum'); ?></h2>
                <?php if (is_array($last) && !empty($last['run_id'])) : ?>
                    <p>
                        <strong><?php esc_html_e('Status:', 'vernal-contentum'); ?></strong> <?php echo esc_html($last['status'] ?? ''); ?>
                        &nbsp;|&nbsp; <?php esc_html_e('Scanned', 'vernal-contentum'); ?>: <?php echo (int) ($last['scanned'] ?? 0); ?>
                        &nbsp;|&nbsp; <?php esc_html_e('Linked', 'vernal-contentum'); ?>: <?php echo (int) ($last['linked'] ?? 0); ?>
                        &nbsp;|&nbsp; <?php esc_html_e('Skipped', 'vernal-contentum'); ?>: <?php echo (int) ($last['skipped'] ?? 0); ?>
                        &nbsp;|&nbsp; <?php esc_html_e('Errors', 'vernal-contentum'); ?>: <?php echo (int) ($last['errors'] ?? 0); ?>
                    </p>
                    <p><code><?php echo esc_html($last['run_id']); ?></code> — <?php echo esc_html($last['completed_at'] ?? $last['started_at'] ?? ''); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e('No runs yet.', 'vernal-contentum'); ?></p>
                <?php endif; ?>
                <?php if (is_array($lock) && !empty($lock['lease_expires_at']) && (int) $lock['lease_expires_at'] > time()) : ?>
                    <p><em><?php esc_html_e('A run lease is currently held.', 'vernal-contentum'); ?></em></p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="vernal_il_run_now" />
                    <?php wp_nonce_field('vernal_il_run_now'); ?>
                    <?php submit_button(__('Run Now', 'vernal-contentum'), 'primary', 'submit', false); ?>
                </form>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vernal_il_save_settings" />
                <?php wp_nonce_field('vernal_il_save_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><?php esc_html_e('Enabled', 'vernal-contentum'); ?></th>
                        <td><label><input type="checkbox" name="vernal_il[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?> /> <?php esc_html_e('Run on schedule', 'vernal-contentum'); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Schedule', 'vernal-contentum'); ?></th>
                        <td>
                            <select name="vernal_il[schedule]">
                                <?php foreach (array('hourly', 'twicedaily', 'daily', 'weekly') as $s) : ?>
                                    <option value="<?php echo esc_attr($s); ?>" <?php selected($settings['schedule'], $s); ?>><?php echo esc_html($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Max new outbound / source pass', 'vernal-contentum'); ?></th>
                        <td><input type="number" name="vernal_il[max_new_outbound_links_per_source]" value="<?php echo (int) $settings['max_new_outbound_links_per_source']; ?>" min="0" max="20" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Max inbound mutations / new target', 'vernal-contentum'); ?></th>
                        <td><input type="number" name="vernal_il[max_inbound_source_mutations_per_new_target]" value="<?php echo (int) $settings['max_inbound_source_mutations_per_new_target']; ?>" min="0" max="20" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Batch sources / tick', 'vernal-contentum'); ?></th>
                        <td><input type="number" name="vernal_il[batch_sources_per_tick]" value="<?php echo (int) $settings['batch_sources_per_tick']; ?>" min="1" max="50" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Min relevance score', 'vernal-contentum'); ?></th>
                        <td><input type="number" step="0.01" min="0" max="1" name="vernal_il[min_relevance_score]" value="<?php echo esc_attr($settings['min_relevance_score']); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Social destination ID', 'vernal-contentum'); ?></th>
                        <td><input type="number" name="vernal_il[social_destination_id]" value="<?php echo (int) $settings['social_destination_id']; ?>" min="0" />
                        <p class="description"><?php esc_html_e('Machine destination id for tenant isolation on match/index.', 'vernal-contentum'); ?></p></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Orphan repair after days', 'vernal-contentum'); ?></th>
                        <td>
                            <label><input type="checkbox" name="vernal_il[orphan_repair_enabled]" value="1" <?php checked(!empty($settings['orphan_repair_enabled'])); ?> /> <?php esc_html_e('Enabled', 'vernal-contentum'); ?></label>
                            <input type="number" name="vernal_il[orphan_repair_after_days]" value="<?php echo (int) $settings['orphan_repair_after_days']; ?>" min="0" />
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Excluded category IDs', 'vernal-contentum'); ?></th>
                        <td><input type="text" class="regular-text" name="vernal_il[excluded_category_ids]" value="<?php echo esc_attr(implode(',', $settings['excluded_category_ids'])); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Excluded post IDs', 'vernal-contentum'); ?></th>
                        <td><input type="text" class="regular-text" name="vernal_il[excluded_post_ids]" value="<?php echo esc_attr(implode(',', $settings['excluded_post_ids'])); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Process new/modified', 'vernal-contentum'); ?></th>
                        <td><label><input type="checkbox" name="vernal_il[process_new_and_modified]" value="1" <?php checked(!empty($settings['process_new_and_modified'])); ?> /> <?php esc_html_e('Yes', 'vernal-contentum'); ?></label></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Prefer same category', 'vernal-contentum'); ?></th>
                        <td><label><input type="checkbox" name="vernal_il[prefer_same_category]" value="1" <?php checked(!empty($settings['prefer_same_category'])); ?> /> <?php esc_html_e('Soft preference (Machine ranking)', 'vernal-contentum'); ?></label></td>
                    </tr>
                </table>
                <?php submit_button(__('Save settings', 'vernal-contentum')); ?>
            </form>

            <h2><?php esc_html_e('Recent mutations', 'vernal-contentum'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('When', 'vernal-contentum'); ?></th>
                        <th><?php esc_html_e('Source', 'vernal-contentum'); ?></th>
                        <th><?php esc_html_e('Target', 'vernal-contentum'); ?></th>
                        <th><?php esc_html_e('Anchor', 'vernal-contentum'); ?></th>
                        <th><?php esc_html_e('Strategy', 'vernal-contentum'); ?></th>
                        <th><?php esc_html_e('Undo', 'vernal-contentum'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$recent) : ?>
                    <tr><td colspan="6"><?php esc_html_e('None yet.', 'vernal-contentum'); ?></td></tr>
                <?php else : foreach ($recent as $row) :
                    $undo_url = wp_nonce_url(
                        add_query_arg(array(
                            'action'      => 'vernal_il_undo',
                            'post_id'     => (int) ($row['source_wp_post_id'] ?? 0),
                            'mutation_id' => (string) ($row['id'] ?? ''),
                        ), admin_url('admin-post.php')),
                        'vernal_il_undo'
                    );
                    ?>
                    <tr>
                        <td><?php echo esc_html($row['inserted_at'] ?? ''); ?></td>
                        <td><?php echo (int) ($row['source_wp_post_id'] ?? 0); ?></td>
                        <td><?php echo (int) ($row['target_wp_post_id'] ?? 0); ?></td>
                        <td><?php echo esc_html($row['anchor'] ?? ''); ?></td>
                        <td><?php echo esc_html($row['strategy'] ?? ''); ?></td>
                        <td><a href="<?php echo esc_url($undo_url); ?>"><?php esc_html_e('Undo', 'vernal-contentum'); ?></a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
