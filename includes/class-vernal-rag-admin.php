<?php
/**
 * RAG exclusions admin UI + REST handlers (used by Vernal_API).
 *
 * @package VernalContentum
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Rag_Admin {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_post_vernal_rag_add_exclusion', array($this, 'handle_add'));
        add_action('admin_post_vernal_rag_remove_exclusion', array($this, 'handle_remove'));
    }

    public function handle_add() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Forbidden', 'vernal-contentum'));
        }
        check_admin_referer('vernal_rag_add_exclusion');
        $cid = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
        if ($cid > 0 && class_exists('Vernal_Rag_Eligibility')) {
            Vernal_Rag_Eligibility::add_excluded_category($cid);
        }
        wp_safe_redirect(add_query_arg(
            array('page' => 'vernal-contentum-rag', 'updated' => 1),
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_remove() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Forbidden', 'vernal-contentum'));
        }
        check_admin_referer('vernal_rag_remove_exclusion');
        $cid = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
        if ($cid > 0 && class_exists('Vernal_Rag_Eligibility')) {
            Vernal_Rag_Eligibility::remove_excluded_category($cid);
        }
        wp_safe_redirect(add_query_arg(
            array('page' => 'vernal-contentum-rag', 'updated' => 1),
            admin_url('admin.php')
        ));
        exit;
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!class_exists('Vernal_Rag_Eligibility')) {
            echo '<div class="wrap"><p>' . esc_html__('RAG eligibility module missing.', 'vernal-contentum') . '</p></div>';
            return;
        }
        $payload = Vernal_Rag_Eligibility::get_exclusions_payload();
        $excluded_ids = $payload['excluded_category_ids'];
        $all_cats = get_categories(array(
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('RAG Ingestion', 'vernal-contentum'); ?></h1>
            <p class="description" style="max-width:720px;">
                <?php esc_html_e('Posts in these categories are not sent into Vernal’s search index. Add or remove one category at a time. This is separate from Article Linking exclusions.', 'vernal-contentum'); ?>
            </p>
            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Exclusions updated.', 'vernal-contentum'); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Excluded categories', 'vernal-contentum'); ?></h2>
            <?php if (!$excluded_ids) : ?>
                <p><?php esc_html_e('None yet. All eligible published posts can be indexed.', 'vernal-contentum'); ?></p>
            <?php else : ?>
                <ul style="list-style:disc;margin-left:1.25rem;">
                    <?php foreach ($payload['categories'] as $row) :
                        $remove_url = wp_nonce_url(
                            add_query_arg(array(
                                'action'      => 'vernal_rag_remove_exclusion',
                                'category_id' => (int) $row['id'],
                            ), admin_url('admin-post.php')),
                            'vernal_rag_remove_exclusion'
                        );
                        ?>
                        <li>
                            <?php echo esc_html($row['name']); ?>
                            <span class="description">(ID <?php echo (int) $row['id']; ?>)</span>
                            — <a href="<?php echo esc_url($remove_url); ?>"><?php esc_html_e('Remove', 'vernal-contentum'); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h2><?php esc_html_e('Add a category', 'vernal-contentum'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vernal_rag_add_exclusion" />
                <?php wp_nonce_field('vernal_rag_add_exclusion'); ?>
                <select name="category_id" required>
                    <option value=""><?php esc_html_e('Select a category…', 'vernal-contentum'); ?></option>
                    <?php foreach ($all_cats as $cat) :
                        if (in_array((int) $cat->term_id, $excluded_ids, true)) {
                            continue;
                        }
                        ?>
                        <option value="<?php echo (int) $cat->term_id; ?>"><?php echo esc_html($cat->name); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button(__('Add', 'vernal-contentum'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Paginated RAG posts for Machine sync / deindex.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_list_posts($request) {
        if (!class_exists('Vernal_Rag_Eligibility')) {
            return new WP_Error('missing', 'RAG module missing', array('status' => 500));
        }
        $page = max(1, (int) $request->get_param('page') ?: 1);
        $per_page = (int) $request->get_param('per_page') ?: 50;
        $per_page = max(1, min(50, $per_page));
        $category_id = (int) $request->get_param('category_id');
        $include_excluded = (string) $request->get_param('include_excluded') === '1'
            || $request->get_param('include_excluded') === true
            || $request->get_param('include_excluded') === 1;

        $args = array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => $per_page,
            'paged'               => $page,
            'orderby'             => array(
                'modified' => 'ASC',
                'ID'       => 'ASC',
            ),
            'ignore_sticky_posts' => true,
            'has_password'        => false,
        );
        if ($category_id > 0) {
            $args['category__in'] = array($category_id);
        }

        // Keep pagination accurate: filter RAG-excluded categories in the query (not after).
        if (!$include_excluded) {
            $excluded = Vernal_Rag_Eligibility::get_excluded_category_ids();
            if ($excluded) {
                $args['tax_query'] = array(
                    array(
                        'taxonomy' => 'category',
                        'field'    => 'term_id',
                        'terms'    => $excluded,
                        'operator' => 'NOT IN',
                    ),
                );
            }
        }

        $q = new WP_Query($args);
        $posts = array();
        $bypass = array('bypass_rag_category_exclusion' => $include_excluded);
        foreach ($q->posts as $post) {
            if (!Vernal_Rag_Eligibility::is_post_eligible($post, $bypass)) {
                continue;
            }
            $posts[] = array(
                'wp_post_id'   => (int) $post->ID,
                'title'        => get_the_title($post),
                'excerpt'      => get_the_excerpt($post),
                'content_text' => wp_strip_all_tags(wp_trim_words($post->post_content, 400)),
                'category_ids' => array_map('intval', wp_get_post_categories($post->ID)),
                'permalink'    => get_permalink($post),
                'published_at' => $post->post_date_gmt,
                'modified_gmt' => $post->post_modified_gmt,
            );
        }

        $total = (int) $q->found_posts;
        $total_pages = max(1, (int) $q->max_num_pages);

        return rest_ensure_response(array(
            'posts'       => $posts,
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => $total_pages,
        ));
    }
}
