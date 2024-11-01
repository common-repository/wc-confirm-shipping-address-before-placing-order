<?php
/**
 * Plugin Name:       WooCommerce - Confirm Shipping Address
 * Description:       Let customers double check their shipping address before placing their order on checkout page. Helps to prevent incorrectly entered shipping addresses.
 * Version:           1.0.1
 * Author URI:        github.com/renstillmann
 * Author:            Rens Tillmann
 * Text Domain:       wc-csabpo
 * Domain Path:       /i18n/languages/
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 4.9
 * Requires PHP:      5.4
 */

if(!defined('ABSPATH')){
    exit; // Exit if accessed directly
}

if(!class_exists('WC_CSABPO')) :
    final class WC_CSABPO {
        public $version = '1.0.1';
        public $slug = 'wc_csabpo';
        public $common_i18n;
        protected static $_instance = null;
        private static $scripts = array();
        protected $fields = null;
        protected $legacy_posted_data = array();
        private $logged_in_customer = null;
        public static function instance(){
            if(is_null(self::$_instance)){
                self::$_instance = new self();
            }
            return self::$_instance;
        }
        public function __construct(){
            $this->define_constants();
            $this->init_hooks();
            do_action('wc_csabpo_loaded');
        }
        private function define_constants(){
            $this->define('WC_CSABPO_VERSION', $this->version );
            $this->define('WC_CSABPO_PLUGIN_FILE', plugin_dir_url(__FILE__)); 
        }
        private function define($name, $value){
            if(!defined($name)){
                define($name, $value);
            }
        }
        private function init_hooks(){
            add_action('init', array($this, 'init'), 0);
            add_action('wp_enqueue_scripts', array($this, 'enqueue_css_js'), 99999 );
            // Ajax call to preflight the checkout validation
            add_action('wp_ajax_woocommerce_wc_csabpo_checkout', array($this, 'wc_csabpo_checkout'));
            add_action('wp_ajax_nopriv_woocommerce_wc_csabpo_checkout', array($this, 'wc_csabpo_checkout'));
            add_action('wc_ajax_wc_csabpo_checkout', array($this, 'wc_csabpo_checkout'));
        }
        public function init(){
            $this->load_plugin_textdomain();
            $this->common_i18n = apply_filters('wc_csabpo_common_i18n_filter', 
                array(
                    'loading' => esc_html__('Loading...', 'wc-csabpo'),
                    'upload_limit_reached' => esc_html__('Upload limit reached!', 'wc-csabpo')
                )
            );
            do_action('wc_csabpo_init');
        }
        public static function enqueue_css_js(){
            // Only add the script and stylesheet on checout page
            if((function_exists('is_checkout')) && is_checkout()) {
                $handle = 'wc-csabpo-checkout';
                $name = str_replace('-', '_', $handle) . '_i18n';
                wp_register_script($handle, WC_CSABPO_PLUGIN_FILE . 'checkout.js', array('jquery'), WC_CSABPO_VERSION, true);  
                $request = 'wc_csabpo_checkout';
                $endpoint =  esc_url_raw(apply_filters('woocommerce_ajax_get_endpoint', add_query_arg('wc-ajax', $request, home_url('/', 'relative')), $request));
                $i18n = array(
                    'endpoint' => $endpoint,
                    'modal' => array(
                        'title' => __('Confirm shipping address:', 'wc-csabpo'),
                        'desc' => __('Please double check your shipping address below and make sure it is correct.', 'wc-csabpo'),
                        'confirm' => __('I confirm that the above shipping address is correct.', 'wc-csabpo'),
                        'edit' => __('Edit address', 'wc-csabpo'),
                        'finalize' => __('Finalize order', 'wc-csabpo'),
                    )
                );
                wp_localize_script($handle, $name, $i18n);
                wp_enqueue_script($handle);
                wp_enqueue_style($handle, WC_CSABPO_PLUGIN_FILE . 'checkout.css', array(), WC_CSABPO_VERSION);
            }
        }
        public function wc_csabpo_checkout(){
            $checkout = WC_CSABPO::instance();
            $errors = new WP_Error();
            $posted_data = $checkout->get_posted_data();
            // Validate posted data and cart items before proceeding.
            $checkout->validate_checkout($posted_data, $errors);
            if(count($errors->errors)!==0){
                echo '1'; // Has validation errors
                die();
            }
            echo '0'; // No errors found, display confirm modal/dialog/popup
            die();
        }
        public function validate_checkout(&$data, &$errors){
            $this->validate_posted_data($data, $errors);
            if(isset($data['terms-field']) && $data['terms-field']===1 && isset($data['terms']) && $data['terms']===0){
                $errors->add('terms', __('Please read and accept the terms and conditions to proceed with your order.', 'woocommerce'));
            }
            if(WC()->cart->needs_shipping()){
                $shipping_country = isset($data['shipping_country']) ? $data['shipping_country'] : WC()->customer->get_shipping_country();
                if(empty($shipping_country)){
                    $errors->add('shipping', __('Please enter an address to continue.', 'woocommerce'));
                }elseif(!in_array($shipping_country, array_keys(WC()->countries->get_shipping_countries()), true)){
                    if(WC()->countries->country_exists($shipping_country)){
                        $errors->add('shipping', sprintf(__('Unfortunately <strong>we do not ship %s</strong>. Please enter an alternative shipping address.', 'woocommerce'), WC()->countries->shipping_to_prefix() . ' ' . $shipping_country));
                    }
                }else{
                    $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
                    foreach(WC()->shipping()->get_packages() as $i => $package){
                        if(!isset($chosen_shipping_methods[$i], $package['rates'][$chosen_shipping_methods[$i]])){
                            $errors->add('shipping', __('No shipping method has been selected. Please double check your address, or contact us if you need any help.', 'woocommerce'));
                        }
                    }
                }
            }
        }
        protected function validate_posted_data(&$data, &$errors){
            foreach($this->get_checkout_fields() as $fieldset_key => $fieldset){
                $validate_fieldset = true;
                if($this->maybe_skip_fieldset($fieldset_key, $data)) $validate_fieldset = false;
                foreach($fieldset as $key => $field){
                    if(!isset($data[$key])) continue;
                    $required = !empty($field['required']);
                    $format = array_filter(isset($field['validate']) ? (array) $field['validate'] : array());
                    $field_label = isset($field['label']) ? $field['label'] : '';
                    if($validate_fieldset && (isset($field['type']) && 'country'===$field['type'] && ''!==$data[$key]) && !WC()->countries->country_exists($data[$key])){
                        $errors->add($key . '_validation', sprintf(__("'%s' is not a valid country code.", 'woocommerce'), $data[$key]));
                    }
                    switch($fieldset_key){
                        case 'shipping':
                            $field_label = sprintf(_x('Shipping %s', 'checkout-validation', 'woocommerce'), $field_label);
                            break;
                        case 'billing':
                            $field_label = sprintf(_x('Billing %s', 'checkout-validation', 'woocommerce'), $field_label);
                            break;
                    }
                    if(in_array('postcode', $format, true)){
                        $country = isset($data[$fieldset_key . '_country']) ? $data[$fieldset_key . '_country'] : WC()->customer->{"get_{$fieldset_key}_country"}();
                        $data[$key] = wc_format_postcode($data[$key], $country);
                        if($validate_fieldset && ''!==$data[$key] && !WC_Validation::is_postcode($data[$key], $country)){
                            switch($country){
                                case 'IE':
                                    $postcode_validation_notice = sprintf(__('%1$s is not valid. You can look up the correct Eircode <a target="_blank" href="%2$s">here</a>.', 'woocommerce'), '<strong>' . esc_html($field_label) . '</strong>', 'https://finder.eircode.ie');
                                    break;
                                default:
                                    $postcode_validation_notice = sprintf(__('%s is not a valid postcode / ZIP.', 'woocommerce'), '<strong>' . esc_html($field_label) . '</strong>');
                            }
                            $errors->add($key . '_validation', apply_filters('woocommerce_checkout_postcode_validation_notice', $postcode_validation_notice, $country, $data[$key]), array('id' => $key));
                        }
                    }
                    if(in_array('phone', $format, true)){
                        if($validate_fieldset && ''!==$data[$key] && !WC_Validation::is_phone($data[$key])){
                            $errors->add($key . '_validation', sprintf(__('%s is not a valid phone number.', 'woocommerce'), '<strong>' . esc_html($field_label) . '</strong>'), array('id' => $key));
                        }
                    }
                    if(in_array('email', $format, true) && ''!==$data[$key]){
                        $email_is_valid = is_email($data[$key]);
                        $data[$key] = sanitize_email($data[$key]);
                        if($validate_fieldset && !$email_is_valid){
                            $errors->add($key . '_validation', sprintf(__('%s is not a valid email address.', 'woocommerce'), '<strong>' . esc_html($field_label) . '</strong>'), array('id'=>$key));
                            continue;
                        }
                    }
                    if(''!==$data[$key] && in_array('state', $format, true)){
                        $country = isset($data[$fieldset_key . '_country']) ? $data[$fieldset_key . '_country'] : WC()->customer->{"get_{$fieldset_key}_country"}();
                        $valid_states = WC()->countries->get_states($country);
                        if(!empty($valid_states) && is_array($valid_states) && count($valid_states) > 0){
                            $valid_state_values = array_map('wc_strtoupper', array_flip(array_map('wc_strtoupper', $valid_states)));
                            $data[$key] = wc_strtoupper($data[$key]);
                            if(isset($valid_state_values[$data[$key]])){
                                // With this part we consider state value to be valid as well, convert it to the state key for the valid_states check below.
                                $data[$key] = $valid_state_values[$data[$key]];
                            }
                            if($validate_fieldset && !in_array($data[$key], $valid_state_values, true)){
                                $errors->add($key . '_validation', sprintf(__('%1$s is not valid. Please enter one of the following: %2$s', 'woocommerce'), '<strong>' . esc_html($field_label) . '</strong>', implode(', ', $valid_states)), array('id' => $key));
                            }
                        }
                    }
                    if($validate_fieldset && $required && ''===$data[$key]){
                        $errors->add($key . '_required', apply_filters('woocommerce_checkout_required_field_notice', sprintf(__('%s is a required field.', 'woocommerce'), '<strong>' . esc_html($field_label) . '</strong>'), $field_label, $key), array('id'=>$key));
                    }
                }
            }
        }
        public function get_checkout_fields($fieldset = ''){
            if(!is_null($this->fields)){
                return $fieldset ? $this->fields[$fieldset] : $this->fields;
            }
            // Fields are based on billing/shipping country. Grab those values but ensure they are valid for the store before using.
            $billing_country = $this->get_value('billing_country');
            $billing_country = empty($billing_country) ? WC()->countries->get_base_country() : $billing_country;
            $allowed_countries = WC()->countries->get_allowed_countries();
            if(!array_key_exists($billing_country, $allowed_countries)){
                $billing_country = current(array_keys($allowed_countries));
            }
            $shipping_country  = $this->get_value('shipping_country');
            $shipping_country  = empty($shipping_country) ? WC()->countries->get_base_country() : $shipping_country;
            $allowed_countries = WC()->countries->get_shipping_countries();
            if(!array_key_exists($shipping_country, $allowed_countries)){
                $shipping_country = current(array_keys($allowed_countries));
            }
            $this->fields = array(
                'billing' => WC()->countries->get_address_fields($billing_country, 'billing_'),
                'shipping' => WC()->countries->get_address_fields($shipping_country, 'shipping_'),
                'account' => array(),
                'order' => array(
                    'order_comments' => array(
                        'type' => 'textarea',
                        'class' => array('notes'),
                        'label' => __('Order notes', 'woocommerce'),
                        'placeholder' => esc_attr__('Notes about your order, e.g. special notes for delivery.', 'woocommerce'),
                    ),
                ),
            );
            if('no'===get_option('woocommerce_registration_generate_username')){
                $this->fields['account']['account_username'] = array(
                    'type' => 'text',
                    'label' => __('Account username', 'woocommerce'),
                    'required' => true,
                    'placeholder' => esc_attr__('Username', 'woocommerce'),
                    'autocomplete' => 'username',
                );
            }
            if('no'===get_option('woocommerce_registration_generate_password')){
                $this->fields['account']['account_password'] = array(
                    'type' => 'password',
                    'label' => __('Create account password', 'woocommerce'),
                    'required' => true,
                    'placeholder' => esc_attr__('Password', 'woocommerce'),
                    'autocomplete' => 'new-password',
                );
            }
            $this->fields = apply_filters('woocommerce_checkout_fields', $this->fields);
            foreach($this->fields as $field_type => $fields){
                // Sort each of the checkout field sections based on priority.
                uasort($this->fields[$field_type], 'wc_checkout_fields_uasort_comparison');
                // Add accessibility labels to fields that have placeholders.
                foreach($fields as $single_field_type => $field){
                    if(empty($field['label']) && !empty($field['placeholder'])){
                        $this->fields[$field_type][$single_field_type]['label'] = $field['placeholder'];
                        $this->fields[$field_type][$single_field_type]['label_class'] = array('screen-reader-text');
                    }
                }
            }
            return $fieldset ? $this->fields[$fieldset] : $this->fields;
        }
        public function is_registration_required(){
            return apply_filters('woocommerce_checkout_registration_required', 'yes'!==get_option('woocommerce_enable_guest_checkout'));
        }
        public function is_registration_enabled(){
            return apply_filters('woocommerce_checkout_registration_enabled', 'yes'===get_option('woocommerce_enable_signup_and_login_from_checkout'));
        }
        protected function maybe_skip_fieldset($fieldset_key, $data){
            if('shipping'===$fieldset_key && (!$data['ship_to_different_address'] || ! WC()->cart->needs_shipping_address())) return true;
            if('account'===$fieldset_key && (is_user_logged_in() || (!$this->is_registration_required() && empty($data['createaccount'])))) return true;
            return false;
        }
        public function get_posted_data(){
            $data = array(
                'terms' => (int) isset($_POST['terms']),
                'terms-field' => (int) isset($_POST['terms-field']),
                'createaccount' => (int) ($this->is_registration_enabled() ? !empty($_POST['createaccount']) : false),
                'payment_method' => isset($_POST['payment_method']) ? wc_clean(wp_unslash($_POST['payment_method'])) : '',
                'shipping_method' => isset($_POST['shipping_method']) ? wc_clean(wp_unslash($_POST['shipping_method'])) : '',
                'ship_to_different_address' => !empty($_POST['ship_to_different_address']) && !wc_ship_to_billing_address_only(),
                'woocommerce_checkout_update_totals' => isset($_POST['woocommerce_checkout_update_totals']),
            );
            $skipped = array();
            $form_was_shown = isset($_POST['woocommerce-process-checkout-nonce']);
            foreach($this->get_checkout_fields() as $fieldset_key => $fieldset){
                if($this->maybe_skip_fieldset($fieldset_key, $data)){
                    $skipped[] = $fieldset_key;
                    continue;
                }
                foreach($fieldset as $key => $field){
                    $type = sanitize_title(isset($field['type']) ? $field['type'] : 'text');
                    if(isset($_POST[$key]) && ''!==$_POST[$key]){
                        switch($type){
                            case 'checkbox':
                                $value = 1;
                                break;
                            case 'multiselect':
                                $value = implode(', ', wc_clean(wp_unslash($_POST[$key])));
                                break;
                            case 'textarea':
                                $value = wc_sanitize_textarea(wp_unslash($_POST[$key]));
                                break;
                            case 'password':
                                break;
                            default:
                                $value = wc_clean(wp_unslash($_POST[$key]));
                                break;
                        }
                    }elseif(isset($field['default']) && $field['default']!=='' && 'checkbox'!==$type && !$form_was_shown){
                        switch($type){
                            case 'checkbox':
                                $value = 1;
                                break;
                            case 'multiselect':
                                $value = implode(', ', wc_clean(wp_unslash($field['default'])));
                                break;
                            case 'textarea':
                                $value = wc_sanitize_textarea(wp_unslash($field['default']));
                                break;
                            case 'password':
                                break;
                            default:
                                $value = wc_clean(wp_unslash($field['default']));
                                break;
                        }
                    }else{
                        $value = '';
                    }
                    $data[$key] = apply_filters('woocommerce_process_checkout_' . $type . '_field', apply_filters('woocommerce_process_checkout_field_' . $key, $value));
                }
            }
            if(in_array('shipping', $skipped, true) && (WC()->cart->needs_shipping_address() || wc_ship_to_billing_address_only())){
                foreach($this->get_checkout_fields('shipping') as $key => $field){
                    $data[$key] = isset($data['billing_' . substr($key, 9)]) ? $data['billing_' . substr($key, 9)] : '';
                }
            }
            $this->legacy_posted_data = $data;
            return apply_filters('woocommerce_checkout_posted_data', $data);
        }
        public function get_value($input){
            // If the form was posted, get the posted value. This will only tend to happen when JavaScript is disabled client side.
            if(!empty($_POST[$input])){
                return wc_clean(wp_unslash($_POST[$input]));
            }
            // Allow 3rd parties to short circuit the logic and return their own default value.
            $value = apply_filters('woocommerce_checkout_get_value', null, $input);
            if(!is_null($value)){
                return $value;
            }
            $customer_object = false;
            if(is_user_logged_in()){
                if(is_null($this->logged_in_customer)){
                    $this->logged_in_customer = new WC_Customer(get_current_user_id(), true);
                }
                $customer_object = $this->logged_in_customer;
            }
            if(!$customer_object){
                $customer_object = WC()->customer;
            }
            if(is_callable(array($customer_object, "get_$input"))){
                $value = $customer_object->{"get_$input"}();
            }elseif($customer_object->meta_exists($input)){
                $value = $customer_object->get_meta($input, true);
            }
            if(''===$value) $value = null;
            return apply_filters('default_checkout_' . $input, $value, $input);
        }
        public function load_plugin_textdomain(){
            $locale = apply_filters('plugin_locale', get_locale(), 'wc-csabpo');
            load_textdomain('wc-csabpo', WP_LANG_DIR . '/wc-confirm-shipping-address-before-placing-order/wc-csabpo-' . $locale . '.mo');
            load_plugin_textdomain('wc-csabpo', false, plugin_basename(dirname(__FILE__)) . '/i18n/languages');
        }
        public function ajax_url(){
            return admin_url('admin-ajax.php', 'relative');
        }
    }
endif;

/**
 * Returns the main instance of WC_CSABPO to prevent the need to use globals.
 *
 * @return WC_CSABPO
 */
if(!function_exists('WC_CSABPO')){
    function WC_CSABPO(){
        return WC_CSABPO::instance();
    }
    // Global for backwards compatibility.
    $GLOBALS['wc_csabpo'] = WC_CSABPO();
}
