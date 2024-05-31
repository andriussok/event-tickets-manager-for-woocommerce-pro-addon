<?php
/**
 * Plugin Name: Event Tickets Manager for Woocommerce Pro Addon
 * Plugin URI: https://github.com/andriussok/event-tickets-manager-for-woocommerce-pro-addon
 * Description: Enables you to exclude specific days from daily event recurrence schedules in Event Tickets Manager for WooCommerce Pro. Exclude any days of the week according to your event needs.
 * Version: 1.0.2
 * Author: Andrius x WPSwings
 * Author URI: https://wpswings.com/product/event-tickets-manager-for-woocommerce-pro/
 * Developer: Andrius
 * Text Domain: etmfwp-addon
 *
 * Requires at least:    4.6
 * Tested up to:         6.4.3
 * WC requires at least: 6.1
 * WC tested up to:      8.6.1
 * License:              GNU General Public License v3.0
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 */

// If file is called directly or not admin area, exit.
if (!defined('ABSPATH')) {
  exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

// HPOS Compatibility.
add_action(
    'before_woocommerce_init',
    function() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }
);

// Load only for admin area
if (is_admin()) {
  // Include the parent class file
  if (file_exists(plugin_dir_path(__FILE__) . '../event-tickets-manager-for-woocommerce-pro/admin/class-event-tickets-manager-for-woocommerce-pro-admin.php')) {
    require_once plugin_dir_path(__FILE__) . '../event-tickets-manager-for-woocommerce-pro/admin/class-event-tickets-manager-for-woocommerce-pro-admin.php';
  }
}

