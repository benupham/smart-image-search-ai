<?php

class SmartImageSearch extends SmartImageSearch_WP_Base
{

    public function __construct()
    {
        parent::__construct();
        $this->is_pro = (int) get_option('sisa_pro') === 1 ? true : false;
        error_log($this->is_pro);
        $this->set_client();
    }

    public function set_client()
    {
        if ($this->is_pro) {
            $this->gcv_client = new SmartImageSearch_SisaPro_Client();
        } else {
            $this->gcv_client = new SmartImageSearch_GCV_Client();
        }
    }

    public function init()
    {
        load_plugin_textdomain(
            self::NAME,
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );

        add_action('rest_api_init', $this->get_method('add_sisa_api_routes'));

        add_filter('wp_generate_attachment_metadata', $this->get_method('process_attachment_upload'), 10, 2);
    }

    public function ajax_init()
    {
        add_filter(
            'wp_ajax_sisa_async_annotate_upload_new_media',
            $this->get_method('ajax_annotate_on_upload')
        );
    }

    public function admin_init()
    {

        add_action(
            'admin_enqueue_scripts',
            $this->get_method('enqueue_scripts')
        );

        $plugin = plugin_basename(
            dirname(dirname(__FILE__)) . '/smart-image-search-ai.php'
        );

        add_filter(
            "plugin_action_links_$plugin",
            $this->get_method('add_sisa_plugin_links')
        );
    }

    public function add_sisa_plugin_links($current_links)
    {
        $additional = array(
            'smartimagesearch' => sprintf(
                '<a href="tools.php?page=smartimagesearch">%s</a>',
                esc_html__('Get Started', 'smartimagesearch')
            ),
        );
        return array_merge($additional, $current_links);
    }

