<?php
/**
 * Plugin Name: PCB Gerber Quote Uploader
 * Description: Collect Gerber archives from customers (ZIP/RAR), review requests in admin, and send price quotations by email.
 * Version: 1.0.0
 * Author: Codex
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCB_Gerber_Quote_Uploader {
    const POST_TYPE = 'pcb_quote_request';

    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_shortcode('pcb_quote_form', [$this, 'render_quote_form']);

        add_action('admin_post_nopriv_pcb_submit_quote', [$this, 'handle_form_submission']);
        add_action('admin_post_pcb_submit_quote', [$this, 'handle_form_submission']);

        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_admin_column'], 10, 2);

        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
        add_action('admin_post_pcb_send_quote_email', [$this, 'handle_send_quote']);

        add_filter('upload_mimes', [$this, 'allow_rar_mime']);
    }

    public function register_post_type() {
        register_post_type(
            self::POST_TYPE,
            [
                'labels' => [
                    'name' => __('PCB Quote Requests', 'pcb-gerber-quote'),
                    'singular_name' => __('PCB Quote Request', 'pcb-gerber-quote'),
                ],
                'public' => false,
                'show_ui' => true,
                'menu_icon' => 'dashicons-media-archive',
                'supports' => ['title'],
                'map_meta_cap' => true,
            ]
        );
    }

    public function allow_rar_mime($mimes) {
        $mimes['rar'] = 'application/vnd.rar';
        return $mimes;
    }

    public function render_quote_form() {
        $status = isset($_GET['pcb_quote_status']) ? sanitize_text_field(wp_unslash($_GET['pcb_quote_status'])) : '';
        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="pcb-quote-form">
            <input type="hidden" name="action" value="pcb_submit_quote">
            <?php wp_nonce_field('pcb_submit_quote', 'pcb_quote_nonce'); ?>

            <p>
                <label for="pcb_name"><?php esc_html_e('Full Name', 'pcb-gerber-quote'); ?></label><br>
                <input type="text" id="pcb_name" name="pcb_name" required>
            </p>

            <p>
                <label for="pcb_email"><?php esc_html_e('Email', 'pcb-gerber-quote'); ?></label><br>
                <input type="email" id="pcb_email" name="pcb_email" required>
            </p>

            <p>
                <label for="pcb_phone"><?php esc_html_e('Phone', 'pcb-gerber-quote'); ?></label><br>
                <input type="text" id="pcb_phone" name="pcb_phone">
            </p>

            <p>
                <label for="pcb_company"><?php esc_html_e('Company', 'pcb-gerber-quote'); ?></label><br>
                <input type="text" id="pcb_company" name="pcb_company">
            </p>

            <p>
                <label for="pcb_message"><?php esc_html_e('Project Details', 'pcb-gerber-quote'); ?></label><br>
                <textarea id="pcb_message" name="pcb_message" rows="5"></textarea>
            </p>

            <p>
                <label for="pcb_gerber_file"><?php esc_html_e('Gerber Archive (ZIP or RAR only)', 'pcb-gerber-quote'); ?></label><br>
                <input type="file" id="pcb_gerber_file" name="pcb_gerber_file" accept=".zip,.rar,application/zip,application/x-rar-compressed,application/vnd.rar" required>
            </p>

            <p>
                <button type="submit"><?php esc_html_e('Submit Quote Request', 'pcb-gerber-quote'); ?></button>
            </p>
        </form>

        <?php if ('success' === $status) : ?>
            <p class="pcb-quote-success"><?php esc_html_e('Thanks! Your quote request was submitted.', 'pcb-gerber-quote'); ?></p>
        <?php elseif ('error' === $status) : ?>
            <p class="pcb-quote-error"><?php esc_html_e('Something went wrong. Please try again.', 'pcb-gerber-quote'); ?></p>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    public function handle_form_submission() {
        if (!isset($_POST['pcb_quote_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pcb_quote_nonce'])), 'pcb_submit_quote')) {
            wp_die(esc_html__('Invalid request.', 'pcb-gerber-quote'));
        }

        $name = isset($_POST['pcb_name']) ? sanitize_text_field(wp_unslash($_POST['pcb_name'])) : '';
        $email = isset($_POST['pcb_email']) ? sanitize_email(wp_unslash($_POST['pcb_email'])) : '';
        $phone = isset($_POST['pcb_phone']) ? sanitize_text_field(wp_unslash($_POST['pcb_phone'])) : '';
        $company = isset($_POST['pcb_company']) ? sanitize_text_field(wp_unslash($_POST['pcb_company'])) : '';
        $message = isset($_POST['pcb_message']) ? sanitize_textarea_field(wp_unslash($_POST['pcb_message'])) : '';

        if (empty($name) || empty($email) || !is_email($email) || empty($_FILES['pcb_gerber_file']['name'])) {
            $this->redirect_with_status('error');
        }

        $file = $_FILES['pcb_gerber_file'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['zip', 'rar'], true)) {
            $this->redirect_with_status('error');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload(
            $file,
            [
                'test_form' => false,
                'mimes' => [
                    'zip' => 'application/zip',
                    'rar' => 'application/vnd.rar',
                ],
            ]
        );

        if (!empty($upload['error']) || empty($upload['file'])) {
            $this->redirect_with_status('error');
        }

        $post_id = wp_insert_post(
            [
                'post_type' => self::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => sprintf('Quote Request - %s - %s', $name, wp_date('Y-m-d H:i')),
            ],
            true
        );

        if (is_wp_error($post_id)) {
            $this->redirect_with_status('error');
        }

        update_post_meta($post_id, '_pcb_name', $name);
        update_post_meta($post_id, '_pcb_email', $email);
        update_post_meta($post_id, '_pcb_phone', $phone);
        update_post_meta($post_id, '_pcb_company', $company);
        update_post_meta($post_id, '_pcb_message', $message);
        update_post_meta($post_id, '_pcb_file_url', esc_url_raw($upload['url']));
        update_post_meta($post_id, '_pcb_file_path', sanitize_text_field($upload['file']));
        update_post_meta($post_id, '_pcb_quote_status', 'new');

        $this->redirect_with_status('success');
    }

    private function redirect_with_status($status) {
        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = home_url('/');
        }
        wp_safe_redirect(add_query_arg('pcb_quote_status', $status, $redirect));
        exit;
    }

    public function admin_columns($columns) {
        $columns['pcb_name'] = __('Customer', 'pcb-gerber-quote');
        $columns['pcb_email'] = __('Email', 'pcb-gerber-quote');
        $columns['pcb_phone'] = __('Phone', 'pcb-gerber-quote');
        $columns['pcb_file'] = __('Gerber File', 'pcb-gerber-quote');
        $columns['pcb_quote_status'] = __('Quote Status', 'pcb-gerber-quote');
        return $columns;
    }

    public function render_admin_column($column, $post_id) {
        switch ($column) {
            case 'pcb_name':
                echo esc_html((string) get_post_meta($post_id, '_pcb_name', true));
                break;
            case 'pcb_email':
                echo esc_html((string) get_post_meta($post_id, '_pcb_email', true));
                break;
            case 'pcb_phone':
                echo esc_html((string) get_post_meta($post_id, '_pcb_phone', true));
                break;
            case 'pcb_file':
                $url = (string) get_post_meta($post_id, '_pcb_file_url', true);
                if ($url) {
                    printf('<a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url($url), esc_html__('Download', 'pcb-gerber-quote'));
                }
                break;
            case 'pcb_quote_status':
                echo esc_html((string) get_post_meta($post_id, '_pcb_quote_status', true));
                break;
        }
    }

    public function register_meta_boxes() {
        add_meta_box(
            'pcb_quote_details',
            __('PCB Quote Details', 'pcb-gerber-quote'),
            [$this, 'render_quote_details_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'pcb_send_quote_email',
            __('Send Quotation Email', 'pcb-gerber-quote'),
            [$this, 'render_send_quote_meta_box'],
            self::POST_TYPE,
            'side',
            'high'
        );
    }

    public function render_quote_details_meta_box($post) {
        $fields = [
            'Name' => get_post_meta($post->ID, '_pcb_name', true),
            'Email' => get_post_meta($post->ID, '_pcb_email', true),
            'Phone' => get_post_meta($post->ID, '_pcb_phone', true),
            'Company' => get_post_meta($post->ID, '_pcb_company', true),
            'Project Details' => get_post_meta($post->ID, '_pcb_message', true),
        ];

        $file_url = (string) get_post_meta($post->ID, '_pcb_file_url', true);

        echo '<table class="widefat striped">';
        foreach ($fields as $label => $value) {
            printf(
                '<tr><th style="width:180px;">%1$s</th><td>%2$s</td></tr>',
                esc_html($label),
                nl2br(esc_html((string) $value))
            );
        }

        if ($file_url) {
            printf(
                '<tr><th>%1$s</th><td><a href="%2$s" target="_blank" rel="noopener">%3$s</a></td></tr>',
                esc_html__('Gerber Archive', 'pcb-gerber-quote'),
                esc_url($file_url),
                esc_html__('Download file', 'pcb-gerber-quote')
            );
        }
        echo '</table>';
    }

    public function render_send_quote_meta_box($post) {
        $price = (string) get_post_meta($post->ID, '_pcb_quote_price', true);
        $notes = (string) get_post_meta($post->ID, '_pcb_quote_notes', true);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="pcb_send_quote_email">
            <input type="hidden" name="quote_post_id" value="<?php echo esc_attr((string) $post->ID); ?>">
            <?php wp_nonce_field('pcb_send_quote_email_' . $post->ID, 'pcb_send_quote_nonce'); ?>

            <p>
                <label for="pcb_quote_price"><?php esc_html_e('Quoted Price', 'pcb-gerber-quote'); ?></label>
                <input type="text" class="widefat" name="pcb_quote_price" id="pcb_quote_price" value="<?php echo esc_attr($price); ?>" placeholder="e.g. 120.00 USD" required>
            </p>

            <p>
                <label for="pcb_quote_notes"><?php esc_html_e('Message to Customer', 'pcb-gerber-quote'); ?></label>
                <textarea class="widefat" rows="5" name="pcb_quote_notes" id="pcb_quote_notes" required><?php echo esc_textarea($notes); ?></textarea>
            </p>

            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Send Quotation Email', 'pcb-gerber-quote'); ?></button>
            </p>
        </form>
        <?php
    }

    public function handle_send_quote() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('Permission denied.', 'pcb-gerber-quote'));
        }

        $post_id = isset($_POST['quote_post_id']) ? absint($_POST['quote_post_id']) : 0;
        if (!$post_id || get_post_type($post_id) !== self::POST_TYPE) {
            wp_die(esc_html__('Invalid quote request.', 'pcb-gerber-quote'));
        }

        if (!isset($_POST['pcb_send_quote_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pcb_send_quote_nonce'])), 'pcb_send_quote_email_' . $post_id)) {
            wp_die(esc_html__('Invalid request.', 'pcb-gerber-quote'));
        }

        $price = isset($_POST['pcb_quote_price']) ? sanitize_text_field(wp_unslash($_POST['pcb_quote_price'])) : '';
        $notes = isset($_POST['pcb_quote_notes']) ? sanitize_textarea_field(wp_unslash($_POST['pcb_quote_notes'])) : '';
        $email = (string) get_post_meta($post_id, '_pcb_email', true);
        $name = (string) get_post_meta($post_id, '_pcb_name', true);

        if (!$price || !$notes || !is_email($email)) {
            wp_die(esc_html__('Missing required email details.', 'pcb-gerber-quote'));
        }

        $subject = sprintf(__('Your PCB quotation from %s', 'pcb-gerber-quote'), get_bloginfo('name'));
        $body = sprintf(
            "Hello %s,\n\nThank you for your PCB inquiry.\n\nQuoted Price: %s\n\n%s\n\nRegards,\n%s",
            $name ? $name : __('Customer', 'pcb-gerber-quote'),
            $price,
            $notes,
            get_bloginfo('name')
        );

        $sent = wp_mail($email, $subject, $body);

        update_post_meta($post_id, '_pcb_quote_price', $price);
        update_post_meta($post_id, '_pcb_quote_notes', $notes);
        update_post_meta($post_id, '_pcb_quote_status', $sent ? 'quoted' : 'email_failed');

        $redirect = add_query_arg(
            [
                'post' => $post_id,
                'action' => 'edit',
                'pcb_quote_email' => $sent ? 'sent' : 'failed',
            ],
            admin_url('post.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }
}

new PCB_Gerber_Quote_Uploader();
