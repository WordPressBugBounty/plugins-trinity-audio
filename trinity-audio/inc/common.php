<?php
  if (!defined('ABSPATH')) exit;
  require_once ABSPATH . 'wp-admin/includes/plugin.php'; // for get_plugin_data()
  require_once __DIR__ . '/constants.php';

  /**
   * A JSON call to Trinity's Integration API. A status other than 2xx is logged; the caller maps it.
   *
   * @param array $options
   *   - method: string, the HTTP method
   *   - path: string, under TRINITY_AUDIO_INTEGRATION_API, e.g. '/v1/wordpress/unit'
   *   - body: array|string, optional. An array is sent as JSON without its null values (the form encoding Sergio
   *     got left them out too); a string is sent as it is
   *   - headers: array, optional, extra request headers
   * @return array|null ['status' => HTTP status, 'body' => the decoded answer], null when it could not be reached
   */
  function trinity_api_request(array $options) {
    $start   = microtime(true);
    $method  = $options['method'];
    $url     = TRINITY_AUDIO_INTEGRATION_API . $options['path'];
    $body    = $options['body'] ?? null;
    $headers = $options['headers'] ?? [];
    $args    = [
      'method'      => $method,
      'timeout'     => TRINITY_AUDIO_MAX_HTTP_REQ_TIMEOUT,
      'httpversion' => '1.1',
      'sslverify'   => false,
      'headers'     => array_merge(['Content-Type' => 'application/json'], $headers)
    ];
    if (is_array($body)) $body = wp_json_encode(array_filter($body, function ($value) {
      return $value !== null;
    }));
    if ($body !== null) $args['body'] = $body;

    $response = wp_remote_request($url, $args);

    trinity_report_long_requests($start, $url);

    if (is_wp_error($response)) {
      trinity_log("Can't reach $method $url", $response->get_error_message(), '', TRINITY_AUDIO_ERROR_TYPES::error);
      return null;
    }

    $status        = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    if (!trinity_api_is_status_ok($status)) trinity_log("$method $url answered $status", $response_body, '', TRINITY_AUDIO_ERROR_TYPES::warn);

    return [
      'status' => $status,
      'body'   => json_decode($response_body, true)
    ];
  }

  function trinity_api_is_status_ok($status) {
    return $status >= 200 && $status < 300;
  }

  function trinity_api_is_ok($result) {
    return $result !== null && trinity_api_is_status_ok($result['status']);
  }

  /**
   * The answer as the plugin's own `code` (what send_response shows), the way Sergio mapped Integration API's.
   *
   * @param array|null $result trinity_api_request's
   * @param array $codes the flow's Integration API error code => plugin code
   * @return string
   */
  function trinity_api_code($result, $codes) {
    if ($result === null || $result['status'] >= 500) return 'ERROR_NETWORK';
    if (trinity_api_is_status_ok($result['status'])) return 'SUCCESS';

    return $codes[$result['body']['code'] ?? ''] ?? 'ERROR';
  }

  /** The site's unit on Integration API, by its install key: `/v1/wordpress/unit/{installkey}{suffix}`. */
  function trinity_unit_path($suffix = '') {
    return TRINITY_AUDIO_UNIT_PATH . '/' . rawurlencode(trinity_get_install_key()) . $suffix;
  }

  function trinity_is_user_admin() {
    return current_user_can('manage_options');
  }

  function trinity_get_install_key() {
    return get_option(TRINITY_AUDIO_INSTALLKEY);
  }

  function trinity_get_view_key() {
    return get_option(TRINITY_AUDIO_VIEWKEY);
  }

  function trinity_get_db_plugin_version() {
    return get_option(TRINITY_AUDIO_PLUGIN_VERSION, []);
  }

  function trinity_get_plugin_migration() {
    return get_option(TRINITY_AUDIO_PLUGIN_MIGRATION, []);
  }

  function trinity_get_source_language() {
    return get_option(TRINITY_AUDIO_SOURCE_LANGUAGE);
  }

  function trinity_get_new_posts_default() {
    return get_option(TRINITY_AUDIO_SOURCE_NEW_POSTS_DEFAULT);
  }

  function trinity_get_powered_by() {
    return !!get_option(TRINITY_AUDIO_POWERED_BY);
  }

  function trinity_get_preconnect() {
    return !!get_option(TRINITY_AUDIO_PRECONNECT);
  }

  function trinity_get_player_label() {
    return get_option(TRINITY_AUDIO_PLAYER_LABEL);
  }

  function trinity_get_skip_tags() {
    return get_option(TRINITY_AUDIO_SKIP_TAGS, []);
  }

  function trinity_get_allowed_shortcodes() {
    return get_option(TRINITY_AUDIO_ALLOW_SHORTCODES, []);
  }

  function trinity_get_is_first_changes_saved() {
    return get_option(TRINITY_AUDIO_FIRST_CHANGES_SAVE);
  }

  function trinity_get_check_for_loop() {
    return get_option(TRINITY_AUDIO_CHECK_FOR_LOOP);
  }

  function trinity_get_enable_for_api() {
    return get_option(TRINITY_AUDIO_ACTIVATE_ON_API_POST_CREATION);
  }

  function trinity_set_is_account_key_linked() {
    return add_option(TRINITY_AUDIO_IS_ACCOUNT_KEY_LINKED, true);
  }

  function trinity_get_is_account_key_linked() {
    return get_option(TRINITY_AUDIO_IS_ACCOUNT_KEY_LINKED);
  }

  function trinity_get_first_time_install() {
    return get_transient(TRINITY_AUDIO_FIRST_TIME_INSTALL);
  }

  function trinity_is_enabled_for_post($post_id) {
    return get_post_meta($post_id, TRINITY_AUDIO_ENABLED, true);
  }

  function trinity_get_plugin_version() {
    $plugin_data = get_plugin_data(__DIR__ . '/../trinity.php');
    return $plugin_data['Version'];
  }

  function trinity_include_audio_player($page_content) {
    $date    = trinity_get_date();
    $post_id = $GLOBALS['post']->ID;

    $post_hash = trinity_ph_get_audio_posthash($post_id);

    $whitelist_shortcodes = trinity_get_allowed_shortcodes();
    $title                = trinity_get_clean_text(get_the_title($post_id), '', $whitelist_shortcodes);
    $content              = get_post_field('post_content', $post_id);

    // TODO: change it
    $clean_text               = trinity_get_clean_text($title, $content, $whitelist_shortcodes);
    $clean_text_without_title = trinity_get_clean_text('', $content, $whitelist_shortcodes);
    $is_no_text               = (bool)trinity_is_text_empty($clean_text);

    if ($is_no_text) {
      return false;
    }

    $viewkey = trinity_get_view_key();

    $source_language = get_post_meta($post_id, TRINITY_AUDIO_SOURCE_LANGUAGE, true);

    if (isset($language_code_map[$source_language])) {
      $source_language = $language_code_map[$source_language];
    }

    $trinity_tts_wp_config = [
      'cleanText'     => $clean_text, // old approach

      'headlineText'  => $title,
      'articleText'   => $clean_text_without_title,
      'metadata'      => [
        'author' => trinity_get_author_of_post_id($post_id)
      ],
      'pluginVersion' => trinity_get_plugin_version(),
    ];

    $response = "<script nitro-exclude data-wpfc-render='false' data-cfasync='false' data-no-optimize='1' data-no-defer='1' data-no-minify='1' data-trinity-mount-date='$date' id='trinity_tts_wp_config'>var TRINITY_TTS_WP_CONFIG = " . json_encode($trinity_tts_wp_config) . ';</script>';

    $player_query_params = [
      'integrationType' => 'wordpress',
      'postHashV2'      => $post_hash,
      'articleLocale'   => $source_language,
      'pageURL'         => get_permalink()
    ];

    // do NOT include trinityAudioPlaceholder with PB when we already have in source code, because of using shortcodes, themes, etc... otherwise it will be flashing in wrong position
    if (!strstr($page_content, 'trinityAudioPlaceholder')) {
      $response .= "<div class='trinityAudioPlaceholder' data-trinity-mount-date='$date'>
                      <div class='trinity-tts-pb' dir='ltr' style='font: 12px / 18px Verdana, Arial; height: 80px; line-height: 80px; text-align: left; margin: 0 0 0 82px;'>
                          <strong style='font-weight: 400'>Getting your <a href='//trinityaudio.ai' style='color: #4b4a4a; text-decoration: none; font-weight: 700;'>Trinity Audio</a> player ready...</strong>
                      </div>
                    </div>";
    }

    $trinity_url = TRINITY_AUDIO_STARTUP . $viewkey . '/';

    add_filter('perfmatters_delay_js_exclusions', function($exclusions) {
      $exclusions[] = '/player/trinity-audio/';
      $exclusions[] = '/plugins/trinity-audio/';
      $exclusions[] = 'trinity_tts_wp_config'; // inline script with ID="trinity_tts_wp_config" which injects TRINITY_TTS_WP_CONFIG

      return $exclusions;
    });
    add_filter('script_loader_tag', 'trinity_exclude_player_from_caching_plugins', 10, 2);

    wp_enqueue_script('trinity-player', add_query_arg($player_query_params, $trinity_url));

    return $response;
  }

  function trinity_get_author_of_post_id($post_id) {
    $post_author_id = get_post_field('post_author', $post_id);
    return get_the_author_meta('display_name', $post_author_id);
  }

  function trinity_exclude_player_from_caching_plugins($tag, $handle) {
    $date = trinity_get_date();

    if ($handle === 'trinity-player') {
      remove_filter('script_loader_tag', 'trinity_exclude_player_from_caching_plugins', 10, 2);
      return str_replace('<script', "<script nitro-exclude data-wpfc-render='false' data-cfasync='false' data-no-optimize='1' data-no-defer='1' data-no-minify='1' data-trinity-mount-date='$date'", $tag);
    }
    return $tag;
  }

  function trinity_save_publisher_token() {
    check_ajax_referer('assign_account_key_action', TRINITY_AUDIO_AJAX_NONCE_NAME);

    if (!trinity_get_install_key()) send_response('WRONG_INSTALLKEY');
    if (empty($_POST['publisher_token'])) send_response('WRONG_PUBLISHER_TOKEN');

    $result = trinity_api_request([
      'method' => 'PUT',
      'path'   => trinity_unit_path('/publisher'),
      'body'   => [
        'accountKey' => $_POST['publisher_token']
      ]
    ]);
    $code   = trinity_api_code($result, TRINITY_AUDIO_ASSIGN_CODES);

    if ($code === 'SUCCESS' || $code === 'ALREADY_ASSIGNED_PUBLISHER_TOKEN') {
      trinity_set_is_account_key_linked();
    }

    send_response($code);
  }

  function trinity_audio_ajax_contact_us() {
    check_ajax_referer('contact_us_action', TRINITY_AUDIO_AJAX_NONCE_NAME);

    $is_include_log = isset($_POST['include_log']);

    $request = [
      'name'    => sanitize_text_field($_POST['name'] ?? ''),
      'email'   => sanitize_text_field($_POST['email'] ?? ''),
      'message' => sanitize_text_field($_POST['message'] ?? ''),
      'website' => sanitize_text_field($_POST['website'] ?? '')
    ];
    // the plugin's log and info page go along when the owner chose to include them
    if ($is_include_log && file_exists(TRINITY_AUDIO_LOG)) $request['logFile'] = base64_encode(file_get_contents(TRINITY_AUDIO_LOG));
    if ($is_include_log && file_exists(TRINITY_AUDIO_INFO_HTML)) $request['infoFile'] = base64_encode(file_get_contents(TRINITY_AUDIO_INFO_HTML));

    $result = trinity_api_request([
      'method' => 'POST',
      'path'   => trinity_unit_path('/contact-us'),
      'body'   => $request
    ]);

    if (!trinity_api_is_ok($result)) wp_send_json(['error' => "Can't send the request"], 500);

    wp_send_json(['ok' => true]);
  }

  function trinity_get_posts($offset = 0, $size = -1): array {
    $result = get_posts(
      [
        'fields'         => 'ids', // Only get post IDs
        'orderby'        => 'ID',
        'order'          => 'DESC',
        'posts_per_page' => $size,
        'offset'         => $offset,
        'post_type'      => ['post'],
      ]
    );

    return $result;
  }

  function trinity_get_all_plugins_installed(): string {
    if (is_admin()) {
      if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
      }

      $all_plugins = get_plugins();
      $plugins     = [];

      foreach ($all_plugins as $plugin) {
        array_push($plugins, $plugin['Name'] . '-' . $plugin['Version']);
      }

      return implode(', ', $plugins);
    }

    return 'unknown';
  }

  function trinity_get_user_email(): string {
    $current_user = wp_get_current_user();
    if (!($current_user instanceof WP_User)) {
      return 'NOT_LOGIN';
    }

    return $current_user->user_email;
  }

  function trinity_get_user_name(): string {
    $current_user = wp_get_current_user();
    if (!($current_user instanceof WP_User)) {
      return '';
    }

    return trim($current_user->user_firstname . ' ' . $current_user->user_lastname);
  }

  function trinity_get_wp_version(): ?string {
    global $wp_version;

    return $wp_version;
  }

  function trinity_get_env_details(): array {
    return [
      'wp_version'         => trinity_get_wp_version(),
      'plugin_version'     => trinity_get_plugin_version(),
      'php_version'        => phpversion(),
      'all_plugins'        => trinity_get_all_plugins_installed(),
      'installkey'         => trinity_get_install_key(),
      'site_domain'        => parse_url(get_site_url(), PHP_URL_HOST),
      'email'              => trinity_get_user_email(),
      'details_event_type' => 'not_set'
    ];
  }

  // The site's details on its unit. Before the first signup there is no unit yet (Sergio answered `{}` for that).
  function trinity_update_details($event_type) {
    if (!trinity_get_install_key()) return;

    $data = trinity_get_env_details();

    trinity_api_request([
      'method' => 'PATCH',
      'path'   => trinity_unit_path(),
      'body'   => [
        'pluginVersion'    => $data['plugin_version'],
        'phpVersion'       => $data['php_version'],
        'wpVersion'        => $data['wp_version'],
        'allPlugins'       => $data['all_plugins'],
        'email'            => $data['email'],
        'detailsEventType' => $event_type
      ]
    ]);
  }

  function trinity_audio_ajax_update_unit_config() {
    check_ajax_referer('update_unit_config_action', TRINITY_AUDIO_AJAX_NONCE_NAME);

    trinity_api_request([
      'method' => 'PATCH',
      'path'   => trinity_unit_path(),
      'body'   => [
        'language'       => $_GET['language'] ?? null,
        'voice'          => $_GET['voice'] ?? null,
        'voiceStyle'     => $_GET['voiceStyle'] ?? null,
        'fab'            => $_GET['fab'] ?? null,
        'playbackRate'   => trinity_api_number($_GET['speed'] ?? null),
        'themeId'        => trinity_api_number($_GET['themeId'] ?? null),
        'audioVariantId' => trinity_api_number($_GET['audioVariantId'] ?? null),
        'showPoweredBy'  => trinity_api_flag($_GET['poweredBy'] ?? null),
        'showSettings'   => trinity_api_flag($_GET['showSettings'] ?? null),
        'shareEnabled'   => trinity_api_flag($_GET['shareEnabled'] ?? null)
      ]
    ]);
  }

  /**
   * A settings number (speed, theme or audio variant id) as a number. An empty one ('' or '0') is left out, as Sergio did.
   *
   * @param string|null $value
   * @return int|float|null null leaves the setting out of the request
   */
  function trinity_api_number($value) {
    if (empty($value)) return null;

    return $value + 0;
  }

  /**
   * A settings switch, '1' or '0', as a boolean.
   *
   * @param string|null $value
   * @return bool|null null (the switch was not sent) leaves the setting out of the request
   */
  function trinity_api_flag($value) {
    if ($value === null) return null;

    return (bool)(int)$value;
  }

  function trinity_send_graphite_metric($metric) {
    trinity_api_request([
      'method' => 'POST',
      'path'   => TRINITY_AUDIO_METRICS_PATH,
      'body'   => [
        'metric' => $metric
      ]
    ]);
  }

  function trinity_send_stat_metrics() {
    check_ajax_referer('send_metric_action', TRINITY_AUDIO_AJAX_NONCE_NAME);

    trinity_send_graphite_metric($_POST['metric']);
  }

  function trinity_audio_ajax_remove_post_banner() {
    check_ajax_referer('remove_post_banner_action', TRINITY_AUDIO_AJAX_NONCE_NAME);
    update_option(TRINITY_AUDIO_REMOVE_POST_BANNER, time());
  }

  function send_response($code) {
    $env_details = trinity_get_env_details();
    $site        = $env_details['site_domain'];

    $register_response = [
      'ERROR_NETWORK' => 'Can\'t activate plugin. Please contact us ' . TRINITY_AUDIO_SUPPORT_EMAIL_LINK . " and provide your domain: <strong>{$site}</strong>",

      'ERROR' => 'Can\'t activate plugin. Please contact us ' . TRINITY_AUDIO_SUPPORT_EMAIL_LINK . " and provide your domain: <strong>{$site}</strong>",

      'ALREADY_REGISTERED' => "It seems like your site <strong>{$site}</strong> is already registered to our services. In order to protect your assets, we do not allow duplicated registrations. <br/> If you've registered before and migrated into a new database/hosting service - please use the form below to insert the <span class='trinity-bold-text'>Install Key</span> if you have one, otherwise contact us at " . TRINITY_AUDIO_SUPPORT_EMAIL_LINK . ' to help resolve this issue.',

      'WRONG_INSTALLKEY' => 'We can see that the following value has changed in your database <strong>wp_options.trinity_audio_installkey</strong>. If you know how to revert the change, please do. If not, please write to us at ' . TRINITY_AUDIO_SUPPORT_EMAIL_LINK . ' and we will respond as fast as we can.',

      'WRONG_PUBLISHER_TOKEN' => '<span class="trinity-bold-text">Account Key</span> is not found - please verify you have the correct one. If the problem persists, please write to us at ' . TRINITY_AUDIO_SUPPORT_EMAIL_LINK . ' and we will respond as fast as we can.',

      'ALREADY_ASSIGNED_PUBLISHER_TOKEN' => "It seems like your installation is already assigned to your Trinity Account. If it's not reflected on the <a href='" . trinity_add_utm_to_url(TRINITY_AUDIO_DASHBOARD_URL) . "' target='_blank'>Trinity Dashboard</a> please contact us at " . TRINITY_AUDIO_SUPPORT_EMAIL_LINK . ' to help resolve this issue.',

      'SUCCESS' => NULL
    ];

    die(json_encode(
      [
        'code'    => $code,
        'message' => $register_response[$code]
      ]));
  }

  function handle_register_response($result) {
    $response_code = trinity_api_code($result, TRINITY_AUDIO_SIGNUP_CODES);

    if ($response_code === 'SUCCESS') {
      $response_install_key = $result['body']['unit']['installKey'] ?? null;
      $response_view_key    = $result['body']['unit']['viewKey'] ?? null;

      /*
      Check if response exists, so we don't override with empty values if smth went wrong.
      User can have installkey and could want to restore viewkey.
      */
      if ($response_install_key) {
        update_option(TRINITY_AUDIO_INSTALLKEY, $response_install_key);
      }
      if ($response_view_key) {
        update_option(TRINITY_AUDIO_VIEWKEY, $response_view_key);
      }

      set_transient(TRINITY_AUDIO_FIRST_TIME_INSTALL, true, 60);
    }

    send_response($response_code);
  }

  function trinity_register() {
    $install_key = trinity_get_install_key();
    $view_key    = trinity_get_view_key();

    if ($install_key && $view_key) {
      trinity_update_details('reactivating');
      return true;
    }

    handle_register_response(trinity_register_unit($install_key));
  }

  /*
   * The signup: Integration API registers the site, or answers both keys again for the install key a site kept
   * (recovery: the one it still has, or the one the owner typed in).
   */
  function trinity_register_unit($install_key) {
    $data = trinity_get_env_details();
    if (!$install_key && !empty($_POST['recover_installkey'])) $install_key = $_POST['recover_installkey'];

    return trinity_api_request([
      'method' => 'POST',
      'path'   => TRINITY_AUDIO_UNIT_PATH,
      'body'   => [
        'siteDomain'        => $data['site_domain'],
        'email'             => $data['email'],
        'pluginVersion'     => $data['plugin_version'],
        'phpVersion'        => $data['php_version'],
        'wpVersion'         => $data['wp_version'],
        'allPlugins'        => $data['all_plugins'],
        'detailsEventType'  => 'activating',
        'newsletterConsent' => !empty($_POST['email_subscription']),
        'accountKey'        => empty($_POST['publisher_token']) ? null : $_POST['publisher_token'],
        'installKey'        => $install_key ?: null
      ]
    ]);
  }

  function trinity_is_text_empty($text) {
    $text = str_replace('.', '', $text);
    return !trim(preg_replace('/\s+/', ' ', $text));
  }

  function trinity_hook_header() {
    if (trinity_get_preconnect()) {
      echo '<link href="https://trinitymedia.ai/" rel="preconnect" crossorigin="anonymous" />' . "\n";
      echo '<link href="https://vd.trinitymedia.ai/" rel="preconnect" crossorigin="anonymous" />' . "\n";
    }
  }

  function trinity_can_not_connect_error_message($error = '') {
    return $error . ' Can\'t connect to Trinity Audio! Please check <a href="/wp-admin/admin.php?page=trinity_audio_logs">logs</a> or contact ' . TRINITY_AUDIO_SUPPORT_MESSAGE;
  }

  function trinity_get_notice_error_message($error = '') {
    return trinity_can_not_connect_error_message("<div class='notice notice-error'><p>" . $error . "<br>") . "</p><p></p></div>";
  }

  function trinity_registered() {
    $install_key = trinity_get_install_key();
    $view_key    = trinity_get_view_key();
    if (empty($install_key) || empty($view_key)) {
      return false;
    }
    return true;
  }

  function trinity_get_package_data() {
    $error_msg = trinity_get_notice_error_message("Can't get plan data.");
    $result    = trinity_api_request([
      'method' => 'GET',
      'path'   => trinity_unit_path('/credits')
    ]);

    if (!trinity_api_is_ok($result)) die(esc_html($error_msg));

    return trinity_credits_to_package_data($result['body']);
  }

  /*
   * The credits answer in the shape the templates read (Sergio's): the balance at the top with `used` for
   * credits-service's `usedCredits`, the cap type, and the package as its old row. No balance reads as Sergio's fallback.
   */
  function trinity_credits_to_package_data($credits) {
    $balance = $credits['balance'];
    $package = $credits['package'];

    if ($balance === null) {
      $balance = [
        'packageLimit' => 'N/A',
        'left'         => 0,
        'used'         => 0
      ];
    } elseif (isset($balance['usedCredits'])) {
      $balance = [
        'packageLimit'  => $balance['packageLimit'],
        'used'          => $balance['usedCredits'],
        'left'          => $balance['left'],
        'nextRefreshAt' => $balance['nextRefreshAt']
      ];
    }

    return json_decode(wp_json_encode(array_merge($balance, [
      'capType' => $package['capType'],
      'package' => [
        'package_name'        => $package['name'],
        'displayName'         => $package['displayName'],
        'package_description' => $package['description'],
        'account_key'         => $package['accountKey']
      ]
    ])));
  }

  function trinity_get_unit_config_from_trinity() {
    $error_msg = trinity_get_notice_error_message("Can't get plugin configuration.");
    $result    = trinity_api_request([
      'method' => 'GET',
      'path'   => trinity_unit_path()
    ]);

    if (!trinity_api_is_ok($result)) die(esc_html($error_msg));

    // only `powered_by` is read, as 1 or 0 (Sergio's answer)
    return (object)[
      'powered_by' => $result['body']['showPoweredBy'] ? 1 : 0
    ];
  }

  /**
   * Locales that have at least one voice (voice v2), for the post-level language dropdown.
   *
   * @return array of ['locale' => 'en-GB', 'localeLanguage' => 'en', 'localeLanguageName' => 'English', 'localeRegion' => 'GB', 'localeRegionName' => 'United Kingdom']
   */
  function trinity_get_locales() {
    $locales = get_transient(TRINITY_AUDIO_LOCALES_CACHE);
    if ($locales) return $locales;

    $result  = trinity_api_request([
      'method' => 'GET',
      'path'   => TRINITY_AUDIO_LOCALES_PATH
    ]);
    $locales = trinity_api_is_ok($result) ? $result['body'] : null;
    if (empty($locales)) return [];

    set_transient(TRINITY_AUDIO_LOCALES_CACHE, $locales, TRINITY_AUDIO_LOCALES_CACHE_TTL);

    return $locales;
  }

  function notifications($package_data) {
    $result  = trinity_api_request([
      'method' => 'GET',
      'path'   => TRINITY_AUDIO_NOTIFICATION_PATH . '?type=general'
    ]);
    $message = trinity_api_is_ok($result) ? $result['body']['message'] : null;

    // a JSON string, put in by hand
    if ($message) {
      $notification = json_decode($message);

      if ($notification && property_exists($notification, 'message_html')) {
        echo wp_kses_post($notification->message_html);
      }
    }

    if (!$package_data) return;

    if ($package_data->capType === 'articles'
      && is_numeric($package_data->used)
      && is_numeric($package_data->packageLimit)
      && $package_data->used >= $package_data->packageLimit)
      echo "<div class='trinity-notification'>
              <span>
                You have a maxed out your plan usage!
                <a class='trinity-bold-text' target='_blank' href='" . esc_html(trinity_add_utm_to_url(TRINITY_AUDIO_PRICING_URL)) . "'>Upgrade your plan</a>        
              </span>
              <span class='trinity-notification-close'></span>
            </div>";
  }

  function trinity_add_utm_to_url($url, $utm_medium = 'wp_admin', $utm_campaign = '') {
    return add_query_arg(array(
      'utm_medium'   => urlencode($utm_medium),
      'utm_source'   => urlencode(get_site_url()),
      'utm_campaign' => urlencode($utm_campaign)
    ), $url);
  }

  function trinity_get_upgrade_url() {
    if (trinity_get_is_account_key_linked()) return TRINITY_AUDIO_UPGRADE_URL;
    return TRINITY_AUDIO_PRICING_URL;
  }

  function trinity_check_post_nonce($action) {
    if (!wp_verify_nonce($_POST[TRINITY_AUDIO_NONCE_NAME], $action)) die(esc_html("Security check failed for $action"));
  }
