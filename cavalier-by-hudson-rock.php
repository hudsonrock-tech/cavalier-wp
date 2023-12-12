<?php
/*
Plugin Name: Cavalier by Hudson Rock
Description: Cavalier plugin by Hudson Rock allows a one-click interface to monitor a database with millions of compromised computers.
Version: 1.0
Author: Hudson Rock
Author URI: https://www.hudsonrock.com
Text Domain: cavalier-wp-plugin
*/

function is_admin_user()
{
    return current_user_can('manage_options');
}

function enqueue_plugin_styles()
{
    $plugin_url = plugin_dir_url(__FILE__);
    wp_enqueue_style('style', $plugin_url . "/stylesheet.css");
}

add_action('admin_print_styles', 'enqueue_plugin_styles');

function enqueue_plugin_scripts()
{
    $plugin_url = plugin_dir_url(__FILE__);
    wp_enqueue_script('plugin-scripts', $plugin_url . 'scripts.js', array('jquery'), '1.0', true);
}

add_action('admin_enqueue_scripts', 'enqueue_plugin_scripts');


register_activation_hook(__FILE__, 'cavalier_activate');

function cavalier_activate()
{
    if (!is_admin_user())
        return;
    delete_option("cavalier_token");
    delete_option('cavalier_domain');
    delete_option('cavalier_dns_record');
    delete_option('cavalier_login_url');

    $current_user = wp_get_current_user();
    $wp_username = $current_user->user_login;
    $wp_email = $current_user->user_email;
    $current_url = home_url();
    $parsed_url = parse_url($current_url);
    $wp_domain = preg_replace('/^(?:[a-zA-Z0-9-]+\.)*([a-zA-Z0-9-]+\.[a-zA-Z]{2,})$/', '$1', $parsed_url['host']);
    $request_body = json_encode(array(
        'domain' => $wp_domain,
        'username' => $wp_username,
        'email' => $wp_email,
    ));

    $args = array(
        'body' => $request_body,
        'headers' => array('Content-Type' => 'application/json'),
    );

    $response = wp_safe_remote_post('https://cavalier.hudsonrock.com/api/user/wp-register', $args);

    if (is_wp_error($response)) {
        error_log('Cavalier Plugin: Failed to retrieve data - ' . $response->get_error_message());
    } else {
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body);

        if ($data && isset($data->wp_domain) && isset($data->wp_dns_record)) {
            update_option('cavalier_domain', $data->wp_domain);
            update_option('cavalier_dns_record', $data->wp_dns_record);
        } else {
            error_log('Cavalier Plugin: Invalid or missing data in the API response.');
        }
    }
}

add_action('admin_menu', 'cavalier_admin_menu');

function cavalier_admin_menu()
{
    if (!is_admin_user())
        return;
    add_menu_page(
        'Cavalier Plugin',
        'Cavalier',
        'manage_options',
        'cavalier-admin-page',
        'cavalier_admin_page',
        'dashicons-privacy'
    );

    add_submenu_page(
        'cavalier-admin-page',
        'Verification',
        'Verification',
        'manage_options',
        'cavalier-admin-page'
    );

    add_submenu_page(
        'cavalier-admin-page',
        'Domain Data',
        'Domain Data',
        'manage_options',
        'cavalier-data',
        'cavalier_data_page'
    );
}


function cavalier_verify_link()
{
    $url = admin_url('admin.php?page=cavalier-admin-page');
    echo '<a href="' . esc_url($url) . '" class="button button-primary">Verify Domain</a>';
}

function cavalier_data_button($txt)
{
    $url = admin_url('admin.php?page=cavalier-data');
    echo '<a href="' . esc_url($url) . '" class="button button-primary">' . $txt . '</a>';
}

function cavalier_login_button()
{
    $cavalier_login_url = get_option('cavalier_login_url');
    $url = "https://cavalier.hudsonrock.com/tokenaccess?token=$cavalier_login_url";
    echo '<a href="' . esc_url($url) . '" class="button button-primary" style="font-weight:bold;" target="_blank">View in Cavalier <span class="dashicons dashicons-external" style="margin-top:2px;"></span></a>';
}

