<?php
/**
 * Jeeb for Gravity Forms Uninstall
 *
 * @author 		jeeb
 */
if( ! defined( 'WP_UNINSTALL_PLUGIN' ) )
    exit();
delete_option('jeebNetwork');
delete_option('jeebRedirectURL');
delete_option('jeebSignature');

global $wpdb;
     $table_name = "jeeb_transactions";
     $sql = "DROP TABLE IF EXISTS $table_name;";
     $wpdb->query($sql);
