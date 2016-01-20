<?php
/*
Plugin Name: WP CustomPost
Plugin URI:  http://www.easecloud.cn/
Description: Easecloud WordPress Template
Version:     0.1
Author:      Alfred
Author URI:  http://www.easecloud.cn/
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Domain Path: null
Text Domain: wp_custom_post
*/

define('WCP_DOMAIN', 'wp_custom_post');

/**
 * 翻译支持
 */
load_plugin_textdomain(
    WCP_DOMAIN,
    false,
    plugin_basename(dirname(__FILE__)).'/languages'
);

require_once 'CustomPost.class.php';