function cavalier_admin_page()
{
    if (!is_admin_user())
        return;
    $domain = get_option('cavalier_domain');
    $dns_record = get_option('cavalier_dns_record');
    $cavalier_token = get_option('cavalier_token');
    echo '<div class="wrap">';
    echo '<img src="https://cavalier.hudsonrock.com/static/media/logo-1.967abb2c.png" style="width:60px;"></img>';
    echo '<h1>Cavalier Verification Details</h1>';

    if (!empty($domain) && !empty($dns_record)) {
        echo '<p>Domain: ' . esc_html($domain) . '</p>';
        if ($cavalier_token) {
            echo '<p>✅ Your domain is verified.</p>';
            cavalier_data_button("Go to Cavalier Data");
        } else {
            echo '<p>Verification Code: <span id="cavalier-dns-record">' . esc_html($dns_record) . '</span>';
            echo ' <button id="copy-dns-record" class="button button-secondary">Click to Copy</button></p>';
            echo '<h2 style="font-weight:bold;">Create a new TXT record →</h2>';
            echo '<ul>';
            echo '<li>➢	Look for a field labeled <b>Name</b>, <b>Host</b>, or <b>Alias</b>. Enter @. (If @ causes an error leave this field blank).</li>';
            echo '<li>➢	Paste the verification code you copied into the field labeled <b>Value</b>, <b>Answer</b>, <b>Destination</b>, or <b>Server</b>.</li>';
            echo '<li>➢	Enter <b>1 hour</b> in the <b>TTL</b> field, or you can leave the default value.</li>';
            echo '</ul>';
            echo '<p>Check the Cavalier data page after verification.</p>';
            echo '<p><b>*DNS changes may take 4-8 hours to propagate.</b></p>';
            cavalier_data_button("Check Verification");
        }
    } else {
        echo '<p>Data not available. Please check the plugin activation or contact support.</p>';
    }

    echo '</div>';

    echo '<script>
        document.getElementById("copy-dns-record").addEventListener("click", function() {
            var dnsRecord = document.getElementById("cavalier-dns-record");
            var tempInput = document.createElement("input");
            document.body.appendChild(tempInput);
            tempInput.setAttribute("value", dnsRecord.textContent);
            tempInput.select();
            document.execCommand("copy");
            document.body.removeChild(tempInput);
            alert("DNS Record copied to clipboard!");
        });
    </script>';
}

function verifyAndSaveToken()
{
    if (!is_admin_user())
        return;
    $domain = get_option('cavalier_domain');

    if (!empty($domain)) {
        $url = 'https://cavalier.hudsonrock.com/api/user/wp-verify';
        $data = json_encode(array('domain' => $domain));

        $response = wp_safe_remote_post($url, array(
            'body' => $data,
            'headers' => array('Content-Type' => 'application/json'),
        ));

        if (!is_wp_error($response)) {
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body);

            if ($data && isset($data->token)) {
                update_option('cavalier_token', $data->token);
                update_option('cavalier_login_url', $data->url_hash);
            } else {
                echo 'Domain verification failed. Please try again.';
            }
        } else {
            echo 'Error verifying domain: ' . esc_html($response->get_error_message());
        }
    } else {
        echo 'Domain is not set. Please check the plugin activation.';
    }
}


