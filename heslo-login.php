<?php
/**
 * Plugin Name: Heslo Login
 * Plugin URI:  https://developer.wordpress.org/plugins/the-basics/
 * Description: Passwordless login for your users using Touch and Face ID
 * Version:     1.0.82
 * Author:      Heslo
 * Author URI:  https://www.getheslo.com
 * Text Domain: heslo-login
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if (!defined('WPINC'))
{
    die;
}

function is_local_dev()
{
    if (in_array($_SERVER['REMOTE_ADDR'], array(
        '10.255.0.2',
        '172.18.0.1',
        '::1'
    )))
    {
        return true;
    }
}

/**
 * Enqueue widget scripts.
 */
function widget_script()
{
    $client_key = get_option('heslo_login_client_key');
    $js_to_load = is_local_dev() ? 'http://localhost:3000/login/script' : 'https://channel-api.getheslo.com/login/script';
?>
        <script src="<?php echo $js_to_load ?>?application_client_id=<?php echo $client_key ?>"></script>
    <?php
}
function login_widget_script()
{
    $auto_installation = get_option('heslo_login_auto_installation');
    return $auto_installation == 'true' ? widget_script() : '';
}
add_action('wp_head', 'widget_script');
add_action('login_enqueue_scripts', 'login_widget_script');

add_action('admin_enqueue_scripts', function ($hook)
{
    // only load scripts on dashboard and settings page
    global $heslologin_settings_page;
    if ($hook != 'index.php' && $hook != $heslologin_settings_page)
    {
        return;
    }

    if (is_local_dev())
    {
        // DEV React dynamic loading
        $js_to_load = 'http://localhost:3000/static/js/bundle.js';
    }
    else
    {
        $js_to_load = plugins_url('assets/app.js', __FILE__);
    }

    wp_enqueue_script('heslo_login_react', $js_to_load, '', time() , true);

    wp_localize_script('heslo_login_react', 'heslo_login_ajax', array(
        'urls' => array(
            'auth' => rest_url('heslo-login/v1/auth') ,
            'callback' => rest_url('heslo-login/v1/auth/callback') ,
            'getConfig' => rest_url('heslo-login/v1/settings') ,
            'setConfig' => rest_url('heslo-login/v1/settings')
        ) ,
        'nonce' => wp_create_nonce('wp_rest') ,
    ));

});