    public function add_sisa_api_routes()
    {
        register_rest_route('smartimagesearch/v1', '/proxy', array(
            // By using this constant we ensure that when the WP_REST_Server changes our readable endpoints will work as intended.
            'methods' => WP_REST_Server::READABLE,
            'callback' => $this->get_method('api_bulk_sisa'),
            'permission_callback' => $this->get_method('sisa_permissions_check'),
        ));
        register_rest_route('smartimagesearch/v1', '/settings', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => $this->get_method('api_get_sisa_settings'),
            'permission_callback' => $this->get_method('sisa_permissions_check'),
        ));
        register_rest_route('smartimagesearch/v1', '/settings', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => $this->get_method('api_update_sisa_settings'),
            'permission_callback' => $this->get_method('sisa_permissions_check'),
        ));
    }

    public function api_get_sisa_settings($request)
    {
        $response = new WP_REST_RESPONSE(array(
            'success' => true,
            'options' => array(
                'apiKey' => get_option('sisa_api_key', ''),
                'proApiKey' => get_option('sisa_pro_api_key') ?: '',
                'isPro' => (int) get_option('sisa_pro', (int) 0),
                'hasPro' => (int) get_option('sisa_pro_plugin', (int) 1),
                'onUpload' => get_option('sisa_on_media_upload', 'async'),
                'altText' => get_option('sisa_alt_text', (int) 1),
                'labels' => get_option('sisa_labels', (int) 0),
                'text' => get_option('sisa_text', (int) 0),
                'logos' => get_option('sisa_logos', (int) 0),
                'landmarks' => get_option('sisa_landmarks', (int) 0),
            ),
        ), 200);
        $response->set_headers(array('Cache-Control' => 'no-cache'));
        return $response;
    }

    public function api_update_sisa_settings($request)
    {
        $json = $request->get_json_params();
        update_option('sisa_api_key', sanitize_text_field(($json['options']['apiKey'])));
        update_option('sisa_pro_api_key', sanitize_text_field(($json['options']['proApiKey'])));

        update_option('sisa_on_media_upload', sanitize_text_field(($json['options']['onUpload'])));
        update_option('sisa_alt_text', sanitize_text_field(($json['options']['altText'])));
        update_option('sisa_labels', sanitize_text_field(($json['options']['labels'])));
        update_option('sisa_text', sanitize_text_field(($json['options']['text'])));
        update_option('sisa_logos', sanitize_text_field(($json['options']['logos'])));
        update_option('sisa_landmarks', sanitize_text_field(($json['options']['landmarks'])));

        $sisa_pro = $this->check_pro_api_key(sanitize_text_field(($json['options']['proApiKey'])));

        if (true === $sisa_pro) {
            update_option('sisa_pro', (int) 1);
            $this->is_pro = true;
            $this->set_client();
        } else {
            update_option('sisa_pro', (int) 0);
            $this->is_pro = false;
            $this->set_client();
        }

        $response = new WP_REST_RESPONSE(array(
            'success' => true,
            'options' => array(
                'apiKey' => $json['options']['apiKey'],
                'proApiKey' => $json['options']['proApiKey'],
                'isPro' => (int) get_option('sisa_pro'),
                'onUpload' => get_option('sisa_on_media_upload', 'async'),
                'altText' => get_option('sisa_alt_text', (int) 1),
                'labels' => get_option('sisa_labels', (int) 0),
                'text' => get_option('sisa_text', (int) 0),
                'logos' => get_option('sisa_logos', (int) 0),
                'landmarks' => get_option('sisa_landmarks', (int) 0),
            ),
        ), 200);
        $response->set_headers(array('Cache-Control' => 'no-cache'));
        return $response;
    }

    public function check_pro_api_key($pro_api_key)
    {
        $response = wp_remote_get('https://smart-image-ai.lndo.site/wp-json/smartimageserver/v1/account?api_key=' . $pro_api_key, array(
            'headers' => array('Content-Type' => 'application/json'),
            'method' => 'GET',
        ));

        $data = json_decode(wp_remote_retrieve_body($response));

        if (isset($data) && isset($data->success)) {
            return true;
        }
        return false;
    }

    public function get_estimate($image_count)
    {
        $response = wp_remote_get('https://smart-image-ai.lndo.site/wp-json/smartimageserver/v1/estimate?imageCount=' . $image_count, array(
            'headers' => array('Content-Type' => 'application/json'),
            'method' => 'GET',
        ));

        $data = json_decode(wp_remote_retrieve_body($response));

        if (isset($data) && isset($data->success)) {
            return $data->cost;
        }
        return new WP_Error('estimate_unavailable', 'Could not generate Pro estimate');
    }

    public function sisa_permissions_check()
    {
        // Restrict endpoint to only users who have the capability to manage options.
        if (current_user_can('manage_options')) {
            return true;
        }

        return new WP_Error('rest_forbidden', esc_html__('You do not have permissions to do that.', 'smartimagesearch'), array('status' => 401));
    }

    public function api_bulk_sisa($request)
    {

        $params = $request->get_query_params();

        $now = time();
        $start = !empty($params['start']) ? $params['start'] : false;

        if (isset($start) && (string)(int)$start == $start && strlen($start) > 9) {
            $now = (int) $start;
        }

        $posts_per_page = 2;

        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'paged' => 1,
            'posts_per_page' => $posts_per_page,
            'date_query' => array(
                'before' => date('Y-m-d H:i:s', $now),
            ),
            'meta_query'  => array(
                array(
                    'key' => '_wp_attachment_image_alt',
                    'value' => '',
                    'compare' => '='
                ),
                array(
                    'key' => '_wp_attachment_image_alt',
                    'compare' => 'NOT EXISTS'
                ),
                'relation' => 'OR'
            ),
            'post_mime_type' => array('image/jpeg', 'image/gif', 'image/png', 'image/bmp'),
            'fields' => 'ids'
        );

        $query = new WP_Query($args);

        if (false === $start) {
            return new WP_REST_RESPONSE(array(
                'success' => true,
                'body' => array(
                    'count' => $query->found_posts,
                    'errors' => 0,
                    'start' => $now,
                    'estimate' => $this->get_estimate($query->found_posts)
                ),
            ), 200);
        }

        if (!$query->have_posts()) {
            return new WP_REST_RESPONSE(array(
                'success' => true,
                'body' => array(
                    'image_data' => array(),
                    'status' => 'no images need annotation.'
                ),
            ), 200);
        }

        $response = array();
        $errors = 0;

        foreach ($query->posts as $p) {

            $annotation_data = array();

            $annotation_data['thumbnail'] = wp_get_attachment_image_url($p);
            $annotation_data['attachmentURL'] = '/wp-admin/upload.php?item=' . $p;

            $attachment = get_post($p);
            $annotation_data['file'] = $attachment->post_name;

            $image = null;

            if ($this->is_pro) {
                if (has_image_size('medium')) {
                    $image = wp_get_attachment_image_url($p, 'medium');
                } else {
                    $image = wp_get_original_image_url($p);
                }
            } else {
                $image = $this->get_filepath($p);
            }

            if ($image === false) {
                $response[] = new WP_Error('bad_image', 'Image filepath not found');
                continue;
            }

            $gcv_result = $this->gcv_client->get_annotation($image);
            error_log("result from server endpoint");
            error_log(print_r($gcv_result, true));

            if (is_wp_error($gcv_result)) {
                ++$errors;
                $annotation_data['gcv_data'] = $gcv_result;
                $response[] = $annotation_data;
                continue;
            }

            $cleaned_data = $this->clean_up_gcv_data($gcv_result);
            $alt = $this->update_image_alt_text($cleaned_data, $p, true);

            if (is_wp_error($alt)) {
                ++$errors;
            }

            $annotation_data['alt_text'] = $alt;

            $response[] = $annotation_data;
        }

        $response = new WP_REST_RESPONSE(array(
            'success' => true,
            'body' => array(
                'image_data' => $response,
                'count' => $query->found_posts - count($query->posts),
                'errors' => $errors,
            ),
        ), 200);

        $response->set_headers(array('Cache-Control' => 'no-cache'));

        return $response;
    }

    public function pro_api_bulk_sisa($request)
    {

        $params = $request->get_query_params();

        $now = time();
        $start = !empty($params['start']) ? $params['start'] : false;

        if (isset($start) && (string)(int)$start == $start && strlen($start) > 9) {
            $now = (int) $start;
        }

        $posts_per_page = 2;

        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'paged' => 1,
            'posts_per_page' => $posts_per_page,
            'date_query' => array(
                'before' => date('Y-m-d H:i:s', $now),
            ),
            'meta_query'  => array(
                'relation' => 'OR'
            ),
            'post_mime_type' => array('image/jpeg', 'image/gif', 'image/png', 'image/bmp'),
            'fields' => 'ids'
        );


        $annotation_options = array(
            '_wp_attachment_image_alt' => get_option('sisa_alt_text', (int) 1),
            'sisa_labels' => get_option('sisa_labels', (int) 0),
            'sisa_text' => get_option('sisa_text', (int) 0),
            'sisa_logos' => get_option('sisa_logos', (int) 0),
            'sisa_landmarks' => get_option('sisa_landmarks', (int) 0),
        );

        foreach ($annotation_options as $key => $value) {
            if ($value === 1) {
                $args['meta_query'][] = array(
                    'key' => $key,
                    'value' => '',
                    'compare' => '='
                );
                $args['meta_query'][] = array(
                    'key' => $key,
                    'compare' => 'NOT EXISTS'
                );
            }
        }

        $query = new WP_Query($args);

        if (false === $start) {
            return new WP_REST_RESPONSE(array(
                'success' => true,
                'body' => array(
                    'count' => $query->found_posts,
                    'errors' => 0,
                    'start' => $now,
                    'estimate' => $this->get_estimate($query->found_posts)
                ),
            ), 200);
        }

        if (!$query->have_posts()) {
            return new WP_REST_RESPONSE(array(
                'success' => true,
                'body' => array(
                    'image_data' => array(),
                    'status' => 'no images need annotation.'
                ),
            ), 200);
        }

        $response = array();
        $errors = 0;

        foreach ($query->posts as $p) {

            $annotation_data = array();

            $annotation_data['thumbnail'] = wp_get_attachment_image_url($p);
            $annotation_data['attachmentURL'] = '/wp-admin/upload.php?item=' . $p;

            $attachment = get_post($p);
            $annotation_data['file'] = $attachment->post_name;

            $image = null;

            if ($this->is_pro) {
                if (has_image_size('medium')) {
                    $image = wp_get_attachment_image_url($p, 'medium');
                } else {
                    $image = wp_get_original_image_url($p);
                }
            } else {
                $image = $this->get_filepath($p);
            }

            if ($image === false) {
                $response[] = new WP_Error('bad_image', 'Image filepath not found');
                continue;
            }

            if ($this->is_pro && $this->has_pro) {
                $features = $this->get_annotation_features($annotation_options);
            }

            $gcv_result = $this->gcv_client->get_annotation($image);
            error_log("result from server endpoint");
            error_log(print_r($gcv_result, true));

            if (is_wp_error($gcv_result)) {
                ++$errors;
                $annotation_data['gcv_data'] = $gcv_result;
                $response[] = $annotation_data;
                continue;
            }

            $cleaned_data = $this->clean_up_gcv_data($gcv_result);
            $alt = $this->update_image_alt_text($cleaned_data, $p, true);

            if (is_wp_error($alt)) {
                ++$errors;
            }

            $annotation_data['alt_text'] = $alt;

            $response[] = $annotation_data;
        }

        $response = new WP_REST_RESPONSE(array(
            'success' => true,
            'body' => array(
                'image_data' => $response,
                'count' => $query->found_posts - count($query->posts),
                'errors' => $errors,
            ),
        ), 200);

        $response->set_headers(array('Cache-Control' => 'no-cache'));

        return $response;
    }

    public function get_annotation_features($annotation_options)
    {
        $features = array();
        $feature_lookup = array(
            '_wp_attachment_image_alt' => 'OBJECT_LOCALIZATION,WEB_DETECTION',
            'sisa_alt_text' => 'OBJECT_LOCALIZATION,WEB_DETECTION',
            'sisa_labels' => 'LABEL_DETECTION',
            'sisa_text' => 'TEXT_DETECTION',
            'sisa_logos' => 'LOGO_DETECTION',
            'sisa_landmarks' => 'LANDMARK_DETECTION',
        );

        foreach ($annotation_options as $key => $value) {
            if ($value === 1) {
                $features[] = $feature_lookup[$key];
            }
        }
        return $features;
    }

    public function get_filepath($p)
    {
        $wp_metadata = wp_get_attachment_metadata($p);
        if (!is_array($wp_metadata) || !isset($wp_metadata['file'])) {
            return false;
        }
        $upload_dir = wp_upload_dir();
        $path_prefix = $upload_dir['basedir'] . '/';
        $path_info = pathinfo($wp_metadata['file']);
        if (isset($path_info['dirname'])) {
            $path_prefix .= $path_info['dirname'] . '/';
        }

        /* Do not use pathinfo for getting the filename.
        It doesn't work when the filename starts with a special character. */
        $path_parts = explode('/', $wp_metadata['file']);
        $name = end($path_parts);
        $filename = $path_prefix . $name;
        return $filename;
    }

    public function clean_up_gcv_data($data)
    {
        $cleaned_data = array();
        $min_score = 0.6;

        if (isset($data->landmarkAnnotations) && !empty($data->landmarkAnnotations)) {
            if ($data->landmarkAnnotations[0]->score >= $min_score) {
                $cleaned_data['landmark'] = $data->landmarkAnnotations[0]->description;
            }
        }
        if (isset($data->labelAnnotations) && !empty($data->labelAnnotations)) {
            $labels = array();
            foreach ($data->labelAnnotations as $label) {
                if ($label->score >= $min_score) {
                    $labels[] = strtolower($label->description);
                }
            }
            $cleaned_data['labels'] = array_values(array_unique($labels));
        }
        if (isset($data->webDetection) && !empty($data->webDetection)) {
            $web_entities = array();
            foreach ($data->webDetection->webEntities as $entity) {
                if (isset($entity->description) && $entity->score >= $min_score)
                    $web_entities[] = strtolower($entity->description);
            }
            $cleaned_data['webEntities'] = array_values(array_unique($web_entities));
            if (isset($data->webDetection->bestGuessLabels) && !empty($data->webDetection->bestGuessLabels)) {
                $web_labels = array();
                foreach ($data->webDetection->bestGuessLabels as $web_label) {
                    if (isset($web_label->label)) {
                        $web_labels[] = $web_label->label;
                    }
                }
                $cleaned_data['webLabels'] = array_values(array_unique($web_labels));
            }
        }
        if (isset($data->localizedObjectAnnotations) && !empty($data->localizedObjectAnnotations)) {
            $objects = array();
            foreach ($data->localizedObjectAnnotations as $object) {
                if ($object->score >= $min_score) {
                    $objects[] = strtolower($object->name);
                }
            }
            $cleaned_data['objects'] =  array_values(array_unique($objects));
        }
        if (isset($data->logoAnnotations) && !empty($data->logoAnnotations)) {
            $logos = array();
            foreach ($data->logoAnnotations as $logo) {
                if ($logo->score >= $min_score) {
                    $logos[] = $logo->description;
                }
            }
            $cleaned_data['logos'] = array_values(array_unique($logos));
        }
        if (isset($data->textAnnotations) && !empty($data->textAnnotations)) {
            $text = $data->textAnnotations[0]->description;
            $cleaned_data['text'] = $text;
        }
        return $cleaned_data;
    }

    public function update_image_alt_text($cleaned_data, $p, $save_alt)
    {
        $success = true;
        $alt = '';

        if (is_array($cleaned_data['webLabels']) && !empty($cleaned_data['webLabels'][0])) {
            $alt = $cleaned_data['webLabels'][0];
        } elseif (is_array($cleaned_data['webEntities']) && !empty($cleaned_data['webEntities'][0])) {
            $alt = $cleaned_data['webEntities'][0];
        } else {
            $alt = $cleaned_data['objects'][0];
        }

        if (!empty($existing = get_post_meta($p, '_wp_attachment_image_alt', true))) {
            return array('existing' => $existing, 'smartimage' => $alt);
        }

        $success = update_post_meta($p, '_wp_attachment_image_alt', $alt);

        if (false === $success) {
            return new WP_Error(500, 'Failed to update alt text.', $alt);
        }

        return array('existing' => '', 'smartimage' => $alt);
    }

    public function enqueue_scripts($hook)
    {

        // only load scripts on dashboard and settings page
        global $sisa_settings_page;
        if ($hook != 'index.php' && $hook != $sisa_settings_page) {
            return;
        }

        if (in_array($_SERVER['REMOTE_ADDR'], array('172.23.0.8', '::1'))) {
            // DEV React dynamic loading
            $js_to_load = 'http://localhost:3000/static/js/bundle.js';
        } else {
            $react_app_manifest = file_get_contents(__DIR__ . '/react-frontend/build/asset-manifest.json');
            if ($react_app_manifest !== false) {
                $manifest_json = json_decode($react_app_manifest, true);
                $main_css = $manifest_json['files']['main.css'];
                $main_js = $manifest_json['files']['main.js'];
                $js_to_load = plugin_dir_url(__FILE__) . '/react-frontend/build' . $main_js;

                $css_to_load = plugin_dir_url(__FILE__) . '/react-frontend/build' . $main_css;
                wp_enqueue_style('smartimagesearch_styles', $css_to_load);
            }
        }

        wp_enqueue_script('smartimagesearch_react', $js_to_load, '', mt_rand(10, 1000), true);
        wp_localize_script('smartimagesearch_react', 'smartimagesearch_ajax', array(
            'urls' => array(
                'proxy' => rest_url('smartimagesearch/v1/proxy'),
                'settings' => rest_url('smartimagesearch/v1/settings'),
                'media' => rest_url('wp/v2/media'),
            ),
            'nonce' => wp_create_nonce('wp_rest'),
        ));
    }

    public function process_attachment_upload($metadata, $attachment_id)
    {
        $annotate_upload = get_option('sisa_on_media_upload', 'async');
        if ($annotate_upload == 'async') {
            $this->async_annotate($metadata, $attachment_id);
        } elseif ($annotate_upload == 'blocking') {
            $this->blocking_annotate($metadata, $attachment_id);
        }
        return $metadata;
    }

    //Does an "async" smart annotation by making an ajax request right after image upload
    public function async_annotate($metadata, $attachment_id)
    {
        $context     = 'wp';
        $action      = 'sisa_async_annotate_upload_new_media';
        $_ajax_nonce = wp_create_nonce('sisa_new_media-' . $attachment_id);
        $body = compact('action', '_ajax_nonce', 'metadata', 'attachment_id', 'context');

        $args = array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'body'      => $body,
            'cookies'   => isset($_COOKIE) && is_array($_COOKIE) ? $_COOKIE : array(),
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        );

        if (getenv('WORDPRESS_HOST') !== false) {
            wp_remote_post(getenv('WORDPRESS_HOST') . '/wp-admin/admin-ajax.php', $args);
        } else {
            wp_remote_post(admin_url('admin-ajax.php'), $args);
        }
    }

    public function ajax_annotate_on_upload()
    {
        if (!is_array($_POST['metadata'])) exit();

        if (current_user_can('upload_files')) {

            $attachment_id = intval($_POST['attachment_id']);
            $image_file_path = $this->get_filepath($attachment_id);

            $gcv_client = new SmartImageSearch_GCV_Client();
            $gcv_result = $gcv_client->get_annotation($image_file_path);

            if (!is_wp_error($gcv_result)) {

                $cleaned_data = $this->clean_up_gcv_data($gcv_result);
                $this->update_image_alt_text($cleaned_data, $attachment_id, true);
            }
        }
        exit();
    }

    public function blocking_annotate($metadata, $attachment_id)
    {

        if (current_user_can('upload_files') && is_array($metadata)) {

            $image_file_path = $this->get_filepath($attachment_id);

            $gcv_client = new SmartImageSearch_GCV_Client();
            $gcv_result = $gcv_client->get_annotation($image_file_path);

            if (!is_wp_error($gcv_result)) {

                $cleaned_data = $this->clean_up_gcv_data($gcv_result);
                $this->update_image_alt_text($cleaned_data, $attachment_id, true);
            }
        }

        return $metadata;
    }

    public function admin_menu()
    {
        global $sisa_settings_page;
        $sisa_settings_page = add_media_page(
            __('Bulk Image Alt Text'),
            esc_html__('Bulk Alt Text'),
            'manage_options',
            'smartimagesearch',
            array($this, 'smartimagesearch_settings_do_page')
        );
    }

    public function delete_all_alt_text()
    {
        global $wpdb;
        $results = $wpdb->delete(
            $wpdb->prefix . 'postmeta',
            array('meta_key' => '_wp_attachment_image_alt'),
            array('%s')
        );
        return $results;
    }

    public function smartimagesearch_settings_do_page()
    {
?>
        <div id="smartimagesearch_settings"></div>
        <div id="smartimagesearch_dashboard"></div>
<?php
    }
}