function cavalier_data_page()
{
    if (!is_admin_user())
        return;
    $domain = get_option('cavalier_domain');
    $cavalier_token = get_option('cavalier_token');

    echo '<div class="wrap">';
    echo '<img src="https://cavalier.hudsonrock.com/static/media/logo-1.967abb2c.png" style="width:60px;"></img>';
    echo '<h1>Cavalier Data (' . $domain . ')</h1>';

    if (!$cavalier_token) {
        verifyAndSaveToken();
    }

    $counts = count_employees_users();
    $default_type = $counts["employees"] > 0 ? "employee" : "client";
    $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : $default_type;
    $cavalier_token = get_option('cavalier_token');

    if ($cavalier_token) {
        echo '<div class="styledBox">To view plaintext passwords, extract data,<br/>and use additional features, please login to Cavalier.</div>';
        cavalier_login_button();
        $page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $api_url = "https://cavalier.hudsonrock.com/api/json/v2/search-by-domain/wp?token=$cavalier_token&page=$page&type=$type";

        $response = wp_safe_remote_post($api_url);

        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            $count = $data['count'];
            echo '<div class="nav-tab-wrapper">';
            if ($counts["employees"] > 0) {
                echo '<a style="margin-left:0px;" href="?page=cavalier-data&type=employee" class="nav-tab ' . ($type === 'employee' ? 'nav-tab-active' : '') . '"> Employees ' . ($count && $type === 'employee' ? '(' . $count . ')' : '') . '</a>';
            }
            if ($counts["users"] > 0) {
                echo '<a style="margin-left:0px;" href="?page=cavalier-data&type=client" class="nav-tab ' . ($type === 'client' ? 'nav-tab-active' : '') . '">Users ' . ($count && $type === 'client' ? '(' . $count . ')' : '') . '</a>';
            }
            echo '</div>';
            if ($data && isset($data['success']) && $data['success'] === true) {
                echo '<table class="wp-list-table widefat fixed striped table-view-list pages">';
                echo '<thead>';
                echo '<tr>';
                echo '<th>Stealer</th>';
                echo '<th>URL</th>';
                echo '<th>Login</th>';
                echo '<th>Password</th>';
                echo '<th>IP</th>';
                echo '<th>Date Compromised</th>';
                echo '<th>Date Uploaded</th>';
                echo '</tr>';
                echo '</thead>';
                echo '<tbody>';

                foreach ($data['data'] as $item) {
                    $row_id = 'row_' . uniqid();
                    echo '<tr>';
                    echo '<td>' . $item['stealer'] . '</td>';
                    echo '<td>' . ($item['url'] ?? 'Not Found') . '</td>';
                    echo '<td>' . ($item['username'] ?? 'Not Found') . '</td>';
                    echo '<td class="blur">' . ($item['password'] ?? 'Not Found') . '</td>';
                    echo '<td>' . ($item['ip'] ?? 'Not Found') . '</td>';
                    echo '<td>' . ($item['date_compromised'] ?? 'Not Found') . '</td>';
                    echo '<td>' . ($item['date_uploaded'] ?? 'Not Found') . '</td>';
                    echo '</tr>';
                }

                echo '</tbody>';
                echo '</table>';

                $total_pages = $data['total_pages'];
                if ($total_pages > 1) {
                    echo '<div class="tablenav">';
                    echo '<div class="tablenav-pages" style="text-align:center;float:none;">';

                    $current_url = add_query_arg('paged', $page, $_SERVER['REQUEST_URI']);

                    $prev_page = max($page - 1, 1);
                    if ($page > 1) {
                        echo '<a class="button button-secondary" style="margin:0px 2px;" href="' . esc_url(add_query_arg('paged', $prev_page, $current_url)) . '">&laquo; Previous</a>';
                    }

                    $next_page = min($page + 1, $total_pages);
                    if ($page < $total_pages) {
                        echo '<a class="button button-secondary" style="margin:0px 2px;" href="' . esc_url(add_query_arg('paged', $next_page, $current_url)) . '">Next &raquo;</a>';
                    }

                    echo '</div>';
                    echo '</div>';
                }
            } else {
                echo '<h2>No data to show.</h2>';
            }
        } else {
            echo '<p>Error fetching data from the API: ' . esc_html($response->get_error_message()) . '</p>';
        }
    } else {
        echo '<p>Domain is not verified. Please verify the domain first.</p>';
        cavalier_verify_link();
    }

    echo '</div>';
}

function count_employees_users()
{
    $cavalier_token = get_option('cavalier_token');
    if ($cavalier_token) {
        $api_url = "https://cavalier.hudsonrock.com/api/json/v2/search-by-domain/wp/count?token=$cavalier_token";
        $response = wp_safe_remote_post($api_url);
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            return $data;
        }
    }
}

function check_resolved_dates()
{
    $cavalier_token = get_option('cavalier_token');
    if ($cavalier_token) {
        $api_url = "https://cavalier.hudsonrock.com/api/wp/notice-bar?token=$cavalier_token";
        $response = wp_safe_remote_post($api_url);
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            return $data;
        }
    }
}

function cavalier_notice_bar()
{
    if (!is_admin_user())
        return;
    $cavalier_token = get_option('cavalier_token');
    if ($cavalier_token) {
        $data = count_employees_users();
        $resolved_dates = check_resolved_dates();
        $employees = $data['employees'];
        $users = $data['users'];
        $show_employees = $resolved_dates['employees'];
        $show_users = $resolved_dates['users'];
        if ($show_employees && $employees > 0) {
            echo '<div class="employees-notice-bar cavalier-notice-bar">';
            echo '<div class="notice-content"><div><img src="https://cavalier.hudsonrock.com/static/media/logo-1.967abb2c.png" width="30"/> New Compromised Employee Credentials Detected on Your Domain.</div><span style="color:white;" class="close-notice pointer" data-token="' . esc_attr($cavalier_token) . '" data-value="employees">X</span></div>';
            echo '</div>';
        }
        if ($show_users && $users > 0) {
            echo '<div class="users-notice-bar cavalier-notice-bar">';
            echo '<div class="notice-content"><div><img src="https://cavalier.hudsonrock.com/static/media/logo-1.967abb2c.png" width="30"/> New Compromised User Credentials Detected on Your Domain.</div><span style="color:white;" class="close-notice pointer" data-token="' . esc_attr($cavalier_token) . '" data-value="users">X</span></div>';
            echo '</div>';
        }
    }
}

add_action('admin_head', 'cavalier_notice_bar');
