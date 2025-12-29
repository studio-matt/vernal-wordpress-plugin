<?php
/*
 * Plugin Settings Page
 * Allows per-post TLDR, FAQ, Canonical Hub inputs (saved as postmeta).
 */

// Add custom meta box to posts/pages
function cmschema_metabox() {
    add_meta_box(
        'cmschema_options',
        'ContentMachine Schema',
        'cmschema_metabox_callback',
        ['post', 'page'],
        'side'
    );
}
add_action('add_meta_boxes', 'cmschema_metabox');

function cmschema_metabox_callback($post) {
    $options = get_post_meta($post->ID, '_cmschema_options', true) ?: [];
    ?>
    <p>
        <label for="cmschema_tldr">TL;DR / Summary:</label><br>
        <textarea id="cmschema_tldr" name="cmschema_options[tldr]" rows="2" style="width:100%;"><?php echo esc_textarea($options['tldr'] ?? ''); ?></textarea>
    </p>
    <p>
        <label for="cmschema_faq">FAQs (Q|A per line):</label><br>
        <textarea id="cmschema_faq" name="cmschema_options[faq]" rows="4" style="width:100%;"><?php echo esc_textarea($options['faq'] ?? ''); ?></textarea>
    </p>
    <p>
        <label for="cmschema_canonical">Canonical Hub URL:</label><br>
        <input id="cmschema_canonical" type="url" name="cmschema_options[canonical]" style="width:100%;" value="<?php echo esc_attr($options['canonical'] ?? ''); ?>" />
    </p>
    <?php
}

// Save per-post settings
add_action('save_post', function($post_id) {
    if (
        isset($_POST['cmschema_options']) &&
        (defined('DOING_AUTOSAVE') && !DOING_AUTOSAVE)
    ) {
        update_post_meta($post_id, '_cmschema_options', array_map('sanitize_text_field', $_POST['cmschema_options']));
    }
});