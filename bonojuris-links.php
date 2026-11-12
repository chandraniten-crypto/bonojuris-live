<?php
/**
 * Plugin Name: Bono Juris Site Links
 * Description: Adds one slim link bar to the top and bottom of every bonojuris.com page so the bookstore and the Economics, Headlines and Markets pages are connected.
 * Version: 1.0
 */
if (!defined('ABSPATH')) exit;

function bj_links_items() {
  return [
    'Home'      => home_url('/'),
    'Economics' => home_url('/live/economics/'),
    'Headlines' => home_url('/live/headlines/'),
    'Markets'   => home_url('/live/markets/'),
  ];
}

function bj_links_bar($pos) {
  $out = '<nav class="bj-links bj-' . esc_attr($pos) . '" aria-label="Bono Juris sections">';
  foreach (bj_links_items() as $label => $url) {
    $out .= '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
  }
  return $out . '</nav>';
}

add_action('wp_head', function () {
  echo '<style>.bj-links{display:flex;flex-wrap:wrap;gap:6px 22px;justify-content:center;padding:9px 16px;background:#f7f3e8;font:14px/1.4 system-ui,sans-serif}'
     . '.bj-links a{color:#6b1d1d;text-decoration:none;font-weight:600}.bj-links a:hover{text-decoration:underline}'
     . '.bj-top{border-bottom:1px solid #e3dcc6}.bj-bottom{border-top:1px solid #e3dcc6}</style>';
});
add_action('wp_body_open', function () { echo bj_links_bar('top'); });
add_action('wp_footer',    function () { echo bj_links_bar('bottom'); });