// Check if the parent class exists
if (class_exists('Event_Tickets_Manager_For_Woocommerce_Pro_Admin')) {

  class ETMFW_Pro_Admin_Addon extends Event_Tickets_Manager_For_Woocommerce_Pro_Admin
  {
    /**
     * Constructor to initialize the parent class.
     */
    public function __construct($plugin_name, $version)
    {

      // Call the parent constructor with required arguments
      parent::__construct($plugin_name, $version);

      // Add your additional initialization here

      // Re-hook the actions with the instance of this class
      // Ajax Create The Recurring Events.
      add_action('wp_ajax_wps_etmfw_create_recurring_event', array($this, 'wps_etmfw_create_recurring_event_callbck'));
      add_action('wp_ajax_nopriv_wps_etmfw_create_recurring_event', array($this, 'wps_etmfw_create_recurring_event_callbck'));

      // Hook the add_exclude_days_setting method to the wps_etmfw_edit_product_settings action
      add_action('wps_etmfw_edit_product_settings', array($this, 'add_exclude_days_setting'), 10, 1);

      // Hook the save_exclude_days_setting method to the woocommerce_process_product_meta action
      add_action('woocommerce_process_product_meta', array($this, 'save_exclude_days_setting'), 10, 1);

      // Add your additional initialization here
      add_action('wps_etmfw_event_product_type_save_fields', array($this, 'save_exclude_days_setting'), 10, 1);
    }

    /**
     * Add exclude days setting to product edit page.
     *
     * @param int $product_id Product ID.
     */
    public function add_exclude_days_setting($product_id)
    {
      // Get existing exclude days
      $product_array = get_post_meta($product_id, 'wps_etmfw_product_array', true);

      // Check if the product array exists and has exclude_days
      if (isset($product_array['exclude_days'])) {
        // Get exclude_days from product array
        $exclude_days = $product_array['exclude_days'];
      } else {
        // Default to an empty array if exclude_days not found
        $exclude_days = array();
      }

      // Define the days of the week
      $days_of_week = array(
        'monday'    => __('Monday', 'etmfwp-addon'),
        'tuesday'   => __('Tuesday', 'etmfwp-addon'),
        'wednesday' => __('Wednesday', 'etmfwp-addon'),
        'thursday'  => __('Thursday', 'etmfwp-addon'),
        'friday'    => __('Friday', 'etmfwp-addon'),
        'saturday'  => __('Saturday', 'etmfwp-addon'),
        'sunday'    => __('Sunday', 'etmfwp-addon'),
      );

      // Output the multi-select dropdown
      woocommerce_wp_select(
        array(
          'id'                => 'wps_etmfw_exclude_days',
          'label'             => __('Select days to exclude', 'etmfwp-addon'),
          'class'             => 'select short chosen_select',
          'options'           => $days_of_week,
          'value'             => $exclude_days,
          'desc_tip'          => true,
          'description'       => __('Select the days you want to exclude for this event.', 'etmfwp-addon'),
          'custom_attributes' => array('multiple' => 'multiple'),
          'name'              => 'wps_etmfw_exclude_days[]', // Add [] to make it an array
        )
      );
    }

    /**
     * Save exclude days setting.
     *
     * @param int $product_id The product ID.
     */
    public function save_exclude_days_setting($product_id)
    {
      // Get existing product settings
      $existing_product_array = get_post_meta($product_id, 'wps_etmfw_product_array', true);

      // Get selected exclude days
      $exclude_days = isset($_POST['wps_etmfw_exclude_days']) ? $_POST['wps_etmfw_exclude_days'] : array();

      // Update the product array with the selected exclude days
      $updated_product_array = array(
        'exclude_days' => $exclude_days,
      );

      // Merge with existing settings if they exist
      if ($existing_product_array && is_array($existing_product_array)) {
        $updated_product_array = array_merge($existing_product_array, $updated_product_array);
      }

      // Save updated settings
      update_post_meta($product_id, 'wps_etmfw_product_array', $updated_product_array);

      // $updated = get_post_meta($product_id, 'wps_etmfw_product_array', true);
    }

    /**
     * Override an existing method from the parent class.
     *
     * Create the Recurrence Event Callback.
     *
     * @param int   $event_id is a eventproduct id.
     * @param int   $recurring_type is a recurring type.
     * @param int   $end_date is a end date.
     * @param int   $start_date is a start date.
     * @param int   $recurring_value is a recurring value.
     * @param array $product_data is a product value.
     * @return void
     */
    public function convert_to_recurring_event($event_id, $recurring_type, $end_date, $start_date, $recurring_value, $product_data)
    {

      $product      = wc_get_product($event_id);
      $thumbnail_id = get_post_thumbnail_id($event_id);
      $event_title  = get_the_title($event_id);

      $wps_event_venue = $product_data[0]['etmfw_event_venue'];
      $wps_event_map   = $product_data[0]['etmfw_display_map'];
      $wps_event_lat   = $product_data[0]['etmfw_event_venue_lat'];
      $wps_event_log   = $product_data[0]['etmfw_event_venue_lng'];

      if ('daily' === $recurring_type) {
        $wps_daily_event_start_time = $product_data[0]['wps_event_recurring_daily_start_time'];
        $wps_daily_event_end_time   = $product_data[0]['wps_event_recurring_daily_end_time'];

        // Split the time string into hour and minute using explode.
        list($hour, $minute) = explode(':', $wps_daily_event_start_time);

        // Convert the hour and minute components to integers.
        $hour   = (int) $hour;
        $minute = (int) $minute;

        $date = new DateTime($product_data[0]['event_start_date_time']);
        $date->setTime($hour, $minute);
        $wps_start_date = $date->format('Y-m-d g:i a');

        // Split the time string into hour and minute using explode.
        list($hour, $minute) = explode(':', $wps_daily_event_end_time);

        // Convert the hour and minute components to integers.
        $hour   = (int) $hour;
        $minute = (int) $minute;

        $date = new DateTime($product_data[0]['event_end_date_time']);
        $date->setTime($hour, $minute);
        // Format the DateTime object as a string in the desired format.
        $wps_end_date = $date->format('Y-m-d g:i a');
      } else {

        $wps_start_date = $product_data[0]['event_start_date_time'];
        $wps_end_date   = $product_data[0]['event_end_date_time'];
      }

      $wps_event_trash            = $product_data[0]['etmfw_event_trash_event'];
      $wps_event_shipping_disable = $product_data[0]['etmfw_event_disable_shipping'];
      $wps_event_fb_share         = $product_data[0]['etmfwp_share_on_fb'];

      // Parse the input date using strtotime for start date.
      $start_date_timestamp = strtotime($wps_start_date);
      $start_formatted_date = gmdate('Y-m-d H:i:s', $start_date_timestamp);

      // Parse the input date using strtotime for end date.
      $end_date_timestamp = strtotime($wps_end_date);
      $end_formatted_date = gmdate('Y-m-d H:i:s', $end_date_timestamp);

      // Set end date.
      $wps_event_set_end_formatted_time = gmdate('(H, i, s)', $end_date_timestamp);

      // Remove the parentheses and split the string by commas.
      $time_parts = explode(',', str_replace(array('(', ')', ' '), '', $wps_event_set_end_formatted_time));

      // Extract the individual time components (hours, minutes, seconds).
      $hours   = (int) trim($time_parts[0]);
      $minutes = (int) trim($time_parts[1]);
      $seconds = (int) trim($time_parts[2]);

      $current_date = new DateTime($start_formatted_date);
      $end_date_obj = new DateTime($end_formatted_date);
      update_post_meta($event_id, 'product_has_recurring', 'yes');

      // CUSTOMISATION START ---------------------------/

      // Get the excluded days from product data
      $exclude_days = isset($product_data[0]['exclude_days']) ? $product_data[0]['exclude_days'] : array();

      while ($current_date <= $end_date_obj) {

        // get lowercase day of the week in words;
        $current_day = strtolower($current_date->format('l'));

        // Check if the current day is excluded
        if (in_array($current_day, $exclude_days)) {
          // Move to the next date
          $current_date->modify('+1 day');
          continue;
        }

        // CUSTOMISATION END -----------------------------/

        // Calculate end date for the current instance based on recurring type.
        $current_end_date = clone $current_date;
        if ('weekly' === $recurring_type) {
          $current_end_date->modify('+6 days');
          $current_end_date->setTime($hours, $minutes, $seconds);
        } elseif ('monthly' === $recurring_type) {
          $current_end_date->modify('last day of this month');
          $current_end_date->setTime($hours, $minutes, $seconds);
        }
        $current_end_date->setTime($hours, $minutes, $seconds);

        $timestamp      = strtotime($current_date->format('Y-m-d'));
        $formatted_date = gmdate('j M Y', $timestamp);

        // Create a new event post for each recurring instance.
        $new_event_id = wp_insert_post(
          array(
            'post_title'  => $event_title . ' (' . $formatted_date . ')',
            'post_type'   => 'product',
            'post_status' => 'publish',
          )
        );

        // Define the array data for your recurring product (replace with your actual data).
        $recurring_product_data = array(
          'etmfw_event_price'                              => $product->get_price(),
          'event_start_date_time'                          => $current_date->format('Y-m-d H:i:s'),
          'wps_etmfw_field_user_type_price_data_baseprice' => 'base_price',
          'event_end_date_time'                            => $current_end_date->format('Y-m-d H:i:s'),
          'etmfw_event_venue'                              => $wps_event_venue,
          'etmfw_event_venue_lat'                          => $wps_event_lat,
          'etmfw_event_venue_lng'                          => $wps_event_log,
          'etmfw_event_trash_event'                        => $wps_event_trash,
          'etmfw_event_disable_shipping'                   => $wps_event_shipping_disable,
          'wps_etmfw_dyn_name'                             => $product_data[0]['wps_etmfw_dyn_name'],
          'wps_etmfw_dyn_mail'                             => $product_data[0]['wps_etmfw_dyn_mail'],
          'wps_etmfw_dyn_contact'                          => $product_data[0]['wps_etmfw_dyn_contact'],
          'wps_etmfw_dyn_date'                             => $product_data[0]['wps_etmfw_dyn_date'],
          'wps_etmfw_dyn_address'                          => $product_data[0]['wps_etmfw_dyn_address'],
          'wps_etmfw_field_user_type_price_data'           => $product_data[0]['wps_etmfw_field_user_type_price_data'],
          'wps_etmfw_field_days_price_data'                => $product_data[0]['wps_etmfw_field_days_price_data'],
          'wps_etmfw_field_stock_price_data'               => $product_data[0]['wps_etmfw_field_stock_price_data'],
          'wps_etmfw_field_data'                           => $product_data[0]['wps_etmfw_field_data'],
          'etmfw_display_map'                              => $wps_event_map,
          'etmfwp_share_on_fb'                             => $wps_event_fb_share,
          'etmfwp_recurring_event_enable'                  => 'no',
        );

        // Assuming $product_id contains the ID of the created recurring product.
        if ($new_event_id) {
          // Save the array as post meta for the product.
          wp_set_object_terms($new_event_id, 'event_ticket_manager', 'product_type');
          update_post_meta($new_event_id, 'wps_etmfw_product_array', $recurring_product_data);
          update_post_meta($new_event_id, '_price', $product->get_price());
          update_post_meta($new_event_id, '_featured', 'yes');
          // fix quantity tracker for recurring events
          update_post_meta( $new_event_id, '_manage_stock', $product->get_manage_stock() ); // inherit from parent event
          update_post_meta( $new_event_id, '_stock', $product->get_stock_quantity() ); // inherit from parent event initial quantity
          // update_post_meta($new_event_id, '_stock', $product->get_stock_status());
          update_post_meta($new_event_id, '_stock_status', 'instock');
          update_post_meta($new_event_id, '_sku', $product->get_sku());
          update_post_meta($new_event_id, '_thumbnail_id', $thumbnail_id);
          update_post_meta($new_event_id, 'is_recurring_' . $new_event_id, 'yes');
          update_post_meta($new_event_id, 'parent_of_recurring', $event_id);
        }
        // Move to the next recurring date.
        if ('daily' === $recurring_type) {
          $current_date->modify("+$recurring_value day");
        } elseif ('weekly' === $recurring_type) {
          $current_date->modify("+$recurring_value week");
        } elseif ('monthly' === $recurring_type) {
          $current_date->modify("+$recurring_value month");
        }
      }
    }
  };

  /**
   * Create the Recurrence Event here.
   *
   * @return void
   */
  function wps_etmfw_create_recurring_event_callbck()
  {

    check_ajax_referer('wps_wet_custom_ajax_nonce', 'nonce');
    $wps_event_product_id = isset($_POST['product_id']) ? sanitize_text_field(wp_unslash($_POST['product_id'])) : '';

    $wps_is_error = false;

    if (!empty($wps_event_product_id) && isset($wps_event_product_id)) {

      $product_data               = get_post_meta($wps_event_product_id, 'wps_etmfw_product_array', array());
      $wps_recurring_event_enable = $product_data[0]['etmfwp_recurring_event_enable'];

      $wps_event_recurring_type  = $product_data[0]['wps_event_recurring_type'];
      $wps_event_recurring_value = $product_data[0]['wps_event_recurring_value'];

      $end_date   = $product_data[0]['event_end_date_time']; // Replace with the desired end date.
      $start_date = $product_data[0]['event_start_date_time'];

      $start_date_time = new DateTime($start_date);
      $end_date_time   = new DateTime($end_date);
      // Calculate the difference between the two dates.
      $interval = $start_date_time->diff($end_date_time);

      // Check if the difference is exactly one week (7 days) and 0 hours, 0 minutes, and 0 seconds.
      if (($interval->days < 7 && ('weekly' === $wps_event_recurring_type)) || ($interval->days < 30 && ('monthly' === $wps_event_recurring_type)) || ($interval->days < 1 && ('daily' === $wps_event_recurring_type))) {
        $wps_is_error = true;
      }

      $timestamp = strtotime($end_date);

      if (false != $timestamp) {
        $wps_formatted_end_date = gmdate('Y-m-d', $timestamp);
      }

      $timestamp_start = strtotime($start_date);

      if (false != $timestamp_start) {
        $wps_formatted_start_date = gmdate('Y-m-d', $timestamp_start);
      }

      if (empty($wps_event_recurring_type) || empty($wps_event_recurring_value || empty($end_date) || empty($start_date)) || ($wps_is_error)) {

        $wps_is_error = true;
      } else {

        $this->convert_to_recurring_event($wps_event_product_id, $wps_event_recurring_type, $wps_formatted_end_date, $wps_formatted_start_date, $wps_event_recurring_value, $product_data);
      }
    }
    echo wp_json_encode($wps_is_error);
    wp_die();
  };

  // Instantiate your custom class
  $admin_instance = new ETMFW_Pro_Admin_Addon('ETMFW_Pro_Admin_Addon', '1.0.0');

}