add_action('admin_menu', function ()
{
    global $heslologin_settings_page;
    $heslologin_settings_page = add_options_page('Heslo Login Settings', 'Heslo Login', 'manage_options', 'heslo-login-settings', 'heslo_login_settings_do_page');
    // Draw the menu page itself
    function heslo_login_settings_do_page()
    {
?>
	  <div id="heslo-login-settings"></div>
	  <?php
    }

    // add link to settings on plugin page (next to "Deactivate")
    add_filter('plugin_action_links_' . plugin_basename(__FILE__) , function ($links)
    {
        $settings_link = '<a href="options-general.php?page=heslo-login-settings">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    });
});

function heslologin_footer_text($default)
{
    // Retun default on non-plugin pages
    $screen = get_current_screen();
    if ($screen->id !== "settings_page_heslo-login-settings")
    {
        return $default;
    }

    $heslologin_footer_text = sprintf(__('Like this plugin? Please leave a <a href="%s" target="_blank">&#9733;&#9733;&#9733;&#9733;&#9733;</a> rating to support continued development. Thanks!', 'heslo-login') , 'https://wordpress.org/support/plugin/heslo/reviews/?rate=5#new-post');

    return $heslologin_footer_text;
}

function sess_start()
{
    if (!session_id()) session_start();
}
add_action('init', 'sess_start');

add_filter('admin_footer_text', 'heslologin_footer_text');

// Base64-urlencoding is a simple variation on base64-encoding
// Instead of +/ we use -_, and the trailing = are removed.
function base64_urlencode($string)
{
    return rtrim(strtr(base64_encode($string) , '+/', '-_') , '=');
}

function heslo_login_auth($request)
{
	$metadata = is_local_dev() ? 'http://localhost:9000/.well-known/openid-configuration' : 'https://api.getheslo.com/.well-known/openid-configuration';

    $identifier = $_GET['identifier'];
    $client_key = get_option('heslo_login_client_key');
    $redirect_uri = rest_url('heslo-login/v1/auth/callback');
    $_SESSION['heslo_login_state'] = bin2hex(random_bytes(5));
    $_SESSION['heslo_login_code_verifier'] = bin2hex(random_bytes(50));
    $code_challenge = base64_urlencode(hash('sha256', $_SESSION['heslo_login_code_verifier'], true));

    $authorize_url = $metadata->authorization_endpoint . '?' . http_build_query(['response_type' => 'code', 'client_id' => $client_key, 'redirect_uri' => $redirect_uri, 'state' => $_SESSION['heslo_login_state'], 'scope' => 'openid profile email', 'code_challenge' => $code_challenge, 'code_challenge_method' => 'S256', 'identifier' => $identifier, ]);

    header("Location: $authorize_url");
    exit;
}

function heslo_login_auth_callback($request)
{
	$metadata = is_local_dev() ? 'http://localhost:9000/.well-known/openid-configuration' : 'https://api.getheslo.com/.well-known/openid-configuration';

    $client_key = get_option('heslo_login_client_key');
    $secret_key = get_option('heslo_login_secret_key');
    $redirect_uri = rest_url('heslo-login/v1/auth/callback');
    if ($_SESSION['heslo_login_state'] != $_GET['state'])
    {
        die("Authorization server returned an invalid state parameter");
    }

    if (isset($_GET['error']))
    {
        die('Authorization server returned an error: ' . htmlspecialchars($_GET['error']));
    }

    $response = http($metadata->token_endpoint, ['grant_type' => 'authorization_code', 'code' => $_GET['code'], 'redirect_uri' => $redirect_uri, 'client_id' => $client_key, 'client_secret' => $secret_key, 'code_verifier' => $_SESSION['heslo_login_code_verifier']]);

    if (!isset($response->access_token))
    {
        die('Error fetching access token');
    }

    $userinfo = http($metadata->userinfo_endpoint, ['access_token' => $response->access_token, ]);

    if ($userinfo->sub)
    {
        $_SESSION['sub'] = $userinfo->sub;
        $_SESSION['username'] = $userinfo->preferred_username;
        $_SESSION['profile'] = $userinfo;

        $user = get_user_by('email', $userinfo->email);

        if ($user)
        {
            login_user($user->ID);
        }
        else
        {
            $random_password = wp_generate_password($length = 12, $include_standard_special_chars = false);
            var_dump($userinfo);
            $userdata = array(
                'user_login' => $userinfo->email,
                'user_email' => $userinfo->email,
                'user_pass' => $random_password,
                'first_name' => $userinfo->given_name,
                'last_name' => $userinfo->family_name,
            );
            echo "creating a new user - $userinfo->email";
            $user_id = wp_insert_user($userdata);
            if (!is_wp_error($user_id))
            {
                echo "User created : " . $user_id;
            }
            login_user($user_id);
        }

    }
}

function heslo_login_get_settings($request)
{
    $client_key = get_option('heslo_login_client_key');
    $secret_key = get_option('heslo_login_secret_key');
    $auto_installation = get_option('heslo_login_auto_installation');
    return new WP_REST_RESPONSE(array(
        'success' => true,
        'value' => array(
            'clientKey' => !$client_key ? '' : $client_key,
            'secretKey' => !$secret_key ? '' : $secret_key,
            'autoInstallation' => !$auto_installation ? '' : $auto_installation,
        )
    ) , 200);
}

// save settings to WP DB
function heslo_login_update_settings($request)
{
    $json = $request->get_json_params();
    // store the values in wp_options table
    $updated_client_key = update_option('heslo_login_client_key', $json['clientKey']);
    $updated_secret_key = update_option('heslo_login_secret_key', $json['secretKey']);
    $updated_auto_installation = update_option('heslo_login_auto_installation', $json['autoInstallation']);
    return new WP_REST_RESPONSE(array(
        'success' => $updated_client_key && $updated_secret_key && $updated_auto_installation,
        'value' => $json
    ) , 200);
}

// check permissions
function heslo_login_settings_permissions_check()
{
    // Restrict endpoint to only users who have the capability to manage options.
    if (current_user_can('manage_options'))
    {
        return true;
    }

    return new WP_Error('rest_forbidden', esc_html__('You do not have permissions to view this data.', 'heslo-login') , array(
        'status' => 401
    ));;
}

add_action('rest_api_init', function ()
{
    register_rest_route('heslo-login/v1', '/auth', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'heslo_login_auth'
    ));
    register_rest_route('heslo-login/v1', '/auth/callback', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'heslo_login_auth_callback'
    ));
    register_rest_route('heslo-login/v1', '/settings', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'heslo_login_get_settings',
        'permission_callback' => 'heslo_login_settings_permissions_check'
    ));
    register_rest_route('heslo-login/v1', '/settings', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'heslo_login_update_settings',
        'permission_callback' => 'heslo_login_settings_permissions_check'
    ));
});

function login_shortcode()
{

    $client_key = get_option('heslo_login_client_key');

    // Things that you want to do.
    $message = "<heslo-login-button id='$client_key' />";

    // Output needs to be return
    return $message;
}
// register shortcode
add_shortcode('heslo_login', 'login_shortcode');

function http($url, $params = false)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($params) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    return json_decode(curl_exec($ch));
}

function login_user($user_id)
{
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id);

    $message = new stdClass();
    $message->type = "redirect";
    $message->url = home_url();
    $message->user_id = $user_id;

    $result_message = json_encode($message);

    header("Content-Type:text/html");

    ob_start();
    echo "<html><body><script>window.opener.postMessage($result_message, '*');window.close();</script></body></html>";
    ob_flush();
    ob_end_clean();

}
