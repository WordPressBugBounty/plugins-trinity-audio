<?php
  if (!defined('ABSPATH')) exit;
  require_once __DIR__ . '/../../initial_checking.php';

  // save content into variable.
  ob_start();
  require_once __DIR__ . '/info-content.php';
  $contents = ob_get_clean();

  // show content. The post rules strip <input>, and the subscription box has the account key one.
  $allowed_html          = wp_kses_allowed_html('post');
  $allowed_html['input'] = [
    'type'        => true,
    'class'       => true,
    'id'          => true,
    'name'        => true,
    'value'       => true,
    'placeholder' => true,
    'spellcheck'  => true,
    'disabled'    => true
  ];
  echo wp_kses($contents, $allowed_html);

  // write that content into file.
  trinity_log_to_file($contents, TRINITY_AUDIO_INFO_HTML, 'w');

  function trinity_info_show_credits() {
    $error_msg = trinity_can_not_connect_error_message('Can\'t get available credits.');
    $result    = trinity_api_request([
      'method' => 'GET',
      'path'   => trinity_unit_path('/credits')
    ]);
    if (!trinity_api_is_ok($result)) {
      echo esc_html($error_msg);
      return;
    }

    $credits_data = trinity_credits_to_package_data($result['body']);
    $cap_type = $credits_data->capType;

    if ($cap_type === 'chars') {
      echo "<p>" . esc_html($credits_data->credits) . "</p>
            <p class='description'>Shows the amount of credits available to generate audio for new posts</p>";
    } else if ($cap_type === 'articles') {
      echo "<p>Used " . esc_html($credits_data->used) . " of " . esc_html($credits_data->packageLimit) . "</p>
            <p class='description'>Amount of articles used to audify</p>";
    } else if ($cap_type === 'no_limit') {
      echo "<p>Unlimited</p>
            <p class='description'>Amount of articles used to audify</p>";
    } else {
      echo "<p>N/A</p>
          <p class='description'>Amount of articles used to audify</p>";
    }
  }

  function trinity_info_tech_show_config() {
    $config = trinity_init_checks_get_ini();

    $wp_version  = trinity_get_wp_version();
    $php_version = phpversion();

    echo "<table class='trinity-inner'>
            <thead>
              <tr>
                <td><span class='trinity-bold-text'>Parameter name</span></td>
                <td><span class='trinity-bold-text'>Minimum required value</span></td>
                <td><span class='trinity-bold-text'>Actual value</span></td>
              </tr>
            </thead>
            <body>
            <tr>
              <td>WordPress</td>
              <td>5.2</td>
              <td>" . esc_html($wp_version) . '</td>
            </tr>
            <tr>
              <td>PHP</td>
              <td>7.2</td>
              <td>' . esc_html($php_version) . '</td>
            </tr>
            <tr></tr>';

    foreach ($config as $key => $value) {
      $expected = $value['min_required'];
      $actual   = $value['actual'];

      echo '<tr>
              <td>' . esc_html($key) . '</td>
              <td>' . esc_html($expected) . '</td>
              <td>' . esc_html($actual) . '</td>
            </tr>';
    }

    echo '</body></table>';
  }
