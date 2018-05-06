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
delete_option('jeebBase');
delete_option('jeebBtc');
delete_option('jeebLtc');
delete_option('jeebXmr');
delete_option('jeebXrp');
delete_option('jeebBch');
delete_option('jeebEth');
delete_option('jeebTestBtc');
delete_option('jeebLang');

global $wpdb;
     $table_name = "jeeb_transactions";
     $sql = "DROP TABLE IF EXISTS $table_name;";
     $wpdb->query($sql);
